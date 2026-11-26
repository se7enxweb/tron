<?php
/**
 * The agent rules layer (doc/agent-rules.md): MASTER.md default and override, AGENTS.md, the precedence, every
 * MASTER.md switch, the MCP surface (instructions, resources, prompts, tron_rules), the admin page's actions, the
 * questionnaire, and the sanitizer that makes MASTER.md shippable, proven with planted canaries.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

final class TronRulesTest extends TronTestCase
{
    /** @var string */ private $dir;
    /** @var string */ private $default;
    /** @var array */ private $env = array();

    public function setUp(): void
    {
        parent::setUp();
        $base = TronFixture::tmp( 'rules' );
        $this->dir = $base . '/prompt';
        $this->default = $base . '/MASTER-default.md';
        foreach ( array( 'AGENTS.md', 'MASTER.md', 'MASTER.disabled', 'rules-state.json' ) as $f )
            if ( is_file( $this->dir . '/' . $f ) )
                unlink( $this->dir . '/' . $f );
        TronFixture::write( $this->default, "# MASTER default\n\n- Rule DEFAULT-MASTER-1: verify with php -l.\n" );
        $this->env = array();
    }

    private function rules( array $settings = array(), array $o = array() ): tronRules
    {
        $env = &$this->env;
        return new tronRules( $o + array(
            'dir' => $this->dir, 'defaultMaster' => $this->default,
            'settings' => array( 'PromptRules' => $settings ),
            'getenv' => function ( string $n ) use ( &$env ) { return $env[$n] ?? null; },
        ) );
    }

    private function agents( string $text = "# Site rules\n\n- Rule SITE-AGENTS-1: deploy with --packer.\n" ): void
    {
        TronFixture::write( $this->dir . '/AGENTS.md', $text );
    }

    // ------------------------------------------------------------------ files and precedence

    public function testDefaultAndOverrideResolution(): void
    {
        $r = $this->rules();
        $this->assertSame( 'default', $r->masterFile()['source'] );
        $this->assertSame( 'none', $r->agentsFile()['source'] );
        $st = $r->state();
        $this->assertSame( 'default', $st['master']['source'] );
        $this->assertSame( 'none', $st['agents']['source'] );
        $this->assertSame( 'missing', $st['agents']['strength'] );

        TronFixture::write( $this->dir . '/MASTER.md', "# MASTER override\n\n- Rule OVERRIDE-MASTER-1.\n" );
        $this->agents();
        $st = $this->rules()->state( null, array(), true );
        $this->assertSame( 'override', $st['master']['source'], 'the override wins over the default' );
        $this->assertSame( $this->dir . '/MASTER.md', $st['master']['path'] );
        $this->assertSame( 'override', $st['agents']['source'] );
        $text = $this->rules()->combined();
        $this->assertContains( 'OVERRIDE-MASTER-1', $text );
        $this->assertNotContains( 'DEFAULT-MASTER-1', $text, 'the default is ignored while an override exists' );

        $this->assertSame( 'missing', $this->rules( array(), array( 'defaultMaster' => $this->dir . '/none.md', 'dir' => $this->dir . '/empty' ) )->state()['master']['decided_by'] );
        $this->assertFalse( isset( $this->rules()->state()['master']['path'] ), 'paths only when asked (administrators)' );
    }

    public function testMasterSupersedesAgents(): void
    {
        $this->agents();
        $text = $this->rules()->combined();
        $this->assertTrue( strpos( $text, 'DEFAULT-MASTER-1' ) < strpos( $text, 'SITE-AGENTS-1' ), 'MASTER.md comes first' );
        $this->assertContains( 'MASTER.md (the Exponential rules) SUPERSEDES', $text );
        $this->assertContains( 'Where the two conflict, follow MASTER.md', $text );
        $this->assertContains( 'Active: MASTER.md (default), AGENTS.md (override)', $text );
        // only AGENTS.md: no precedence sentence, no master block
        $only = $this->rules( array( 'Master' => 'disabled' ) )->combined();
        $this->assertNotContains( 'DEFAULT-MASTER-1', $only );
        $this->assertNotContains( 'SUPERSEDES', $only );
        $this->assertContains( 'SITE-AGENTS-1', $only );
        // Agents=disabled leaves the site's file out
        $this->assertNotContains( 'SITE-AGENTS-1', $this->rules( array( 'Agents' => 'disabled' ) )->combined() );
        $this->assertSame( '', $this->rules( array( 'Master' => 'disabled', 'Agents' => 'disabled' ) )->combined() );
    }

    // ------------------------------------------------------------------ switches

    private function decided( tronRules $r, ?int $user = null, array $session = array() ): string
    {
        $d = $r->decideMaster( $user, $session );
        return ( $d['active'] ? 'on' : 'off' ) . ':' . $d['decided_by'];
    }

    public function testEverySwitchAndItsPrecedence(): void
    {
        $store = new tronRulesStore( $this->rules() );
        $this->assertSame( 'on:default', $this->decided( $this->rules() ) );
        $this->assertSame( 'off:ini', $this->decided( $this->rules( array( 'Master' => 'disabled' ) ) ) );
        $this->assertSame( 'on:ini', $this->decided( $this->rules( array( 'Master' => 'enabled' ) ) ) );

        $store->setAdminToggle( 'disabled', TronFixture::ADMIN );
        $this->assertSame( 'off:admin', $this->decided( $this->rules( array( 'Master' => 'enabled' ) ) ), 'the admin toggle beats the INI' );
        $store->setAdminToggle( 'enabled', TronFixture::ADMIN );
        $this->assertSame( 'on:admin', $this->decided( $this->rules( array( 'Master' => 'disabled' ) ) ) );

        $this->env['TRON_MASTER_RULES'] = 'disabled';
        $this->assertSame( 'off:environment', $this->decided( $this->rules() ), 'the environment beats the admin toggle' );
        $this->env['TRON_MASTER_RULES'] = 'on';
        $store->setAdminToggle( 'disabled', TronFixture::ADMIN );
        $this->assertSame( 'on:environment', $this->decided( $this->rules() ) );
        $this->env['TRON_MASTER_RULES'] = 'nonsense';
        $this->assertSame( 'off:admin', $this->decided( $this->rules() ), 'an unknown value is not set' );

        $this->env['TRON_MASTER_RULES'] = 'enabled';
        $store->setMarker( true );
        $this->assertSame( 'off:file-marker', $this->decided( $this->rules() ), 'the file marker beats everything' );
        $store->setMarker( false );
        $store->setAdminToggle( '', TronFixture::ADMIN );
        unset( $this->env['TRON_MASTER_RULES'] );

        // personal switches only turn off
        $pref = function ( int $uid ) { return $uid === TronFixture::ADMIN ? 'disabled' : null; };
        $r = $this->rules( array(), array( 'userPreference' => $pref ) );
        $this->assertSame( 'off:user', $this->decided( $r, TronFixture::ADMIN ) );
        $this->assertSame( 'on:default', $this->decided( $r, TronFixture::ADMIN + 1 ) );
        $this->assertSame( 'off:session', $this->decided( $this->rules(), null, array( 'master' => 'disabled' ) ) );
        $this->assertSame( 'off:ini', $this->decided( $this->rules( array( 'Master' => 'disabled' ) ), null, array( 'master' => 'enabled' ) ),
                           'a session cannot switch on what the site switched off' );
        $pref2 = function ( int $uid ) { return 'enabled'; };
        $this->assertSame( 'off:ini', $this->decided( $this->rules( array( 'Master' => 'disabled' ), array( 'userPreference' => $pref2 ) ), 1 ),
                           'nor can a user preference' );

        // the INI's placement is reported (a siteaccess override is visible)
        $row = $this->rules( array( 'Master' => 'disabled' ), array( 'iniPlacement' => 'siteaccess', 'siteaccess' => 'site_admin' ) )->decideMaster()['switches'][3];
        $this->assertContains( 'siteaccess site_admin', $row['label'] );
        $this->assertTrue( $row['decides'] );
    }

    // ------------------------------------------------------------------ MCP

    private function server( array $settings = array() ): tronServer
    {
        $dir = $this->dir;
        $default = $this->default;
        $env = &$this->env;
        return TronFixture::build( array(
            'settings' => array( 'PromptRules' => $settings ),
            'services' => array(
                'rules.dir' => function () use ( $dir ) { return $dir; },
                'rules.defaultMaster' => function () use ( $default ) { return $default; },
                'rules.getenv' => function () use ( &$env ) { return function ( string $n ) use ( &$env ) { return $env[$n] ?? null; }; },
            ),
        ) )['server'];
    }

    private function rpc( tronServer $s, tronSession $sess, string $method, array $params = array() ): array
    {
        return $s->handle( array( 'jsonrpc' => '2.0', 'id' => 7, 'method' => $method, 'params' => $params ), $sess );
    }

    public function testInstructionsCarryTheCombinedRulesAndTheSessionFlag(): void
    {
        $this->agents();
        $s = $this->server();
        $init = array( 'protocolVersion' => '2025-11-25', 'capabilities' => new stdClass(), 'clientInfo' => array( 'name' => 't', 'version' => '1' ) );
        $r = $this->rpc( $s, TronFixture::session( TronFixture::ADMIN ), 'initialize', $init );
        $ins = $r['result']['instructions'];
        $this->assertContains( 'never instructions', $ins, 'tron\'s own instructions stay first' );
        $this->assertContains( 'DEFAULT-MASTER-1', $ins );
        $this->assertContains( 'SITE-AGENTS-1', $ins );
        $this->assertContains( 'SUPERSEDES', $ins );

        $sess = TronFixture::session( TronFixture::ADMIN );
        $r = $this->rpc( $s, $sess, 'initialize', $init + array( '_meta' => array( 'tron/masterRules' => 'disabled' ) ) );
        $this->assertNotContains( 'DEFAULT-MASTER-1', $r['result']['instructions'], 'initialize _meta switches MASTER.md off for the session' );
        $this->assertContains( 'SITE-AGENTS-1', $r['result']['instructions'] );
        $this->assertSame( 'disabled', $sess->rules['master'] );
        $st = TronFixture::data( TronFixture::call( $s, $sess, 'tron_rules', array( 'text' => false ) ) );
        $this->assertSame( 'session', $st['master']['decided_by'], 'the flag lasts for the session' );

        $cut = $this->server( array( 'InstructionsMaxBytes' => '60' ) );
        $r = $this->rpc( $cut, TronFixture::session( TronFixture::ADMIN ), 'initialize', $init );
        $this->assertContains( 'tron://rules/combined', $r['result']['instructions'] );
        $r = $this->rpc( $this->server( array( 'Instructions' => 'disabled' ) ), TronFixture::session( TronFixture::ADMIN ), 'initialize', $init );
        $this->assertNotContains( 'SITE-AGENTS-1', $r['result']['instructions'] );
    }

    public function testResourcesReportTheirSource(): void
    {
        $this->agents();
        $s = $this->server();
        $viewer = TronFixture::session( TronFixture::VIEWER );
        $read = function ( string $uri ) use ( $s, $viewer ) {
            $r = $this->rpc( $s, $viewer, 'resources/read', array( 'uri' => $uri ) );
            return json_decode( $r['result']['contents'][0]['text'], true );
        };
        $a = $read( 'tron://rules/agents' );
        $this->assertSame( 'override', $a['source'] );
        $this->assertContains( 'SITE-AGENTS-1', $a['text'] );
        $this->assertTrue( $a['superseded_by_master'] );
        $this->assertFalse( isset( $a['path'] ), 'no paths for a user without tron/admin' );
        $m = $read( 'tron://rules/master' );
        $this->assertSame( 'default', $m['source'] );
        $c = $read( 'tron://rules/combined' );
        $this->assertSame( 'default', $c['master']['source'] );
        $this->assertContains( 'SUPERSEDES', $c['text'] );
        TronFixture::write( $this->dir . '/MASTER.md', "# Override\n\n- OVERRIDE-MASTER-1\n" );
        $this->assertSame( 'override', $read( 'tron://rules/status' )['master']['source'] );
        $status = TronFixture::data( TronFixture::call( $s, $viewer, 'tron_status' ) );
        $this->assertSame( 'override', $status['rules']['master'], 'tron_status names the active source' );
        $this->assertSame( 'override', $status['rules']['agents'] );

        $list = $this->rpc( $s, $viewer, 'resources/list' );
        $uris = array_column( $list['result']['resources'], 'uri' );
        foreach ( array( 'tron://rules/agents', 'tron://rules/master', 'tron://rules/combined', 'tron://rules/status' ) as $u )
            $this->assertContains( $u, $uris );
    }

    public function testPromptsAndTool(): void
    {
        $this->agents();
        $s = $this->server();
        $sess = TronFixture::session( TronFixture::ADMIN );
        $p = $this->rpc( $s, $sess, 'prompts/get', array( 'name' => 'tron_project_rules' ) );
        $text = $p['result']['messages'][0]['content']['text'];
        $this->assertContains( 'DEFAULT-MASTER-1', $text );
        $p = $this->rpc( $s, $sess, 'prompts/get', array( 'name' => 'tron_project_rules', 'arguments' => array( 'master' => 'disabled' ) ) );
        $this->assertNotContains( 'DEFAULT-MASTER-1', $p['result']['messages'][0]['content']['text'], 'the prompt argument is a per-call off switch' );
        $this->assertFalse( isset( $sess->rules['master'] ), '... that does not stick to the session' );
        $w = $this->rpc( $s, $sess, 'prompts/get', array( 'name' => 'tron_rules_wizard', 'arguments' => array( 'project_type' => 'site' ) ) );
        $wt = $w['result']['messages'][0]['content']['text'];
        $this->assertContains( 'Project name (project_name)', $wt );
        $this->assertContains( 'already has an AGENTS.md', $wt );

        $all = TronFixture::data( TronFixture::call( $s, $sess, 'tron_rules' ) );
        $this->assertContains( 'SITE-AGENTS-1', $all['text'] );
        $off = TronFixture::data( TronFixture::call( $s, $sess, 'tron_rules', array( 'master' => 'disabled' ) ) );
        $this->assertSame( false, $off['master']['active'] );
        $this->assertSame( 'session', $off['master']['decided_by'] );
        $back = TronFixture::data( TronFixture::call( $s, $sess, 'tron_rules', array( 'master' => 'enabled', 'text' => false ) ) );
        $this->assertSame( true, $back['master']['active'] );
        $this->assertFalse( isset( $back['text'] ) );
    }

    // ------------------------------------------------------------------ admin page

    public function testAdminActions(): void
    {
        $db = tronDbPdo::sqliteMemory();
        $audit = new tronAuditMemory();
        $c = new tronAdminContext( $db, array( 'AuditSettings' => array( 'Sinks' => array( 'database' ) ) ), new tronArrayUserDirectory( TronFixture::users() ), array(
            'clock' => function () { return TronFixture::NOW; }, 'audit' => $audit,
            'actor' => array( 'id' => TronFixture::ADMIN, 'login' => 'admin' ) ) );
        $prefs = array();
        $data = new tronAdminRulesData( $c, array( 'rules' => $this->rules(),
            'setPreference' => function ( int $uid, ?string $v ) use ( &$prefs ) { $prefs[$uid] = $v; } ) );

        $r = $data->handle( 'Generate', array( 'TronQ' => array( 'project_name' => 'Acme shop', 'project_type' => 'site', 'dont' => "- Never touch the payment settings\n" ) ) );
        $this->assertContains( '# AGENTS.md: Acme shop', $r['editor'] );
        $this->assertContains( '- Never touch the payment settings', $r['editor'] );
        $this->assertSame( 'none', $data->data()['agents']['source'], 'the questionnaire does not save' );

        $r = $data->handle( 'SaveAgents', array( 'TronAgentsText' => $r['editor'] ) );
        $this->assertSame( null, $r['error'] );
        $this->assertSame( 'override', $data->data()['agents']['source'] );
        $this->assertSame( 'Acme shop', substr( $data->data()['agents']['text'], 13, 9 ) );

        $r = $data->handle( 'UploadAgents', array(), "# Uploaded\n\n- admin login: `root` / `hunter2!x`\n" );
        $this->assertContains( 'looks like it holds a secret (credential pair)', implode( ' ', $r['warnings'] ) );
        $this->assertNotContains( 'hunter2', implode( ' ', $r['warnings'] ), 'a warning names the line, never the value' );
        $r = $data->handle( 'UploadAgents', array(), null );
        $this->assertContains( 'Choose a file', (string)$r['error'] );
        $r = $data->handle( 'SaveAgents', array( 'TronAgentsText' => "bad\0binary" ) );
        $this->assertContains( 'not a text file', (string)$r['error'] );

        $data->handle( 'SaveMaster', array( 'TronMasterText' => "# Site master\n\n- SITE-MASTER-1\n" ) );
        $this->assertSame( 'override', $data->data()['master']['source'] );
        $data->handle( 'RemoveMaster', array() );
        $this->assertSame( 'default', $data->data()['master']['source'] );

        $data->handle( 'AdminToggle', array( 'TronAdminMaster' => 'disabled' ) );
        $this->assertSame( 'admin', $data->data()['decided_by'] );
        $data->handle( 'AdminToggle', array( 'TronAdminMaster' => '' ) );
        $data->handle( 'Marker', array( 'TronMarker' => '1' ) );
        $this->assertSame( 'file-marker', $data->data()['decided_by'] );
        $this->assertTrue( $data->data()['marker'] );
        $data->handle( 'Marker', array( 'TronMarker' => '0' ) );
        $data->handle( 'Preference', array( 'TronPreference' => 'disabled' ) );
        $this->assertSame( 'disabled', $prefs[TronFixture::ADMIN] );
        $r = $data->handle( 'ExpaiDraft', array() );
        $this->assertContains( 'not active', (string)$r['error'] );
        $data->handle( 'RemoveAgents', array() );
        $this->assertSame( 'none', $data->data()['agents']['source'] );

        $methods = array_column( $audit->entries, 'method' );
        foreach ( array( 'rules.agents.save', 'rules.master.save', 'rules.master.remove', 'rules.master.toggle', 'rules.master.marker', 'rules.agents.remove' ) as $a )
            $this->assertContains( 'admin/' . $a, $methods, "audited: $a" );
        $this->assertNotContains( 'hunter2', json_encode( $audit->entries ), 'the audit names size and hash, never the text' );
    }

    public function testQuestionnaireAndStrength(): void
    {
        $md = tronRulesGenerator::generate( array( 'project_name' => 'X', 'release' => 'bogus', 'commits' => 'none' ) );
        foreach ( array( '## Project', '## Stack', '## Conventions', '## Always', '## Never', '## Verification', '## Commits and releases' ) as $h )
            $this->assertContains( $h, $md );
        $this->assertContains( 'Agents never tag', $md, 'an unknown choice falls back to the safe default' );
        $this->assertContains( 'Agents do not commit', $md );
        $this->assertSame( 'solid', tronRules::strength( file_get_contents( dirname( __DIR__, 2 ) . '/doc/MASTER.md' ) ) );
        $this->assertSame( 'hollow', tronRules::strength( "# AGENTS.md\n\n## Rules\n\n- TODO\n- <rule>\n" ) );
        $this->assertTrue( in_array( tronRules::strength( $md ), array( 'weak', 'solid' ), true ) );
        $this->assertSame( 'missing', tronRules::strength( "  \n" ) );
    }

    // ------------------------------------------------------------------ sanitizer

    /** A private AGENTS.md with a canary of every kind the sanitizer must catch. */
    private static function plantedSource(): string
    {
        return implode( "\n", array(
            '# AGENTS.md for a private project eZ Publish legacy',
            '',
            '## READ FIRST — A published release is permanent',
            '',
            '- Never move a pushed tag. KEEP-RELEASE-RULE.',
            '- Check `https://edit.alpha.canaryco.example.net/` and https://app.api.canaryco.example.net/api first.',
            '- The node 10.200.0.42 and 93.184.216.77 serve it; version 0.0.4.10 is not an address.',
            '',
            '## Source Control Rules',
            '',
            '- Do not mention `./ai` in commit messages. CANARY-AI-RULE',
            '  - Scripts in ai/bin/one/canary_script.py stay private.',
            '- Stage files by name. KEEP-STAGE-RULE.',
            '- Write Exponential, never eZ Publish or ezpublish. KEEP-NAME-RULE.',
            '- Sessions talk through gnubus CANARY-BUS and xsh doc:list.',
            '- The canarysrv server CANARY-NAME is private.',
            '',
            '## Build & Run',
            '',
            '```bash',
            'php bin/php/ezcache.php --clear-all   # KEEP-FENCE-LINE',
            'cd /var/www/vhosts/canary-host/doc && ls /opt/sevenx/pkg/canary',
            '```',
            '- DSN mysql://canaryuser:CanaryDbPass9@db.internal/canarydb KEEP-DSN-LINE',
            '- password = CanaryPassw0rd!x and api_key: sk-canary0123456789abcdefghij',
            '- Token tron_abcd1234_CANARYsecretCANARYsecret01 and ghp_CANARY0123456789abcdefghijklmnop',
            '- JWT eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJjYW5hcnkifQ.c2lnbmF0dXJlY2FuYXJ5',
            '- Files under /web/vh/canary/doc and /root/.config/canary.',
            '',
            '## Auth / Access',
            '',
            '- HTTP Basic auth for staging: `canarystaff` / `Canary$Basic2026`.',
            '',
            '## Test / Playground Environment',
            '',
            '- eZ admin login: `admin` / `CanaryAdmin$`',
            '',
            '## Conventions',
            '',
            '- Sign in with `admin` / `LiteralCanary#77` on the box. ',
            '- Prefer `public static` helpers. KEEP-CONVENTION.',
            '- Return `true`/`false` from checks. KEEP-PAIR.',
            '',
        ) );
    }

    /** The installation's private profile, as settings/override would give it: neutral placeholders only. */
    private static function privateProfile(): array
    {
        return array( 'names' => array( 'canarysrv' ), 'domains' => array( 'canaryco.example.net' ), 'networks' => array( '10.200.0.0/16' ) );
    }

    public function testSanitizerLeavesNoCanary(): void
    {
        $s = new tronRulesSanitizer( array( 'LiteralCanary#77' ), self::privateProfile() );
        $out = $s->sanitize( self::plantedSource() );
        foreach ( array( 'canaryco', '10.200.', '93.184.', 'CANARY-AI', 'canary_script', 'ai/bin', 'CANARY-BUS', 'gnubus', 'xsh',
                         'CANARY-NAME', 'canarysrv', '/var/www', '/opt/sevenx', '/web/vh', '/root/', 'canaryuser', 'CanaryDbPass9', 'CanaryPassw0rd',
                         'sk-canary', 'CANARYsecret', 'ghp_CANARY', 'eyJhbGci', 'canarystaff', 'Canary$Basic', 'CanaryAdmin', 'LiteralCanary',
                         'eZ Publish', 'ezpublish' ) as $canary )
            $this->assertNotContains( $canary, $out, "canary $canary survived" );
        $this->assertSame( array(), $s->leaks( $out ), 'leaks() finds nothing in the result' );
        foreach ( array( 'KEEP-RELEASE-RULE', 'KEEP-STAGE-RULE', 'KEEP-NAME-RULE', 'KEEP-FENCE-LINE', 'KEEP-DSN-LINE', 'KEEP-CONVENTION', '0.0.4.10',
                         '`true`/`false`', 'admin.example.org', 'api.example.org', '192.0.2.', '<DB_PASSWORD>', '<SECRET>', '<API_KEY>', '<TRON_TOKEN>', '<JWT>' ) as $kept )
            $this->assertContains( $kept, $out, "lost: $kept" );
        $this->assertNotContains( 'Auth / Access', $out, 'sections outside the allow list are left out' );
        $this->assertContains( '# MASTER.md: Exponential rules for agents', $out );

        // leaks() catches each kind in a raw text, by pattern name only
        $raw = $s->leaks( self::plantedSource() );
        $names = array_unique( array_column( $raw, 'pattern' ) );
        foreach ( array( 'internal host', 'private network address', 'IPv4 address', 'server path', 'private tree', 'internal ops', 'private name',
                         'former product name', 'credential pair', 'tron token', 'GitHub token', 'API key', 'JSON web token',
                         'credentials in a URL', 'secret assignment', 'known secret #1' ) as $n )
            $this->assertContains( $n, $names, "leaks() misses $n" );
        $this->assertNotContains( 'LiteralCanary', json_encode( $raw ), 'leaks() never returns the text' );
    }

    public function testPrivateProfileComesFromTheOverrideSettings(): void
    {
        $root = TronFixture::tmp( 'private-profile' );
        TronFixture::write( $root . '/settings/override/tron.ini.append.php', implode( "\n", array(
            '<?php /* #?ini charset="utf-8"?', '', '[PromptRules]', 'PrivateNames[]', 'PrivateNames[]=canarysrv', 'PrivateNames[]=othersrv',
            'PrivateDomains[]=canaryco.example.net', '', '[WebSettings]', 'MeshNetworks[]', 'MeshNetworks[]=10.200.0.0/16', '', '*/ ?>', '' ) ) );
        $p = tronRulesSanitizer::privateFromSettings( $root );
        $this->assertSame( array( 'canarysrv', 'othersrv' ), $p['names'] );
        $this->assertSame( array( 'canaryco.example.net' ), $p['domains'] );
        $this->assertSame( array( '10.200.0.0/16' ), $p['networks'] );
        $this->assertSame( array( 'names' => array(), 'domains' => array(), 'networks' => array() ),
                           tronRulesSanitizer::privateFromSettings( $root . '/none' ), 'no override: nothing private is known' );

        // the same filtering as the planted test, from the file
        $s = new tronRulesSanitizer( array(), $p );
        $out = $s->sanitize( "# A\n\n## Conventions\n\n- The CanarySrv server is private.\n- KEEP-THIS on https://canaryco.example.net/ and 10.200.3.4.\n" );
        $this->assertNotContains( 'CanarySrv', $out );
        $this->assertNotContains( 'canaryco', $out );
        $this->assertNotContains( '10.200.', $out );
        $this->assertContains( 'KEEP-THIS', $out );
        $out = $s->sanitize( "# A\n\n## Conventions\n\n- Use canarysrv-editor for edits.\n- Ask x-othersrv first.\n- KEEP-THESE canarysrvs and canarysrv_x.\n" );
        $this->assertNotContains( 'canarysrv-editor', $out, 'a hyphen ends the name, as \\b does' );
        $this->assertNotContains( 'x-othersrv', $out );
        $this->assertContains( 'KEEP-THESE', $out, 'a longer word is not the name' );
        $names = array_column( $s->leaks( "OTHERSRV is up\nsee www.canaryco.example.net\nping 10.200.9.9\n" ), 'pattern' );
        foreach ( array( 'private name', 'internal host', 'private network address' ) as $n )
            $this->assertContains( $n, $names, "leaks() misses $n" );
        $this->assertSame( array(), ( new tronRulesSanitizer( array(), array() ) )->leaks( "othersrv and canaryco.example.net\n" ),
                           'without the profile these words are ordinary' );
    }

    public function testShippedMasterIsClean(): void
    {
        $path = dirname( __DIR__, 2 ) . '/doc/MASTER.md';
        $text = (string)file_get_contents( $path );
        $this->assertSame( array(), ( new tronRulesSanitizer() )->leaks( $text ), 'the shipped MASTER.md has nothing private' );
        foreach ( array( 'A published release is permanent', 'Release versioning', 'Source control rules', 'Admin design and template styling rules',
                         'Verification checklist', 'Database gotchas', 'Security notes' ) as $h )
            $this->assertContains( '## ' . $h, $text );
        $this->assertContains( '`0.0.4.9` -> `0.0.4.10`', $text, 'the last position counts on' );
        $this->assertSame( 0, preg_match( '/eZ ?Publish/i', $text ) );
    }
}
