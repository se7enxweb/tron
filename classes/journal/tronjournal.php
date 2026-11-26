<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The activity journal: what tron did, told so that someone can read it later and understand it.
 *
 * The audit log (tron_audit) answers "which call, by whom, with what outcome". The journal builds on the same entries
 * and adds what a later reader needs: the session each call belonged to (client, user, token, transport, node, start
 * and end), what the agent said it was doing (intent, notes, milestones), what each call touched (nodes, objects,
 * layouts, INI keys, files), what came back, the diff or plan of a write, and what background jobs did. Every step is
 * chained to the one before it by a SHA-256 hash, so a changed, removed or inserted row shows up (verify()).
 *
 * Settings: tron.ini [JournalSettings] (doc/activity-journal.md). Everything it stores is redacted like the audit
 * (tronRedactor): secret-looking keys, tron tokens, bearer headers and "password=..." in text never reach a row.
 */
final class tronJournal
{
    const STEP_KINDS = array( 'start', 'call', 'note', 'intent', 'milestone', 'progress', 'checkpoint', 'result', 'end' );
    const OUTCOMES = array( 'ok', 'started', 'dry-run', 'refused', 'error' );
    const DEFAULTS = array(
        'Enabled' => 'enabled', 'IdleMinutes' => 30, 'RetentionDays' => 180, 'Archive' => 'enabled',
        'ArchiveDir' => 'var/log/tron-journal', 'StoreDiffs' => 'enabled', 'MaxDiffBytes' => 20000,
        'MaxResultChars' => 300, 'MaxInputBytes' => 8000, 'Required' => 'disabled',
    );

    /** @var tronDb */ private $db;
    /** @var array */ private $s;
    /** @var callable|null */ private $clock;
    /** @var string */ private $node;

    public function __construct( tronDb $db, array $settings = array(), ?callable $clock = null, string $node = '' )
    {
        $this->db = $db;
        $this->s = $settings + self::DEFAULTS;
        $this->clock = $clock;
        $this->node = $node !== '' ? $node : (string)( function_exists( 'gethostname' ) ? gethostname() : '' );
    }

    /**
     * The journal of an environment: its "db" service and tron.ini [JournalSettings]. Null when the journal is not switched
     * on (Enabled=enabled; tron.ini ships it on, a bare settings array leaves it off), there is no database, or its tables
     * are not installed yet (tron.php install).
     */
    public static function fromEnvironment( tronEnvironment $env ): ?self
    {
        $settings = (array)( $env->settings()['JournalSettings'] ?? array() );
        if ( ( $settings['Enabled'] ?? 'disabled' ) !== 'enabled' || !$env->has( 'db' ) )
            return null;
        $db = $env->get( 'db' );
        if ( !$db instanceof tronDb || !$db->tableExists( 'tron_journal_step' ) )
            return null;
        return new self( $db, $settings, function () use ( $env ) { return $env->now(); } );
    }

    public function db(): tronDb
    {
        return $this->db;
    }

    public function setting( string $name )
    {
        return $this->s[$name] ?? null;
    }

    public function now(): int
    {
        return $this->clock ? (int)call_user_func( $this->clock ) : time();
    }

    public function node(): string
    {
        return $this->node;
    }

    // ------------------------------------------------------------------ sessions

