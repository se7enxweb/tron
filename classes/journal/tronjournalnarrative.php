<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Tells a journal session so that a person can read it: a narrative in plain sentences ("At 21:04:12 Claude Code (as
 * claude-cluster) used List tickets, then Get a ticket on ticket 5."), a timeline (one row per step, for the
 * administration and the tools), and exports as text, Markdown and JSON.
 *
 * Sentences go through a translator (callable( text, params ) => string, the administration passes ezpI18n), so the
 * story reads in the administrator's language; without one the English text is used with its %placeholders filled.
 * Tool titles (name => title) make "used List tickets" out of "centre_ticket_list"; without them the name is used.
 */
final class tronJournalNarrative
{
    const KIND_LABELS = array( 'node' => 'node', 'object' => 'object', 'layout' => 'layout', 'ini' => 'setting', 'file' => 'file',
                               'ticket' => 'ticket', 'plan' => 'plan', 'apply' => 'apply', 'user' => 'user', 'block' => 'block' );

    /** @var callable|null */ private $tr;
    /** @var array */ private $titles;

    public function __construct( ?callable $translator = null, array $toolTitles = array() )
    {
        $this->tr = $translator;
        $this->titles = $toolTitles;
    }

    /** The tool titles of a server (what tools/list calls them). */
    public static function titlesOf( tronServer $server ): array
    {
        $t = array();
        foreach ( $server->registry()->tools() as $tool )
            $t[$tool->name] = $tool->title;
        return $t;
    }

    // ------------------------------------------------------------------ timeline

    /**
     * One row per step, the "started" step of a finished write folded into its outcome.
     * Row: seq, time (H:i:s), date, created, kind, label, tool, write, outcome, code, duration_ms, objects (text),
     * objects_list, input, result, diff_ref, diff, ref_seq, started_at.
     */
    public function timeline( array $steps ): array
    {
        $finished = array();
        foreach ( $steps as $s )
            if ( $s['kind'] === 'call' && (int)$s['ref_seq'] > 0 )
                $finished[(int)$s['ref_seq']] = $s['created'];
        $out = array();
        foreach ( $steps as $s )
        {
            if ( $s['kind'] === 'call' && $s['outcome'] === 'started' && isset( $finished[(int)$s['seq']] ) )
                continue;
            $in = json_decode( (string)$s['input'], true );
            $out[] = array(
                'seq' => (int)$s['seq'], 'created' => (int)$s['created'], 'time' => date( 'H:i:s', (int)$s['created'] ),
                'date' => date( 'Y-m-d', (int)$s['created'] ), 'kind' => $s['kind'], 'label' => $this->label( $s, is_array( $in ) ? $in : array() ),
                'tool' => (string)$s['tool'], 'write' => (bool)$s['write_call'], 'outcome' => (string)$s['outcome'], 'code' => (string)$s['code'],
                'duration_ms' => (int)$s['duration_ms'], 'objects' => $this->objectsText( (array)( $s['objects_list'] ?? array() ) ),
                'objects_short' => $this->objectsText( (array)( $s['objects_list'] ?? array() ), 3 ),
                                'objects_list' => (array)( $s['objects_list'] ?? array() ), 'input' => (string)$s['input'], 'result' => (string)$s['result'],
                'diff_ref' => (string)$s['diff_ref'], 'diff' => (string)$s['diff'], 'ref_seq' => (int)$s['ref_seq'],
                'hash' => (string)( $s['hash'] ?? '' ),
            );
        }
        return $out;
    }

