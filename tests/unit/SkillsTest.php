<?php
/**
 * Skills: the standard (manifest and pack), the catalog and its states, the runner and its ops, extensions that ship
 * packs, live skills as tools and prompts, drafts, validation and forks, and the redaction canaries.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

require_once dirname( __DIR__ ) . '/lib/skillfixtures.php';

final class TronSkillsTest extends TronTestCase
{
    private static function exampleDir(): string
    {
        return dirname( __DIR__ ) . '/fixtures/extensions/expskillsexample';
    }

    private static function manifest(): array
    {
        return json_decode( (string)file_get_contents( self::exampleDir() . '/tron/skills/example-centre/skills/example.centre.labels.json' ), true );
    }

    /** The example extension's own settings file, read the way the kernel merges it (one group here). */
    private static function exampleSettings(): array
    {
        $out = array();
        $group = '';
        foreach ( file( self::exampleDir() . '/settings/tron.ini.append.php', FILE_IGNORE_NEW_LINES ) as $line )
        {
            if ( preg_match( '/^\[([A-Za-z0-9_-]+)\]$/', $line, $m ) )
                $group = $m[1];
            else if ( $group !== '' && preg_match( '/^([A-Za-z0-9_]+)=(.*)$/', $line, $m ) )
                $out[$group][$m[1]] = $m[2];
        }
        return $out;
    }

    /** A world with the example extension active, its pack proven and, when asked, approved. */
    private function world( bool $approve, int $user = TronFixture::ADMIN, array $extra = array() ): array
    {
        $db = tronDbPdo::sqliteMemory();
        TronSkillFixtures::seed( $db, TronFixture::NOW );
        $tmp = TronFixture::tmp( 'skills-world' );
        $ext = $tmp . '/expskillsexample';
        self::copyTree( self::exampleDir(), $ext );
        $settings = self::exampleSettings() + $extra;
        $f = TronFixture::build( array( 'db' => $db, 'settings' => $settings, 'extensions' => array( 'expskillsexample' => $ext ) ) );
        $env = tronSkillProgram::env( $f['server'] );
        $pack = tronSkillProgram::catalog( $env )->pack( 'example-centre' );
        $this->assertTrue( $pack !== null, 'the example pack loads' );
        $session = TronFixture::session( TronFixture::ADMIN );
        $rows = array();
        foreach ( $pack->skills as $n => $m )
        {
            $r = tronSkillValidator::prove( $f['server'], $session, $m );
            $rows[$n] = array( 'version' => $m->version, 'hash' => $m->hash(), 'result' => $r['result'], 'runs' => array( 'fixtures' => $r['result'] ) );
        }
        $pack->writeProof( $rows, tronVersion::VERSION, TronFixture::NOW );
        if ( $approve )
            tronSkillProgram::store( $env )->approve( $pack->skills['example.centre.labels'], 'example-centre', TronFixture::ADMIN );
        // build again: the factory now finds the approved, proven skill
        $f = TronFixture::build( array( 'db' => $db, 'settings' => $settings, 'extensions' => array( 'expskillsexample' => $ext ) ) );
        return $f + array( 'session' => TronFixture::session( $user ), 'ext' => $ext );
    }

    private static function copyTree( string $from, string $to ): void
    {
        $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $from, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
        if ( !is_dir( $to ) )
            mkdir( $to, 0775, true );
        foreach ( $it as $f )
        {
            $target = $to . '/' . substr( $f->getPathname(), strlen( $from ) + 1 );
            if ( $f->isDir() )
            {
                if ( !is_dir( $target ) )
                    mkdir( $target, 0775, true );
            }
            else
                copy( $f->getPathname(), $target );
        }
        @unlink( $to . '/tron/skills/example-centre/proof.json' );
    }

    public function testTheExampleManifestKeepsTheStandard(): void
    {
        $m = tronSkillManifest::fromArray( self::manifest() );
        $this->assertSame( 'skill_example_centre_labels', $m->toolName() );
        $this->assertSame( array( 'centre_ticket_list' ), $m->units() );
        $this->assertSame( array( '--board' => 'board', '-b' => 'board' ), $m->cliOptions() );
        $this->assertSame( 64, strlen( $m->hash() ) );
    }

    public function testTheStandardRefusesWithEveryReason(): void
    {
        $d = self::manifest();
        $d['name'] = 'Bad Name';
        $d['colour'] = 'red';
        $d['parameters']['free'] = array( 'type' => 'string', 'description' => 'no limits at all', 'examples' => array( 'x' ) );
        $d['parameters']['n'] = array( 'type' => 'integer', 'description' => 'a number', 'examples' => array( 3 ) );
        $d['steps'][] = array( 'id' => 'nested', 'unit' => 'skill_other_one' );
        $d['steps'][] = array( 'id' => 'bad', 'op' => 'where', 'from' => '$steps.nope', 'field' => 'x', 'cmp' => 'eq', 'value' => 1 );
        $d['examples'] = array();
        $d['version'] = '1.0';
        $e = tronSkillManifest::errors( $d );
        $text = implode( "\n", $e );
        foreach ( array( 'name: lower-case dotted', "unknown key 'colour'", 'parameters.free: a string parameter needs enum, pattern or maxLength',
                         'parameters.n: a number parameter needs minimum and maximum', 'a skill runs units, not other skills',
                         '$steps.nope names no earlier step', 'examples: at least one example', 'version: X.Y.Z.N' ) as $needle )
            $this->assertContains( $needle, $text );
        $this->assertThrows( function () use ( $d ) { tronSkillManifest::fromArray( $d ); }, 'tronSkillInvalid', 'breaks the standard' );

        $d = self::manifest();
        $d['requires']['units'][] = 'centre_ticket_get';
        $this->assertContains( 'centre_ticket_get is listed but no step runs it', implode( "\n", tronSkillManifest::errors( $d ) ) );
        $d = self::manifest();
        $d['parameters']['board']['secret'] = true;
        $this->assertContains( 'a skill takes no secrets', implode( "\n", tronSkillManifest::errors( $d ) ) );
        $d = self::manifest();
        $d['output']['value']['extra'] = 1;
        $this->assertContains( "the answer key 'extra' is not described", implode( "\n", tronSkillManifest::errors( $d ) ) );
        $d = self::manifest();
        $d['derived_from'] = array( 'name' => 'a.b', 'version' => '0.0.0.1', 'license' => 'GPL-2.0-or-later' );
        $d['license'] = 'MIT';
        $this->assertContains( 'a fork of a GPL-2.0-or-later skill keeps that license', implode( "\n", tronSkillManifest::errors( $d ) ) );
    }

    public function testAPackThatBreaksTheStandardIsRefused(): void
    {
        $tmp = TronFixture::tmp( 'skills-refused' );
        $dir = $tmp . '/broken-pack';
        if ( !is_dir( $dir . '/skills' ) )
            mkdir( $dir . '/skills', 0775, true );
        @unlink( $dir . '/README.md' );
        file_put_contents( $dir . '/pack.json', json_encode( array( 'id' => 'other-id', 'title' => 'Broken', 'description' => 'A pack that breaks the standard on purpose.',
            'version' => '0.0.0.1', 'author' => 'Test', 'license' => 'GPL-2.0-or-later', 'prefix' => 'broken', 'requires' => array( 'tron' => '0.0.0.1' ) ) ) );
        copy( self::exampleDir() . '/tron/skills/example-centre/skills/example.centre.labels.json', $dir . '/skills/example.centre.labels.json' );
        try
        {
            tronSkillPack::read( $dir );
            $this->fail( 'the pack was not refused' );
        }
        catch ( tronSkillInvalid $e )
        {
            $text = implode( "\n", $e->reasons() );
            $this->assertContains( "must equal the directory name 'broken-pack'", $text );
            $this->assertContains( 'README.md: every pack documents itself', $text );
            $this->assertContains( "must start with the pack prefix 'broken.'", $text );
            $this->assertContains( 'needs tron 0.0.0.2, more than the pack declares (0.0.0.1)', $text );
        }
    }

    public function testExtensionsShipPacksOnlyWhenRegistered(): void
    {
        $fx = dirname( __DIR__ ) . '/fixtures/extensions';
        $c = new tronSkillCatalog( self::exampleSettings(), dirname( __DIR__, 2 ), array( 'expskillsexample' => $fx . '/expskillsexample', 'expskillsunregistered' => $fx . '/expskillsunregistered' ) );
        $rows = array();
        foreach ( $c->packs() as $r )
            $rows[$r['id']] = $r;
        $this->assertSame( 'loaded', $rows['example-centre']['state'] );
        $this->assertSame( 'expskillsexample', $rows['example-centre']['origin'] );
        $this->assertSame( '1.0.0.3', $rows['example-centre']['provider']['version'] );
        $this->assertSame( 'GNU General Public License v2.0 (or any later version)', $rows['example-centre']['provider']['license'] );
        $this->assertSame( 'unregistered', $rows['loose-pack']['state'] );
        $this->assertContains( '[SkillPack_loose-pack]', $rows['loose-pack']['reason'] );

        $off = new tronSkillCatalog( self::exampleSettings() + array( 'SkillPackSettings' => array( 'Disabled' => array( 'example-centre' ) ) ), dirname( __DIR__, 2 ), array( 'expskillsexample' => $fx . '/expskillsexample' ) );
        foreach ( $off->packs() as $r )
            if ( $r['id'] === 'example-centre' )
                $this->assertSame( 'disabled', $r['state'] );
    }

    public function testStatesFromParkedToLive(): void
    {
        $w = $this->world( false );
        $env = tronSkillProgram::env( $w['server'] );
        $all = tronSkillProgram::all( $env );
        $this->assertSame( 'ready', $all['example.centre.labels']['state'], 'proven, not yet approved' );
        $this->assertTrue( $w['server']->registry()->findTool( 'skill_example_centre_labels' ) === null, 'not offered before approval' );

        $w = $this->world( true );
        $all = tronSkillProgram::all( tronSkillProgram::env( $w['server'] ) );
        $this->assertSame( 'live', $all['example.centre.labels']['state'] );
        $this->assertTrue( $w['server']->registry()->findTool( 'skill_example_centre_labels' ) !== null, 'offered once approved' );

        // a unit that is not here parks the skill, with the reason
        $m = tronSkillManifest::fromArray( array( 'requires' => array( 'tron' => '0.0.0.2', 'units' => array( 'node_tree' ), 'wave' => 'A' ),
            'steps' => array( array( 'id' => 'list', 'unit' => 'node_tree', 'args' => array( 'node_id' => 2 ) ), array( 'id' => 'labels', 'op' => 'group', 'from' => '$steps.list.items', 'by' => 'class_identifier' ) ) ) + self::manifest() );
        $s = tronSkillCatalog::state( $m, 'loaded', '', $w['server']->registry(), null, array() );
        $this->assertSame( 'parked', $s['state'] );
        $this->assertContains( 'node_tree', $s['reason'] );

        // a write unit parks it too (wave B)
        $m = tronSkillManifest::fromArray( array( 'requires' => array( 'tron' => '0.0.0.2', 'units' => array( 'centre_ticket_create' ) ),
            'steps' => array( array( 'id' => 'list', 'unit' => 'centre_ticket_create', 'args' => array( 'title' => 'x' ) ), array( 'id' => 'labels', 'op' => 'group', 'from' => '$steps.list', 'by' => 'labels' ) ) ) + self::manifest() );
        $s = tronSkillCatalog::state( $m, 'loaded', '', $w['server']->registry(), array( 'result' => 'pass' ), array() );
        $this->assertSame( 'parked', $s['state'] );
        $this->assertContains( 'wave B', $s['reason'] );

        // an edited manifest needs a new approval
        $d = self::manifest();
        $d['title'] = 'Labels in use, edited';
        $m = tronSkillManifest::fromArray( $d );
        $s = tronSkillCatalog::state( $m, 'loaded', '', $w['server']->registry(), array( 'result' => 'pass' ), array( $m->name => 'an older hash' ) );
        $this->assertSame( 'ready', $s['state'] );
        $this->assertContains( 'approve again', $s['reason'] );
    }

    public function testALiveSkillIsAToolAndAPrompt(): void
    {
        $w = $this->world( true );
        $r = TronFixture::data( TronFixture::call( $w['server'], $w['session'], 'skill_example_centre_labels', array( 'board' => 'main' ) ) );
        $this->assertSame( 'main', $r['board'] );
        $this->assertSame( array( 'key' => 'site', 'count' => 4 ), $r['labels'][0], 'site is the most used label on main' );

        $p = $w['server']->handle( array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'prompts/get',
                                          'params' => array( 'name' => 'skill_example_centre_labels', 'arguments' => array( 'board' => 'ops' ) ) ), $w['session'] );
        $this->assertContains( 'skill_example_centre_labels', $p['result']['messages'][0]['content']['text'] );
        $this->assertContains( '"board":"ops"', $p['result']['messages'][0]['content']['text'] );

        // the steps are audited as the caller's own calls
        $targets = array_column( $w['audit']->entries, 'target' );
        $this->assertContains( 'skill_example_centre_labels', $targets );
        $this->assertContains( 'centre_ticket_list', $targets );
    }

    public function testASkillCannotDoMoreThanItsCaller(): void
    {
        $w = $this->world( true, TronFixture::USEONLY );
        $r = TronFixture::call( $w['server'], $w['session'], 'skill_example_centre_labels', array( 'board' => 'main' ) );
        $this->assertTrue( $r['result']['isError'], 'refused' );
        $this->assertContains( 'Permission denied', $r['result']['content'][0]['text'] );
        $sim = tronSkillRunner::simulate( $w['server'], $w['session'], tronSkillManifest::fromArray( self::manifest() ) );
        $this->assertFalse( $sim['allowed'] );
        $this->assertSame( 'tron/centre_read', $sim['checks'][0]['policy'] );
    }

    public function testOpsAndWiring(): void
    {
        $x = new tronSkillExpr( array( 'param' => array( 'n' => 2 ), 'now' => TronFixture::NOW, 'user' => array( 'login' => 'admin' ) ) );
        $x->setStep( 'list', array( 'items' => array(
            array( 'id' => 1, 'due' => '2026-11-20', 'labels' => array( 'a', 'b' ), 'lanes' => array( 1, 2 ) ),
            array( 'id' => 2, 'due' => '2026-11-30', 'labels' => array( 'a' ), 'lanes' => array( 3 ) ),
            array( 'id' => 3, 'due' => null, 'labels' => array(), 'lanes' => array() ) ) ) );
        $call = function () { return array(); };
        $this->assertSame( '2026-11-24', $x->ref( '$today' ) );
        $this->assertSame( '2026-12-01', $x->ref( '$date.+1w' ) );
        $this->assertSame( '2026-W48', $x->ref( '$week' ) );
        $this->assertSame( 3, $x->ref( '$steps.list.items|count' ) );
        $this->assertSame( 'n is 2 for admin', $x->resolve( 'n is {{$param.n}} for {{$user.login}}' ) );
        $late = tronSkillOps::run( array( 'op' => 'where', 'from' => '$steps.list.items', 'field' => 'due', 'cmp' => 'lt', 'value' => '$today' ), $x, $call );
        $this->assertSame( array( 1 ), array_column( $late, 'id' ) );
        $g = tronSkillOps::run( array( 'op' => 'group', 'from' => '$steps.list.items', 'by' => 'labels' ), $x, $call );
        $this->assertSame( array( 'key' => 'a', 'count' => 2 ), $g[0] );
        $this->assertSame( array( 1, 2, 3 ), tronSkillOps::run( array( 'op' => 'flatten', 'from' => '$steps.list.items', 'field' => 'lanes' ), $x, $call ) );
        $aged = tronSkillOps::run( array( 'op' => 'age', 'from' => '$steps.list.items', 'field' => 'due', 'as' => 'days' ), $x, $call );
        $this->assertSame( 4, $aged[0]['days'] );
        $this->assertSame( array( 1, 2 ), array_column( tronSkillOps::run( array( 'op' => 'limit', 'count' => '$param.n', 'from' => '$steps.list.items' ), $x, $call ), 'id' ) );
        $this->assertThrows( function () use ( $x, $call ) {
            tronSkillOps::run( array( 'op' => 'assert', 'value' => '$steps.list.items|count', 'cmp' => 'le', 'compare' => 2, 'message' => 'too many: {{$steps.list.items|count}}' ), $x, $call );
        }, 'tronToolError', 'too many: 3' );
    }

    public function testDraftValidateApproveAndFork(): void
    {
        $w = $this->world( true );
        $s = $w['session'];
        $d = self::manifest();
        $d['name'] = 'local.labels.ops';
        $d['parameters']['board']['default'] = 'ops';
        $r = TronFixture::data( TronFixture::call( $w['server'], $s, 'skill_draft', array( 'manifest' => $d ) ) );
        $this->assertSame( 'draft', $r['state'] );
        $id = $r['draft_id'];

        $bad = $d;
        unset( $bad['examples'] );
        $e = TronFixture::call( $w['server'], $s, 'skill_draft', array( 'manifest' => $bad ) );
        $this->assertTrue( $e['result']['isError'] );
        $this->assertContains( 'examples: at least one example', $e['result']['content'][0]['text'] );

        $taken = TronFixture::call( $w['server'], $s, 'skill_draft', array( 'manifest' => self::manifest() ) );
        $this->assertContains( 'is taken by the pack example-centre', $taken['result']['content'][0]['text'] );

        $v = TronFixture::data( TronFixture::call( $w['server'], $s, 'skill_validate', array( 'draft_id' => $id ) ) );
        $this->assertSame( 'pass', $v['result'] );
        $this->assertSame( 'pass', $v['checks']['policy'] );
        $this->assertSame( 'pass', $v['checks']['redaction'] );
        $store = tronSkillProgram::store( tronSkillProgram::env( $w['server'] ) );
        $this->assertSame( 'draft', $store->draftById( $id )['state'], 'validating changes nothing' );

        $f = TronFixture::data( TronFixture::call( $w['server'], $s, 'skill_fork', array( 'name' => 'example.centre.labels', 'new_name' => 'local.labels.main',
                                                                                         'changes' => array( 'title' => 'Labels on main' ) ) ) );
        $row = $store->draftById( $f['draft_id'] );
        $m = json_decode( $row['manifest'], true );
        $this->assertSame( 'example.centre.labels', $m['derived_from']['name'] );
        $this->assertSame( 'example-centre', $m['derived_from']['pack'] );
        $this->assertSame( 'GPL-2.0-or-later', $m['license'] );
        $this->assertSame( 'local', $row['pack'] );
        $this->assertSame( 'Labels on main', $m['title'] );

        $store->setDraftState( $id, 'approved', TronFixture::ADMIN );
        $all = tronSkillProgram::all( tronSkillProgram::env( $w['server'] ) );
        $this->assertSame( 'live', $all['local.labels.ops']['state'] );
    }

    public function testCanariesNeverReachAnAnswer(): void
    {
        $w = $this->world( true );
        $d = self::manifest();
        $d['name'] = 'local.ticket.bodies';
        $d['requires']['units'] = array( 'centre_ticket_list', 'centre_ticket_get' );
        $d['steps'] = array(
            array( 'id' => 'list', 'unit' => 'centre_ticket_list', 'args' => array( 'board' => '$param.board', 'limit' => 50 ) ),
            array( 'id' => 'full', 'op' => 'each', 'from' => '$steps.list.items', 'unit' => 'centre_ticket_get', 'args' => array( 'id' => '$item.id' ), 'max' => 50 ),
        );
        $d['output'] = array( 'value' => array( 'tickets' => '$steps.full' ), 'schema' => array( 'type' => 'object', 'properties' => array( 'tickets' => array( 'type' => 'array' ) ) ) );
        $m = tronSkillManifest::fromArray( $d );
        foreach ( array( 'main', 'ops' ) as $board )
        {
            $r = ( new tronSkillRunner( $w['server'], $w['session'] ) )->run( $m, array( 'board' => $board ) );
            $text = json_encode( $r['output'] );
            foreach ( TronSkillFixtures::CANARIES as $c )
                $this->assertNotContains( $c, $text, "canary on $board" );
        }
        $p = tronSkillValidator::prove( $w['server'], $w['session'], $m );
        $this->assertSame( 'pass', $p['result'] );
    }

    public function testTheAdministrationApprovesRevokesSwitchesAndForks(): void
    {
        $w = $this->world( false );
        $audit = new tronAuditMemory();
        $c = new tronAdminContext( $w['db'], self::exampleSettings(), new tronArrayUserDirectory( TronFixture::users() ), array(
            'extensions' => array( 'expskillsexample' => $w['ext'] ), 'clock' => function () { return TronFixture::NOW; },
            'audit' => $audit, 'actor' => array( 'id' => TronFixture::ADMIN, 'login' => 'admin' ) ) );
        $a = new tronAdminSkillsData( $c, $w['server'], TronFixture::session( TronFixture::ADMIN ) );
        $d = $a->data( tronAdminSkillsData::filtersFrom( array( 'pack' => 'example-centre', 'state' => 'ready', 'bogus' => 1 ) ) );
        $this->assertSame( 1, $d['total'] );
        $this->assertSame( 'tron/skills/(pack)/example-centre/(state)/ready', tronAdminSkillsData::base( $d['filters'] ) );
        $packs = array_column( $d['packs'], null, 'id' );
        $this->assertSame( '1.0.0.3', $packs['example-centre']['provider']['version'] );
        $one = $a->skill( 'example.centre.labels' );
        $this->assertSame( '--board, -b', $one['parameters'][0]['option'] );
        $this->assertContains( 'xsh tron:skill:run example.centre.labels --board main', $one['examples'][0]['cli'] );

        $this->assertSame( 'example.centre.labels is approved and live.', $a->approve( 'example.centre.labels' )['notice'] ?? '' );
        $this->assertSame( 'live', $a->skill( 'example.centre.labels' )['state'] );
        $this->assertContains( 'admin/skill-approve', array_column( $audit->entries, 'method' ) );
        $this->assertContains( 'revoked', $a->revoke( 'example.centre.labels' )['notice'] );
        $this->assertSame( 'ready', $a->skill( 'example.centre.labels' )['state'] );
        $a->setPack( 'example-centre', false );
        $this->assertSame( 'disabled', $a->skill( 'example.centre.labels' )['state'] );
        $a->setPack( 'example-centre', true );
        $f = $a->fork( 'example.centre.labels', 'local.example.labels', 'local' );
        $this->assertTrue( isset( $f['draft_id'] ), 'forked' );
        $bad = $a->fork( 'example.centre.labels', 'Bad Name', 'local' );
        $this->assertContains( 'name: lower-case dotted', $bad['error'] ?? '' );
        $this->assertSame( 'local.example.labels is approved and live.', $a->approve( 'local.example.labels', $f['draft_id'] )['notice'] ?? '' );

        // approving proves the skill as the administrator: one whose roles do not allow the units cannot approve it
        $weak = new tronAdminSkillsData( $c, $w['server'], TronFixture::session( TronFixture::USEONLY ) );
        $this->assertContains( 'did not pass', $weak->approve( 'example.centre.labels' )['error'] ?? '' );
    }

    /**
     * Approving proves the skill with the administrator's own roles: an all-modules role (module and function "*") and a
     * tron-wide role (module tron, function "*") grant every tron
     * policy the units need, as the kernel's check does; a limited centre_read does not (the unit takes no limited
     * grant); the policy simulator gives the same answer as the access checker for each of them.
     */
    public function testApprovalHonoursWildcardRolesLikeTheKernel(): void
    {
        $w = $this->world( false );
        $access = new tronRoleAccessChecker(
            array( 'all' => array( '*/*' ), 'tronall' => array( 'tron/*' ), 'limitedread' => array( 'tron/use', 'tron/centre_read:limited' ) ),
            array( 21 => array( 'all' ), 22 => array( 'tronall' ), 23 => array( 'limitedread' ) ) );
        $users = array( 21 => 'starstar', 22 => 'tronstar', 23 => 'limitedreader' );
        $f = TronFixture::build( array( 'db' => $w['db'], 'access' => $access, 'settings' => self::exampleSettings(),
                                        'extensions' => array( 'expskillsexample' => $w['ext'] ) ) );
        $m = tronSkillProgram::find( tronSkillProgram::env( $f['server'] ), 'example.centre.labels' )['manifest'];
        $c = new tronAdminContext( $w['db'], self::exampleSettings(), new tronArrayUserDirectory( TronFixture::users() ), array(
            'extensions' => array( 'expskillsexample' => $w['ext'] ), 'clock' => function () { return TronFixture::NOW; },
            'actor' => array( 'id' => 21, 'login' => 'starstar' ) ) );
        foreach ( $users as $id => $login )
        {
            $session = new tronSession( new tronPrincipal( $id, $login ), 'admin', false, '2025-11-25', 'admin' );
            $expect = $id !== 23;
            $proof = tronSkillValidator::prove( $f['server'], $session, $m );
            $this->assertSame( $expect ? 'pass' : 'fail', $proof['result'], "$login proves the skill" );
            foreach ( tronSkillRunner::simulate( $f['server'], $session, $m )['checks'] as $chk )
            {
                list( $mod, $fn ) = explode( '/', $chk['policy'] );
                $this->assertSame( $access->check( $session->principal, $mod, $fn ) === tronAccessChecker::YES, $chk['allowed'], "$login {$chk['policy']}: the simulation is the access checker's answer" );
            }
            $r = TronFixture::data( TronFixture::call( $f['server'], $session, 'tron_policy_check', array( 'tool' => 'centre_ticket_list' ) ) );
            $this->assertSame( $expect, $r['checks'][0]['allowed'], "$login: tron_policy_check agrees" );
            $a = ( new tronAdminSkillsData( $c, $f['server'], $session ) )->approve( 'example.centre.labels' );
            $this->assertSame( $expect, isset( $a['notice'] ), "$login approves: " . json_encode( $a['error'] ?? '' ) );
        }

        // without a prover outside an installation there is no role engine to ask: a clear refusal, never "may not run"
        $none = ( new tronAdminSkillsData( $c ) )->approve( 'example.centre.labels' );
        $this->assertContains( 'needs a server that checks the administrator', $none['error'] ?? '' );
    }

    public function testEveryShippedPackKeepsTheStandard(): void
    {
        $dirs = glob( dirname( __DIR__, 2 ) . '/share/skills/*/pack.json' ) ?: array();
        $this->assertTrue( count( $dirs ) > 0, 'tron ships packs' );
        $names = array();
        foreach ( $dirs as $p )
        {
            $pack = tronSkillPack::read( dirname( $p ) );
            foreach ( $pack->skills as $n => $m )
            {
                $this->assertFalse( isset( $names[$n] ), "$n in one pack only" );
                $names[$n] = true;
                $this->assertTrue( strlen( $m->toolName() ) <= 64, "$n: tool name fits" );
            }
        }
    }
}
