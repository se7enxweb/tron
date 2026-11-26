<?php
/**
 * The MCP engine factory: engines registered by settings alone, the bridge API wrapped without an adapter (a class
 * that only has the same methods), the neutral contract, several engines at once each under its own prefix, the
 * same refusal of a write tool through every engine with an audit entry for every call, switching an engine on and
 * off in the settings, and the interface scan.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

require_once dirname( __DIR__ ) . '/fixtures/mcp_engine_stubs.php';

final class TronMcpEngineTest extends TronTestCase
{
    /** The settings an extension would ship: two engines, registered only here. */
    private static function settings( array $more = array() ): array
    {
        return array_replace_recursive( array(
            'McpEngine_fork' => array( 'Class' => 'TronStubBridgeFork', 'Enabled' => 'enabled', 'Label' => 'Fork' ),
            'McpEngine_notes' => array( 'Class' => 'TronStubNotesEngine', 'Label' => 'Notes', 'WritePolicy' => 'notes/write' ),
        ), $more );
    }

    /** The test world as a host in the same request sees it (bridge-API engines offered). */
    private static function build( array $o ): array
    {
        return TronFixture::build( $o + array( 'inProcess' => true ) );
    }

    private static function hub( array $f ): tronMcpEngineHub
    {
        return new tronMcpEngineHub( new tronMcpTronEngine( $f['server'], $f['audit'], 'test' ) );
    }

    private static function principal( int $userId ): tronPrincipal
    {
        return new tronPrincipal( $userId, TronFixture::users()[$userId]['login'] );
    }

    private static function names( array $tools ): array
    {
        return array_column( $tools, 'name' );
    }

    public function setUp(): void
    {
        TronStubBridgeFork::$calls = array();
        TronStubNotesEngine::$notes = array();
    }

    public function testBridgeApiIsRecognisedWithoutTheInterface(): void
    {
        $this->assertFalse( is_subclass_of( 'TronStubBridgeFork', 'tronMcpBridgeApi' ), 'the stub does not declare the interface' );
        $this->assertTrue( tronMcpBridgeEngine::matches( 'TronStubBridgeFork' ) );
        $this->assertSame( 'bridge', tronMcpEngineFactory::kind( 'TronStubBridgeFork' ) );
        $this->assertSame( 'engine', tronMcpEngineFactory::kind( 'TronStubNotesEngine' ) );
        $this->assertSame( null, tronMcpEngineFactory::kind( 'TronStubHalfBridge' ) );
        $this->assertFalse( tronMcpBridgeEngine::matches( 'TronStubHalfBridge' ) );
        $this->assertThrows( function () { new tronMcpBridgeEngine( 'TronStubHalfBridge' ); }, 'InvalidArgumentException', 'bridge API' );
    }

    public function testTheInterfaceMirrorsTheBridgeApi(): void
    {
        $r = new ReflectionClass( 'tronMcpBridgeApi' );
        $methods = array();
        foreach ( $r->getMethods() as $m )
        {
            $this->assertTrue( $m->isStatic(), $m->getName() . ' is static' );
            $methods[$m->getName()] = array( $m->getNumberOfParameters(), $m->getNumberOfRequiredParameters() );
        }
        ksort( $methods );
        $api = tronMcpBridgeEngine::API;
        ksort( $api );
        $this->assertSame( $api, $methods );
        foreach ( array_keys( $api ) as $name )
        {
            $mine = array_map( function ( $p ) { return $p->getName(); }, ( new ReflectionMethod( 'tronMcpBridgeApi', $name ) )->getParameters() );
            $stub = array_map( function ( $p ) { return $p->getName(); }, ( new ReflectionMethod( 'TronStubBridgeFork', $name ) )->getParameters() );
            $this->assertSame( $stub, $mine, "the parameters of $name" );
        }
    }

    public function testEnginesRegisteredBySettingsOnly(): void
    {
        $f = self::build( array( 'settings' => self::settings() ) );
        $names = array_map( function ( $t ) { return $t->name; }, $f['server']->registry()->tools() );
        $this->assertContains( 'fork__stub_get', $names );
        $this->assertContains( 'fork__stub_search', $names );
        $this->assertContains( 'notes__note_list', $names );
        $this->assertContains( 'notes__note_create', $names );
        $this->assertContains( 'tron_whoami', $names, 'tron\'s own programs stay' );
        $this->assertSame( array( 'fork' => 'Fork', 'notes' => 'Notes' ), $f['server']->option( 'engines' ) );
        $unavailable = $f['server']->registry()->unavailableTools();
        $this->assertTrue( isset( $unavailable['notes__Bad-Name'] ), 'a tool name off the pattern is left out with the reason' );
        $rows = array();
        foreach ( (array)$f['server']->option( 'programs' ) as $row )
            $rows[$row['name']] = $row;
        $this->assertSame( 'loaded', $rows['fork']['state'] );
        $this->assertSame( 'engine:TronStubBridgeFork', $rows['fork']['origin'] );
        $this->assertSame( 'loaded', $rows['notes']['state'] );
        $this->assertSame( '1.2.3', $rows['notes']['version'] );
        $this->assertSame( 2, $rows['fork']['tools'] );
    }

    public function testSeveralEnginesEachUnderItsOwnPrefix(): void
    {
        $f = self::build( array( 'settings' => self::settings() ) );
        $hub = self::hub( $f );
        $this->assertSame( array( 'tron', 'fork', 'notes' ), array_keys( $hub->engines() ) );
        $tools = $hub->tools( self::principal( TronFixture::ADMIN ) );
        $names = self::names( $tools );
        $this->assertContains( 'tron__tron_whoami', $names );
        $this->assertContains( 'fork__stub_get', $names );
        $this->assertContains( 'notes__note_create', $names );
        foreach ( $tools as $t )
        {
            $this->assertSame( 0, strpos( $t['name'], $t['engine'] . '__' ), $t['name'] . ' carries its engine prefix' );
            $this->assertSame( $t['name'], tronMcpEngineHub::hostName( $t['engine'], $t['tool'] ) );
        }
        $who = $hub->call( 'tron__tron_whoami', array(), self::principal( TronFixture::ADMIN ) );
        $this->assertSame( 'admin', $who['login'] );
        $this->assertSame( 'inprocess', $who['transport'] );
        $got = $hub->call( 'fork__stub_get', array( 'node_id' => 7 ), self::principal( TronFixture::ADMIN ) );
        $this->assertSame( array( 'tool' => 'stub_get', 'node_id' => 7, 'name' => 'Example item' ), $got );
        $this->assertSame( array( array( 'stub_get', array( 'node_id' => 7 ) ) ), TronStubBridgeFork::$calls );
        $made = $hub->call( 'notes__note_create', array( 'text' => 'hello' ), self::principal( TronFixture::ADMIN ) );
        $this->assertSame( array( 'created' => 1, 'by' => 'admin' ), $made );
        $this->assertTrue( $hub->tronEngine()->writes( 'notes__note_create' ) );
        $this->assertFalse( $hub->tronEngine()->writes( 'fork__stub_get' ) );
    }

    public function testABridgeAnswerWithAnErrorIsAToolError(): void
    {
        $f = self::build( array( 'settings' => self::settings() ) );
        $hub = self::hub( $f );
        $this->assertThrows( function () use ( $hub ) {
            $hub->call( 'fork__stub_get', array( 'node_id' => 404 ), self::principal( TronFixture::ADMIN ) );
        }, 'tronToolError', 'Node 404' );
        $last = end( $f['audit']->entries );
        $this->assertSame( 'error', $last['outcome'] );
        $this->assertThrows( function () use ( $hub ) {
            $hub->call( 'fork__stub_get', array( 'nope' => 1 ), self::principal( TronFixture::ADMIN ) );
        }, 'tronToolError', 'Invalid arguments' );
    }

    public function testTheBridgeRunsOnlyAsTheSignedInUser(): void
    {
        $f = self::build( array( 'settings' => self::settings(), 'currentUserId' => function () { return TronFixture::ADMIN; } ) );
        $hub = self::hub( $f );
        $this->assertSame( 'Example item', $hub->call( 'fork__stub_get', array(), self::principal( TronFixture::ADMIN ) )['name'] );
        $this->assertThrows( function () use ( $hub ) {
            $hub->call( 'fork__stub_get', array(), self::principal( TronFixture::VIEWER ) );
        }, 'tronToolError', 'signed-in user only' );
        $this->assertCount( 1, TronStubBridgeFork::$calls, 'the bridge never ran for the other user' );
    }

    public function testAViewerIsRefusedTheSameWriteThroughEveryEngineAndEveryCallIsAudited(): void
    {
        $f = self::build( array( 'settings' => self::settings() ) );
        $hub = self::hub( $f );
        $viewer = self::principal( TronFixture::VIEWER );
        $visible = self::names( $hub->tools( $viewer ) );
        $this->assertNotContains( 'notes__note_create', $visible, 'the write tool is not offered to a viewer' );
        $this->assertNotContains( 'tron__centre_ticket_create', $visible );
        $this->assertContains( 'notes__note_list', $visible, 'reads are' );
        $this->assertContains( 'fork__stub_get', $visible );

        $attempts = array(
            'tron' => array( 'tron__centre_ticket_create', array( 'title' => 'x' ) ),
            'notes' => array( 'notes__note_create', array( 'text' => 'x' ) ),
            'fork' => array( 'fork__stub_delete', array( 'node_id' => 2 ) ),          // the bridge has no write: not offered
        );
        foreach ( $attempts as $engine => $a )
        {
            $before = count( $f['audit']->entries );
            $refused = false;
            try
            {
                $hub->call( $a[0], $a[1], $viewer );
            }
            catch ( tronToolError $e )
            {
                $refused = true;
            }
            $this->assertTrue( $refused, "$engine refuses the viewer" );
            $this->assertSame( $before + 1, count( $f['audit']->entries ), "$engine: one audit entry" );
            $entry = end( $f['audit']->entries );
            $this->assertSame( 'viewer', $entry['login'] );
            $this->assertSame( 'inprocess', $entry['transport'] );
            $this->assertTrue( in_array( $entry['outcome'], array( 'denied', 'invalid' ), true ), "$engine: refused in the audit ({$entry['outcome']})" );
        }
        $this->assertSame( array(), TronStubNotesEngine::$notes, 'nothing was written' );
        $this->assertSame( array(), TronStubBridgeFork::$calls );

        // the same calls as a user who holds the policies run, and are audited as well
        $before = count( $f['audit']->entries );
        $hub->call( 'notes__note_create', array( 'text' => 'x' ), self::principal( TronFixture::ADMIN ) );
        $hub->call( 'fork__stub_get', array(), $viewer );
        $this->assertSame( $before + 3, count( $f['audit']->entries ), 'a write is audited before (started) and after (ok); a read once' );
    }

    public function testOverStdioAndHttpABridgeEngineIsNotOffered(): void
    {
        $f = self::build( array( 'settings' => self::settings(), 'inProcess' => false ) );
        $this->assertSame( null, $f['server']->registry()->findTool( 'fork__stub_get' ) );
        $this->assertContains( 'signed-in user only', $f['server']->registry()->unavailableTools()['fork__stub_get'] );
        $this->assertTrue( $f['server']->registry()->findTool( 'notes__note_list' ) !== null, 'an engine of the neutral contract is' );
        $r = TronFixture::call( $f['server'], TronFixture::session( TronFixture::VIEWER ), 'notes__note_list' );
        $this->assertSame( false, $r['result']['isError'] );
        $this->assertContains( '"asked_by":"viewer"', $r['result']['content'][0]['text'], 'run as the session\'s user' );
    }

    public function testAWriteToolWithoutAPolicyIsLeftOut(): void
    {
        $f = self::build( array( 'settings' => self::settings( array( 'McpEngine_notes' => array( 'WritePolicy' => '' ) ) ) ) );
        $this->assertSame( null, $f['server']->registry()->findTool( 'notes__note_create' ) );
        $this->assertContains( 'WritePolicy', $f['server']->registry()->unavailableTools()['notes__note_create'] );
        $this->assertTrue( $f['server']->registry()->findTool( 'notes__note_list' ) !== null );
    }

    public function testSwitchingAnEngineOffAndOnInTheSettings(): void
    {
        $off = self::build( array( 'settings' => self::settings( array( 'McpEngine_fork' => array( 'Enabled' => 'disabled' ) ) ) ) );
        $names = self::names( self::hub( $off )->tools( self::principal( TronFixture::ADMIN ) ) );
        $this->assertNotContains( 'fork__stub_get', $names );
        $this->assertContains( 'notes__note_list', $names );
        $this->assertFalse( isset( self::hub( $off )->engines()['fork'] ) );
        $rows = array_column( (array)$off['server']->option( 'programs' ), 'state', 'name' );
        $this->assertSame( 'disabled', $rows['fork'] );
        $this->assertThrows( function () use ( $off ) {
            self::hub( $off )->call( 'fork__stub_get', array(), self::principal( TronFixture::ADMIN ) );
        }, 'tronToolError', 'Unknown tool' );

        $on = self::build( array( 'settings' => self::settings() ) );
        $this->assertContains( 'fork__stub_get', self::names( self::hub( $on )->tools( self::principal( TronFixture::ADMIN ) ) ) );

        // [ProgramSettings] Disabled[] and Setup > tron switch an engine off like a program
        $prog = self::build( array( 'settings' => self::settings( array( 'ProgramSettings' => array( 'Disabled' => array( 'notes' ) ) ) ) ) );
        $this->assertSame( null, $prog['server']->registry()->findTool( 'notes__note_list' ) );
        $sw = self::build( array( 'settings' => self::settings(), 'switchedOff' => array( 'tool' => array( 'fork__stub_search' ) ) ) );
        $this->assertSame( null, $sw['server']->registry()->findTool( 'fork__stub_search' ) );
        $this->assertTrue( $sw['server']->registry()->findTool( 'fork__stub_get' ) !== null );
    }

    public function testBadRegistrationsCostOnlyThemselves(): void
    {
        $f = self::build( array( 'settings' => self::settings( array(
            'McpEngine_mcp' => array( 'Class' => 'TronStubNotesEngine' ),
            'McpEngine_centre' => array( 'Class' => 'TronStubNotesEngine', 'WritePolicy' => 'tron/centre_write' ),
            'McpEngine_half' => array( 'Class' => 'TronStubHalfBridge' ),
            'McpEngine_gone' => array( 'Class' => 'TronNoSuchClass' ),
            'McpEngine_Bad' => array( 'Class' => 'TronStubNotesEngine' ),
            'McpEngine_tron' => array( 'Class' => 'tronMcpTronEngine' ),
        ) ) ) );
        $rows = array_column( (array)$f['server']->option( 'programs' ), 'state', 'name' );
        $this->assertSame( 'failed', $rows['mcp'], 'reserved id' );
        $this->assertSame( 'conflict', $rows['centre'], 'a program has the name' );
        $this->assertSame( 'failed', $rows['half'] );
        $this->assertSame( 'failed', $rows['gone'] );
        $this->assertSame( 'failed', $rows['Bad'] );
        $this->assertSame( 'loaded', $rows['tron'], 'tron is its programs, not an engine row' );
        $this->assertSame( 'loaded', $rows['fork'] );
        $this->assertTrue( $f['server']->registry()->findTool( 'centre_ticket_list' ) !== null, 'the program keeps its tools' );
    }

    public function testTheInterfaceScanFindsEnginesWithoutLoadingThem(): void
    {
        $root = TronFixture::tmp( 'mcpengine-scan' );
        TronFixture::write( $root . '/ext/a/classes/forkbridge.php', "<?php\nclass ScanForkBridge\n{\n"
            . "    public static function tools() {}\n    public static function toolNames() {}\n    public static function isBridgeSafe( \$name ) {}\n"
            . "    public static function description( \$name ) {}\n    public static function inputSchema( \$name ) {}\n"
            . "    public static function tool( \$name, \$siteAccess = null, ?eZINI \$ini = null ) {}\n"
            . "    public static function call( \$name, array \$input = array(), \$siteAccess = null, ?eZINI \$ini = null ) {}\n}\n" );
        TronFixture::write( $root . '/ext/b/classes/notes.php', "<?php\nfinal class ScanNotes implements Countable, tronMcpEngine\n{\n}\n" );
        TronFixture::write( $root . '/ext/c/classes/half.php', "<?php\nclass ScanHalf\n{\n    public static function isBridgeSafe( \$name ) {}\n}\n" );
        TronFixture::write( $root . '/ext/d/classes/other.php', "<?php\nclass ScanOther\n{\n}\n" );
        $autoload = array( 'ScanForkBridge' => 'ext/a/classes/forkbridge.php', 'ScanNotes' => 'ext/b/classes/notes.php',
                           'ScanHalf' => 'ext/c/classes/half.php', 'ScanOther' => 'ext/d/classes/other.php', 'ScanMissing' => 'ext/e/x.php' );
        $rows = tronMcpEngineFactory::scan( $autoload, $root, array( 'McpEngine_fork' => array( 'Class' => 'ScanForkBridge' ) ) );
        $this->assertSame( array(
            array( 'class' => 'ScanForkBridge', 'file' => 'ext/a/classes/forkbridge.php', 'kind' => 'bridge', 'id' => 'fork' ),
            array( 'class' => 'ScanNotes', 'file' => 'ext/b/classes/notes.php', 'kind' => 'engine', 'id' => '' ),
        ), $rows );
        $this->assertFalse( class_exists( 'ScanForkBridge', false ), 'the scan loaded nothing' );
        $this->assertSame( 'scannotes', tronMcpEngineFactory::idFor( 'ScanNotes' ) );

        // ScanRegister=enabled registers what the scan found and no INI group names
        $built = tronMcpEngineFactory::build( array( 'McpEngineSettings' => array( 'ScanRegister' => 'enabled' ) ), null,
                                              array( array( 'class' => 'TronStubNotesEngine', 'file' => 'x', 'kind' => 'engine', 'id' => '' ) ) );
        $this->assertSame( array( 'tronstubnotesengine' ), array_keys( $built['engines'] ) );
        $none = tronMcpEngineFactory::build( array(), null, array( array( 'class' => 'TronStubNotesEngine', 'file' => 'x', 'kind' => 'engine', 'id' => '' ) ) );
        $this->assertSame( array(), $none['engines'], 'the scan alone registers nothing by default' );
    }
}