    private function label( array $s, array $in ): string
    {
        switch ( $s['kind'] )
        {
            case 'start':      return $this->t( 'Session started' );
            case 'end':        return $this->t( 'Session ended' ) . ( !empty( $in['reason'] ) ? ': ' . $in['reason'] : '' );
            case 'intent':     return $this->t( 'Intent' ) . ': ' . (string)( $in['title'] ?? '' );
            case 'note':       return $this->t( 'Note' ) . ': ' . (string)( $in['text'] ?? '' );
            case 'milestone':  return $this->t( 'Milestone' ) . ': ' . (string)( $in['text'] ?? '' );
            case 'progress':   return $this->t( 'Progress %percent%', array( '%percent' => (int)( $in['percent'] ?? 0 ) ) ) . ( !empty( $in['text'] ) ? ': ' . $in['text'] : '' );
            case 'checkpoint': return $this->t( 'Checkpoint' ) . ': ' . (string)( $in['label'] ?? '' );
            case 'result':     return $this->t( 'Result' ) . ( $s['result'] !== '' ? ': ' . $s['result'] : '' );
        }
        return $this->toolName( (string)$s['tool'] );
    }

    // ------------------------------------------------------------------ narrative

    /** @return string[] paragraphs */
    public function narrative( array $session, array $steps, ?array $verify = null ): array
    {
        $rows = $this->timeline( $steps );
        $paras = array();
        $who = $this->who( $session );
        $sentences = array();
        $reads = array();
        $readTime = '';
        $flush = function () use ( &$reads, &$readTime, &$sentences ) {
            if ( !$reads )
                return;
            $sentences[] = $this->t( 'At %time it %actions.', array( '%time' => $readTime, '%actions' => implode( ', ' . $this->t( 'then' ) . ' ', $reads ) ) );
            $reads = array();
        };
        $calls = 0;
        foreach ( $rows as $r )
        {
            $t = $r['time'];
            if ( $r['kind'] === 'call' && $r['tool'] === 'tron_journal_note' && $r['outcome'] === 'ok' )
                continue;          // its note, intent or milestone step tells it
            if ( $r['kind'] === 'call' && $r['outcome'] === 'ok' && !$r['write'] )
            {
                $calls++;
                if ( !$reads )
                    $readTime = $t;
                $reads[] = $this->t( 'used %tool', array( '%tool' => $this->toolName( $r['tool'] ) ) ) . ( $r['objects'] !== '' ? ' ' . $this->t( 'on %objects', array( '%objects' => $r['objects_short'] ) ) : '' );
                if ( count( $reads ) >= 6 )
                    $flush();
                continue;
            }
            $flush();
            $p = array( '%time' => $t, '%tool' => $this->toolName( $r['tool'] ), '%objects' => $r['objects_short'], '%ms' => $r['duration_ms'],
                        '%why' => $r['result'] !== '' ? $r['result'] : $r['code'], '%code' => $r['code'] );
            switch ( $r['kind'] )
            {
                case 'start':
                    $sentences[] = $session['kind'] === 'job'
                        ? $this->t( 'On %date at %time the background job “%title” started (as %login) on %node.', array( '%date' => $r['date'], '%time' => $t, '%title' => $session['title'], '%login' => $session['login'] !== '' ? $session['login'] : '-', '%node' => $session['node'] !== '' ? $session['node'] : '-' ) )
                        : $this->t( 'On %date at %time %who started a session over %transport on %node.', array( '%date' => $r['date'], '%time' => $t, '%who' => $who, '%transport' => $session['transport'], '%node' => $session['node'] !== '' ? $session['node'] : '-' ) );
                    break;
                case 'intent':
                    $in = json_decode( $r['input'], true );
                    $sentences[] = $this->t( 'At %time it said what it is doing: “%title”.', array( '%time' => $t, '%title' => (string)( $in['title'] ?? '' ) ) )
                                   . ( !empty( $in['intent'] ) ? ' ' . (string)$in['intent'] : '' );
                    break;
                case 'note':
                    $in = json_decode( $r['input'], true );
                    $sentences[] = $this->t( 'At %time it noted: “%text”.', array( '%time' => $t, '%text' => (string)( $in['text'] ?? '' ) ) );
                    break;
                case 'milestone':
                    $in = json_decode( $r['input'], true );
                    $sentences[] = $this->t( 'At %time it reached a milestone: “%text”.', array( '%time' => $t, '%text' => (string)( $in['text'] ?? '' ) ) );
                    break;
                case 'progress':
                    $in = json_decode( $r['input'], true );
                    $sentences[] = $this->t( 'At %time the job was %percent% done.', array( '%time' => $t, '%percent' => (int)( $in['percent'] ?? 0 ) ) )
                                   . ( !empty( $in['text'] ) ? ' ' . (string)$in['text'] . '.' : '' );
                    break;
                case 'checkpoint':
                    $in = json_decode( $r['input'], true );
                    $sentences[] = $this->t( 'At %time it reached the checkpoint “%label”.', array( '%time' => $t, '%label' => (string)( $in['label'] ?? '' ) ) );
                    break;
                case 'result':
                    $sentences[] = $r['outcome'] === 'ok'
                        ? $this->t( 'At %time the job finished: %summary.', array( '%time' => $t, '%summary' => $r['result'] !== '' ? $r['result'] : $this->t( 'no summary' ) ) )
                        : $this->t( 'At %time the job failed: %summary.', array( '%time' => $t, '%summary' => $r['result'] !== '' ? $r['result'] : $this->t( 'no reason given' ) ) );
                    break;
                case 'end':
                    break;
                case 'call':
                    $calls++;
                    if ( $r['outcome'] === 'refused' )
                        $sentences[] = $this->t( 'At %time the call to %tool was refused: %why.', $p );
                    else if ( $r['outcome'] === 'error' )
                        $sentences[] = $this->t( 'At %time %tool failed (%code): %why.', $p );
                    else if ( $r['outcome'] === 'started' )
                        $sentences[] = $this->t( 'At %time it started the write tool %tool; no outcome was recorded, so the call may not have finished.', $p );
                    else
                    {
                        $s = $r['objects'] !== ''
                            ? $this->t( 'At %time it changed %objects with %tool (%ms ms).', $p )
                            : $this->t( 'At %time it made a change with %tool (%ms ms).', $p );
                        if ( $r['result'] !== '' )
                            $s .= ' ' . $this->t( 'The answer: %result.', array( '%result' => $r['result'] ) );
                        if ( $r['diff'] !== '' || $r['diff_ref'] !== '' )
                            $s .= ' ' . ( $r['diff_ref'] !== '' ? $this->t( 'Its diff is recorded (%ref).', array( '%ref' => $r['diff_ref'] ) ) : $this->t( 'Its diff is kept with the step.' ) );
                        $sentences[] = $s;
                    }
                    break;
            }
        }
        $flush();
        $paras[] = implode( ' ', $sentences );

        $summary = array( '%steps' => count( $steps ), '%calls' => $calls, '%writes' => (int)$session['writes'], '%refused' => (int)$session['refused'], '%errors' => (int)$session['errors'] );
        if ( (int)$session['ended'] > 0 )
            $paras[] = $this->t( 'The session ended at %time (%reason) after %steps steps: %calls calls, %writes writes, %refused refused, %errors errors.',
                                 $summary + array( '%time' => date( 'H:i:s', (int)$session['ended'] ), '%reason' => $session['end_reason'] !== '' ? $session['end_reason'] : '-' ) );
        else
            $paras[] = $this->t( 'The session is still open; its last activity was at %time. So far %steps steps: %calls calls, %writes writes, %refused refused, %errors errors.',
                                 $summary + array( '%time' => date( 'Y-m-d H:i:s', (int)$session['last_activity'] ) ) );
        if ( $verify !== null )
            $paras[] = $verify['ok']
                ? $this->t( 'The hash chain of its %n steps is intact.', array( '%n' => (int)$verify['steps'] ) )
                : $this->t( 'Warning: the hash chain is broken at step %n: %reason.', array( '%n' => (int)$verify['broken_at'], '%reason' => (string)$verify['reason'] ) );
        return $paras;
    }