    /**
     * Opens a session. $meta: kind (mcp|job), parent_id, user_id, login, token_id, transport, client_name,
     * client_version, protocol, remote, title. Its first step ("start") holds the same facts, inside the hash chain.
     * @return array the session row
     */
    public function open( array $meta ): array
    {
        $now = $this->now();
        $row = array(
            'session_key' => bin2hex( random_bytes( 16 ) ), 'kind' => (string)( $meta['kind'] ?? 'mcp' ),
            'parent_id' => (int)( $meta['parent_id'] ?? 0 ), 'user_id' => (int)( $meta['user_id'] ?? 0 ),
            'login' => self::cut( (string)( $meta['login'] ?? '' ), 150 ), 'token_id' => (int)( $meta['token_id'] ?? 0 ),
            'transport' => self::cut( (string)( $meta['transport'] ?? '' ), 16 ),
            'client_name' => self::cut( (string)tronRedactor::redact( (string)( $meta['client_name'] ?? '' ) ), 100 ),
            'client_version' => self::cut( (string)tronRedactor::redact( (string)( $meta['client_version'] ?? '' ) ), 50 ),
            'protocol' => self::cut( (string)( $meta['protocol'] ?? '' ), 16 ), 'node' => self::cut( $this->node, 100 ),
            'remote' => self::cut( (string)( $meta['remote'] ?? '' ), 64 ),
            'title' => self::cut( (string)tronRedactor::redact( (string)( $meta['title'] ?? '' ) ), 255 ), 'intent' => '',
            'started' => $now, 'last_activity' => $now, 'ended' => 0, 'end_reason' => '',
            'status' => 'open', 'steps' => 0, 'writes' => 0, 'errors' => 0, 'refused' => 0, 'head_hash' => '',
        );
        $row['id'] = $this->db->insert( 'tron_journal_session', $row );
        $this->append( $row['id'], array( 'kind' => 'start', 'input' => self::json( self::startFacts( $row ) ) ) );
        return $this->session( $row['id'] );
    }

    /**
     * The open HTTP session of this user and token, if it was active within [JournalSettings] IdleMinutes; else a new
     * one. HTTP is stateless (every request is its own tronSession), so this is what makes one client's requests one
     * story.
     */
    public function openOrResume( array $meta ): array
    {
        $idle = max( 1, (int)$this->s['IdleMinutes'] ) * 60;
        $rows = $this->db->select( 'SELECT id FROM tron_journal_session WHERE kind = :k AND transport = :t AND user_id = :u AND token_id = :tok'
                                   . ' AND status = :st AND last_activity >= :since ORDER BY last_activity DESC',
                                   array( 'k' => 'mcp', 't' => (string)( $meta['transport'] ?? '' ), 'u' => (int)( $meta['user_id'] ?? 0 ),
                                          'tok' => (int)( $meta['token_id'] ?? 0 ), 'st' => 'open', 'since' => $this->now() - $idle ), 1 );
        if ( $rows )
            return $this->session( (int)$rows[0]['id'] );
        return $this->open( $meta );
    }

    public function session( int $id ): ?array
    {
        $r = $this->db->select( 'SELECT * FROM tron_journal_session WHERE id = :id', array( 'id' => $id ), 1 );
        return $r ? self::typed( $r[0] ) : null;
    }

    public function sessionByKey( string $key ): ?array
    {
        $r = $this->db->select( 'SELECT * FROM tron_journal_session WHERE session_key = :k', array( 'k' => $key ), 1 );
        return $r ? self::typed( $r[0] ) : null;
    }

    /** Sets what the session is for (title and intent): a step "intent" in the chain, and the session row for lists. */
    public function intent( int $sessionId, string $title, string $intent = '' ): array
    {
        $title = self::cut( (string)tronRedactor::redact( trim( $title ) ), 255 );
        $intent = self::text( trim( $intent ), 2000 );
        $step = $this->append( $sessionId, array( 'kind' => 'intent', 'input' => array( 'title' => $title, 'intent' => $intent ) ) );
        $this->db->execute( 'UPDATE tron_journal_session SET title = :t, intent = :i WHERE id = :id',
                            array( 't' => $title, 'i' => $intent, 'id' => $sessionId ) );
        return $step;
    }

    /** A note or a milestone in the agent's own words. */
    public function note( int $sessionId, string $text, string $kind = 'note' ): array
    {
        if ( !in_array( $kind, array( 'note', 'milestone' ), true ) )
            $kind = 'note';
        return $this->append( $sessionId, array( 'kind' => $kind, 'input' => array( 'text' => self::text( trim( $text ), 2000 ) ) ) );
    }

