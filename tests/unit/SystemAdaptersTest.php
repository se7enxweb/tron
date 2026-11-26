<?php
/**
 * The program "system" (doc/system-adapters.md, doc/api-coverage.md): the inventory on a fixture installation (every
 * area, risk classes, coverage, private extensions left out), the catalog and its Markdown, and every adapter against
 * fixture sources: parameters checked, writes refused, permissions as the caller, output against the output schema,
 * redaction, commands read-only or dry run only.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

require_once dirname( __DIR__ ) . '/fixtures/system_sources.php';

final class TronSystemAdaptersTest extends TronTestCase
{
    /** @var tronSystemArraySource */ private $source;
    /** @var array */ private $f;

    private static function root(): string
    {
        return TronSystemFixture::root();
    }

    private static function inventory( array $more = array(), bool $private = true ): array
    {
        return TronSystemFixture::inventory( $more, $private );
    }

    private static function point( array $catalog, string $id ): ?array
    {
        foreach ( $catalog['points'] as $p )
            if ( $p['id'] === $id )
                return $p;
        return null;
    }

    public function setUp(): void
    {
        parent::setUp();
        $services = TronSystemFixture::services();
        $this->source = $services['system.source'];
        $this->f = TronFixture::build( array( 'services' => $services ) );
    }

    private function ok( string $tool, array $args = array(), int $user = TronFixture::ADMIN )
    {
        $r = TronFixture::call( $this->f['server'], TronFixture::session( $user ), $tool, $args );
        if ( isset( $r['error'] ) || !empty( $r['result']['isError'] ) )
            $this->fail( "$tool failed: " . json_encode( $r ) );
        $v = TronFixture::data( $r );
        $t = $this->f['server']->registry()->findTool( $tool );
        if ( $t && isset( $t->outputSchema ) )
            $this->assertSame( array(), tronSchemaValidator::validate( $t->outputSchema, $v ), "$tool answers its output schema" );
        return $v;
    }

    private function err( string $tool, array $args = array(), int $user = TronFixture::ADMIN ): string
    {
        $r = TronFixture::call( $this->f['server'], TronFixture::session( $user ), $tool, $args );
        if ( isset( $r['error'] ) )
            return (string)$r['error']['message'];
        $this->assertTrue( !empty( $r['result']['isError'] ), "$tool fails: " . json_encode( $r ) );
        return (string)$r['result']['content'][0]['text'];
    }

    // ------------------------------------------------------------------ risk classes

    public function testRiskClassesFromNamesAndDescriptions(): void
    {
        $this->assertSame( 'read', tronSystemRisk::classify( 'view' ) );
        $this->assertSame( 'write', tronSystemRisk::classify( 'edit' ) );
        $this->assertSame( 'dangerous', tronSystemRisk::classify( 'removeobject' ), 'a word that starts with remove' );
        $this->assertSame( 'read', tronSystemRisk::classify( 'copyright' ), 'an exact word wins over a prefix' );
        $this->assertSame( 'admin', tronSystemRisk::classify( 'assign' ) );
        $this->assertSame( 'write', tronSystemRisk::classify( 'frobnicate' ), 'an unknown word is a write' );
        $this->assertSame( 'read', tronSystemRisk::classify( 'frobnicate', 'read' ) );
        $this->assertSame( 'admin', tronSystemRisk::classify( 'list', 'write', true ), 'a read of an admin module is an admin read' );
        $this->assertSame( array( 'read', false ), tronSystemRisk::classifyCommand( 'exp:status', 'Show the state of the things' ) );
        $this->assertSame( array( 'write', true ), tronSystemRisk::classifyCommand( 'exp:contentcheck', 'Content checks (--fix is a dry run unless --apply)' ) );
        $this->assertSame( array( 'dangerous', false ), tronSystemRisk::classifyCommand( 'exp:dfscleanup', 'Check files and delete nonexistent ones' ) );
        $this->assertSame( 'write', tronSystemRisk::max( 'read', 'write' ) );
    }

    // ------------------------------------------------------------------ inventory

    public function testInventoryFindsEveryAreaOfTheFixture(): void
    {
        $c = self::inventory();
        $areas = array_keys( $c['summary']['areas'] );
        foreach ( array( 'module', 'module_view', 'policy_function', 'fetch_function', 'operation', 'template_operator', 'datatype',
                         'workflow_event', 'service_method', 'ini_file', 'ini_block', 'console_command', 'bin_script', 'cronjob_part', 'queue_job_type' ) as $a )
            $this->assertContains( $a, $areas, "area $a" );
        $this->assertSame( 'tron-api-coverage', $c['catalog'] );
        $this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', TronFixture::NOW ), $c['generated'] );
        $this->assertSame( array(), $c['problems'] );
    }

    public function testInventoryRiskAndCoveragePerPoint(): void
    {
        $c = self::inventory();
        $p = self::point( $c, 'module_view:content/view' );
        $this->assertSame( array( 'read', true, 'system_module_view_read' ), array( $p['risk'], $p['covered'], $p['by'] ) );
        $p = self::point( $c, 'module_view:content/edit' );
        $this->assertSame( array( 'write', false ), array( $p['risk'], $p['covered'] ) );
        $this->assertContains( 'POST actions', $p['planned'] );
        $this->assertSame( 'dangerous', self::point( $c, 'module_view:content/removeobject' )['risk'] );
        $this->assertFalse( self::point( $c, 'module_view:content/download' )['covered'], 'a denied read view is not covered' );
        $this->assertSame( array( 'read', 'system_fetch_call' ), array( self::point( $c, 'fetch_function:content/node' )['risk'], self::point( $c, 'fetch_function:content/node' )['by'] ) );
        $w = self::point( $c, 'fetch_function:content/store_draft' );
        $this->assertSame( array( 'write', false ), array( $w['risk'], $w['covered'] ), 'operation_types write' );
        $this->assertSame( 'system_operator_eval', self::point( $c, 'template_operator:upcase' )['by'] );
        $this->assertSame( 'system_operator_eval', self::point( $c, 'template_operator:has_role' )['by'], 'has_role reads' );
        $this->assertFalse( self::point( $c, 'template_operator:fetch' )['covered'] );
        $this->assertFalse( self::point( $c, 'template_operator:ezini' )['covered'] );
        $this->assertSame( 'write', self::point( $c, 'template_operator:texttoimage' )['risk'] );
        $this->assertSame( 'system_operator_eval', self::point( $c, 'template_operator:demo_wash' )['by'], 'extension operators count' );
        $this->assertSame( 'node_read', self::point( $c, 'datatype:ezstring' )['by'] );
        $this->assertSame( 'write', self::point( $c, 'workflow_event:event_ezapprove' )['risk'] );
        $this->assertSame( 'write', self::point( $c, 'operation:content/publish' )['risk'] );
        $this->assertSame( 'ini_resolve', self::point( $c, 'ini_block:site.ini/DatabaseSettings' )['by'] );
        $this->assertSame( 'ini_list', self::point( $c, 'ini_file:expqueue.ini' )['by'] );
        $this->assertSame( 'admin', self::point( $c, 'cronjob_part:frequent' )['risk'] );
        $this->assertTrue( self::point( $c, 'cronjob_part:(default)' ) !== null );
        $this->assertSame( 'demoNoopHandler', self::point( $c, 'queue_job_type:demo.noop' )['meta']['handler'] );
        $this->assertSame( 'system_module_view_describe', self::point( $c, 'policy_function:content/read' )['by'] );
    }

    public function testInventoryServiceMethods(): void
    {
        $c = self::inventory();
        $fetch = self::point( $c, 'service_method:eZContentObject::fetchList' );
        $this->assertSame( array( 'read', 'system_fetch_call', 'content/list' ), array( $fetch['risk'], $fetch['by'], $fetch['meta']['fetch'] ), 'a fetch function calls it' );
        $this->assertSame( 'node_read', self::point( $c, 'service_method:eZContentObject::fetch' )['by'], 'the node units read what it reads' );
        $this->assertSame( 'write', self::point( $c, 'service_method:eZContentObject::store' )['risk'] );
        $this->assertSame( 'dangerous', self::point( $c, 'service_method:eZContentObject::remove' )['risk'] );
        $this->assertTrue( self::point( $c, 'service_method:eZContentObject::internalThing' ) === null, 'private methods are no API' );
        $this->assertTrue( self::point( $c, 'service_method:eZContentObject::__construct' ) === null );
        $this->assertSame( 'read', self::point( $c, 'service_method:demoLayoutService::loadLayout' )['risk'] );
    }

    public function testInventoryCommandsAndScripts(): void
    {
        $c = self::inventory();
        $s = self::point( $c, 'console_command:exp:expmigrationstatus' );
        $this->assertSame( array( 'read', true, 'system_console_command', 'read' ), array( $s['risk'], $s['covered'], $s['by'], $s['mode'] ) );
        $d = self::point( $c, 'console_command:exp:contentcheck' );
        $this->assertSame( array( true, 'dry_run' ), array( $d['covered'], $d['mode'] ), 'a reviewed dry run counts for a write' );
        $this->assertTrue( $d['risk'] !== 'read' );
        $this->assertFalse( self::point( $c, 'console_command:exp:ezfixdata' )['covered'] );
        $this->assertSame( 'dangerous', self::point( $c, 'console_command:exp:dfscleanup' )['risk'] );
        $this->assertSame( 'system_cli_script', self::point( $c, 'bin_script:bin/php/expmigrationstatus.php' )['by'] );
        $this->assertSame( 'exp:ezfixdata', self::point( $c, 'bin_script:bin/php/ezfixdata.php' )['meta']['command'] );
        $this->assertSame( 'List the demo rows', self::point( $c, 'bin_script:extension/demo/bin/php/demolist.php' )['summary'] );
    }

    public function testPrivateExtensionsAreLeftOut(): void
    {
        $json = json_encode( self::inventory() );
        $this->assertFalse( (bool)preg_match( '/privsrv/', $json ), 'the private extension appears nowhere' );
        $this->assertTrue( tronSystemInventory::privateName( 'extension/privsrv/x' ) );
        $this->assertTrue( tronSystemInventory::privateName( 'ext:privsrv_editor:serve' ), 'a trailing * matches the beginning of a word' );
        $this->assertTrue( tronSystemInventory::privateName( 'the Canary project' ), 'a whole word' );
        $this->assertFalse( tronSystemInventory::privateName( 'canaryish' ), 'a whole word only' );
        $this->assertFalse( tronSystemInventory::privateName( 'extension/explayouts/x' ) );
        tronSystemInventory::setPrivateNames( array() );
        $this->assertFalse( tronSystemInventory::privateName( 'extension/privsrv/x' ), 'tron ships no private names' );
        $this->assertTrue( strpos( json_encode( self::inventory( array(), false ) ), 'privsrv' ) !== false, 'without the setting it is listed' );
        $c = self::inventory( array( 'exclude' => function ( $e ) { return $e === 'demo'; } ) );
        $this->assertFalse( strpos( json_encode( $c ), 'demo_wash' ) !== false, 'exclude leaves an extension out' );
    }

    public function testCatalogSummaryAndMarkdown(): void
    {
        $c = new tronSystemCatalog( self::inventory() );
        $s = $c->summary();
        $t = $s['totals'];
        $this->assertSame( $t['points'], $t['read'] + $t['write'] );
        $this->assertTrue( $t['read_covered'] <= $t['read'] && $t['write_covered'] <= $t['write'] );
        $this->assertTrue( $t['read_pct'] > 50 );
        $md = $c->markdown();
        foreach ( array( '# API coverage', '## How to read it', '## Adapters', '`fetch.call`', '`system_fetch_call`', '## Totals', '## The plan for the rest', '### Module views' ) as $want )
            $this->assertContains( $want, $md );
        $hits = $c->search( array( 'area' => 'fetch_function', 'covered' => false ) );
        $this->assertSame( 1, $hits['total'] );
        $this->assertSame( 'content/store_draft', $hits['items'][0]['name'] );
        $this->assertTrue( $c->search( array( 'q' => 'content view' ) )['total'] >= 1 );
        $this->assertThrows( function () { new tronSystemCatalog( array( 'points' => array() ) ); }, 'InvalidArgumentException' );
    }

    public function testIniAndConsoleParsers(): void
    {
        $ini = tronSystemInventory::parseIni( "[A]\nX=1\nL[]\nL[]=a\nL[]=b\nH[k]=v\n#c\n[B]\n" );
        $this->assertSame( array( 'A' => array( 'X' => '1', 'L' => array( 'a', 'b' ), 'H' => array( 'k' => 'v' ) ), 'B' => array() ), $ini );
        $cmds = tronSystemInventory::parseConsoleList( (string)file_get_contents( dirname( self::root() ) . '/console.txt' ) );
        $this->assertSame( array( 'exp:expmigrationstatus', 'exp:contentcheck', 'exp:ezfixdata', 'exp:dfscleanup', 'ext:privsrv:serve' ), array_column( $cmds, 'name' ) );
    }

    // ------------------------------------------------------------------ catalog tools

    public function testCatalogTools(): void
    {
        $r = $this->ok( 'system_catalog', array( 'area' => 'module_view', 'covered' => true ) );
        $this->assertContains( 'module_view:content/view', array_column( $r['items'], 'id' ) );
        $this->assertNotContains( 'module_view:content/edit', array_column( $r['items'], 'id' ) );
        $r = $this->ok( 'system_coverage' );
        $this->assertSame( 'system_fetch_call', $r['adapters']['fetch.call']['tool'] );
        $this->assertTrue( isset( $r['areas']['fetch_function'] ) );
        $this->ok( 'system_catalog', array(), TronFixture::USEONLY );
        $this->assertContains( 'Permission denied', $this->err( 'system_catalog', array(), TronFixture::NOROLE ) );
    }

    // ------------------------------------------------------------------ fetch

    public function testFetchDescribeAndCall(): void
    {
        $d = $this->ok( 'system_fetch_describe', array( 'module' => 'content' ) );
        $by = array_column( $d['functions'], null, 'name' );
        $this->assertTrue( $by['node']['callable'] );
        $this->assertFalse( $by['store_draft']['callable'] );
        $this->assertSame( array( 'name' => 'node_id', 'type' => 'integer', 'required' => true ), $by['node']['params'][0] );

        $r = $this->ok( 'system_fetch_call', array( 'module' => 'content', 'function' => 'node', 'params' => array( 'node_id' => '2' ) ) );
        $this->assertSame( 2, $r['result']['node_id'], 'integer coerced' );
        $this->assertSame( 'admin', $r['ran_as'] );
        $this->assertFalse( isset( $r['result']['password_hash'] ), 'secret fields never leave' );
        $this->assertNotContains( 'jane.doe@example.com', json_encode( $r ), 'mail addresses are masked by the redactor' );
        $this->assertSame( array( 'fetch', TronFixture::ADMIN, 'content/node', array( 'node_id' => 2 ) ), $this->source->calls[0], 'ran as the caller' );

        $r = $this->ok( 'system_fetch_call', array( 'module' => 'content', 'function' => 'list', 'params' => array( 'parent_node_id' => 2 ) ) );
        $this->assertSame( 100, $this->source->calls[1][3]['limit'], 'a list fetch gets limit 100' );
        $this->ok( 'system_fetch_call', array( 'module' => 'content', 'function' => 'list', 'params' => array( 'parent_node_id' => 2, 'limit' => 5000 ) ) );
        $this->assertSame( 100, $this->source->calls[2][3]['limit'], 'and never more' );
    }

    public function testFetchRefusals(): void
    {
        $this->assertContains( 'write fetch', $this->err( 'system_fetch_call', array( 'module' => 'content', 'function' => 'store_draft' ) ) );
        $this->assertContains( "no parameter 'nodeid'", $this->err( 'system_fetch_call', array( 'module' => 'content', 'function' => 'node', 'params' => array( 'nodeid' => 2 ) ) ) );
        $this->assertContains( "needs the parameter 'node_id'", $this->err( 'system_fetch_call', array( 'module' => 'content', 'function' => 'node' ) ) );
        $this->assertContains( 'must be of type integer', $this->err( 'system_fetch_call', array( 'module' => 'content', 'function' => 'node', 'params' => array( 'node_id' => 'two' ) ) ) );
        $this->assertContains( 'no fetch function', $this->err( 'system_fetch_call', array( 'module' => 'content', 'function' => 'nope' ) ) );
        $this->assertContains( 'No module', $this->err( 'system_fetch_call', array( 'module' => 'nomodule', 'function' => 'x' ) ) );
        $this->assertContains( 'content/read', $this->err( 'system_fetch_call', array( 'module' => 'content', 'function' => 'node', 'params' => array( 'node_id' => 2 ) ), TronFixture::USEONLY ),
            'the module\'s read policy is the caller\'s' );
        $this->assertSame( 0, count( array_filter( $this->source->calls, function ( $c ) { return $c[1] === TronFixture::USEONLY; } ) ), 'nothing ran for a refused caller' );
    }

    // ------------------------------------------------------------------ operators

    public function testOperatorEval(): void
    {
        $r = $this->ok( 'system_operator_eval', array( 'operator' => 'upcase', 'input' => 'hello' ) );
        $this->assertSame( 'HELLO', $r['result'] );
        $r = $this->ok( 'system_operator_eval', array( 'operator' => 'shorten', 'input' => 'hello world', 'params' => array( 8 ) ) );
        $this->assertSame( 'hello...', $r['result'] );
        $this->assertContains( 'ini units', $this->err( 'system_operator_eval', array( 'operator' => 'ezini', 'input' => 'x' ) ) );
        $this->assertContains( 'writes image files', $this->err( 'system_operator_eval', array( 'operator' => 'texttoimage', 'input' => 'x' ) ) );
        $this->assertContains( 'No template operator', $this->err( 'system_operator_eval', array( 'operator' => 'nosuchop' ) ) );
    }

    // ------------------------------------------------------------------ modules and views

    public function testModuleDescribeAndViewRead(): void
    {
        $d = $this->ok( 'system_module_view_describe', array( 'module' => 'content' ) );
        $views = array_column( $d['views'], null, 'name' );
        $this->assertTrue( $views['view']['readable'] );
        $this->assertFalse( $views['edit']['readable'] );
        $this->assertSame( array( 'PublishButton' ), $views['edit']['post_actions'] );
        $this->assertSame( array( 'Class', 'Section' ), $d['functions']['read'] );
        $this->assertSame( array( 'content' ), $this->ok( 'system_module_view_describe' )['modules'] );

        $r = $this->ok( 'system_module_view_read', array( 'module' => 'content', 'view' => 'view', 'params' => array( 'full', 2 ) ) );
        $this->assertSame( 'Home', $r['title'] );
        $this->assertContains( 'Welcome', $r['text'] );
        $this->assertNotContains( 'x()', $r['text'], 'scripts are dropped' );
        $this->assertSame( array( array( 'href' => '/about', 'text' => 'About' ) ), $r['links'] );
        $this->assertContains( 'read views only', $this->err( 'system_module_view_read', array( 'module' => 'content', 'view' => 'edit', 'params' => array( 1 ) ) ) );
        $this->assertContains( 'streams a file', $this->err( 'system_module_view_read', array( 'module' => 'content', 'view' => 'download' ) ) );
        $this->assertContains( 'Permission denied', $this->err( 'system_module_view_read', array( 'module' => 'content', 'view' => 'view', 'params' => array( 'full', 2 ) ), TronFixture::USEONLY ) );
        $this->assertContains( 'characters', $this->err( 'system_module_view_read', array( 'module' => 'content', 'view' => 'view', 'params' => array( 'full', '<x>' ) ) ) );
    }

    // ------------------------------------------------------------------ commands

    public function testConsoleCommandReadAndDryRunOnly(): void
    {
        $r = $this->ok( 'system_console_command', array( 'command' => 'exp:expmigrationstatus', 'args' => array( '--siteaccess=site' ) ) );
        $this->assertSame( 'read', $r['mode'] );
        $this->assertNotContains( "\e[", $r['stdout'], 'colours stripped' );
        $this->assertContains( 'bin/php/console exp:expmigrationstatus --siteaccess=site', $r['stdout'] );
        $r = $this->ok( 'system_console_command', array( 'command' => 'exp:contentcheck', 'args' => array( 'unused' ) ) );
        $this->assertSame( 'dry_run', $r['mode'] );
        $this->assertContains( 'applies or forces', $this->err( 'system_console_command', array( 'command' => 'exp:contentcheck', 'args' => array( '--apply' ) ) ) );
        $this->assertContains( 'applies or forces', $this->err( 'system_console_command', array( 'command' => 'exp:contentcheck', 'args' => array( '--force=1' ) ) ) );
        $this->assertContains( 'characters', $this->err( 'system_console_command', array( 'command' => 'exp:expmigrationstatus', 'args' => array( '; rm -rf /' ) ) ) );
        $this->assertContains( 'not allowed', $this->err( 'system_console_command', array( 'command' => 'exp:ezfixdata' ) ) );
        $this->assertContains( 'Only these sub-commands', $this->err( 'system_console_command', array( 'command' => 'exp:audit', 'args' => array( 'rotate' ) ) ) );
        $this->assertContains( 'Permission denied', $this->err( 'system_console_command', array( 'command' => 'exp:expmigrationstatus' ), TronFixture::USEONLY ),
            'commands need setup/setup' );
    }

    public function testCliScript(): void
    {
        $cwd = getcwd();
        chdir( self::root() );
        try
        {
            $r = $this->ok( 'system_cli_script', array( 'script' => 'bin/php/expmigrationstatus.php' ) );
            $this->assertContains( 'bin/php/expmigrationstatus.php', $r['stdout'] );
            $this->assertContains( 'not allowed', $this->err( 'system_cli_script', array( 'script' => 'bin/php/ezfixdata.php' ) ) );
            $this->assertContains( 'not allowed', $this->err( 'system_cli_script', array( 'script' => 'extension/demo/bin/php/demolist.php' ) ) );
        }
        finally
        {
            chdir( $cwd );
        }
        $allow = new tronSystemAllow( array( 'ScriptAllow' => array( 'extension/demo/bin/php/demolist.php' ), 'ConsoleAllow' => array( 'exp:foo;dry_run' ) ) );
        $this->assertSame( 'read', $allow->script( 'extension/demo/bin/php/demolist.php' )['mode'] );
        $this->assertSame( 'dry_run', $allow->console( 'exp:foo' )['mode'] );
        $this->assertSame( 'bin/php/foo.php', tronSystemAllow::scriptOfCommand( 'exp:foo' ) );
    }

    // ------------------------------------------------------------------ queue

    public function testQueueJobs(): void
    {
        $r = $this->ok( 'system_queue_jobs', array( 'state' => 'queued' ) );
        $this->assertSame( array( 1, 'expqueue.sleep' ), array( $r['total'], $r['items'][0]['type'] ) );
        $this->assertSame( array( 'done' => 1, 'queued' => 1 ), $r['counts'] );
        $j = $this->ok( 'system_queue_job', array( 'job' => '1' ) );
        $this->assertSame( 'expqueue.noop', $j['job']['type'] );
        $this->assertSame( 'expqueue.sleep', $this->ok( 'system_queue_job', array( 'job' => 'fedcba9876543210fedcba9876543210' ) )['job']['type'] );
        $this->assertContains( 'No job', $this->err( 'system_queue_job', array( 'job' => '99' ) ) );
        $this->assertContains( 'Permission denied', $this->err( 'system_queue_jobs', array(), TronFixture::USEONLY ) );
    }

    public function testWithoutAnInstallationNothingIsOffered(): void
    {
        $f = TronFixture::build();
        $names = array_map( function ( $t ) { return $t->name; }, $f['server']->registry()->tools() );
        $this->assertNotContains( 'system_catalog', $names );
        $this->assertNotContains( 'system_fetch_call', $names );
        $this->assertTrue( isset( $f['server']->registry()->unavailableTools()['system_fetch_call'], $f['server']->registry()->unavailableTools()['system_catalog'] ) );
    }

    /**
     * The contract fuzzer (tests/lib/fuzz.php) on every adapter: valid, boundary and hostile inputs as every test user;
     * never an internal error, never a leak, every successful answer within its output schema.
     */
    public function testFuzzEveryAdapter(): void
    {
        $lib = dirname( __DIR__ ) . '/lib/fuzz.php';
        if ( !is_file( $lib ) )
            return;
        require_once $lib;
        $per = (int)( getenv( 'TRON_FUZZ_PER_TOOL' ) ?: 40 );
        $errors = array();
        $logger = function ( $level, $message ) use ( &$errors ) {
            if ( $level === 'error' )
                $errors[] = $message;
        };
        $cwd = getcwd();
        chdir( self::root() );
        try
        {
            $f = TronFixture::build( array( 'services' => TronSystemFixture::services(), 'logger' => $logger ) );
            $users = array_keys( TronFixture::users() );
            $problems = array();
            $id = 0;
            $n = 0;
            foreach ( $f['server']->registry()->tools() as $tool )
            {
                if ( $tool->program !== 'system' )
                    continue;
                $fz = new TronFuzz( 20261125 + crc32( $tool->name ) % 100000 );
                foreach ( $fz->inputs( $tool->inputSchema, $per, array() ) as $in )
                {
                    list( $cat, $args ) = $in;
                    $user = $fz->next( 2 ) === 0 ? TronFixture::ADMIN : $fz->pick( $users );
                    $r = $f['server']->handle( array( 'jsonrpc' => '2.0', 'id' => ++$id, 'method' => 'tools/call',
                                                      'params' => array( 'name' => $tool->name, 'arguments' => $args ) ), TronFixture::session( $user ) );
                    $p = TronFuzz::check( $r, $tool, $user, TronFuzz::canaryNeedles() );
                    foreach ( $errors as $e )
                        $p[] = 'server logged an error: ' . substr( $e, 0, 200 );
                    $errors = array();
                    $n++;
                    if ( $p )
                        $problems[] = "{$tool->name} [$cat] " . substr( (string)json_encode( $args, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE ), 0, 200 ) . ': ' . implode( '; ', $p );
                }
            }
        }
        finally
        {
            chdir( $cwd );
        }
        $this->assertSame( array(), array_slice( $problems, 0, 10 ) );
        $this->assertTrue( $n >= 11 * $per, "$n fuzz calls" );
    }

    public function testEveryAdapterIsDeclaredAndDocumented(): void
    {
        $names = array_map( function ( $t ) { return $t->name; }, $this->f['server']->registry()->tools() );
        $doc = (string)@file_get_contents( dirname( __DIR__, 2 ) . '/doc/system-adapters.md' );
        foreach ( tronSystemCatalog::ADAPTERS as $id => $a )
        {
            $this->assertContains( $a['tool'], $names, "$id is registered" );
            $this->assertContains( $a['tool'], $doc, "$id is documented" );
            if ( property_exists( 'tronTool', 'outputSchema' ) )
                $this->assertTrue( $this->f['server']->registry()->findTool( $a['tool'] )->outputSchema !== null, "$id declares its output" );
            $this->assertFalse( $this->f['server']->registry()->findTool( $a['tool'] )->write, "$id is no write tool" );
        }
    }
}