    private function who( array $s ): string
    {
        $client = trim( $s['client_name'] . ( $s['client_version'] !== '' ? ' ' . $s['client_version'] : '' ) );
        $as = $s['login'] . ( (int)$s['token_id'] > 0 ? ', ' . $this->t( 'token #%id', array( '%id' => (int)$s['token_id'] ) ) : '' );
        return $client !== ''
            ? $this->t( '%client (as %login)', array( '%client' => $client, '%login' => $as ) )
            : $this->t( 'an MCP client (as %login)', array( '%login' => $as ) );
    }

    private function toolName( string $tool ): string
    {
        if ( isset( $this->titles[$tool] ) && $this->titles[$tool] !== $tool )
            return $this->titles[$tool] . ' (' . $tool . ')';
        return $tool;
    }

    /** "node 123, setting site.ini[SiteSettings]IndexPage" */
    public function objectsText( array $objects, int $max = 0 ): string
    {
        $out = array();
        foreach ( $objects as $o )
        {
            if ( !isset( $o['kind'], $o['ref'] ) )
                continue;
            $label = self::KIND_LABELS[$o['kind']] ?? $o['kind'];
            $out[] = $this->t( $label ) . ' ' . $o['ref'];
        }
        $out = array_values( array_unique( $out ) );
        if ( $max > 0 && count( $out ) > $max )
            return implode( ', ', array_slice( $out, 0, $max ) ) . ' ' . $this->t( 'and %n more', array( '%n' => count( $out ) - $max ) );
        return implode( ', ', $out );
    }

