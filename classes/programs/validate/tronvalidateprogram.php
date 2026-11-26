<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The program "validate" (doc/validate.md): one result format for every validator (tronValidateResult).
 *
 * validate_html, validate_css, validate_seo (tronValidateMarkup), validate_json (+ JSON Schema, tronValidateJson),
 * validate_xml (+ XSD, no network), validate_yaml (tronYamlParser), validate_ini (tronIniParser, Exponential
 * rules), validate_tpl (the tpl program's lint, under its own policy), validate_php (php -l in a sandbox
 * directory), validate_links (through the web client: SSRF guard, robots.txt, a delay between requests),
 * validate_a11y (a browser job, where browsers run), validate_format (e-mail, URL, phone).
 *
 * A validator never echoes the values it checks (e-mail addresses and phone numbers would be personal data):
 * findings name the rule and the position.
 */
final class tronValidateProgram implements tronProgram
{
    const POLICY = array( 'tron', 'validate' );
    const MAX = 2000000;

    public function register( tronRegistry $registry, tronEnvironment $env ): void
    {
        if ( !tronRootPrograms::open( $registry, $env, array( 'validate_html', 'validate_css', 'validate_json', 'validate_xml', 'validate_yaml', 'validate_ini', 'validate_tpl', 'validate_php', 'validate_links', 'validate_a11y', 'validate_format', 'validate_seo' ) ) )
            return;
        $out = tronValidateResult::schema();
        $text = function ( string $what ) {
            return array( 'type' => 'string', 'maxLength' => self::MAX, 'description' => $what );
        };
        $url = array( 'type' => 'string', 'minLength' => 8, 'maxLength' => tronWebGuard::MAX_URL, 'description' => 'Fetch the document from this public address instead (SSRF guard, robots.txt)' );
        $tool = function ( string $name, string $title, string $description, array $props, array $required, callable $run ) use ( $registry, $out ) {
            $registry->tool( tronTool::define( array(
                'name' => $name, 'title' => $title, 'description' => $description . ' Answers the validators\' one format: valid, counts, findings with severity, rule, message and location.',
                'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => $required, 'properties' => $props ),
                'policy' => self::POLICY, 'outputSchema' => $out,
                'handler' => function ( array $a, tronCallContext $ctx ) use ( $run ) {
                    $r = $run( $a, $ctx );
                    return $r instanceof tronValidateResult ? $r->toArray() : $r;
                },
            ) ) );
        };

        $tool( 'validate_html', 'Validate HTML', 'Checks an HTML document: structure (mismatched or stray tags, entities), doctype, lang, title, duplicate ids, labels, image alt, button and link names, nested forms, obsolete elements, inline styles and handlers.',
            array( 'html' => $text( 'The HTML' ), 'url' => $url ), array(), function ( array $a ) use ( $env ) {
                list( $html, $meta ) = self::source( $env, $a, 'html' );
                $r = tronValidateMarkup::html( $html );
                $r->meta += $meta;
                return $r;
            } );

        $tool( 'validate_css', 'Validate CSS', 'Checks a stylesheet: comments, strings, braces and parentheses closed, declarations with a property and a value, property names, hex colours, duplicate properties, empty rules, unknown at-rules, !important.',
            array( 'css' => $text( 'The stylesheet' ) ), array( 'css' ), function ( array $a ) {
                return tronValidateMarkup::css( $a['css'] );
            } );

        $tool( 'validate_json', 'Validate JSON', 'Checks JSON syntax (with the line and column of the first error) and, with schema, the document against a JSON Schema (draft 2020-12 and draft-07 keywords, local $ref).',
            array( 'json' => $text( 'The JSON document' ), 'schema' => array( 'type' => array( 'string', 'object', 'boolean' ), 'description' => 'A JSON Schema, as text or as an object' ) ),
            array( 'json' ), function ( array $a ) {
                $r = new tronValidateResult( 'json' );
                $err = tronValidateJson::syntax( $a['json'] );
                if ( $err !== null )
                    return $r->add( 'error', 'json.syntax', $err[2], $err[0], $err[1] );
                if ( !array_key_exists( 'schema', $a ) )
                    return $r;
                $schema = $a['schema'];
                if ( is_string( $schema ) )
                {
                    $se = tronValidateJson::syntax( $schema );
                    if ( $se !== null )
                        return $r->add( 'error', 'schema.syntax', 'The schema is not valid JSON: ' . $se[2] . " (line {$se[0]}, column {$se[1]})" );
                    $schema = json_decode( $schema, true );
                }
                $r->merge( tronValidateJson::schema( json_decode( $a['json'], true ), $schema ) );
                $r->meta = array( 'schema' => true );
                return $r;
            } );

        $tool( 'validate_xml', 'Validate XML', 'Checks that XML is well-formed (entity declarations refused) and, with xsd, valid against an XML Schema. Nothing is fetched over the network: imports and includes of the schema are not followed.',
            array( 'xml' => $text( 'The XML document' ), 'xsd' => $text( 'An XML Schema (XSD) to validate against' ) ), array( 'xml' ), function ( array $a ) {
                return self::xml( $a['xml'], $a['xsd'] ?? null );
            } );

        $tool( 'validate_yaml', 'Validate YAML', 'Checks YAML as configuration uses it: indentation and tabs, mappings and lists at the right level, quoting, block scalars, flow collections, duplicate keys; anchors, aliases, tags and several documents are reported as not read.',
            array( 'yaml' => $text( 'The YAML document' ) ), array( 'yaml' ), function ( array $a ) {
                list( , $f ) = tronYamlParser::parse( $a['yaml'] );
                return ( new tronValidateResult( 'yaml' ) )->merge( $f );
            } );

        $tool( 'validate_ini', 'Validate an INI file', 'Checks an Exponential settings file the way eZINI reads it: the PHP wrapper of .ini.append.php files, [Block] lines, Name=value, Name[], Name[]=value and Name[key]=value, settings outside a block, a name used as a value and as a list, duplicate blocks and settings, stray spaces.',
            array( 'ini' => $text( 'The INI text' ), 'file_name' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_.-]{1,120}$', 'description' => 'e.g. site.ini.append.php (enables the wrapper check)' ) ),
            array( 'ini' ), function ( array $a ) {
                $p = tronIniParser::parse( $a['ini'], (string)( $a['file_name'] ?? '' ) );
                $r = ( new tronValidateResult( 'ini' ) )->merge( $p['findings'] );
                $r->meta = array( 'blocks' => count( $p['blocks'] ), 'settings' => array_sum( array_map( 'count', $p['blocks'] ) ) );
                return $r;
            } );

        $tpl = tronUnitSources::get( $env, 'tpl' );
        if ( !$tpl instanceof tronUnitTplSource || !$tpl->available() )
            $registry->unavailable( 'validate_tpl', 'no template source here (the tpl program reads through an Exponential installation)' );
        else
            $tool( 'validate_tpl', 'Validate a template', 'Checks a template (path inside a design, or template text) with the installation\'s own template parser, through the tpl program\'s lint and under its policy: errors, warnings, unknown functions and operators.',
                array( 'path' => array( 'type' => 'string', 'maxLength' => 500 ), 'template' => array( 'type' => 'string', 'maxLength' => 200000 ) ), array(),
                function ( array $a, tronCallContext $ctx ) use ( $env ) {
                    $srv = $env->get( 'tron.server' );
                    $lint = $srv->registry()->findTool( 'tpl_lint' );
                    if ( !$lint )
                        throw new tronToolError( 'The tpl program\'s lint is not available here' );
                    $args = isset( $a['path'] ) ? array( 'path' => $a['path'] ) : array( 'source' => (string)( $a['template'] ?? '' ) );
                    $o = $srv->invoke( $lint, $args, $ctx->session );
                    if ( !$o['ok'] )
                        throw new tronToolError( $o['error'], $o['details'] );
                    $r = new tronValidateResult( 'tpl' );
                    foreach ( (array)$o['value']['errors'] as $e )
                        $r->add( 'error', 'tpl.syntax', (string)$e, self::lineOf( (string)$e ) );
                    foreach ( (array)$o['value']['warnings'] as $w )
                        $r->add( 'warning', 'tpl.warning', (string)$w, self::lineOf( (string)$w ) );
                    foreach ( (array)$o['value']['unknown'] as $u )
                        $r->add( 'error', 'tpl.unknown', "Unknown function or operator: $u" );
                    $r->meta = array( 'path' => $o['value']['path'], 'functions' => $o['value']['functions'] ?? array(), 'operators' => $o['value']['operators'] ?? array() );
                    return $r;
                } );

        $tool( 'validate_php', 'Lint PHP', 'Checks PHP syntax with php -l in a sandbox directory, with a time limit: the code is never run. Answers the parse error with its line.',
            array( 'php' => $text( 'The PHP source (with its <?php tag)' ) ), array( 'php' ), function ( array $a ) use ( $env ) {
                return self::php( $env, $a['php'] );
            } );

        $tool( 'validate_links', 'Check links', 'Finds broken links of a page (url) or of HTML text (html with base_url): each link is requested (HEAD, else GET) through the SSRF guard and robots.txt, one at a time with delay_ms between requests. 4xx and 5xx answers and refused or failing addresses are errors, redirects are information.',
            array( 'url' => $url, 'html' => $text( 'HTML whose links to check' ), 'base_url' => array( 'type' => 'string', 'maxLength' => tronWebGuard::MAX_URL ),
                   'max_links' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ),
                   'delay_ms' => array( 'type' => 'integer', 'minimum' => 100, 'maximum' => 10000, 'default' => 500 ),
                   'same_host_only' => array( 'type' => 'boolean', 'default' => false ) ), array(),
            function ( array $a ) use ( $env ) {
                return self::links( $env, $a );
            } );

