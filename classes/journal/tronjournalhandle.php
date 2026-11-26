<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * A connection's place in the activity journal, kept on the tronSession ($session->journal).
 *
 * The server attaches it when the session starts (initialize) or at its first audited call, and puts it into every
 * audit entry under "journal"; tronJournalSink turns those entries into steps. Encoded as JSON (the audit file) it
 * is the session key, so an audit line points at its journal session.
 *
 * A tool handler can tell the journal more than the call itself shows, through $ctx->session->journal (null when the
 * journal is off, so check it):
 *   ->note( 'why' ), ->milestone( 'done with X' ), ->intent( 'title', 'what for' )
 *   ->objects( array( 'node:123', 'ini:site.ini[SiteSettings]IndexPage' ) )   what the call touched
 *   ->diff( $diffOrPlan, 'plan:<key>' )                                     a write's before/after, or a pointer to it
 *   ->job( 'reindex' )                                                      a background job's own journal, linked here
 */
final class tronJournalHandle implements JsonSerializable
{
    /** @var tronJournal */ private $journal;
    /** @var int */ private $sessionId;
    /** @var string */ private $key;
    /** @var bool */ private $write = false;
    /** @var array target => seq of the "started" step of a write tool not finished yet */ private $started = array();
    /** @var array */ private $pending = array();

    public function __construct( tronJournal $journal, array $sessionRow )
    {
        $this->journal = $journal;
        $this->sessionId = (int)$sessionRow['id'];
        $this->key = (string)$sessionRow['session_key'];
    }

    /**
     * The session's handle, attached on first use. Null when the environment has no journal ("tron.journal") or it
     * could not open the session (the call goes on; the audit log still has it).
     */
    public static function attach( tronSession $session, tronEnvironment $env ): ?self
    {
        if ( $session->journal instanceof self )
            return $session->journal;
        if ( $session->journal === false || !$env->has( 'tron.journal' ) )
            return null;
        $journal = $env->get( 'tron.journal' );
        if ( !$journal instanceof tronJournal )
            return null;
        try
        {
            $p = $session->principal;
            $meta = array(
                'kind' => 'mcp', 'user_id' => $p->userId, 'login' => $p->login, 'token_id' => $p->tokenId,
                'transport' => $session->transport, 'remote' => $session->remote, 'protocol' => (string)$session->protocolVersion,
                'client_name' => (string)( $session->clientInfo['title'] ?? $session->clientInfo['name'] ?? '' ),
                'client_version' => (string)( $session->clientInfo['version'] ?? '' ),
            );
            $row = $session->transport === 'http' ? $journal->openOrResume( $meta ) : $journal->open( $meta );
        }
        catch ( Throwable $e )
        {
            $session->journal = false;          // tried once; do not try again on every call of this connection
            return null;
        }
        return $session->journal = new self( $journal, $row );
    }

    /** What the server adds to an audit entry: array( 'journal' => handle ) or nothing. */
    public static function entryKey( tronSession $session, tronEnvironment $env ): array
    {
        $h = self::attach( $session, $env );
        return $h ? array( 'journal' => $h ) : array();
    }

    /** The server hands over what a tool returned, before the "ok" entry is written. */
    public static function result( tronSession $session, $value, string $mode = 'call', ?string $planKey = null ): void
    {
        if ( !$session->journal instanceof self )
            return;
        $session->journal->pending['result'] = array( $value );
        if ( $mode === 'apply' && $planKey !== null && $planKey !== '' )
            $session->journal->pending['diff_ref'] = 'plan:' . $planKey;
    }