    // ------------------------------------------------------------------ exports

    public function text( array $session, array $steps, ?array $verify = null ): string
    {
        $head = $this->t( 'tron session %id', array( '%id' => (int)$session['id'] ) ) . ( $session['title'] !== '' ? ': ' . $session['title'] : '' );
        return $head . "\n" . str_repeat( '=', min( 78, strlen( $head ) ) ) . "\n\n" . implode( "\n\n", $this->narrative( $session, $steps, $verify ) ) . "\n";
    }

    public function markdown( array $session, array $steps, ?array $verify = null ): string
    {
        $md = '# ' . self::mdInline( $this->t( 'tron session %id', array( '%id' => (int)$session['id'] ) ) . ( $session['title'] !== '' ? ': ' . $session['title'] : '' ) ) . "\n\n";
        $facts = array(
            $this->t( 'Kind' ) => $session['kind'], $this->t( 'User' ) => $session['login'] . ( (int)$session['token_id'] > 0 ? ' (token #' . (int)$session['token_id'] . ')' : '' ),
            $this->t( 'Client' ) => trim( $session['client_name'] . ' ' . $session['client_version'] ), $this->t( 'Transport' ) => $session['transport'],
            $this->t( 'Node' ) => $session['node'], $this->t( 'Started' ) => date( 'Y-m-d H:i:s', (int)$session['started'] ),
            $this->t( 'Ended' ) => (int)$session['ended'] > 0 ? date( 'Y-m-d H:i:s', (int)$session['ended'] ) . ' (' . $session['end_reason'] . ')' : $this->t( 'open' ),
            $this->t( 'Session key' ) => $session['session_key'],
        );
        $md .= '| | |' . "\n" . '|---|---|' . "\n";
        foreach ( $facts as $k => $v )
            $md .= '| ' . self::mdCell( (string)$k ) . ' | ' . self::mdCell( (string)$v ) . " |\n";
        if ( $session['intent'] !== '' )
            $md .= "\n> " . self::mdInline( (string)$session['intent'] ) . "\n";
        $md .= "\n## " . $this->t( 'Story' ) . "\n\n" . implode( "\n\n", array_map( array( __CLASS__, 'mdInline' ), $this->narrative( $session, $steps, $verify ) ) ) . "\n";
        $md .= "\n## " . $this->t( 'Timeline' ) . "\n\n| # | " . $this->t( 'Time' ) . ' | ' . $this->t( 'Step' ) . ' | ' . $this->t( 'Outcome' ) . ' | ms | '
             . $this->t( 'Objects' ) . ' | ' . $this->t( 'Result' ) . " |\n|---|---|---|---|---|---|---|\n";
        $diffs = '';
        foreach ( $this->timeline( $steps ) as $r )
        {
            $md .= '| ' . $r['seq'] . ' | ' . $r['date'] . ' ' . $r['time'] . ' | ' . self::mdCell( $r['label'] . ( $r['write'] ? ' [write]' : '' ) ) . ' | '
                 . self::mdCell( trim( $r['outcome'] . ' ' . $r['code'] ) ) . ' | ' . ( $r['kind'] === 'call' ? $r['duration_ms'] : '' ) . ' | '
                 . self::mdCell( $r['objects'] ) . ' | ' . self::mdCell( $r['kind'] === 'call' ? $r['result'] : '' ) . " |\n";
            if ( $r['diff'] !== '' || $r['diff_ref'] !== '' )
                $diffs .= "\n### " . $this->t( 'Step %n', array( '%n' => $r['seq'] ) ) . ': ' . self::mdInline( $r['label'] ) . "\n\n"
                        . ( $r['diff_ref'] !== '' ? $this->t( 'Reference' ) . ': `' . str_replace( '`', "'", $r['diff_ref'] ) . "`\n\n" : '' )
                        . ( $r['diff'] !== '' ? "```\n" . str_replace( '```', "'''", $r['diff'] ) . "\n```\n" : '' );
        }
        if ( $diffs !== '' )
            $md .= "\n## " . $this->t( 'Changes' ) . "\n" . $diffs;
        if ( $verify !== null )
            $md .= "\n## " . $this->t( 'Integrity' ) . "\n\n" . ( $verify['ok'] ? $this->t( 'The hash chain of its %n steps is intact.', array( '%n' => (int)$verify['steps'] ) )
                   : $this->t( 'Warning: the hash chain is broken at step %n: %reason.', array( '%n' => (int)$verify['broken_at'], '%reason' => (string)$verify['reason'] ) ) )
                   . ' ' . $this->t( 'Last hash' ) . ': `' . $session['head_hash'] . "`\n";
        return $md;
    }