    /** Ends a session (stdio closed, job finished). $status: ended, ok, error. Ending twice changes nothing. */
    public function end( int $sessionId, string $reason = '', string $status = 'ended' ): void
    {
        $s = $this->session( $sessionId );
        if ( !$s || $s['ended'] > 0 )
            return;
        $reason = self::cut( (string)tronRedactor::redact( $reason ), 255 );
        $this->append( $sessionId, array( 'kind' => 'end', 'input' => array( 'reason' => $reason, 'status' => $status ) ) );
        $this->db->execute( 'UPDATE tron_journal_session SET ended = :e, end_reason = :r, status = :s WHERE id = :id',
                            array( 'e' => $this->now(), 'r' => $reason, 's' => self::cut( $status, 16 ), 'id' => $sessionId ) );
    }

    // ------------------------------------------------------------------ steps

    /**
     * Appends one step and chains it: hash = sha256( previous hash, the step's fields ). Fields: kind, method, tool,
     * program, write_call, input (array, redacted here), result (text), outcome, code, duration_ms, objects (list of
     * array( kind, ref, action )), diff_ref, diff (array or text, redacted, cut at MaxDiffBytes), ref_seq.
     * @return array the stored step
     */
    public function append( int $sessionId, array $step ): array
    {
        $kind = in_array( $step['kind'] ?? '', self::STEP_KINDS, true ) ? $step['kind'] : 'note';
        $objects = tronJournalObjects::normalise( (array)( $step['objects'] ?? array() ), !empty( $step['write_call'] ) ? 'write' : 'read' );
        $diff = '';
        if ( isset( $step['diff'] ) && $step['diff'] !== '' && $step['diff'] !== array() && $this->s['StoreDiffs'] === 'enabled' )
            $diff = self::text( is_string( $step['diff'] ) ? $step['diff'] : self::json( tronRedactor::redact( $step['diff'] ) ), max( 200, (int)$this->s['MaxDiffBytes'] ), false );
        $input = $step['input'] ?? array();
        $row = array(
            'session_id' => $sessionId, 'created' => (int)( $step['created'] ?? $this->now() ), 'kind' => $kind,
            'method' => self::cut( (string)( $step['method'] ?? '' ), 64 ), 'tool' => self::cut( (string)( $step['tool'] ?? '' ), 255 ),
            'program' => self::cut( (string)( $step['program'] ?? '' ), 64 ), 'write_call' => empty( $step['write_call'] ) ? 0 : 1,
            'input' => self::text( is_string( $input ) ? $input : self::json( tronRedactor::redact( $input ) ), max( 500, (int)$this->s['MaxInputBytes'] ), false ),
            'result' => self::text( (string)( $step['result'] ?? '' ), max( 80, (int)$this->s['MaxResultChars'] ) ),
            'outcome' => self::cut( (string)( $step['outcome'] ?? ( $kind === 'call' ? 'ok' : '' ) ), 16 ),
            'code' => self::cut( (string)( $step['code'] ?? '' ), 64 ), 'duration_ms' => (int)( $step['duration_ms'] ?? 0 ),
            'objects' => $objects ? self::json( $objects ) : '', 'diff_ref' => self::cut( (string)tronRedactor::redact( (string)( $step['diff_ref'] ?? '' ) ), 255 ),
            'diff' => $diff, 'ref_seq' => (int)( $step['ref_seq'] ?? 0 ),
        );

        // Two HTTP requests of one session may append at once: the unique (session_id, seq) refuses the second, which
        // reads the new head and tries again.
        for ( $try = 0; ; $try++ )
        {
            $head = $this->db->select( 'SELECT seq, hash FROM tron_journal_step WHERE session_id = :s ORDER BY seq DESC', array( 's' => $sessionId ), 1 );
            $row['seq'] = $head ? (int)$head[0]['seq'] + 1 : 1;
            $row['prev_hash'] = $head ? (string)$head[0]['hash'] : self::genesis( $this->sessionKey( $sessionId ) );
            $row['hash'] = self::hash( $row['prev_hash'], $row );
            try
            {
                $row['id'] = $this->db->insert( 'tron_journal_step', $row );
                break;
            }
            catch ( tronDbException $e )
            {
                if ( $try >= 3 )
                    throw $e;
            }
        }

        foreach ( $objects as $o )
            $this->db->insert( 'tron_journal_object', array( 'session_id' => $sessionId, 'step_id' => $row['id'], 'kind' => $o['kind'],
                                                             'ref' => self::cut( $o['ref'], 255 ), 'action' => $o['action'], 'created' => $row['created'] ) );

        $counts = '';
        if ( $kind === 'call' && $row['outcome'] !== 'started' )
        {
            if ( $row['write_call'] && $row['outcome'] === 'ok' )
                $counts .= ', writes = writes + 1';
            if ( $row['outcome'] === 'error' )
                $counts .= ', errors = errors + 1';
            if ( $row['outcome'] === 'refused' )
                $counts .= ', refused = refused + 1';
        }
        // The head only moves forward (a slower concurrent append must not pull it back)
        $this->db->execute( 'UPDATE tron_journal_session SET steps = :n, head_hash = :h WHERE id = :id AND steps < :n2',
                            array( 'n' => $row['seq'], 'h' => $row['hash'], 'id' => $sessionId, 'n2' => $row['seq'] ) );
        $this->db->execute( 'UPDATE tron_journal_session SET last_activity = :t' . $counts . ' WHERE id = :id',
                            array( 't' => max( $row['created'], $this->now() ), 'id' => $sessionId ) );
        return self::typedStep( $row );
    }