    public function id(): int
    {
        return $this->sessionId;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function journal(): tronJournal
    {
        return $this->journal;
    }

    public function jsonSerialize(): string
    {
        return $this->key;
    }

    // ------------------------------------------------------------------ for tools

    public function note( string $text ): array
    {
        return $this->journal->note( $this->sessionId, $text, 'note' );
    }

    public function milestone( string $text ): array
    {
        return $this->journal->note( $this->sessionId, $text, 'milestone' );
    }

    public function intent( string $title, string $intent = '' ): array
    {
        return $this->journal->intent( $this->sessionId, $title, $intent );
    }

    /** Objects the current call touched, in addition to what its arguments and answer show ("node:123", ...). */
    public function objects( array $objects ): void
    {
        $this->pending['objects'] = array_merge( (array)( $this->pending['objects'] ?? array() ), $objects );
    }

    /** The current write's diff (array or text; redacted and cut on storing) and/or a pointer to it (plan:<key>, apply:<id>). */
    public function diff( $diff, string $ref = '' ): void
    {
        $this->pending['diff'] = $diff;
        if ( $ref !== '' )
            $this->pending['diff_ref'] = $ref;
    }

    /** A background job's journal, linked to this session. */
    public function job( string $name ): tronJournalJob
    {
        $s = $this->journal->session( $this->sessionId ) ?: array();
        return $this->journal->job( $name, array( 'user_id' => $s['user_id'] ?? 0, 'login' => $s['login'] ?? '', 'token_id' => $s['token_id'] ?? 0 ), $this->sessionId );
    }

    public function end( string $reason ): void
    {
        $this->journal->end( $this->sessionId, $reason );
    }

    // ------------------------------------------------------------------ from the audit sink

    /** Turns one audit entry of this session into a step (tronJournalSink calls it). */
    public function record( array $entry ): void
    {
        $method = (string)( $entry['method'] ?? '' );
        $target = (string)( $entry['target'] ?? '' );
        $detail = json_decode( (string)( $entry['detail'] ?? '' ), true );
        $args = is_array( $detail ) && is_array( $detail['args'] ?? null ) ? $detail['args'] : array();
        $note = is_array( $detail ) ? (string)( $detail['note'] ?? '' ) : '';
        list( $outcome, $code ) = self::outcome( (string)( $entry['outcome'] ?? '' ), $note );

        $pending = $this->pending;
        $refSeq = 0;
        $write = false;
        if ( $outcome === 'started' )
            $write = true;
        else if ( isset( $this->started[$method . ' ' . $target] ) )
        {
            $write = true;
            $refSeq = $this->started[$method . ' ' . $target];
            unset( $this->started[$method . ' ' . $target] );
        }

        $value = isset( $pending['result'] ) && in_array( $outcome, array( 'ok', 'dry-run' ), true ) ? $pending['result'][0] : null;
        $value = self::plain( $value );
        $objects = tronJournalObjects::from( $args, $value, $write ? 'write' : 'read', $target );
        if ( isset( $pending['objects'] ) )
            $objects = array_merge( $objects, tronJournalObjects::normalise( (array)$pending['objects'], $write ? 'write' : 'read' ) );

        $diff = $pending['diff'] ?? null;
        $diffRef = (string)( $pending['diff_ref'] ?? '' );
        if ( is_array( $value ) && $outcome !== 'started' )
        {
            foreach ( array( 'diff', 'changes' ) as $k )
                if ( $diff === null && isset( $value[$k] ) && $value[$k] !== array() && $value[$k] !== '' )
                    $diff = $value[$k];
            foreach ( array( 'plan_id', 'plan_key' ) as $k )
                if ( $diffRef === '' && isset( $value[$k] ) && is_scalar( $value[$k] ) )
                    $diffRef = 'plan:' . $value[$k];
            if ( $diffRef === '' && isset( $value['apply_id'] ) && is_scalar( $value['apply_id'] ) )
                $diffRef = 'apply:' . $value['apply_id'];
        }
        if ( $diffRef === '' && isset( $args['plan_key'] ) && is_scalar( $args['plan_key'] ) )
            $diffRef = 'plan:' . $args['plan_key'];
        // An applied plan: the diff its dry run recorded, so the write step carries it too
        if ( $diff === null && strpos( $diffRef, 'plan:' ) === 0 && $outcome !== 'started' )
            $diff = $this->journal->planDiff( $diffRef );

        $result = in_array( $outcome, array( 'ok', 'dry-run' ), true ) ? ( $value !== null ? tronJournalObjects::summary( $value, max( 80, (int)$this->journal->setting( 'MaxResultChars' ) ) ) : '' ) : $note;
        $step = $this->journal->append( $this->sessionId, array(
            'created' => (int)( $entry['created'] ?? $this->journal->now() ), 'kind' => 'call', 'method' => $method,
            'tool' => $target, 'program' => (string)( $entry['program'] ?? '' ), 'write_call' => $write, 'input' => $args,
            'result' => $result, 'outcome' => $outcome, 'code' => $code, 'duration_ms' => (int)( $entry['duration_ms'] ?? 0 ),
            'objects' => $objects, 'diff' => $diff ?? '', 'diff_ref' => $diffRef, 'ref_seq' => $refSeq,
        ) );
        if ( $outcome === 'started' )
            $this->started[$method . ' ' . $target] = $step['seq'];
        else
            $this->pending = array();
    }

    /** An answer as plain arrays: JSON objects (stdClass, JsonSerializable) become arrays, so the journal reads any answer. */
    public static function plain( $value )
    {
        if ( !is_object( $value ) && !is_array( $value ) )
            return $value;
        $json = json_encode( $value, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE );
        return $json === false ? null : json_decode( $json, true );
    }

    /** The audit outcome as the journal tells it: ok, started, refused (with why) or error (with a code). */
    public static function outcome( string $audit, string $note ): array
    {
        switch ( $audit )
        {
            case 'ok':              return array( 'ok', '' );
            case 'dry-run':         return array( 'dry-run', '' );
            case 'refused':         return array( 'refused', 'plan' );
            case 'started':         return array( 'started', '' );
            case 'denied':          return array( 'refused', 'policy' );
            case 'invalid':         return array( 'refused', 'invalid_arguments' );
            case 'unauthenticated': return array( 'refused', 'unauthenticated' );
        }
        if ( $note === 'not found' )
            return array( 'error', 'not_found' );
        if ( preg_match( '/^([A-Z][A-Za-z0-9_\\\\]+(Exception|Error)): /', $note, $m ) )
            return array( 'error', 'internal' );
        return array( 'error', 'tool_error' );
    }
}
