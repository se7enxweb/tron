<?php
/**
 * The activity journal on SQLite: sessions and steps from real tools/call traffic, intent and notes, objects, the
 * narrative, the hash chain and its tamper detection, redaction of canary secrets, background jobs, HTTP sessions
 * within the idle window, retention, the journal tools and the administration's Activity data.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

final class TronJournalTest extends TronTestCase
{
    const CANARIES = array( 'CANARY-PW-7731', 'CANARY-KEY-9902', 'CANARY-TEXT-4410', 'Qx9CanaryTokenSecretValue12345678' );

    /** @var array */ private $f;
    /** @var tronJournal */ private $j;

    public function setUp(): void
    {
        $this->f = TronFixture::build( array( 'settings' => self::settings() ) );
        $this->j = new tronJournal( $this->f['db'], self::settings()['JournalSettings'], function () { return TronFixture::NOW; }, 'node-a' );
    }

    private static function settings( array $journal = array() ): array
    {
        return array( 'JournalSettings' => $journal + array( 'Enabled' => 'enabled', 'IdleMinutes' => 30, 'RetentionDays' => 30 ) );
    }

    private function rpc( tronSession $s, string $method, array $params = array() ): array
    {
        return $this->f['server']->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ), $s );
    }

    private function call( tronSession $s, string $tool, array $args = array(), array $meta = array() ): array
    {
        $p = array( 'name' => $tool, 'arguments' => $args );
        if ( $meta )
            $p['_meta'] = $meta;
        return $this->rpc( $s, 'tools/call', $p );
    }

    /** An editor's stdio session: initialize, two reads, one write, a refusal, invalid arguments. */
    private function editorSession(): tronSession
    {
        $s = new tronSession( new tronPrincipal( TronFixture::EDITOR, 'editor', 7 ), 'stdio', true, null, 'stdio' );
        $this->rpc( $s, 'initialize', array( 'protocolVersion' => '2025-11-25', 'capabilities' => new stdClass(), 'clientInfo' => array( 'name' => 'Claude Code', 'version' => '2.1.0' ) ) );
        $this->call( $s, 'tron_journal_note', array( 'kind' => 'intent', 'title' => 'Tidy the inbox', 'text' => 'Close what is done, file the rest.' ) );
        $this->call( $s, 'tron_whoami' );
        $this->call( $s, 'centre_ticket_create', array( 'title' => 'Deploy notes', 'body' => 'see runbook' ) );
        $this->call( $s, 'centre_ticket_get', array( 'id' => 1 ) );
        $this->call( $s, 'centre_decision_record', array( 'id' => 1, 'decision' => 'yes' ) );
        $this->call( $s, 'centre_ticket_get', array( 'nope' => 1 ) );
        $this->call( $s, 'tron_journal_note', array( 'kind' => 'milestone', 'text' => 'Inbox triaged' ) );
        return $s;
    }

    public function testSessionAndStepsFromRealCalls(): void
    {
        $s = $this->editorSession();
        $this->assertTrue( $s->journal instanceof tronJournalHandle, 'initialize attached a journal handle' );
        $list = $this->j->sessions( array() );
        $this->assertSame( 1, $list['total'] );
        $row = $list['rows'][0];
        $this->assertSame( 'Claude Code', $row['client_name'] );
        $this->assertSame( '2.1.0', $row['client_version'] );
        $this->assertSame( 'editor', $row['login'] );
        $this->assertSame( 7, $row['token_id'] );
        $this->assertSame( 'stdio', $row['transport'] );
        $this->assertSame( 'Tidy the inbox', $row['title'] );
        $this->assertSame( 'Close what is done, file the rest.', $row['intent'] );
        $this->assertSame( 1, $row['writes'] );
        $this->assertSame( 2, $row['refused'], 'the policy refusal and the invalid arguments' );

        $steps = $this->j->steps( $row['id'] );
        $kinds = array_column( $steps, 'kind' );
        $this->assertSame( 'start', $kinds[0] );
        $this->assertContains( 'intent', $kinds );
        $this->assertContains( 'milestone', $kinds );
        $byOutcome = array();
        foreach ( $steps as $st )
            if ( $st['kind'] === 'call' )
                $byOutcome[$st['tool'] . ' ' . $st['outcome']] = $st;
        $started = $byOutcome['centre_ticket_create started'];
        $done = $byOutcome['centre_ticket_create ok'];
        $this->assertSame( 1, $started['write_call'] );
        $this->assertSame( $started['seq'], $done['ref_seq'], 'the outcome points at its started step' );
        $this->assertContains( array( 'kind' => 'ticket', 'ref' => '1', 'action' => 'write' ), $done['objects_list'] );
        $this->assertContains( 'id: 1', $done['result'] );
        $this->assertContains( array( 'kind' => 'ticket', 'ref' => '1', 'action' => 'read' ), $byOutcome['centre_ticket_get ok']['objects_list'] );
        $this->assertSame( 'policy', $byOutcome['centre_decision_record refused']['code'] );
        $this->assertContains( 'centre_decide', $byOutcome['centre_decision_record refused']['result'] );
        $this->assertSame( 'invalid_arguments', $byOutcome['centre_ticket_get refused']['code'] );
        $this->assertTrue( $this->j->verify( $row['id'] )['ok'] );
    }

    public function testAuditEntriesCarryTheSessionKey(): void
    {
        $s = $this->editorSession();
        $entries = $this->f['audit']->entries ?? array();
        $this->assertTrue( count( $entries ) > 0, 'the memory audit has entries' );
        $line = json_encode( $entries[0] );
        $this->assertContains( '"journal":"' . $s->journal->key() . '"', $line, 'JSON of an audit entry names the journal session' );
    }

    public function testNarrativeReadsLikeAStory(): void
    {
        $this->editorSession();
        $row = $this->j->sessions( array() )['rows'][0];
        $this->j->end( $row['id'], 'stdin closed' );
        $row = $this->j->session( $row['id'] );
        $n = new tronJournalNarrative( null, array( 'centre_ticket_create' => 'Create a ticket or card', 'centre_ticket_get' => 'Get a ticket' ) );
        $text = $n->text( $row, $this->j->steps( $row['id'] ), $this->j->verify( $row['id'] ) );
        $this->assertContains( 'Claude Code 2.1.0 (as editor, token #7) started a session over stdio on ', $text );
        $this->assertContains( 'it said what it is doing: “Tidy the inbox”. Close what is done', $text );
        $this->assertContains( 'it changed ticket 1 with Create a ticket or card (centre_ticket_create)', $text );
        $this->assertContains( 'used Get a ticket (centre_ticket_get) on ticket 1', $text );
        $this->assertContains( 'the call to centre_decision_record was refused: needs tron/centre_decide', $text );
        $this->assertContains( 'reached a milestone: “Inbox triaged”', $text );
        $this->assertContains( 'The session ended at', $text );
        $this->assertContains( '(stdin closed)', $text );
        $this->assertContains( 'The hash chain of its', $text );
        $md = $n->markdown( $row, $this->j->steps( $row['id'] ), $this->j->verify( $row['id'] ) );
        $this->assertContains( '## Timeline', $md );
        $this->assertContains( '| Client | Claude Code 2.1.0 |', $md );
        $json = json_decode( $n->json( $row, $this->j->steps( $row['id'] ), null ), true );
        $this->assertSame( $row['id'], $json['session']['id'] );
        $this->assertSame( 'start', $json['steps'][0]['kind'] );
        $timeline = $n->timeline( $this->j->steps( $row['id'] ) );
        $this->assertSame( 0, count( array_filter( $timeline, function ( $r ) { return $r['outcome'] === 'started'; } ) ), 'a finished write is one row' );
    }

    public function testHashChainDetectsTampering(): void
    {
        $this->editorSession();
        $id = $this->j->sessions( array() )['rows'][0]['id'];
        $db = $this->f['db'];
        $this->assertTrue( $this->j->verify( $id )['ok'] );

        // a changed result
        $db->execute( 'UPDATE tron_journal_step SET result = :r WHERE session_id = :s AND seq = 5', array( 'r' => 'nothing happened', 's' => $id ) );
        $v = $this->j->verify( $id );
        $this->assertFalse( $v['ok'] );
        $this->assertSame( 5, $v['broken_at'] );
        $this->assertContains( 'changed after it was written', $v['reason'] );
    }

    public function testHashChainDetectsRemovedAndSwappedRows(): void
    {
        $this->editorSession();
        $id = $this->j->sessions( array() )['rows'][0]['id'];
        $db = $this->f['db'];
        $n = (int)$this->j->session( $id )['steps'];

        $db->execute( 'DELETE FROM tron_journal_step WHERE session_id = :s AND seq = :n', array( 's' => $id, 'n' => $n ) );
        $v = $this->j->verify( $id );
        $this->assertFalse( $v['ok'], 'the last step removed' );
        $this->assertContains( 'the session expects', $v['reason'] );

        $this->setUp();
        $this->editorSession();
        $id = $this->j->sessions( array() )['rows'][0]['id'];
        $this->f['db']->execute( 'DELETE FROM tron_journal_step WHERE session_id = :s AND seq = 3', array( 's' => $id ) );
        $this->assertSame( 3, $this->j->verify( $id )['broken_at'], 'a step in the middle removed' );

        $this->setUp();
        $this->editorSession();
        $id = $this->j->sessions( array() )['rows'][0]['id'];
        $this->f['db']->execute( 'UPDATE tron_journal_session SET login = :l WHERE id = :s', array( 'l' => 'someone-else', 's' => $id ) );
        $v = $this->j->verify( $id );
        $this->assertFalse( $v['ok'], 'the session row rewritten' );
        $this->assertContains( 'login', $v['reason'] );
    }

    public function testCanarySecretsNeverReachTheJournal(): void
    {
        $s = new tronSession( new tronPrincipal( TronFixture::ADMIN, 'admin' ), 'stdio', false, '2025-11-25', 'stdio' );
        $this->call( $s, 'centre_ticket_create', array( 'title' => 'Rotate password=' . self::CANARIES[0],
            'body' => 'api_key: ' . self::CANARIES[1] . ' and tron_abcdef12_' . self::CANARIES[3] . ' and Bearer ' . self::CANARIES[3] ) );
        $this->call( $s, 'tron_journal_note', array( 'text' => 'the secret = ' . self::CANARIES[2] . ' must not show' ) );
        $this->call( $s, 'centre_ticket_get', array( 'id' => 1 ) );
        $h = $s->journal;
        $h->diff( array( 'password' => self::CANARIES[0], 'after' => 'token: ' . self::CANARIES[1] ) );
        $this->call( $s, 'centre_ticket_comment', array( 'id' => 1, 'body' => 'ok' ) );
        $job = $this->j->job( 'canary job', array( 'login' => 'admin' ) );
        $job->checkpoint( 'cp', array( 'api_key' => self::CANARIES[1] ) );
        $job->finish( 'ok', 'done, password=' . self::CANARIES[0] );

        $dump = '';
        foreach ( tronJournalSchema::names() as $t )
            foreach ( $this->f['db']->select( 'SELECT * FROM ' . $t ) as $row )
                $dump .= json_encode( $row ) . "\n";
        $this->assertContains( 'Rotate password', $dump, 'the journal did record the calls' );
        foreach ( self::CANARIES as $c )
            $this->assertNotContains( $c, $dump, "canary $c" );
        $n = new tronJournalNarrative();
        foreach ( $this->j->sessions( array() )['rows'] as $row )
            foreach ( self::CANARIES as $c )
                $this->assertNotContains( $c, $n->json( $row, $this->j->steps( $row['id'] ), $this->j->verify( $row['id'] ) ), "canary $c in the export" );
    }

    public function testBackgroundJobTellsWhatItDid(): void
    {
        $s = $this->editorSession();
        $job = $s->journal->job( 'reindex the media site' );
        $job->progress( 40, '400 of 1000 nodes' );
        $job->checkpoint( 'batch 4 done', array( 'last' => 400 ), array( 'node:400' ) );
        $job->finish( 'ok', '1000 nodes reindexed', array( 'nodes' => 1000 ) );
        $jobs = $this->j->sessions( array( 'kind' => 'job' ) );
        $this->assertSame( 1, $jobs['total'] );
        $row = $jobs['rows'][0];
        $this->assertSame( $s->journal->id(), $row['parent_id'] );
        $this->assertSame( 'ok', $row['status'] );
        $this->assertTrue( $row['ended'] > 0 );
        $text = ( new tronJournalNarrative() )->text( $row, $this->j->steps( $row['id'] ), $this->j->verify( $row['id'] ) );
        $this->assertContains( 'the background job “reindex the media site” started (as editor)', $text );
        $this->assertContains( 'the job was 40% done. 400 of 1000 nodes.', $text );
        $this->assertContains( 'reached the checkpoint “batch 4 done”', $text );
        $this->assertContains( 'the job finished: 1000 nodes reindexed', $text );
        $this->assertSame( 1, $this->j->objectHistory( 'node:400' )['total'] );
    }

    public function testHttpRequestsWithinTheIdleWindowAreOneSession(): void
    {
        $p = new tronPrincipal( TronFixture::ADMIN, 'admin', 3 );
        foreach ( array( 1, 2, 3 ) as $i )
            $this->call( new tronSession( $p, 'http', false, '2025-11-25', '192.0.2.1' ), 'tron_whoami' );
        $this->assertSame( 1, $this->j->sessions( array( 'transport' => 'http' ) )['total'], 'three requests, one session' );
        $this->call( new tronSession( new tronPrincipal( TronFixture::ADMIN, 'admin', 4 ), 'http', false, '2025-11-25', '192.0.2.1' ), 'tron_whoami' );
        $this->assertSame( 2, $this->j->sessions( array( 'transport' => 'http' ) )['total'], 'another token, another session' );
        $id = $this->j->sessions( array( 'transport' => 'http' ) )['rows'][1]['id'];
        $this->f['db']->execute( 'UPDATE tron_journal_session SET last_activity = :t WHERE id = :id', array( 't' => TronFixture::NOW - 3600, 'id' => $id ) );
        $this->assertSame( 1, $this->j->closeIdle(), 'the idle session is closed, the active one stays open' );
    }

    public function testSearchAndObjectHistory(): void
    {
        $this->editorSession();
        $this->assertSame( 2, $this->j->objectHistory( 'ticket:1' )['total'], 'the create (its answer names ticket 1) and the get' );
        $this->assertSame( 1, $this->j->search( array( 'tool' => 'centre_ticket_create', 'outcome' => 'ok' ) )['total'] );
        $this->assertSame( 2, $this->j->search( array( 'outcome' => 'refused' ) )['total'] );
        $this->assertSame( 3, $this->j->search( array( 'text' => 'deploy notes' ) )['total'], 'the create (started, ok) and the get that read it back' );
        $this->assertSame( 1, $this->j->sessions( array( 'object' => 'ticket:1' ) )['total'] );
        $this->assertSame( 0, $this->j->sessions( array( 'user' => 'viewer' ) )['total'] );
        $this->assertSame( 1, $this->j->sessions( array( 'from' => '2026-11-24', 'to' => '2026-11-24' ) )['total'] );
        $this->assertSame( 0, $this->j->sessions( array( 'from' => '2026-11-25' ) )['total'] );
    }

    public function testJournalToolsRespectOwnership(): void
    {
        $this->editorSession();
        $viewer = new tronSession( new tronPrincipal( TronFixture::VIEWER, 'viewer' ), 'stdio', false, '2025-11-25', 'stdio' );
        $mine = TronFixture::data( $this->call( $viewer, 'tron_journal_list' ) );
        $this->assertSame( 1, $mine['total'], 'the viewer sees only its own session' );
        $this->assertSame( 'viewer', $mine['sessions'][0]['user'] );
        $editorId = $this->j->sessions( array( 'user' => 'editor' ) )['rows'][0]['id'];
        $r = $this->call( $viewer, 'tron_journal_show', array( 'session' => $editorId ) );
        $this->assertTrue( $r['result']['isError'], 'another user\'s session is not shown' );

        $admin = new tronSession( new tronPrincipal( TronFixture::ADMIN, 'admin' ), 'stdio', false, '2025-11-25', 'stdio' );
        $all = TronFixture::data( $this->call( $admin, 'tron_journal_list', array( 'user' => 'editor' ) ) );
        $this->assertSame( 1, $all['total'] );
        $show = TronFixture::data( $this->call( $admin, 'tron_journal_show', array( 'session' => $editorId ) ) );
        $this->assertTrue( $show['verify']['ok'] );
        $this->assertContains( 'Tidy the inbox', implode( ' ', $show['narrative'] ) );
        $this->assertContains( 'Create a ticket or card (centre_ticket_create)', implode( ' ', $show['narrative'] ), 'tool titles from the registry' );
        $tl = TronFixture::data( $this->call( $admin, 'tron_journal_show', array( 'session' => $editorId, 'format' => 'timeline' ) ) );
        $this->assertSame( 'start', $tl['timeline'][0]['kind'] );
        $md = TronFixture::data( $this->call( $admin, 'tron_journal_show', array( 'session' => $editorId, 'format' => 'markdown' ) ) );
        $this->assertContains( '## Story', $md['markdown'] );
        $found = TronFixture::data( $this->call( $admin, 'tron_journal_search', array( 'object' => 'ticket:1', 'outcome' => 'ok' ) ) );
        $this->assertSame( 2, $found['total'] );
        $cur = TronFixture::data( $this->call( $admin, 'tron_journal_show', array( 'current' => true, 'format' => 'timeline' ) ) );
        $this->assertSame( 'admin', $cur['session']['user'] );
    }

    public function testDryRunAndApplyKeepTheDiff(): void
    {
        if ( !class_exists( 'tronPlanner' ) || !property_exists( 'tronTool', 'plan' ) )
        {
            $this->assertTrue( true );
            return;      // a tron without dry runs: nothing to point at
        }
        // A program whose write tool has a computed plan: it sets node 5's priority from 1 to 3
        $base = TronFixture::tmp( 'journal-dryrun' ) . '/ext_jdry';
        TronFixture::write( "$base/tron/program.json", json_encode( array( 'name' => 'jdry', 'class' => 'tronJdryProgram', 'file' => 'tron/jdry.php', 'version' => '1.0.0' ) ) );
        TronFixture::write( "$base/tron/jdry.php", "<?php\nfinal class tronJdryProgram implements tronProgram\n{\n    public function register( tronRegistry \$registry, tronEnvironment \$env ): void\n    {\n"
            . "        \$registry->tool( tronTool::define( array( 'name' => 'jdry_priority_set', 'description' => 'fixture', 'policy' => array( 'tron', 'use' ), 'write' => true,\n"
            . "            'inputSchema' => array( 'type' => 'object', 'properties' => array( 'node_id' => array( 'type' => 'integer' ), 'priority' => array( 'type' => 'integer' ) ) ),\n"
            . "            'plan' => function ( array \$a ) { return array( 'changes' => array( array( 'table' => 'node', 'op' => 'update', 'before' => array( 'priority' => 1 ), 'after' => array( 'priority' => \$a['priority'] ) ) ), 'state' => array( 'priority' => 1 ) ); },\n"
            . "            'handler' => function ( array \$a ) { return array( 'node_id' => \$a['node_id'], 'priority' => \$a['priority'] ); } ) ) );\n    }\n}\n" );
        $this->f = TronFixture::build( array( 'settings' => self::settings(), 'extensions' => array( 'ext_jdry' => $base ) ) );
        $this->j = new tronJournal( $this->f['db'], self::settings()['JournalSettings'], function () { return TronFixture::NOW; }, 'node-a' );
        $s = new tronSession( new tronPrincipal( TronFixture::ADMIN, 'admin' ), 'stdio', false, '2025-11-25', 'stdio' );
        $args = array( 'node_id' => 5, 'priority' => 3 );
        $plan = TronFixture::data( $this->call( $s, 'jdry_priority_set', $args, array( 'tron/dryRun' => true ) ) );
        $this->assertTrue( isset( $plan['plan_id'] ) && $plan['plan_id'] !== null, 'the dry run made a plan' );
        $done = $this->call( $s, 'jdry_priority_set', $args, array( 'tron/planId' => (string)$plan['plan_id'] ) );
        $this->assertFalse( $done['result']['isError'], 'the plan was applied' );
        $steps = $this->j->search( array( 'tool' => 'jdry_priority_set' ) )['rows'];
        $dry = array_values( array_filter( $steps, function ( $r ) { return $r['outcome'] === 'dry-run'; } ) );
        $applied = array_values( array_filter( $steps, function ( $r ) { return $r['outcome'] === 'ok'; } ) );
        $this->assertSame( 'plan:' . $plan['plan_id'], $dry[0]['diff_ref'] );
        $this->assertContains( '"priority":3', $dry[0]['diff'], 'the dry run step keeps the diff' );
        $this->assertSame( 'plan:' . $plan['plan_id'], $applied[0]['diff_ref'], 'the apply points at the plan' );
        $this->assertSame( $dry[0]['diff'], $applied[0]['diff'], 'and carries the planned diff' );
        $this->assertSame( 1, $applied[0]['write_call'] );
        $this->assertContains( array( 'kind' => 'node', 'ref' => '5', 'action' => 'write' ), $applied[0]['objects_list'] );
        $text = ( new tronJournalNarrative() )->text( $this->j->session( $applied[0]['session_id'] ), $this->j->steps( $applied[0]['session_id'] ) );
        $this->assertContains( 'Its diff is recorded (plan:' . $plan['plan_id'] . ')', $text );
    }

    public function testRetentionArchivesThenRemoves(): void
    {
        $this->editorSession();
        $id = $this->j->sessions( array() )['rows'][0]['id'];
        $this->f['db']->execute( 'UPDATE tron_journal_session SET last_activity = :t WHERE id = :id', array( 't' => TronFixture::NOW - 40 * 86400, 'id' => $id ) );
        $dry = $this->j->rotate( true );
        $this->assertSame( 1, $dry['sessions'] );
        $this->assertSame( 1, $this->j->sessions( array() )['total'], 'a dry run removes nothing' );
        $dir = TronFixture::tmp( 'journal-archive' );
        foreach ( glob( "$dir/*.jsonl" ) ?: array() as $old )
            unlink( $old );
        $done = $this->j->rotate( false, $dir );
        $this->assertSame( 1, $done['sessions'] );
        $this->assertSame( 0, $this->j->sessions( array() )['total'] );
        $this->assertSame( 0, (int)$this->f['db']->select( 'SELECT count(*) AS n FROM tron_journal_step' )[0]['n'] );
        $line = json_decode( (string)file_get_contents( $done['archive'] ), true );
        $this->assertSame( 'Tidy the inbox', $line['session']['title'] );
        $this->assertTrue( $line['verify']['ok'], 'archived with its chain checked' );
    }

    public function testOffWithoutTheSetting(): void
    {
        $f = TronFixture::build();
        $s = TronFixture::session( TronFixture::ADMIN );
        TronFixture::call( $f['server'], $s, 'tron_whoami' );
        $this->assertSame( null, $s->journal, 'no journal unless [JournalSettings] Enabled=enabled' );
        $this->assertSame( 0, (int)$f['db']->select( 'SELECT count(*) AS n FROM tron_journal_session' )[0]['n'] );
        $this->assertSame( null, $f['server']->registry()->findTool( 'tron_journal_list' ) );
    }

    public function testAnswersWithJsonObjects(): void
    {
        $s = new tronSession( new tronPrincipal( TronFixture::ADMIN, 'admin' ), 'stdio', false, '2025-11-25', 'stdio' );
        $this->call( $s, 'tron_whoami' );
        $answer = (object)array( 'node_id' => 7, 'items' => array( (object)array( 'a' => 1 ) ), 'meta' => new stdClass(), 'password' => 'CANARY-OBJ-1' );
        tronJournalHandle::result( $s, $answer );
        $s->journal->record( array( 'created' => TronFixture::NOW, 'method' => 'tools/call', 'target' => 'node_read', 'program' => 'node',
                                    'outcome' => 'ok', 'duration_ms' => 3, 'detail' => json_encode( array( 'args' => array( 'node_id' => 7 ), 'note' => '' ) ) ) );
        $steps = $this->j->steps( $s->journal->id() );
        $last = end( $steps );
        $this->assertSame( 'ok', $last['outcome'] );
        $this->assertContains( 'items: 1 item', $last['result'] );
        $this->assertNotContains( 'CANARY-OBJ-1', $last['result'] );
        $this->assertContains( array( 'kind' => 'node', 'ref' => '7', 'action' => 'read' ), $last['objects_list'] );
        $this->assertSame( array( 'node_id' => 5 ), tronJournalHandle::plain( (object)array( 'node_id' => 5 ) ) );
        $this->assertContains( 'meta: ', tronJournalObjects::summary( (object)array( 'meta' => new stdClass() ) ) );
    }

    public function testObjectsFromArguments(): void
    {
        $o = tronJournalObjects::from( array( 'node_id' => 123, 'layout_id' => '4', 'ini' => 'site.ini', 'group' => 'SiteSettings', 'key' => 'IndexPage',
                                              'path' => 'var/log/x.log', 'thing_id' => 9, 'flag' => true ), array( 'object_id' => 57 ), 'write', 'exp_node_move' );
        $refs = array_map( function ( $x ) { return $x['kind'] . ':' . $x['ref'] . ':' . $x['action']; }, $o );
        $this->assertContains( 'node:123:write', $refs );
        $this->assertContains( 'layout:4:write', $refs );
        $this->assertContains( 'ini:site.ini[SiteSettings]IndexPage:write', $refs );
        $this->assertContains( 'file:var/log/x.log:write', $refs );
        $this->assertContains( 'thing:9:write', $refs );
        $this->assertContains( 'object:57:write', $refs );
        $ini = tronJournalObjects::from( array( 'file' => 'site', 'block' => 'SiteSettings', 'setting' => 'IndexPage' ), null, 'read', 'ini_resolve' );
        $this->assertSame( array( 'kind' => 'ini', 'ref' => 'site.ini[SiteSettings]IndexPage', 'action' => 'read' ), $ini[0] );
        $this->assertCount( 1, $ini, 'the INI file is one object, not a file too' );
        $this->assertSame( array( 'node', '123' ), tronJournalObjects::parse( 'node:123' ) );
        $this->assertSame( '3 items', tronJournalObjects::summary( array( 1, 2, 3 ) ) );
        $this->assertNotContains( 'hunter2', tronJournalObjects::summary( array( 'secret_answer' => 'hunter2' ) ) );
    }

    public function testAdminActivityData(): void
    {
        $this->editorSession();
        $c = new tronAdminContext( $this->f['db'], self::settings(), new tronArrayUserDirectory( TronFixture::users() ),
                                   array( 'clock' => function () { return TronFixture::NOW; } ) );
        $d = new tronAdminActivityData( $c );
        $list = $d->page( array() );
        $this->assertSame( 'list', $list['mode'] );
        $this->assertSame( 1, $list['total'] );
        $this->assertSame( 'Tidy the inbox', $list['sessions'][0]['title'] );
        $filtered = $d->page( array( 'outcome' => 'refused', 'user' => 'editor' ) );
        $this->assertSame( 1, $filtered['total'] );
        $this->assertSame( 0, $d->page( array( 'outcome' => 'bogus', 'user' => 'nobody' ) )['total'] );
        $id = $list['sessions'][0]['id'];
        $detail = $d->page( array( 'session' => (string)$id ) );
        $this->assertSame( 'session', $detail['mode'] );
        $this->assertTrue( $detail['verify']['ok'] );
        $this->assertTrue( count( $detail['timeline'] ) > 5 );
        $this->assertContains( 'Tidy the inbox', implode( ' ', $detail['narrative'] ) );
        $obj = $d->page( array( 'object' => tronAdminActivityData::encode( 'ticket:1' ) ) );
        $this->assertSame( 'object', $obj['mode'] );
        $this->assertSame( 2, $obj['total'] );
        $md = $d->export( $id, 'markdown' );
        $this->assertSame( 'text/markdown; charset=utf-8', $md['type'] );
        $this->assertContains( '## Timeline', $md['body'] );
        $this->assertContains( 'tron-session-' . $id . '.md', $md['filename'] );
        $this->assertSame( null, $d->export( 999, 'json' ) );
    }
}