    /** @return array[] the steps of a session in order */
    public function steps( int $sessionId ): array
    {
        return array_map( array( __CLASS__, 'typedStep' ), $this->db->select( 'SELECT * FROM tron_journal_step WHERE session_id = :s ORDER BY seq', array( 's' => $sessionId ) ) );
    }

    /**
     * Checks a session's chain: every step's hash from its fields and the one before it, the sequence without gaps,
     * the session row's head and step count (a removed last step), and the session facts against its start step.
     * @return array array( 'ok' => bool, 'steps' => n, 'broken_at' => seq|0, 'reason' => text )
     */
    public function verify( int $sessionId ): array
    {
        $s = $this->session( $sessionId );
        if ( !$s )
            return array( 'ok' => false, 'steps' => 0, 'broken_at' => 0, 'reason' => 'no such session' );
        $prev = self::genesis( $s['session_key'] );
        $steps = $this->db->select( 'SELECT * FROM tron_journal_step WHERE session_id = :s ORDER BY seq', array( 's' => $sessionId ) );
        $n = 0;
        foreach ( $steps as $row )
        {
            $n++;
            if ( (int)$row['seq'] !== $n )
                return self::broken( $n, count( $steps ), "step $n is missing (found step {$row['seq']})" );
            if ( (string)$row['prev_hash'] !== $prev )
                return self::broken( $n, count( $steps ), "step $n does not follow step " . ( $n - 1 ) );
            if ( self::hash( $prev, $row ) !== (string)$row['hash'] )
                return self::broken( $n, count( $steps ), "step $n was changed after it was written" );
            $prev = (string)$row['hash'];
        }
        if ( $n !== (int)$s['steps'] || $prev !== (string)$s['head_hash'] && $n > 0 )
            return self::broken( $n + 1, $n, 'the session expects ' . (int)$s['steps'] . ' steps ending in ' . substr( (string)$s['head_hash'], 0, 12 ) . '…; the last step on record is ' . $n );
        if ( $steps )
        {
            $facts = json_decode( (string)$steps[0]['input'], true );
            $now = self::startFacts( $s );
            if ( !is_array( $facts ) || $facts != $now )
                return self::broken( 1, $n, 'the session row differs from its start step (' . implode( ', ', array_keys( array_diff_assoc( array_map( 'strval', $now ), array_map( 'strval', (array)$facts ) ) ) ) . ')' );
        }
        return array( 'ok' => true, 'steps' => $n, 'broken_at' => 0, 'reason' => '' );
    }

