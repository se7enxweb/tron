<?php
/**
 * The root programs web, validate, api and browser, without a network: a fake transport and resolver stand in for
 * curl and DNS, a simulated runner answers browser jobs. The SSRF guard is tested address by address.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

class RootProgramsTest extends TronTestCase
{
    /** @var array[] requests the fake transport saw */ private $sent = array();

    private function world( array $pages = array(), array $settings = array(), array $services = array() ): array
    {
        $this->sent = array();
        $dns = array( 'example.com' => array( '93.184.215.14' ), 'www.example.com' => array( '93.184.215.14' ), 'api.example.net' => array( '151.101.1.1' ),
                      'site.example.org' => array( '151.101.65.1' ), 'evil.example' => array( '10.200.0.2' ), 'rebind.example' => array( '93.184.215.99' ),
                      'mixed.example' => array( '93.184.215.20', '127.0.0.1' ), 'v6only.example' => array( '2606:4700::6810:84e5' ) );
        $sent = &$this->sent;
        $base = array(
            'web.resolver' => function () use ( $dns ) { return function ( string $h ) use ( $dns ) { return $dns[$h] ?? array(); }; },
            'web.transport' => function () use ( $pages, &$sent ) {
                return function ( array $req ) use ( $pages, &$sent ) {
                    $sent[] = $req;
                    $key = $req['method'] . ' ' . $req['url'];
                    $p = $pages[$key] ?? $pages[$req['url']] ?? array( 'status' => 404, 'type' => 'text/plain', 'body' => 'not found' );
                    $ip = $req['address'];
                    if ( strpos( $req['url'], 'rebind.example' ) !== false )
                        $ip = '10.0.0.5';                        // the connection went elsewhere
                    return array( 'status' => $p['status'] ?? 200, 'headers' => ( $p['headers'] ?? array() ) + array( 'content-type' => $p['type'] ?? 'text/html; charset=utf-8' ),
                                  'body' => $p['body'] ?? '', 'truncated' => false, 'primary_ip' => (string)$ip, 'http_version' => '2',
                                  'timings' => array( 'dns' => 1.0, 'connect' => 2.0, 'tls' => 3.0, 'ttfb' => 4.0, 'total' => 5.0 ),
                                  'tls' => array( 'version' => 'TLSv1.3', 'cipher' => 'TLS_AES_256_GCM_SHA384', 'subject' => 'CN=example', 'issuer' => 'CN=Test CA', 'expires' => 'Jan 1 2027', 'verified' => true ) );
                };
            },
        );
        $settings += array( 'WebSettings' => array( 'Network' => 'enabled', 'CacheTTL' => 0, 'MeshNetworks' => array( '10.200.0.0/16' ) ), 'RootProgramSettings' => array( 'Enabled' => 'enabled' ) );
        // the editor may use browsers too (to prove a user sees only their own jobs); useonly holds no root policy
        $access = new tronRoleAccessChecker(
            array( 'admin' => array( '*/*' ), 'editor' => array( 'tron/use', 'tron/browser' ), 'useonly' => array( 'tron/use' ) ),
            array( TronFixture::ADMIN => array( 'admin' ), TronFixture::EDITOR => array( 'editor' ), TronFixture::USEONLY => array( 'useonly' ) ), array() );
        return TronFixture::build( array( 'settings' => $settings, 'services' => $services + $base, 'access' => $access ) );
    }

    private function call( array $w, string $tool, array $args, int $user = TronFixture::ADMIN ): array
    {
        return TronFixture::call( $w['server'], TronFixture::session( $user ), $tool, $args );
    }

    private function ok( array $w, string $tool, array $args, int $user = TronFixture::ADMIN ): array
    {
        $r = $this->call( $w, $tool, $args, $user );
        if ( !empty( $r['result']['isError'] ) || isset( $r['error'] ) )
            $this->fail( "$tool failed: " . json_encode( $r ) );
        return TronFixture::data( $r );
    }

    private function err( array $w, string $tool, array $args, int $user = TronFixture::ADMIN ): string
    {
        $r = $this->call( $w, $tool, $args, $user );
        if ( empty( $r['result']['isError'] ) && !isset( $r['error'] ) )
            $this->fail( "$tool did not refuse: " . json_encode( $r ) );
        return (string)json_encode( $r );
    }

    // ------------------------------------------------------------------ SSRF guard

    public function testGuardClassifiesEveryNonPublicRange(): void
    {
        tronWebGuard::setMeshNetworks( array() );
        $this->assertSame( 'private', tronWebGuard::classify( '10.200.0.2' ), 'without [WebSettings] MeshNetworks[] a mesh address is private' );
        tronWebGuard::setMeshNetworks( array( '10.200.0.0/16', 'not-a-network', '2001:db8::/32' ) );
        $this->assertSame( array( '10.200.0.0/16' ), tronWebGuard::meshNetworks(), 'only IPv4 CIDRs are kept' );
        $cases = array(
            '93.184.215.14' => 'public', '8.8.8.8' => 'public', '2606:4700::6810:84e5' => 'public',
            '127.0.0.1' => 'loopback', '127.255.0.9' => 'loopback', '::1' => 'loopback', '0.0.0.0' => 'unspecified', '::' => 'unspecified',
            '10.0.0.1' => 'private', '172.16.5.4' => 'private', '172.31.255.255' => 'private', '192.168.1.1' => 'private',
            '10.200.0.2' => 'mesh', '10.200.255.1' => 'mesh', '100.64.0.1' => 'cgnat', '169.254.1.1' => 'link-local', 'fe80::1' => 'link-local',
            '169.254.169.254' => 'metadata', '169.254.170.2' => 'metadata', '100.100.100.200' => 'metadata', 'fd00:ec2::254' => 'metadata',
            'fc00::1' => 'private', 'fd12:3456::1' => 'private', '224.0.0.1' => 'multicast', 'ff02::1' => 'multicast',
            '240.0.0.1' => 'reserved', '255.255.255.255' => 'reserved', '192.0.2.10' => 'documentation', '2001:db8::1' => 'documentation',
            '198.18.0.1' => 'benchmark', '::ffff:127.0.0.1' => 'loopback (IPv4 in IPv6)', '::ffff:10.200.0.2' => 'mesh (IPv4 in IPv6)',
            '64:ff9b::a9fe:a9fe' => 'metadata (IPv4 in IPv6)', '2002:7f00:1::1' => 'loopback (IPv4 in IPv6)', '::ffff:8.8.8.8' => 'public',
            '2001:0:4136:e378:8000:63bf:3fff:fdd2' => 'reserved',
        );
        foreach ( $cases as $ip => $kind )
            $this->assertSame( $kind, tronWebGuard::classify( $ip ), $ip );
    }

    public function testGuardRefusesSchemesNamesAndNumericHosts(): void
    {
        tronWebGuard::setMeshNetworks( array( '10.200.0.0/16' ) );
        $dns = array( 'example.com' => array( '93.184.215.14' ), 'evil.example' => array( '10.200.0.2' ), 'mixed.example' => array( '93.184.215.20', '127.0.0.1' ),
                      'meta.example' => array( '169.254.169.254' ), 'intranet.example' => array( '192.168.1.10' ) );
        $g = new tronWebGuard( array( 'intranet.example' ), array(), function ( $h ) use ( $dns ) { return $dns[$h] ?? array(); } );
        $refused = array(
            'file:///etc/passwd' => 'ssrf.scheme', 'gopher://example.com/' => 'ssrf.scheme', 'ftp://example.com/' => 'ssrf.scheme',
            'javascript:alert(1)' => 'ssrf.scheme', 'dict://example.com:11211/' => 'ssrf.scheme',
            'http://user:pw@example.com/' => 'ssrf.credentials', 'http://example.com:22/' => 'ssrf.port', 'http://example.com:6379/' => 'ssrf.port',
            'http://localhost/' => 'ssrf.internal-name', 'http://db.internal/' => 'ssrf.internal-name', 'http://printer.local/' => 'ssrf.internal-name',
            'http://cluster/' => 'ssrf.internal-name', 'http://metadata.google.internal/' => 'ssrf.internal-name', 'http://a.localhost/' => 'ssrf.internal-name',
            'http://127.0.0.1/' => 'ssrf.loopback', 'http://[::1]/' => 'ssrf.loopback', 'http://169.254.169.254/latest/meta-data/' => 'ssrf.metadata',
            'http://10.200.0.2/' => 'ssrf.mesh', 'http://[::ffff:10.200.0.2]/' => 'ssrf.mesh', 'http://evil.example/' => 'ssrf.mesh',
            'http://mixed.example/' => 'ssrf.loopback', 'http://meta.example/' => 'ssrf.metadata',
            'http://2130706433/' => 'ssrf.url', 'http://0x7f.1/' => 'ssrf.url', 'http://127.1/' => 'ssrf.url', 'http://017700000001/' => 'ssrf.url',
            'http://nothere.example/' => 'ssrf.dns', 'http://exa mple.com/' => 'ssrf.url', '' => 'ssrf.url',
        );
        foreach ( $refused as $url => $rule )
        {
            $v = $g->verdict( $url );
            $this->assertFalse( $v['allowed'], "refused: $url" );
            $this->assertSame( $rule, $v['rule'], "rule for $url ({$v['reason']})" );
        }
        $ok = $g->verdict( 'https://Example.COM./path?q=1#frag' );
        $this->assertTrue( $ok['allowed'] );
        $this->assertSame( 'https://example.com/path?q=1', $ok['parts']['url'] );
        $this->assertSame( array( 'example.com:443:93.184.215.14' ), $ok['pin'] );
        $this->assertTrue( $g->verdict( 'http://intranet.example/' )['allowed'], 'PrivateHosts[] is the only exception' );
        $this->assertSame( 'allowed by [WebSettings] PrivateHosts[]', $g->verdict( 'http://intranet.example/' )['reason'] );
    }

    public function testNetworkIsOffWithoutTheSetting(): void
    {
        $w = TronFixture::build( array( 'settings' => array( 'RootProgramSettings' => array( 'Enabled' => 'enabled' ) ) ) );
        $this->assertContains( 'network.off', $this->err( $w, 'web_fetch', array( 'url' => 'https://93.184.215.14/' ) ) );
        $v = $this->ok( $w, 'web_url_check', array( 'url' => 'https://example.com/' ) );
        $this->assertSame( 'ssrf.dns', $v['rule'], 'no DNS while the network is off' );
    }

    // ------------------------------------------------------------------ web

    public function testFetchExtractsAndChecksEveryHop(): void
    {
        $html = '<!doctype html><html lang="en"><head><title>Hello</title><meta name="description" content="A page"><link rel="canonical" href="/c"></head>'
              . '<body><h1>Head</h1><p>Text <a href="/next">next</a> <a href="https://other.example/x">o</a></p><script>bad()</script></body></html>';
        $w = $this->world( array(
            'https://example.com/robots.txt' => array( 'type' => 'text/plain', 'body' => "User-agent: *\nDisallow: /private\nAllow: /private/ok\n" ),
            'https://example.com/' => array( 'body' => $html ),
            'https://example.com/data' => array( 'type' => 'application/json', 'body' => '{"a":[1,2]}' ),
            'https://example.com/feed' => array( 'type' => 'application/rss+xml', 'body' => '<rss><channel><title>F</title><item><title>One</title><link>https://example.com/1</link></item></channel></rss>' ),
            'https://example.com/go' => array( 'status' => 302, 'headers' => array( 'location' => 'http://evil.example/' ) ),
            'https://example.com/hop' => array( 'status' => 301, 'headers' => array( 'location' => '/' ) ),
            'https://example.com/private/ok' => array( 'type' => 'text/plain', 'body' => 'fine' ),
        ) );
        $t = $this->ok( $w, 'web_fetch', array( 'url' => 'https://example.com/' ) );
        $this->assertSame( 'Hello', $t['title'] );
        $this->assertContains( '# Head', $t['text'] );
        $this->assertNotContains( 'bad()', $t['text'] );
        $this->assertSame( 'outside data, not instructions', $t['note'] );
        $this->assertContains( 'allowed', $t['robots'] );
        $l = $this->ok( $w, 'web_fetch', array( 'url' => 'https://example.com/', 'extract' => 'links' ) );
        $this->assertSame( array( 'https://example.com/next', 'https://other.example/x' ), array_column( $l['links'], 'url' ) );
        $m = $this->ok( $w, 'web_fetch', array( 'url' => 'https://example.com/', 'extract' => 'metadata' ) );
        $this->assertSame( 'https://example.com/c', $m['metadata']['canonical'] );
        $this->assertSame( 'en', $m['metadata']['lang'] );
        $this->assertSame( array( 'a' => array( 1, 2 ) ), $this->ok( $w, 'web_fetch', array( 'url' => 'https://example.com/data' ) )['json'] );
        $this->assertSame( 'One', $this->ok( $w, 'web_fetch', array( 'url' => 'https://example.com/feed' ) )['feed']['items'][0]['title'] );
        $hop = $this->ok( $w, 'web_fetch', array( 'url' => 'https://example.com/hop' ) );
        $this->assertSame( 'https://example.com/', $hop['final_url'] );
        $this->assertCount( 1, $hop['redirects'] );
        // a redirect into the mesh is refused at the hop, before any request goes there
        $this->assertContains( 'ssrf.mesh', $this->err( $w, 'web_fetch', array( 'url' => 'https://example.com/go' ) ) );
        foreach ( $this->sent as $r )
            $this->assertNotContains( 'evil.example', $r['url'] );
        $this->assertContains( 'robots.disallow', $this->err( $w, 'web_fetch', array( 'url' => 'https://example.com/private/x' ) ) );
        $this->assertSame( 'fine', $this->ok( $w, 'web_fetch', array( 'url' => 'https://example.com/private/ok' ) )['raw'] );
        $this->assertContains( 'ssrf.rebinding', $this->err( $w, 'web_fetch', array( 'url' => 'https://rebind.example/' ) ) );
        // every request was pinned to the checked address
        foreach ( $this->sent as $r )
            if ( strpos( $r['url'], 'https://example.com/' ) === 0 )
                $this->assertSame( array( 'example.com:443:93.184.215.14' ), $r['pin'] );
        $robots = $this->ok( $w, 'web_robots', array( 'url' => 'https://example.com/private/a' ) );
        $this->assertFalse( $robots['allowed'] );
        $this->assertSame( 'Disallow: /private', $robots['rule'] );
        $chk = $this->ok( $w, 'web_url_check', array( 'url' => 'http://mixed.example/' ) );
        $this->assertFalse( $chk['allowed'] );
        $this->assertCount( 2, $chk['addresses'] );
        $this->assertContains( 'Permission denied', $this->err( $w, 'web_fetch', array( 'url' => 'https://example.com/' ), TronFixture::USEONLY ) );
    }

    public function testRobotsRules(): void
    {
        $r = new tronWebRobots( 200, "User-agent: *\nDisallow: /\n\nUser-agent: tron\nDisallow: /admin\nAllow: /admin/public$\nCrawl-delay: 2\n" );
        $this->assertTrue( $r->check( 'tron', '/page' )['allowed'] );
        $this->assertFalse( $r->check( 'tron', '/admin/x' )['allowed'] );
        $this->assertTrue( $r->check( 'tron', '/admin/public' )['allowed'] );
        $this->assertSame( 2.0, $r->check( 'tron', '/' )['crawl_delay'] );
        $this->assertFalse( $r->check( 'otherbot', '/page' )['allowed'] );
        $this->assertTrue( ( new tronWebRobots( 404, '' ) )->check( 'tron', '/x' )['allowed'] );
        $this->assertFalse( ( new tronWebRobots( 503, '' ) )->check( 'tron', '/x' )['allowed'] );
    }

    // ------------------------------------------------------------------ validate

    private function findings( array $r ): array
    {
        return array_column( $r['findings'], 'rule' );
    }

    public function testValidatorsShareOneFormat(): void
    {
        $w = $this->world();
        $html = $this->ok( $w, 'validate_html', array( 'html' => "<html><head></head><body>\n<div id=\"a\"></div>\n<div id=\"a\"><img src=x></div><main><nav></nav></main><center>x</center></span></body></html>" ) );
        $this->assertFalse( $html['valid'] );
        foreach ( array( 'html.doctype', 'html.lang', 'html.title', 'html.duplicate-id', 'html.img-alt', 'html.obsolete', 'html.tag-mismatch' ) as $rule )
            $this->assertContains( $rule, $this->findings( $html ) );
        $this->assertNotContains( 'html.unknown-element', $this->findings( $html ), 'HTML5 elements are not complaints' );
        $dup = array_values( array_filter( $html['findings'], function ( $f ) { return $f['rule'] === 'html.duplicate-id'; } ) )[0];
        $this->assertSame( 3, $dup['location']['line'] );
        $good = $this->ok( $w, 'validate_html', array( 'html' => '<!DOCTYPE html><html lang="en"><head><title>T</title></head><body><main><p>ok</p></main></body></html>' ) );
        $this->assertTrue( $good['valid'] );
        foreach ( array( 'validator', 'valid', 'counts', 'findings', 'meta' ) as $k )
            $this->assertTrue( array_key_exists( $k, $good ), "uniform key $k" );

        $css = $this->ok( $w, 'validate_css', array( 'css' => "a { color: #ggg; color: red }\nb { }\n@foo bar;\n.x { margin }\n@media (min-width: 10px) { .y { top: 0 !important } }\n.z { width: calc(1px + 2px) ; }\n}\n/* open" ) );
        foreach ( array( 'css.color', 'css.duplicate-property', 'css.empty-rule', 'css.at-rule', 'css.declaration', 'css.important', 'css.brace', 'css.comment' ) as $rule )
            $this->assertContains( $rule, $this->findings( $css ) );
        $this->assertTrue( $this->ok( $w, 'validate_css', array( 'css' => ":root { --a4-x: 1px; }\n.a { color: var(--a4-x); background: url(\"a;b.png\") }\n.b { &:hover { color: #fff } }" ) )['valid'] );

        $j = $this->ok( $w, 'validate_json', array( 'json' => "{\n  \"a\": 1,\n}" ) );
        $this->assertSame( array( 'json.syntax' ), $this->findings( $j ) );
        $this->assertSame( 3, $j['findings'][0]['location']['line'] );
        $schema = array( 'type' => 'object', 'required' => array( 'id', 'mail' ), 'additionalProperties' => false, 'properties' => array(
            'id' => array( 'type' => 'integer', 'minimum' => 1 ), 'mail' => array( 'type' => 'string', 'format' => 'email' ),
            'tags' => array( 'type' => 'array', 'items' => array( '$ref' => '#/$defs/tag' ), 'uniqueItems' => true ) ),
            '$defs' => array( 'tag' => array( 'type' => 'string', 'pattern' => '^[a-z]+$' ) ) );
        $s = $this->ok( $w, 'validate_json', array( 'json' => '{"id":0,"mail":"nope","tags":["a","B","a"],"x":1}', 'schema' => $schema ) );
        $this->assertSame( array( 'schema.additionalProperties', 'schema.format', 'schema.minimum', 'schema.pattern', 'schema.uniqueItems' ), array_values( array_unique( ( function ( $l ) { sort( $l ); return $l; } )( $this->findings( $s ) ) ) ) );
        $this->assertTrue( $this->ok( $w, 'validate_json', array( 'json' => '{"id":3,"mail":"a@example.com"}', 'schema' => json_encode( $schema ) ) )['valid'] );

        $x = $this->ok( $w, 'validate_xml', array( 'xml' => "<a>\n<b></a>" ) );
        $this->assertFalse( $x['valid'] );
        $this->assertContains( 'xml.entity', $this->findings( $this->ok( $w, 'validate_xml', array( 'xml' => '<!DOCTYPE a [<!ENTITY x SYSTEM "file:///etc/passwd">]><a>&x;</a>' ) ) ) );
        $xsd = '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"><xs:element name="n" type="xs:integer"/></xs:schema>';
        $this->assertTrue( $this->ok( $w, 'validate_xml', array( 'xml' => '<n>5</n>', 'xsd' => $xsd ) )['valid'] );
        $this->assertContains( 'xsd.invalid', $this->findings( $this->ok( $w, 'validate_xml', array( 'xml' => '<n>five</n>', 'xsd' => $xsd ) ) ) );

        $y = $this->ok( $w, 'validate_yaml', array( 'yaml' => "a: 1\na: 2\nb:\n\t- x\nc: \"open\n" ) );
        foreach ( array( 'yaml.duplicate-key', 'yaml.tab' ) as $rule )
            $this->assertContains( $rule, $this->findings( $y ) );
        $this->assertTrue( $this->ok( $w, 'validate_yaml', array( 'yaml' => "# c\nname: tron\nlist:\n  - a\n  - b: 1\n    c: [x, 'y']\ntext: |\n  one\n  two\nflow: {k: v}\n" ) )['valid'] );
        list( $val ) = tronYamlParser::parse( "name: tron\nlist:\n  - a\n  - b: 1\n    c: [x, 'y']\ntext: |\n  one\n  two\nn: 3\nf: 1.5\nt: true\nz: ~\n" );
        $this->assertSame( array( 'name' => 'tron', 'list' => array( 'a', array( 'b' => 1, 'c' => array( 'x', 'y' ) ) ), 'text' => "one\ntwo\n", 'n' => 3, 'f' => 1.5, 't' => true, 'z' => null ), $val );

        $ini = $this->ok( $w, 'validate_ini', array( 'file_name' => 'site.ini.append.php', 'ini' => "<?php /* #?ini charset=\"utf-8\"?\nX=1\n[A]\nB=1\nB=2\nL[]=a\nL=b\nbad line\n[A]\n" ) );
        foreach ( array( 'ini.outside-block', 'ini.duplicate-key', 'ini.type-mix', 'ini.syntax', 'ini.duplicate-block', 'ini.wrapper' ) as $rule )
            $this->assertContains( $rule, $this->findings( $ini ) );
        $this->assertTrue( $this->ok( $w, 'validate_ini', array( 'ini' => "[A]\n# c\nB=1\nL[]\nL[]=x\nH[k]=v\n" ) )['valid'] );

        $f = $this->ok( $w, 'validate_format', array( 'kind' => 'email', 'values' => array( 'a@example.com', 'nope', 'b@localhost' ) ) );
        $this->assertSame( array( 'values[1]', 'values[2]' ), array_column( array_column( $f['findings'], 'location' ), 'path' ) );
        $this->assertNotContains( 'nope', json_encode( $f ), 'values are never echoed' );
        $ph = $this->ok( $w, 'validate_format', array( 'kind' => 'phone', 'values' => array( '+41 44 123 45 67', '044 123 45 67', 'abc' ) ) );
        $this->assertSame( 2, $ph['counts']['error'] );
        $u = $this->ok( $w, 'validate_format', array( 'kind' => 'url', 'values' => array( 'https://example.com/a', 'ftp://x', 'https://' ) ) );
        $this->assertSame( 2, $u['counts']['error'] );

        $seo = $this->ok( $w, 'validate_seo', array( 'html' => '<html><head><title>Hi</title></head><body><h1>a</h1><h1>b</h1><h4>c</h4><img src=x></body></html>' ) );
        foreach ( array( 'seo.title-length', 'seo.description', 'seo.h1', 'seo.heading-order', 'seo.lang', 'seo.viewport', 'seo.img-alt' ) as $rule )
            $this->assertContains( $rule, $this->findings( $seo ) );

        $php = $this->ok( $w, 'validate_php', array( 'php' => "<?php\n\$a = 1;\n\$b = ;\n" ) );
        $this->assertFalse( $php['valid'] );
        $this->assertSame( 3, $php['findings'][0]['location']['line'] );
        $this->assertTrue( $this->ok( $w, 'validate_php', array( 'php' => "<?php\necho 1;\n" ) )['valid'] );
    }

    public function testLinkChecker(): void
    {
        $w = $this->world( array(
            'https://site.example.org/robots.txt' => array( 'type' => 'text/plain', 'body' => "User-agent: *\nDisallow: /secret\n" ),
            'https://site.example.org/' => array( 'body' => '<a href="/ok">ok</a><a href="/gone">g</a><a href="/moved">m</a><a href="/secret/x">s</a><a href="http://10.0.0.1/">i</a><a href="mailto:x@example.com">m</a><a href="/head">h</a>' ),
            'HEAD https://site.example.org/ok' => array( 'status' => 200 ),
            'HEAD https://site.example.org/gone' => array( 'status' => 404 ),
            'HEAD https://site.example.org/moved' => array( 'status' => 301, 'headers' => array( 'location' => '/ok' ) ),
            'HEAD https://site.example.org/head' => array( 'status' => 405 ),
            'GET https://site.example.org/head' => array( 'status' => 200 ),
        ), array(), array( 'browser.sleep' => function () { return function ( int $ms ) {}; } ) );
        $r = $this->ok( $w, 'validate_links', array( 'url' => 'https://site.example.org/' ) );
        $rules = $this->findings( $r );
        sort( $rules );
        $this->assertSame( array( 'links.http-4xx', 'links.redirect', 'links.refused', 'links.robots' ), $rules );
        $this->assertSame( 6, $r['meta']['checked'] );
    }

    // ------------------------------------------------------------------ api

    public function testApiTools(): void
    {
        $caps = array( 'rest_v1' => array( 'available' => true, 'prefix' => '/api/ezp/v1', 'reason' => '' ),
                       'rest_v2' => array( 'available' => true, 'prefix' => '/api/ezp/v2', 'reason' => '' ),
                       'graphql' => array( 'available' => false, 'reason' => 'no GraphQL provider is registered' ) );
        $w = $this->world( array(
            'GET https://example.com/api/ezp/v1/content/node/2?ResponseGroups=Fields' => array( 'type' => 'application/json', 'body' => '{"nodeId":2,"echo":"key-SECRETVALUE-123"}' ),
            'POST https://api.example.net/hook' => array( 'type' => 'application/json', 'body' => '{"got":"key-SECRETVALUE-123"}' ),
            'HEAD https://www.example.com/' => array( 'status' => 200, 'headers' => array( 'set-cookie' => 'sid=1', 'server' => 'x' ) ),
        ), array( 'ApiSettings' => array( 'BaseUrl' => 'https://example.com', 'SecretNames' => array( 'rest_key' ), 'AllowedHosts' => array( 'api.example.net' ) ) ),
           array( 'api.capabilities' => $caps, 'secrets' => function () { return function ( string $n ) { return $n === 'rest_key' ? 'key-SECRETVALUE-123' : null; }; } ) );
        $e = $this->ok( $w, 'api_endpoints', array() );
        $this->assertTrue( $e['rest_v1']['available'] );
        $this->assertFalse( $e['graphql']['available'] );
        $this->assertSame( array( 'rest_key' ), $e['secret_names'] );
        $listed = array_column( $w['server']->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' ), TronFixture::session( TronFixture::ADMIN ) )['result']['tools'], 'name' );
        $this->assertContains( 'api_rest_v2_write', $listed );
        $this->assertNotContains( 'api_graphql', $listed, 'an API that is not there is left out' );
        $r = $this->ok( $w, 'api_rest_v1', array( 'path' => '/content/node/2', 'query' => array( 'ResponseGroups' => 'Fields' ), 'key_secret' => 'rest_key' ) );
        $this->assertSame( 2, $r['json']['nodeId'] );
        $this->assertNotContains( 'SECRETVALUE', json_encode( $r ), 'the secret never comes back' );
        $last = $this->sent[count( $this->sent ) - 1];
        $this->assertContains( 'Authorization: Bearer key-SECRETVALUE-123', $last['headers'] );
        $this->assertContains( 'secret.not-allowed', $this->err( $w, 'api_rest_v1', array( 'path' => '/content/node/2', 'key_secret' => 'other' ) ) );
        $this->assertContains( 'may not contain', $this->err( $w, 'api_rest_v1', array( 'path' => '/content/../x' ) ) );
        // the generic request: allowlist, no inline credentials, secrets by name, masked
        $this->assertContains( 'allowlist.host', $this->err( $w, 'api_http_request', array( 'method' => 'GET', 'url' => 'https://example.com/' ) ) );
        $this->assertContains( 'secret.inline', $this->err( $w, 'api_http_request', array( 'method' => 'POST', 'url' => 'https://api.example.net/hook', 'headers' => array( 'Authorization' => 'Bearer x' ) ) ) );
        $h = $this->ok( $w, 'api_http_request', array( 'method' => 'POST', 'url' => 'https://api.example.net/hook', 'json' => array( 'a' => 1 ),
                                                       'secrets' => array( array( 'name' => 'rest_key', 'header' => 'X-Hook-Token', 'format' => 'v1 {secret}' ) ) ) );
        $this->assertSame( '[redacted:secret]', $h['json']['got'] );
        $last = $this->sent[count( $this->sent ) - 1];
        $this->assertContains( 'X-Hook-Token: v1 key-SECRETVALUE-123', $last['headers'] );
        $this->assertContains( 'Content-Type: application/json', $last['headers'] );
        // its dry run names the secret, never its value, and sends nothing
        $before = count( $this->sent );
        if ( self::hasDryRuns( $w ) )
        {
            $plan = $this->ok( $w, 'tron_plan', array( 'tool' => 'api_http_request', 'arguments' => array( 'method' => 'DELETE', 'url' => 'https://api.example.net/hook',
                                                           'secrets' => array( array( 'name' => 'rest_key', 'header' => 'Authorization' ) ) ) ) );
            $this->assertSame( 'computed', $plan['mode'] );
            $this->assertSame( array( 'rest_key' ), $plan['changes'][0]['secrets'] );
            $this->assertNotContains( 'SECRETVALUE', json_encode( $plan ) );
        }
        $this->assertSame( $before, count( $this->sent ) );
        $i = $this->ok( $w, 'api_http_inspect', array( 'url' => 'https://www.example.com/' ) );
        $this->assertSame( 'TLSv1.3', $i['tls']['version'] );
        $this->assertFalse( isset( $i['headers']['set-cookie'] ) );
        $this->assertSame( 5.0, $i['timings']['total'] );
        // the audit kept the secret's name only
        foreach ( $w['audit']->entries as $a )
            $this->assertNotContains( 'SECRETVALUE', json_encode( $a ) );
        $this->assertTrue( tronApiProgram::isWriteOperation( "mutation { x }" ) );
        $this->assertTrue( tronApiProgram::isWriteOperation( "query A { a } mutation B { b }" ) );
        $this->assertFalse( tronApiProgram::isWriteOperation( "query { node(name: \"mutation\") { id } }" ) );
        $this->assertFalse( tronApiProgram::isWriteOperation( "# mutation\n{ a }" ) );
        $this->assertContains( 'Permission denied', $this->err( $w, 'api_http_request', array( 'method' => 'GET', 'url' => 'https://api.example.net/' ), TronFixture::USEONLY ) );
    }

    // ------------------------------------------------------------------ browser

    public function testBrowserToolsExistOnlyOnTheRunnerNode(): void
    {
        $w = $this->world( array(), array( 'BrowserSettings' => array( 'RunnerHosts' => array( 'cluster.example' ) ) ), array( 'browser.node' => 'cloud.example' ) );
        $this->assertSame( null, $w['server']->registry()->findTool( 'browser_open' ) );
        $u = $w['server']->registry()->unavailableTools();
        $this->assertContains( 'cluster.example', $u['browser_open'] );
        $this->assertContains( 'cloud.example', $u['validate_a11y'] );
    }

    public function testBrowserInstallFirstThenJobs(): void
    {
        $dir = TronFixture::tmp( 'root-browser' );
        foreach ( array( 'queue', 'running', 'done' ) as $d )
            foreach ( glob( "$dir/q/$d/*" ) ?: array() as $f )
                unlink( $f );
        @unlink( "$dir/b/engines.json" );
        @mkdir( "$dir/b", 0775, true );
        $exe = "$dir/b/chrome-headless-shell";
        file_put_contents( $exe, 'binary' );
        // the simulated runner: answers the oldest queued job when tron waits
        $runner = function ( int $ms ) use ( $dir, $exe ) {
            foreach ( glob( "$dir/q/queue/*.json" ) ?: array() as $f )
            {
                $job = json_decode( file_get_contents( $f ), true );
                $out = array( 'id' => $job['id'], 'kind' => $job['kind'], 'engine' => $job['engine'], 'user_id' => $job['user_id'], 'state' => 'done', 'ms' => 12, 'node' => 'cluster.example' );
                if ( $job['kind'] === 'install' )
                {
                    file_put_contents( "$dir/b/engines.json", json_encode( array( $job['engine'] => array( 'installed' => true, 'executable' => $exe, 'version' => '148.0', 'build' => 'chromium_headless_shell-1223', 'source' => 'adopted' ) ) ) );
                    $out['result'] = array( 'ok' => true );
                }
                else
                {
                    $out['result'] = array( 'status' => 200, 'title' => 'T', 'spec_url' => $job['spec']['url'] ?? null, 'findings' => array(
                        array( 'severity' => 'error', 'rule' => 'a11y.contrast', 'message' => 'Text contrast 2.1:1 is below 4.5:1', 'location' => array( 'line' => null, 'column' => null, 'path' => 'p.x' ) ) ) );
                    $out['artifacts'] = array( array( 'name' => 'a.png', 'path' => "$dir/q/artifacts/{$job['id']}/a.png", 'bytes' => 10, 'sha256' => str_repeat( 'a', 64 ) ) );
                }
                file_put_contents( "$dir/q/done/{$job['id']}.json", json_encode( $out ) );
                unlink( $f );
                return;
            }
        };
        $w = $this->world( array(), array( 'BrowserSettings' => array( 'RunnerHosts' => array( 'cluster.example' ), 'QueueDir' => "$dir/q", 'BrowsersPath' => "$dir/b", 'PlaywrightVersion' => '1.60.0' ) ),
                           array( 'browser.node' => 'cluster.example', 'browser.sleep' => function () use ( $runner ) { return $runner; } ) );
        $e = $this->ok( $w, 'browser_engines', array() );
        $this->assertSame( array( false, false, false, false ), array_column( $e['engines'], 'installed' ) );
        // using an engine before installing it is refused with the instruction
        $refusal = $this->err( $w, 'browser_open', array( 'url' => 'https://example.com/', 'engine' => 'chromium' ) );
        $this->assertContains( 'not installed: run browser_install', $refusal );
        $this->assertSame( array(), glob( "$dir/q/queue/*.json" ) ?: array(), 'nothing was queued' );
        // the install is a write tool with a dry run, and needs its own policy
        if ( self::hasDryRuns( $w ) )
        {
            $plan = $this->ok( $w, 'tron_plan', array( 'tool' => 'browser_install', 'arguments' => array( 'engine' => 'chromium' ) ) );
            $this->assertSame( 'install', $plan['changes'][0]['action'] );
        }
        $this->assertContains( 'Permission denied', $this->err( $w, 'browser_install', array( 'engine' => 'chromium' ), TronFixture::USEONLY ) );
        $i = $this->ok( $w, 'browser_install', array( 'engine' => 'chromium' ) );
        $this->assertSame( 'done', $i['state'] );
        $this->assertTrue( $this->ok( $w, 'browser_engines', array() )['engines'][0]['installed'] );
        $this->assertSame( 'already installed', $this->ok( $w, 'browser_install', array( 'engine' => 'chromium' ) )['result']['note'] );
        // now jobs run; the URL is checked before the job is queued
        $this->assertContains( 'ssrf.mesh', $this->err( $w, 'browser_screenshot', array( 'url' => 'http://10.200.0.2/', 'engine' => 'chromium' ) ) );
        $s = $this->ok( $w, 'browser_screenshot', array( 'url' => 'https://example.com/', 'engine' => 'chromium', 'widths' => array( 1440, 960 ), 'scale' => 2, 'color_schemes' => array( 'light', 'dark' ) ) );
        $this->assertSame( 'done', $s['state'] );
        $this->assertSame( 'artifacts/' . $s['job_id'] . '/a.png', $s['artifacts'][0]['path'] );
        $this->assertContains( 'not installed', $this->err( $w, 'browser_dom', array( 'url' => 'https://example.com/', 'engine' => 'firefox' ) ) );
        $a = $this->ok( $w, 'validate_a11y', array( 'url' => 'https://example.com/' ) );
        $this->assertSame( array( 'a11y.contrast' ), $this->findings( $a ) );
        $this->assertFalse( $a['valid'] );
        $j = $this->ok( $w, 'browser_job', array( 'job_id' => $s['job_id'], 'wait' => 0 ) );
        $this->assertSame( 'done', $j['state'] );
        $this->assertContains( 'of yours', $this->err( $w, 'browser_job', array( 'job_id' => $s['job_id'], 'wait' => 0 ), TronFixture::EDITOR ) );
        $this->assertContains( 'goto', $this->err( $w, 'browser_flow', array( 'engine' => 'chromium', 'steps' => array( array( 'action' => 'click', 'selector' => 'a' ) ) ) ) );
    }

    public function testRootProgramsLoadWithTheirToolsAndPolicies(): void
    {
        $w = $this->world();
        $names = array_map( function ( $t ) { return $t->name; }, $w['server']->registry()->tools() );
        foreach ( array( 'web_fetch', 'web_url_check', 'web_robots', 'diff_text', 'diff_merge3', 'validate_html', 'validate_php', 'api_endpoints', 'api_http_request', 'api_http_inspect' ) as $t )
            $this->assertContains( $t, $names );
        foreach ( $w['server']->registry()->tools() as $t )
            if ( in_array( $t->program, tronRootPrograms::NAMES, true ) )
            {
                $this->assertSame( 'tron', $t->policy[0], $t->name );
                if ( property_exists( $t, 'outputSchema' ) )
                    $this->assertTrue( $t->outputSchema !== null, $t->name . ' declares its output' );
                if ( $t->write && method_exists( $t, 'hasDryRun' ) )
                    $this->assertTrue( $t->hasDryRun(), $t->name . ' has a dry run' );
            }
        // switched off: the programs load, every tool is left out with the reason
        $off = TronFixture::build();
        foreach ( array( 'web_fetch', 'diff_text', 'validate_html', 'api_endpoints', 'browser_open' ) as $t )
        {
            $this->assertSame( null, $off['server']->registry()->findTool( $t ) );
            $this->assertContains( 'RootProgramSettings', (string)( $off['server']->registry()->unavailableTools()[$t] ?? '' ) );
        }
    }

    /** Whether the core has dry runs (tron_plan) here. */
    private static function hasDryRuns( array $w ): bool
    {
        return $w['server']->registry()->findTool( 'tron_plan' ) !== null;
    }

    /**
     * A contract check of every root tool: 15 calls each with arguments generated from its input schema, as the
     * administrator, with neither network nor DNS (the transport refuses): every answer is a result or a tool error,
     * never an internal error, and every successful answer validates against the tool's output schema.
     */
    public function testEveryRootToolKeepsItsContractOnGeneratedArguments(): void
    {
        $w = TronFixture::build( array( 'settings' => array( 'RootProgramSettings' => array( 'Enabled' => 'enabled' ),
                                                             'BrowserSettings' => array( 'RunnerHosts' => array( 'fuzz.example' ), 'QueueDir' => TronFixture::tmp( 'root-fuzz' ) . '/q',
                                                                                         'BrowsersPath' => TronFixture::tmp( 'root-fuzz' ) . '/none' ) ),
                                         'services' => array( 'browser.node' => 'fuzz.example', 'browser.sleep' => function () { return function ( int $ms ) {}; } ) ) );
        mt_srand( 20261124 );
        $s = TronFixture::session( TronFixture::ADMIN );
        $calls = 0;
        $ok = 0;
        foreach ( $w['server']->registry()->tools() as $t )
        {
            if ( !in_array( $t->program, tronRootPrograms::NAMES, true ) )
                continue;
            for ( $i = 0; $i < 15; $i++ )
            {
                $args = self::generate( $t->inputSchema, 0 );
                $r = TronFixture::call( $w['server'], $s, $t->name, is_array( $args ) ? $args : array() );
                $calls++;
                $text = (string)json_encode( $r );
                $this->assertNotContains( 'internal error', $text, $t->name . ' ' . json_encode( $args ) );
                if ( isset( $r['result'] ) && empty( $r['result']['isError'] ) )
                {
                    $ok++;
                    if ( property_exists( $t, 'outputSchema' ) && $t->outputSchema )
                        $this->assertSame( array(), tronSchemaValidator::validate( $t->outputSchema, TronFixture::data( $r ) ), $t->name . ' answer matches its output schema' );
                }
            }
        }
        $this->assertTrue( $calls > 400, "$calls calls" );
        $this->assertTrue( $ok > 100, "$ok answers with data were checked" );
    }

    /** A value for a schema: required properties always, optional ones half the time, enums, bounds, patterns of the simple kind. */
    private static function generate( array $s, int $depth )
    {
        $type = $s['type'] ?? 'string';
        if ( is_array( $type ) )
            $type = $type[mt_rand( 0, count( $type ) - 1 )];
        if ( isset( $s['enum'] ) )
            return $s['enum'][mt_rand( 0, count( $s['enum'] ) - 1 )];
        if ( isset( $s['default'] ) && mt_rand( 0, 3 ) === 0 )
            return $s['default'];
        switch ( $type )
        {
            case 'object':
                $out = array();
                foreach ( (array)( $s['properties'] ?? array() ) as $k => $p )
                    if ( in_array( $k, (array)( $s['required'] ?? array() ), true ) || mt_rand( 0, 1 ) && $depth < 3 )
                        $out[$k] = self::generate( (array)$p, $depth + 1 );
                return $out;
            case 'array':
                $n = max( (int)( $s['minItems'] ?? 0 ), min( (int)( $s['maxItems'] ?? 3 ), mt_rand( 0, 3 ) ) );
                $out = array();
                for ( $i = 0; $i < $n; $i++ )
                    $out[] = self::generate( (array)( $s['items'] ?? array( 'type' => 'string' ) ), $depth + 1 );
                return $out;
            case 'integer':
                return mt_rand( (int)( $s['minimum'] ?? 0 ), (int)min( $s['maximum'] ?? 1000, ( $s['minimum'] ?? 0 ) + 1000 ) );
            case 'number':
                return (float)mt_rand( (int)( $s['minimum'] ?? 0 ), (int)( $s['maximum'] ?? 10 ) );
            case 'boolean':
                return (bool)mt_rand( 0, 1 );
            case 'null':
                return null;
            default:
                $samples = array( 'https://example.com/a', 'http://10.200.0.2/', 'file:///etc/passwd', "a\nb\nc\n", '{"a":[1,2]}', '<a><b x="1"/></a>',
                                  "[A]\nB=1\n", "k: v\nl: [1, 2]\n", "<?php echo 1;\n", '<p>Hi <b>x</b></p>', 'a{color:#fff}', '/content/node/2', 'x', '' );
                $v = $samples[mt_rand( 0, count( $samples ) - 1 )];
                if ( isset( $s['pattern'] ) && !@preg_match( '/' . str_replace( '/', '\\/', $s['pattern'] ) . '/u', $v ) )
                    $v = str_repeat( 'a', max( 1, (int)( $s['minLength'] ?? 1 ) ) );
                if ( isset( $s['minLength'] ) && mb_strlen( $v ) < $s['minLength'] )
                    $v = str_pad( $v, (int)$s['minLength'], 'x' );
                if ( isset( $s['maxLength'] ) && mb_strlen( $v ) > $s['maxLength'] )
                    $v = mb_substr( $v, 0, (int)$s['maxLength'] );
                return $v;
        }
    }
}