    public function json( array $session, array $steps, ?array $verify = null ): string
    {
        $clean = array();
        foreach ( $steps as $s )
        {
            unset( $s['objects_list'] );
            $s['input'] = json_decode( (string)$s['input'], true ) ?? $s['input'];
            $s['objects'] = $s['objects'] !== '' ? json_decode( (string)$s['objects'], true ) : array();
            $clean[] = $s;
        }
        $out = array( 'session' => $session, 'verify' => $verify, 'narrative' => $this->narrative( $session, $steps, $verify ), 'steps' => $clean );
        return (string)json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
    }

    /** A session in one format: narrative (text), markdown, json, timeline (array). */
    public function render( string $format, array $session, array $steps, ?array $verify = null )
    {
        switch ( $format )
        {
            case 'markdown': return $this->markdown( $session, $steps, $verify );
            case 'json':     return $this->json( $session, $steps, $verify );
            case 'timeline': return $this->timeline( $steps );
        }
        return $this->text( $session, $steps, $verify );
    }

    private function t( string $text, array $params = array() ): string
    {
        if ( $this->tr )
            return (string)call_user_func( $this->tr, $text, $params );
        return strtr( $text, array_map( 'strval', $params ) );
    }

    private static function mdCell( string $s ): string
    {
        return str_replace( array( '|', "\n", "\r" ), array( '\\|', ' ', '' ), $s );
    }

    private static function mdInline( string $s ): string
    {
        return preg_replace( '/^([#>])/m', '\\\\$1', str_replace( "\r", '', $s ) );
    }
}