    // ------------------------------------------------------------------ reading

    /**
     * Sessions newest first. Filters: user (login), user_id, kind, transport, status, from / to (YYYY-MM-DD or unix
     * time), tool (sessions with a call of it), outcome (sessions with a step of it), object ("node:123").
     * @return array array( 'rows' => sessions, 'total' => n )
     */
    public function sessions( array $f, int $limit = 50, int $offset = 0 ): array
    {
        list( $where, $p ) = $this->sessionWhere( $f );
        $w = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
        $total = (int)$this->db->select( 'SELECT count(*) AS n FROM tron_journal_session' . $w, $p )[0]['n'];
        $rows = $this->db->select( 'SELECT * FROM tron_journal_session' . $w . ' ORDER BY started DESC, id DESC', $p, max( 1, min( 500, $limit ) ), max( 0, $offset ) );
        return array( 'rows' => array_map( array( __CLASS__, 'typed' ), $rows ), 'total' => $total );
    }

    /**
     * Steps across sessions, newest first, with their session's login, client and title. Filters as sessions(), plus
     * kind (step kind) and text (in the input, the result or the tool name).
     * @return array array( 'rows' => steps, 'total' => n )
     */
    public function search( array $f, int $limit = 50, int $offset = 0 ): array
    {
        list( $where, $p ) = $this->sessionWhere( array_diff_key( $f, array_flip( array( 'tool', 'outcome', 'object', 'kind', 'from', 'to' ) ) ), 's.' );
        if ( isset( $f['kind'] ) && in_array( $f['kind'], array( 'mcp', 'job' ), true ) )
        {
            $where[] = 's.kind = :skind';
            $p['skind'] = $f['kind'];
        }
        else if ( isset( $f['kind'] ) && in_array( $f['kind'], self::STEP_KINDS, true ) )
        {
            $where[] = 't.kind = :kind';
            $p['kind'] = $f['kind'];
        }
        if ( isset( $f['tool'] ) && $f['tool'] !== '' )
        {
            $where[] = 't.tool = :tool';
            $p['tool'] = (string)$f['tool'];
        }
        if ( isset( $f['outcome'] ) && in_array( $f['outcome'], self::OUTCOMES, true ) )
        {
            $where[] = 't.outcome = :outcome';
            $p['outcome'] = $f['outcome'];
        }
        foreach ( array( 'from' => '>=', 'to' => '<' ) as $k => $op )
        {
            if ( isset( $f[$k] ) && ( $t = self::time( $f[$k], $k === 'to' ) ) !== null )
            {
                $where[] = "t.created $op :$k";
                $p[$k] = $t;
            }
        }
        if ( isset( $f['object'] ) && ( $o = tronJournalObjects::parse( (string)$f['object'] ) ) )
        {
            $where[] = 't.id IN (SELECT step_id FROM tron_journal_object WHERE kind = :okind AND ref = :oref)';
            $p['okind'] = $o[0];
            $p['oref'] = $o[1];
        }
        if ( isset( $f['text'] ) && trim( (string)$f['text'] ) !== '' )
        {
            $where[] = '(lower(t.input) LIKE :text OR lower(t.result) LIKE :text2 OR lower(t.tool) LIKE :text3)';
            $like = '%' . strtolower( trim( (string)$f['text'] ) ) . '%';
            $p['text'] = $p['text2'] = $p['text3'] = $like;
        }
        $w = ' FROM tron_journal_step t JOIN tron_journal_session s ON s.id = t.session_id' . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' );
        $total = (int)$this->db->select( 'SELECT count(*) AS n' . $w, $p )[0]['n'];
        $rows = $this->db->select( 'SELECT t.*, s.login AS login, s.user_id AS user_id, s.client_name AS client_name, s.title AS title, s.kind AS session_kind, s.transport AS transport'
                                   . $w . ' ORDER BY t.created DESC, t.id DESC', $p, max( 1, min( 500, $limit ) ), max( 0, $offset ) );
        return array( 'rows' => array_map( array( __CLASS__, 'typedStep' ), $rows ), 'total' => $total );
    }

