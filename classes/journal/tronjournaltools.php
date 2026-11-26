<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The journal's tools, part of the core program "tron" (tronCoreProgram registers them):
 *
 *   tron_journal_note    the agent says why it is doing something, or that it reached a milestone, in its own session
 *   tron_journal_list    sessions, filtered by user, date, tool, outcome, object, kind
 *   tron_journal_show    one session as a narrative, a timeline, Markdown or JSON, with its hash chain checked
 *   tron_journal_search  steps across sessions: by user, date, tool, outcome, object ("node:123") or text
 *
 * Every user with tron/use reads their own sessions; a user with tron/audit reads everyone's. The tools are left out
 * (with the reason in tron_status) when the journal is off or its tables are not installed.
 */
final class tronJournalTools
{
    public static function register( tronRegistry $registry, tronEnvironment $env ): void
    {
        $names = array( 'tron_journal_note', 'tron_journal_list', 'tron_journal_show', 'tron_journal_search' );
        if ( !$env->has( 'tron.journal' ) || !$env->get( 'tron.journal' ) instanceof tronJournal )
        {
            // Switched on but not usable (tables missing): say why the tools are not there. Switched off: nothing to say.
            if ( ( $env->settings()['JournalSettings']['Enabled'] ?? 'disabled' ) !== 'enabled' )
                return;
            foreach ( $names as $n )
                $registry->unavailable( $n, 'the activity journal is off or its tables are missing (tron.php install)' );
            return;
        }
        $journal = function () use ( $env ): tronJournal { return $env->get( 'tron.journal' ); };
        $dateProp = array( 'type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$', 'description' => 'A day, YYYY-MM-DD.' );
        $filterProps = array(
            'user' => array( 'type' => 'string', 'maxLength' => 150, 'description' => 'Only this user (login). Without tron/audit only your own sessions are shown.' ),
            'from' => $dateProp + array( 'title' => 'From (first day)' ), 'to' => $dateProp + array( 'title' => 'To (last day)' ),
            'tool' => array( 'type' => 'string', 'maxLength' => 255, 'description' => 'Only sessions or steps with a call of this tool.' ),
            'outcome' => array( 'type' => 'string', 'enum' => tronJournal::OUTCOMES, 'description' => 'ok, started, refused or error.' ),
            'object' => array( 'type' => 'string', 'maxLength' => 290, 'description' => 'An object as kind:ref, e.g. node:123, object:57, layout:4, ticket:5, ini:site.ini[SiteSettings]IndexPage, file:var/log/x.log.' ),
            'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ),
            'offset' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
        );

        $registry->tool( tronTool::define( array(
            'name' => 'tron_journal_note',
            'outputSchema' => array( 'type' => 'object', 'required' => array( 'session', 'session_key', 'step', 'kind' ), 'properties' => array(
                'session' => array( 'type' => 'integer' ), 'session_key' => array( 'type' => 'string' ), 'step' => array( 'type' => 'integer' ), 'kind' => array( 'type' => 'string' ) ) ),
            'title' => 'Note in the activity journal',
            'description' => 'Writes into the activity journal of this session, so a later reader understands what you did and why. '
                           . 'kind "intent": what this session is for (title, plus text as the longer purpose; set it at the start). '
                           . '"note": why you are about to do something. "milestone": a step of the task is done. '
                           . 'Never put secrets here; text that looks like a password or token is masked anyway.',
            'inputSchema' => array( 'type' => 'object', 'properties' => array(
                'text' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 2000 ),
                'kind' => array( 'type' => 'string', 'enum' => array( 'note', 'milestone', 'intent' ), 'default' => 'note' ),
                'title' => array( 'type' => 'string', 'maxLength' => 255, 'description' => 'For kind intent: a short title of the session.' ),
            ), 'required' => array( 'text' ), 'additionalProperties' => false ),
            'policy' => array( 'tron', 'use' ),
            'handler' => function ( array $args, tronCallContext $ctx ) use ( $env ) {
                $h = tronJournalHandle::attach( $ctx->session, $env );
                if ( !$h )
                    throw new tronToolError( 'The activity journal could not open a session for this connection; the note was not kept.' );
                if ( $args['kind'] === 'intent' )
                    $step = $h->intent( (string)( $args['title'] ?? '' ) !== '' ? (string)$args['title'] : (string)$args['text'], (string)( $args['title'] ?? '' ) !== '' ? (string)$args['text'] : '' );
                else if ( $args['kind'] === 'milestone' )
                    $step = $h->milestone( (string)$args['text'] );
                else
                    $step = $h->note( (string)$args['text'] );
                return array( 'session' => $h->id(), 'session_key' => $h->key(), 'step' => $step['seq'], 'kind' => $step['kind'] );
            },
        ) ) );