        if ( tronBrowserProgram::notHere( $env ) !== null )
            $registry->unavailable( 'validate_a11y', (string)tronBrowserProgram::notHere( $env ) );
        else
            $tool( 'validate_a11y', 'Accessibility check', 'Runs the accessibility checks of browser_a11y on a rendered page (an installed engine on the runner node) and answers them in the validators\' format; meta.job_id names the job if it did not finish within wait.',
                array( 'url' => $url, 'engine' => array( 'type' => 'string', 'enum' => tronBrowserProgram::ENGINES, 'default' => 'chromium' ),
                       'color_scheme' => array( 'type' => 'string', 'enum' => array( 'light', 'dark' ), 'default' => 'light' ),
                       'width' => array( 'type' => 'integer', 'minimum' => 200, 'maximum' => 3840, 'default' => 1440 ),
                       'wait' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 60, 'default' => 30 ) ), array( 'url' ),
                function ( array $a, tronCallContext $ctx ) use ( $env ) {
                    $spec = array( 'url' => tronWebGuard::fromEnv( $env )->check( $a['url'] )['parts']['url'], 'color_scheme' => $a['color_scheme'],
                                   'width' => $a['width'], 'private_hosts' => array_values( (array)$env->setting( 'WebSettings', 'PrivateHosts', array() ) ),
                                   'mesh_networks' => array_values( (array)$env->setting( 'WebSettings', 'MeshNetworks', array() ) ), 'timeout' => 60 );
                    $j = tronBrowserProgram::job( $env, $ctx, 'a11y', $a['engine'], $spec, (int)$a['wait'], 90 );
                    $r = new tronValidateResult( 'a11y' );
                    if ( $j['state'] === 'failed' )
                        $r->add( 'error', 'a11y.job', 'The browser job failed: ' . $j['error'] );
                    else if ( $j['state'] !== 'done' )
                        $r->add( 'info', 'a11y.pending', 'The browser job has not finished; ask browser_job with meta.job_id' );
                    else
                        $r->merge( (array)( $j['result']['findings'] ?? array() ) );
                    $r->meta = array( 'job_id' => $j['job_id'], 'state' => $j['state'], 'engine' => $a['engine'] );
                    return $r;
                } );

        $tool( 'validate_format', 'Validate formats', 'Checks values as e-mail addresses (RFC 5321 lengths, a domain with a dot), web addresses (http/https, a valid host) or international phone numbers (E.164: + and 8 to 15 digits). Findings name the position in values, never the value.',
            array( 'kind' => array( 'type' => 'string', 'enum' => array( 'email', 'url', 'phone' ) ),
                   'values' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 1000, 'items' => array( 'type' => 'string', 'maxLength' => 2048 ) ) ),
            array( 'kind', 'values' ), function ( array $a ) {
                return self::formats( $a['kind'], $a['values'] );
            } );

        $tool( 'validate_seo', 'SEO basics', 'Checks a page for the search basics: title and description length, one h1, heading order, canonical, lang, viewport, noindex, image alt, Open Graph.',
            array( 'html' => $text( 'The HTML' ), 'url' => $url ), array(), function ( array $a ) use ( $env ) {
                list( $html, $meta, $base ) = self::source( $env, $a, 'html' );
                $r = tronValidateMarkup::seo( $html, $base );
                $r->meta += $meta;
                return $r;
            } );
    }

    /** @return array( text, meta, base url ) from the argument or fetched from url */
    private static function source( tronEnvironment $env, array $a, string $key ): array
    {
        if ( isset( $a[$key] ) === isset( $a['url'] ) )
            throw new tronToolError( "Give $key or url (one of them)" );
        if ( isset( $a[$key] ) )
            return array( (string)$a[$key], array(), (string)( $a['base_url'] ?? '' ) );
        $r = tronWebProgram::get( $env, $a['url'] );
        if ( $r['status'] < 200 || $r['status'] >= 300 )
            throw new tronToolError( "The address answered HTTP {$r['status']}" );
        return array( tronWebProgram::utf8( $r ), array( 'url' => $r['final_url'], 'status' => $r['status'], 'bytes' => strlen( $r['body'] ) ), $r['final_url'] );
    }

    private static function lineOf( string $msg ): ?int
    {
        return preg_match( '/line\D{0,3}(\d+)/i', $msg, $m ) ? (int)$m[1] : null;
    }

    public static function xml( string $xml, ?string $xsd ): tronValidateResult
    {
        $r = new tronValidateResult( 'xml' );
        $errors = array();
        $doc = tronWebExtract::xml( $xml, $errors );
        foreach ( $errors as $e )
            $r->add( ( $e['level'] ?? 'error' ) === 'warning' ? 'warning' : 'error', strpos( $e['message'], 'Entity' ) === 0 ? 'xml.entity' : 'xml.syntax', $e['message'], $e['line'], $e['column'] );
        if ( !$doc || $xsd === null )
            return $r;
        if ( preg_match( '/<!ENTITY/i', $xsd ) )
            return $r->add( 'error', 'xsd.entity', 'The schema declares entities; it is not used' );
        $prev = libxml_use_internal_errors( true );
        // no network and no files: an import or include of the schema is not followed
        libxml_set_external_entity_loader( function () { return null; } );
        try
        {
            $ok = @$doc->schemaValidateSource( $xsd, LIBXML_NONET );
            foreach ( libxml_get_errors() as $e )
                $r->add( $e->level === LIBXML_ERR_WARNING ? 'warning' : 'error', 'xsd.invalid', trim( $e->message ), $e->line ?: null, $e->column ?: null );
            if ( !$ok && !$r->hasErrors() )
                $r->add( 'error', 'xsd.invalid', 'The document is not valid against the schema (or the schema is not a valid XSD)' );
        }
        finally
        {
            libxml_clear_errors();
            libxml_use_internal_errors( $prev );
            libxml_set_external_entity_loader( null );
        }
        $r->meta = array( 'xsd' => true );
        return $r;
    }

    /** php -l on a copy in the sandbox directory, killed after [ValidateSettings] PhpLintTimeout seconds. */
    public static function php( tronEnvironment $env, string $code ): tronValidateResult
    {
        $r = new tronValidateResult( 'php' );
        $dir = rtrim( (string)$env->setting( 'ValidateSettings', 'SandboxDir', 'var/tmp/tron-validate' ), '/' );
        if ( !is_dir( $dir ) && !@mkdir( $dir, 0770, true ) && !is_dir( $dir ) )
            throw new tronToolError( 'The sandbox directory cannot be created' );
        $dir = (string)realpath( $dir );
        $bin =(string)$env->setting( 'ValidateSettings', 'PhpBinary', '' );
        if ( $bin === '' )
            $bin = PHP_SAPI === 'cli' ? PHP_BINARY : PHP_BINDIR . '/php';
        if ( !is_executable( $bin ) )
            throw new tronToolError( 'No PHP command line binary to lint with ([ValidateSettings] PhpBinary)' );
        $file = $dir . '/lint-' . bin2hex( random_bytes( 8 ) ) . '.php';
        file_put_contents( $file, $code );
        try
        {
            $p = proc_open( array( $bin, '-n', '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-l', $file ),
                            array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $dir, array( 'PATH' => '/usr/bin:/bin' ) );
            if ( !is_resource( $p ) )
                throw new tronToolError( 'php -l could not be started' );
            fclose( $pipes[0] );
            stream_set_blocking( $pipes[1], false );
            stream_set_blocking( $pipes[2], false );
            $outText = '';
            $deadline = microtime( true ) + max( 1, (int)$env->setting( 'ValidateSettings', 'PhpLintTimeout', 10 ) );
            while ( true )
            {
                $outText .= (string)stream_get_contents( $pipes[1] ) . (string)stream_get_contents( $pipes[2] );
                $st = proc_get_status( $p );
                if ( !$st['running'] )
                    break;
                if ( microtime( true ) > $deadline )
                {
                    proc_terminate( $p, 9 );
                    throw new tronToolError( 'php -l took too long' );
                }
                usleep( 20000 );
            }
            $outText .= (string)stream_get_contents( $pipes[1] ) . (string)stream_get_contents( $pipes[2] );
            fclose( $pipes[1] );
            fclose( $pipes[2] );
            proc_close( $p );
        }
        finally
        {
            @unlink( $file );
        }
        $outText = str_replace( $file, '(source)', $outText );
        foreach ( preg_split( '/\R/', $outText ) as $line )
            if ( preg_match( '/^(?:PHP )?(Parse error|Fatal error|Deprecated|Warning):\s*(.*?) in \(source\) on line (\d+)/', $line, $m ) )
                $r->add( in_array( $m[1], array( 'Deprecated', 'Warning' ), true ) ? 'warning' : 'error', 'php.' . strtolower( str_replace( ' ', '-', $m[1] ) ), $m[2], (int)$m[3] );
        if ( !$r->hasErrors() && strpos( $outText, 'No syntax errors' ) === false )
            $r->add( 'error', 'php.lint', 'php -l did not confirm the syntax: ' . mb_substr( trim( $outText ), 0, 300 ) );
        $r->meta = array( 'php' => PHP_VERSION );
        return $r;
    }

    public static function links( tronEnvironment $env, array $a ): tronValidateResult
    {
        $r = new tronValidateResult( 'links' );
        if ( isset( $a['url'] ) )
        {
            $page = tronWebProgram::get( $env, $a['url'] );
            $html = tronWebProgram::utf8( $page );
            $base = $page['final_url'];
        }
        else if ( isset( $a['html'] ) )
        {
            $html = (string)$a['html'];
            $base = (string)( $a['base_url'] ?? '' );
            if ( $base === '' )
                throw new tronToolError( 'Give base_url with html' );
        }
        else
            throw new tronToolError( 'Give url, or html with base_url' );
        $host = strtolower( (string)parse_url( $base, PHP_URL_HOST ) );
        $links = array();
        foreach ( tronWebExtract::links( $html, $base, 2000 ) as $l )
        {
            $u = preg_replace( '/#.*$/', '', $l['url'] );
            if ( !empty( $a['same_host_only'] ) && !$l['internal'] )
                continue;
            $links[$u] = $l['text'];
        }
        $max = (int)( $a['max_links'] ?? 50 );
        $delay = (int)( $a['delay_ms'] ?? 500 );
        $sleep = $env->has( 'browser.sleep' ) ? $env->get( 'browser.sleep' ) : function ( int $ms ) { usleep( $ms * 1000 ); };
        $client = tronWebClient::fromEnv( $env );
        $checked = 0;
        $i = 0;
        foreach ( $links as $u => $text )
        {
            if ( $checked >= $max )
                break;
            if ( $checked > 0 )
                call_user_func( $sleep, $delay );
            $checked++;
            $path = 'links[' . $i++ . ']';
            try
            {
                $res = tronWebProgram::get( $env, $u, array( 'method' => 'HEAD', 'timeout' => 15, 'cache' => false ) );
                if ( in_array( $res['status'], array( 405, 501 ), true ) )
                    $res = tronWebProgram::get( $env, $u, array( 'method' => 'GET', 'timeout' => 15, 'max_bytes' => 65536, 'cache' => false ) );
                $st = $res['status'];
                if ( $st >= 400 )
                    $r->add( 'error', 'links.http-' . ( $st >= 500 ? '5xx' : '4xx' ), "HTTP $st: $u", null, null, $path );
                else if ( $res['redirects'] )
                    $r->add( 'info', 'links.redirect', "$u redirects to {$res['final_url']}", null, null, $path );
            }
            catch ( tronToolError $e )
            {
                $rule = (string)( $e->details()['rule'] ?? 'links.failed' );
                $sev = strpos( $rule, 'robots' ) === 0 ? 'warning' : 'error';
                $r->add( $sev, strpos( $rule, 'ssrf' ) === 0 ? 'links.refused' : ( $sev === 'warning' ? 'links.robots' : 'links.failed' ), $e->getMessage() . ": $u", null, null, $path );
            }
        }
        $r->meta = array( 'base' => $base, 'found' => count( $links ), 'checked' => $checked, 'host' => $host );
        return $r;
    }

    public static function formats( string $kind, array $values ): tronValidateResult
    {
        $r = new tronValidateResult( 'format' );
        $g = new tronWebGuard();
        foreach ( array_values( $values ) as $i => $v )
        {
            $v = (string)$v;
            $path = "values[$i]";
            if ( $kind === 'email' )
            {
                $at = strrpos( $v, '@' );
                if ( $v === '' || $at === false || filter_var( $v, FILTER_VALIDATE_EMAIL ) === false )
                    $r->add( 'error', 'format.email', 'Not a valid e-mail address', null, null, $path );
                else if ( strlen( $v ) > 254 || $at > 64 )
                    $r->add( 'error', 'format.email-length', 'Too long for an e-mail address (local part 64, whole 254)', null, null, $path );
                else if ( strpos( substr( $v, $at + 1 ), '.' ) === false )
                    $r->add( 'warning', 'format.email-domain', 'The domain has no dot', null, null, $path );
            }
            else if ( $kind === 'url' )
            {
                if ( !preg_match( '~^https?://~i', $v ) )
                    $r->add( 'error', 'format.url-scheme', 'Not an http or https address', null, null, $path );
                else if ( $g->parse( $v ) === null && ( new tronWebGuard( array(), range( 1, 65535 ) ) )->parse( $v ) === null )
                    $r->add( 'error', 'format.url', 'Not a valid web address', null, null, $path );
                else if ( preg_match( '/\s/', $v ) )
                    $r->add( 'error', 'format.url', 'A web address has no spaces', null, null, $path );
            }
            else
            {
                $digits = preg_replace( '/[\s().\/-]/', '', $v );
                if ( !preg_match( '/^\+[1-9][0-9]{7,14}$/', (string)$digits ) )
                    $r->add( 'error', 'format.phone', preg_match( '/^0?[0-9]{6,14}$/', (string)$digits ) ? 'A national number: write it in international form (+ and the country code)' : 'Not an international phone number (E.164: + and 8 to 15 digits)', null, null, $path );
            }
        }
        $r->meta = array( 'kind' => $kind, 'checked' => count( $values ) );
        return $r;
    }
}