    /** The diff the dry run of a plan recorded ("plan:<id>"), or null. */
    public function planDiff( string $ref ): ?string
    {
        $r = $this->db->select( 'SELECT diff FROM tron_journal_step WHERE diff_ref = :r AND outcome = :o ORDER BY id DESC', array( 'r' => $ref, 'o' => 'dry-run' ), 1 );
        return $r && (string)$r[0]['diff'] !== '' ? (string)$r[0]['diff'] : null;
    }

    /** Everything the journal knows about one object ("node:123"), newest first. */
    public function objectHistory( string $object, int $limit = 100, int $offset = 0 ): array
    {
        return $this->search( array( 'object' => $object ), $limit, $offset );
    }

    // ------------------------------------------------------------------ retention

    /**
     * Removes sessions that ended (or went idle) more than [JournalSettings] RetentionDays ago, with their steps and
     * objects. With Archive=enabled each removed session is first appended, steps included, to a JSON-lines file
     * <ArchiveDir>/tron-journal-<YYYY-MM>.jsonl, so nothing is lost without a trace. $dryRun only counts.
     * @return array array( 'sessions' => n, 'steps' => n, 'archive' => path|'' )
     */
    public function rotate( bool $dryRun = false, ?string $archiveDir = null ): array
    {
        $days = (int)$this->s['RetentionDays'];
        if ( $days <= 0 )
            return array( 'sessions' => 0, 'steps' => 0, 'archive' => '' );
        $cut = $this->now() - $days * 86400;
        $old = $this->db->select( 'SELECT * FROM tron_journal_session WHERE last_activity < :c ORDER BY id', array( 'c' => $cut ) );
        $steps = 0;
        $file = '';
        if ( $old && !$dryRun && $this->s['Archive'] === 'enabled' )
        {
            $dir = $archiveDir ?? (string)$this->s['ArchiveDir'];
            if ( !is_dir( $dir ) && !@mkdir( $dir, 0770, true ) && !is_dir( $dir ) )
                throw new RuntimeException( "Cannot create the journal archive directory $dir" );
            $file = rtrim( $dir, '/' ) . '/tron-journal-' . date( 'Y-m', $this->now() ) . '.jsonl';
        }
        foreach ( $old as $s )
        {
            $id = (int)$s['id'];
            $list = $this->steps( $id );
            $steps += count( $list );
            if ( $dryRun )
                continue;
            if ( $file !== '' )
            {
                $line = self::json( array( 'session' => self::typed( $s ), 'steps' => $list, 'verify' => $this->verify( $id ), 'archived' => $this->now() ) ) . "\n";
                if ( @file_put_contents( $file, $line, FILE_APPEND | LOCK_EX ) === false )
                    throw new RuntimeException( "Cannot write the journal archive $file; nothing was removed" );
                tronAuditFile::shareWithDirectoryOwner( $file );
            }
            $this->db->execute( 'DELETE FROM tron_journal_object WHERE session_id = :s', array( 's' => $id ) );
            $this->db->execute( 'DELETE FROM tron_journal_step WHERE session_id = :s', array( 's' => $id ) );
            $this->db->execute( 'DELETE FROM tron_journal_session WHERE id = :s', array( 's' => $id ) );
        }
        // HTTP sessions have no end of their own: one idle longer than IdleMinutes is closed
        if ( !$dryRun )
            $this->closeIdle();
        return array( 'sessions' => count( $old ), 'steps' => $steps, 'archive' => $file );
    }