        $registry->tool( tronTool::define( array(
            'name' => 'tron_journal_list',
            'outputSchema' => array( 'type' => 'object', 'required' => array( 'total', 'sessions' ), 'properties' => array(
                'total' => array( 'type' => 'integer', 'minimum' => 0 ), 'sessions' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ) ) ),
            'title' => 'List journal sessions',
            'description' => 'Sessions of the activity journal, newest first: who (user, client, token), how (transport, node), when, the title the agent gave, and counts of steps, writes, refusals and errors. '
                           . 'kind "job" lists background jobs. Then use tron_journal_show with a session id.',
            'inputSchema' => array( 'type' => 'object', 'properties' => $filterProps + array(
                'kind' => array( 'type' => 'string', 'enum' => array( 'mcp', 'job' ) ),
                'transport' => array( 'type' => 'string', 'enum' => array( 'stdio', 'http', 'cli', 'job' ) ),
            ), 'additionalProperties' => false ),
            'policy' => array( 'tron', 'use' ),
            'handler' => function ( array $args, tronCallContext $ctx ) use ( $journal ) {
                $f = self::scope( $args, $ctx );
                $res = $journal()->sessions( $f, (int)$args['limit'], (int)$args['offset'] );
                return array( 'total' => $res['total'], 'sessions' => array_map( array( __CLASS__, 'sessionOut' ), $res['rows'] ) );
            },
        ) ) );

        $registry->tool( tronTool::define( array(
            'name' => 'tron_journal_show',
            'outputSchema' => array( 'type' => 'object', 'required' => array( 'session', 'verify' ), 'properties' => array(
                'session' => array( 'type' => 'object' ), 'verify' => array( 'type' => 'object' ), 'narrative' => array( 'type' => 'array' ),
                'timeline' => array( 'type' => 'array' ), 'markdown' => array( 'type' => 'string' ), 'steps' => array( 'type' => 'array' ) ) ),
            'title' => 'Show a journal session',
            'description' => 'One session of the activity journal. format "narrative" (default): the story in plain sentences; "timeline": one row per step with tool, outcome, duration, objects, result and diff reference; "markdown" and "json": the full export. '
                           . 'The hash chain is checked on every call (verify). current: true shows this very session.',
            'inputSchema' => array( 'type' => 'object', 'properties' => array(
                'session' => array( 'type' => 'integer', 'minimum' => 1 ),
                'current' => array( 'type' => 'boolean', 'default' => false ),
                'format' => array( 'type' => 'string', 'enum' => array( 'narrative', 'timeline', 'markdown', 'json' ), 'default' => 'narrative' ),
            ), 'additionalProperties' => false ),
            'policy' => array( 'tron', 'use' ),
            'handler' => function ( array $args, tronCallContext $ctx ) use ( $journal, $env ) {
                $j = $journal();
                $id = (int)( $args['session'] ?? 0 );
                if ( !empty( $args['current'] ) )
                {
                    $h = tronJournalHandle::attach( $ctx->session, $env );
                    $id = $h ? $h->id() : 0;
                }
                $s = $id > 0 ? $j->session( $id ) : null;
                if ( !$s || ( !$ctx->can( 'tron', 'audit' ) && (int)$s['user_id'] !== $ctx->principal->userId ) )
                    throw new tronToolError( 'No such session' . ( $ctx->can( 'tron', 'audit' ) ? '' : ' among your own' ) . '. tron_journal_list lists them.' );
                $steps = $j->steps( $id );
                $verify = $j->verify( $id );
                $n = new tronJournalNarrative( null, self::titles( $env ) );
                $out = array( 'session' => self::sessionOut( $s ), 'verify' => $verify );
                switch ( $args['format'] )
                {
                    case 'timeline':
                        $out['timeline'] = array_map( function ( $r ) {
                            unset( $r['objects_list'], $r['created'], $r['hash'] );
                            return $r;
                        }, $n->timeline( $steps ) );
                        break;
                    case 'markdown':
                        $out['markdown'] = $n->markdown( $s, $steps, $verify );
                        break;
                    case 'json':
                        $out = json_decode( $n->json( $s, $steps, $verify ), true );
                        break;
                    default:
                        $out['narrative'] = $n->narrative( $s, $steps, $verify );
                }
                return $out;
            },
        ) ) );

        $registry->tool( tronTool::define( array(
            'name' => 'tron_journal_search',
            'outputSchema' => array( 'type' => 'object', 'required' => array( 'total', 'steps' ), 'properties' => array(
                'total' => array( 'type' => 'integer', 'minimum' => 0 ), 'steps' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ) ) ),
            'title' => 'Search the activity journal',
            'description' => 'Steps across all sessions, newest first, by user, day, tool, outcome, object or text. Answers questions like "everything tron did to node 123" (object node:123), '
                           . '"which writes were refused this week" (outcome refused, from) or "who used centre_ticket_close" (tool).',
            'inputSchema' => array( 'type' => 'object', 'properties' => $filterProps + array(
                'text' => array( 'type' => 'string', 'maxLength' => 200, 'description' => 'Words in the arguments, the result or the tool name.' ),
                'kind' => array( 'type' => 'string', 'enum' => array_merge( array( 'mcp', 'job' ), tronJournal::STEP_KINDS ) ),
            ), 'additionalProperties' => false ),
            'policy' => array( 'tron', 'use' ),
            'handler' => function ( array $args, tronCallContext $ctx ) use ( $journal ) {
                $res = $journal()->search( self::scope( $args, $ctx ), (int)$args['limit'], (int)$args['offset'] );
                $rows = array();
                foreach ( $res['rows'] as $r )
                    $rows[] = array( 'session' => $r['session_id'], 'step' => $r['seq'], 'time' => date( 'c', $r['created'] ), 'user' => $r['login'],
                                     'kind' => $r['kind'], 'tool' => $r['tool'], 'write' => (bool)$r['write_call'], 'outcome' => $r['outcome'],
                                     'code' => $r['code'], 'duration_ms' => $r['duration_ms'], 'objects' => $r['objects_list'],
                                     'result' => $r['result'], 'diff_ref' => $r['diff_ref'], 'session_title' => $r['title'] );
                return array( 'total' => $res['total'], 'steps' => $rows );
            },
        ) ) );
    }

    /** The filters of a call; without tron/audit always limited to the caller's own sessions. */
    private static function scope( array $args, tronCallContext $ctx ): array
    {
        $f = array_intersect_key( $args, array_flip( array( 'user', 'from', 'to', 'tool', 'outcome', 'object', 'kind', 'transport', 'text' ) ) );
        if ( !$ctx->can( 'tron', 'audit' ) )
        {
            unset( $f['user'] );
            $f['user_id'] = $ctx->principal->userId;
        }
        return $f;
    }

    private static function titles( tronEnvironment $env ): array
    {
        return $env->has( 'tron.tooltitles' ) ? (array)call_user_func( $env->get( 'tron.tooltitles' ) ) : array();
    }

    public static function sessionOut( array $s ): array
    {
        return array(
            'id' => $s['id'], 'kind' => $s['kind'], 'parent' => $s['parent_id'] ?: null, 'user' => $s['login'], 'token_id' => $s['token_id'],
            'client' => trim( $s['client_name'] . ' ' . $s['client_version'] ), 'transport' => $s['transport'], 'node' => $s['node'],
            'title' => $s['title'], 'intent' => $s['intent'], 'started' => date( 'c', $s['started'] ),
            'ended' => $s['ended'] ? date( 'c', $s['ended'] ) : null, 'end_reason' => $s['end_reason'], 'status' => $s['status'],
            'steps' => $s['steps'], 'writes' => $s['writes'], 'refused' => $s['refused'], 'errors' => $s['errors'],
        );
    }
}