    /** Ends open sessions that have been idle longer than IdleMinutes (an HTTP client that went away, a killed process). */
    public function closeIdle(): int
    {
        $idle = max( 1, (int)$this->s['IdleMinutes'] ) * 60;
        $rows = $this->db->select( 'SELECT id FROM tron_journal_session WHERE status = :st AND last_activity < :since',
                                   array( 'st' => 'open', 'since' => $this->now() - $idle ) );
        foreach ( $rows as $r )
            $this->end( (int)$r['id'], 'idle for more than ' . (int)$this->s['IdleMinutes'] . ' minutes' );
        return count( $rows );
    }

    // ------------------------------------------------------------------ jobs

    /** Starts a background job's journal (a session of kind "job"; $parentId links it to the session that queued it). */
    public function job( string $name, array $meta = array(), int $parentId = 0 ): tronJournalJob
    {
        $row = $this->open( array( 'kind' => 'job', 'title' => $name, 'parent_id' => $parentId,
                                   'transport' => (string)( $meta['transport'] ?? 'job' ) ) + $meta );
        return new tronJournalJob( $this, (int)$row['id'] );
    }

    // ------------------------------------------------------------------ helpers

    private function sessionWhere( array $f, string $prefix = '' ): array
    {
        $where = array();
        $p = array();
        if ( isset( $f['user'] ) && trim( (string)$f['user'] ) !== '' )
        {
            $where[] = $prefix . 'login = :login';
            $p['login'] = trim( (string)$f['user'] );
        }
        if ( isset( $f['user_id'] ) && (int)$f['user_id'] > 0 )
        {
            $where[] = $prefix . 'user_id = :uid';
            $p['uid'] = (int)$f['user_id'];
        }
        foreach ( array( 'kind' => array( 'mcp', 'job' ), 'transport' => array( 'stdio', 'http', 'cli', 'job', 'admin' ), 'status' => array( 'open', 'ended', 'ok', 'error' ) ) as $k => $allowed )
        {
            if ( isset( $f[$k] ) && in_array( $f[$k], $allowed, true ) )
            {
                $where[] = $prefix . $k . ' = :' . $k;
                $p[$k] = $f[$k];
            }
        }
        foreach ( array( 'from' => '>=', 'to' => '<' ) as $k => $op )
        {
            if ( isset( $f[$k] ) && ( $t = self::time( $f[$k], $k === 'to' ) ) !== null )
            {
                $where[] = $prefix . "started $op :$k";
                $p[$k] = $t;
            }
        }
        if ( isset( $f['tool'] ) && $f['tool'] !== '' )
        {
            $where[] = $prefix . 'id IN (SELECT session_id FROM tron_journal_step WHERE tool = :tool)';
            $p['tool'] = (string)$f['tool'];
        }
        if ( isset( $f['outcome'] ) && in_array( $f['outcome'], self::OUTCOMES, true ) )
        {
            $where[] = $prefix . 'id IN (SELECT session_id FROM tron_journal_step WHERE outcome = :outcome)';
            $p['outcome'] = $f['outcome'];
        }
        if ( isset( $f['object'] ) && ( $o = tronJournalObjects::parse( (string)$f['object'] ) ) )
        {
            $where[] = $prefix . 'id IN (SELECT session_id FROM tron_journal_object WHERE kind = :okind AND ref = :oref)';
            $p['okind'] = $o[0];
            $p['oref'] = $o[1];
        }
        return array( $where, $p );
    }

    /** "2026-11-24" (start of that day; with $end the start of the next) or a unix time; null when neither. */
    public static function time( $v, bool $end = false ): ?int
    {
        if ( is_int( $v ) || ( is_string( $v ) && ctype_digit( $v ) && strlen( $v ) > 8 ) )
            return (int)$v;
        if ( is_string( $v ) && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m ) && checkdate( (int)$m[2], (int)$m[3], (int)$m[1] ) )
            return (int)mktime( 0, 0, 0, (int)$m[2], (int)$m[3] + ( $end ? 1 : 0 ), (int)$m[1] );
        return null;
    }

    private function sessionKey( int $id ): string
    {
        $r = $this->db->select( 'SELECT session_key FROM tron_journal_session WHERE id = :id', array( 'id' => $id ), 1 );
        if ( !$r )
            throw new RuntimeException( "No journal session $id" );
        return (string)$r[0]['session_key'];
    }

    /** The facts of a session that its start step carries, for verify(). */
    private static function startFacts( array $s ): array
    {
        $out = array();
        foreach ( array( 'kind', 'parent_id', 'user_id', 'login', 'token_id', 'transport', 'client_name', 'client_version', 'protocol', 'node', 'remote', 'started' ) as $k )
            $out[$k] = in_array( $k, array( 'parent_id', 'user_id', 'token_id', 'started' ), true ) ? (int)$s[$k] : (string)$s[$k];
        return $out;
    }

    public static function genesis( string $sessionKey ): string
    {
        return hash( 'sha256', 'tron-journal:' . $sessionKey );
    }

    /** The chained hash of a step: its own fields (not id, prev_hash, hash) after the previous hash. */
    public static function hash( string $prev, array $row ): string
    {
        $fields = array();
        foreach ( array( 'seq', 'created', 'kind', 'method', 'tool', 'program', 'write_call', 'input', 'result', 'outcome', 'code',
                         'duration_ms', 'objects', 'diff_ref', 'diff', 'ref_seq' ) as $k )
        {
            $v = $row[$k] ?? '';
            $fields[$k] = in_array( $k, array( 'seq', 'created', 'write_call', 'duration_ms', 'ref_seq' ), true ) ? (int)$v : (string)$v;
        }
        return hash( 'sha256', $prev . "\n" . self::json( $fields ) );
    }

    private static function broken( int $at, int $steps, string $reason ): array
    {
        return array( 'ok' => false, 'steps' => $steps, 'broken_at' => $at, 'reason' => $reason );
    }

    public static function typed( array $r ): array
    {
        foreach ( array( 'id', 'parent_id', 'user_id', 'token_id', 'started', 'last_activity', 'ended', 'steps', 'writes', 'errors', 'refused' ) as $k )
            if ( isset( $r[$k] ) )
                $r[$k] = (int)$r[$k];
        return $r;
    }

    public static function typedStep( array $r ): array
    {
        foreach ( array( 'id', 'session_id', 'seq', 'created', 'write_call', 'duration_ms', 'ref_seq', 'user_id' ) as $k )
            if ( isset( $r[$k] ) )
                $r[$k] = (int)$r[$k];
        $r['objects_list'] = $r['objects'] !== '' && $r['objects'] !== null ? (array)json_decode( (string)$r['objects'], true ) : array();
        return $r;
    }

    public static function json( $v ): string
    {
        $s = json_encode( $v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
        return $s === false ? '' : $s;
    }

    /** Text redacted (unless already) and cut. */
    private static function text( string $s, int $max, bool $redact = true ): string
    {
        if ( $redact )
            $s = tronRedactor::maskSecretsInText( tronRedactor::maskTokens( $s ) );
        else
            $s = tronRedactor::maskTokens( $s );
        return self::cut( $s, $max );
    }

    private static function cut( string $s, int $max ): string
    {
        if ( strlen( $s ) <= $max )
            return $s;
        $cut = function_exists( 'mb_strcut' ) ? mb_strcut( $s, 0, $max - 3, 'UTF-8' ) : substr( $s, 0, $max - 3 );
        return $cut . '...';
    }
}
