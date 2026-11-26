<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The centre tools when the extension expcentre runs in the same installation (centre.se7enx.com): one MCP tool per
 * tool of centre's API, named and shaped as the centre plan's "MCP contract for tron" table says, each passed to
 * expCentreApi::call( $tool, $args, $user ) as the session's user. centre owns the data, the numbering (EXP-123),
 * the projects and their roles; it checks its own policies (centre/board, card_read, card_write, decide, manage)
 * with their Subtree limitations on every call. tron adds its own gate in front: the policy centre/api, which the
 * plan grants to API users, and the audit log.
 *
 * centre answers {"ok":true,"result":...} or {"ok":false,"error":{"code":...,"message":...}}; an error becomes a
 * tool error "<code>: <message>" the agent can act on.
 *
 * The API is the environment service "centre.api": a callable( string $tool, array $args, int $userId ): array.
 */
final class tronCentreBridge
{
    const POLICY = array( 'centre', 'api' );

    /** @return array tool => array( title, write, description, schema properties, required ) */
    public static function contract(): array
    {
        // a card number: EXP-123, or the number of an adopted help desk ticket (7X-2026-00012)
        $number = array( 'type' => 'string', 'pattern' => '^[A-Z0-9]{1,16}(-\d{4})?-\d{1,10}$', 'description' => 'The card number, e.g. EXP-123 or 7X-2026-00012.' );
        $due = array( 'type' => 'string', 'pattern' => '^(\d{4}-\d{2}-\d{2})?$', 'description' => 'A due date YYYY-MM-DD; empty clears it.' );
        $tags = array( 'type' => 'string', 'maxLength' => 255, 'description' => 'Tags, comma separated (stored in lower case, at most 10).' );
        $userId = array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 2147483647, 'description' => 'A user id from centre_list_assignable_users; 0 = unassigned.' );
        $limit = array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 );
        $prefix = array( 'type' => 'string', 'pattern' => '^[A-Z]{2,6}$', 'description' => 'The project prefix, e.g. EXP.' );
        $lane = array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'description' => 'A lane of the project: Backlog, Ready, Doing, Review, Done.' );
        $priority = array( 'type' => 'string', 'enum' => array( 'low', 'medium', 'high', 'urgent' ) );
        return array(
            'centre_whoami' => array( 'Who am I in centre', false, 'The centre user this session acts as, and its grade (viewer, member, manager) per project.', array(), array() ),
            'centre_list_projects' => array( 'List projects', false, 'The projects within your reach, with prefix, name and state.',
                array( 'state' => array( 'type' => 'string', 'enum' => array( 'active', 'archived' ) ) ), array() ),
            'centre_get_project' => array( 'Get a project', false, 'One project: lanes and members (names only).', array( 'prefix' => $prefix ), array( 'prefix' ) ),
            'centre_get_board' => array( 'Get a board', false, 'The board of a project: its lanes with their cards.', array( 'prefix' => $prefix ), array( 'prefix' ) ),
            'centre_search_cards' => array( 'Search cards', false, 'Cards matching words, optionally in one project: number, title, lane, flags.',
                array( 'query' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ), 'prefix' => $prefix ), array( 'query' ) ),
            'centre_get_card' => array( 'Get a card', false, 'One card with what include asks for: comments, subtasks, links, time.',
                array( 'number' => $number, 'include' => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => array( 'comments', 'subtasks', 'links', 'time' ) ) ) ), array( 'number' ) ),
            'centre_create_card' => array( 'Make a ticket', true, 'Creates a card ("make ticket") in a project and returns its number. source ts (a help desk ticket, the default) or card (internal). idempotency_key makes a retried create return the first card.',
                array( 'prefix' => $prefix, 'title' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 255 ), 'body' => array( 'type' => 'string', 'maxLength' => 65000 ),
                       'lane' => $lane, 'priority' => $priority, 'source' => array( 'type' => 'string', 'enum' => array( 'ts', 'card' ) ),
                       'decision_needed' => array( 'type' => 'boolean' ), 'decision_question' => array( 'type' => 'string', 'maxLength' => 1000 ),
                       'assignee_id' => $userId, 'due' => $due, 'tags' => $tags,
                       'idempotency_key' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{8,64}$' ) ),
                array( 'prefix', 'title' ) ),
            'centre_update_card' => array( 'Update a card', true, 'Changes fields of a card (title, body, priority, assignee, due, tags ...); centre refuses fields you may not change.',
                array( 'number' => $number, 'fields' => array( 'type' => 'object' ) ), array( 'number', 'fields' ) ),
            'centre_move_card' => array( 'Move a card', true, 'Moves a card to a lane, optionally to a position. The Done lane resolves a help desk ticket.',
                array( 'number' => $number, 'lane' => $lane, 'position' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 2147483647 ) ), array( 'number', 'lane' ) ),
            'centre_comment' => array( 'Comment on a card', true, 'Adds a comment (Markdown) to a card; on a help desk ticket it is a post its followers get by mail.',
                array( 'number' => $number, 'body' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 65000 ) ), array( 'number', 'body' ) ),
            'centre_add_subtask' => array( 'Add a subtask', true, 'Adds a subtask or checklist item to a card.',
                array( 'number' => $number, 'title' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 255 ) ), array( 'number', 'title' ) ),
            'centre_update_subtask' => array( 'Update a subtask', true, 'Changes a subtask: status 0 to do, 1 doing, 2 done, or its title.',
                array( 'number' => $number, 'id' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 2147483647 ), 'status' => array( 'type' => 'integer', 'enum' => array( 0, 1, 2 ) ),
                       'title' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 255 ) ), array( 'number', 'id' ) ),
            'centre_set_flags' => array( 'Set card flags', true, 'Sets Decision needed (needs centre/decide) or public (needs centre/manage) on a card.',
                array( 'number' => $number, 'decision_needed' => array( 'type' => 'boolean' ), 'decision_question' => array( 'type' => 'string', 'maxLength' => 1000 ),
                       'public' => array( 'type' => 'boolean' ), 'public_title' => array( 'type' => 'string', 'maxLength' => 255 ),
                       'public_summary' => array( 'type' => 'string', 'maxLength' => 1000 ) ), array( 'number' ) ),
            'centre_link' => array( 'Link a card', true, 'Links a card to another card, a ticket, a commit, a pull request, a gnubus message (gb-...) or a URL.',
                array( 'number' => $number, 'kind' => array( 'type' => 'string', 'enum' => array( 'card', 'ticket', 'commit', 'pr', 'gnubus', 'url' ) ),
                       'target' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 255 ), 'label' => array( 'type' => 'string', 'maxLength' => 255 ) ),
                array( 'number', 'kind', 'target' ) ),
            'centre_log_time' => array( 'Log time', true, 'Logs minutes of work on a card (billable unless an SLA covers it, as the help desk rules say).',
                array( 'number' => $number, 'minutes' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 1440 ), 'billable' => array( 'type' => 'boolean' ),
                       'note' => array( 'type' => 'string', 'maxLength' => 1000 ) ), array( 'number', 'minutes' ) ),
            'centre_decisions' => array( 'Weekly decisions', false, 'The weekly list: what is not done and what must be decided, optionally for one project or since a date.',
                array( 'prefix' => $prefix, 'since' => array( 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ) ), array() ),
            'centre_record_decision' => array( 'Record a decision', true, 'Records the decision taken on a card that needed one (needs centre/decide).',
                array( 'number' => $number, 'decision' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 65000 ) ), array( 'number', 'decision' ) ),
            // centre 0.0.0.2: the rest of the partner's Kanboard tools and centre's own views (read unless marked)
            'centre_list_assignable_users' => array( 'List assignable users', false, 'The people who can be assigned in a project (user id and name).', array( 'prefix' => $prefix ), array( 'prefix' ) ),
            'centre_set_tags' => array( 'Set tags', true, 'Replaces the tags of a card.', array( 'number' => $number, 'tags' => $tags ), array( 'number', 'tags' ) ),
            // centre 0.0.0.10: a close says why (a resolution of centre's [ResolutionSettings]; none = "Closed")
            'centre_close_card' => array( 'Close a card', true, 'Moves a card to Done with a resolution (why it was closed: not_needed, duplicate, wont_fix, released ...; none = "Closed"), what the resolution names and an optional comment; a help desk ticket card is resolved and its reply names the resolution.',
                array( 'number' => $number,
                       'resolution' => array( 'type' => 'string', 'pattern' => '^([a-z][a-z0-9_]{0,31})?$', 'description' => 'A resolution key of centre (centre:ticket:close --resolution=list); empty = no resolution.' ),
                       'ref' => array( 'type' => 'string', 'maxLength' => 255, 'description' => 'What the resolution names, required for those that name one: a card number (duplicate, merged), a card number or an http(s) link (superseded, moved_elsewhere), a version (released).' ),
                       'comment' => array( 'type' => 'string', 'maxLength' => 5000, 'description' => 'A comment; on a help desk ticket card it is mailed to the customer with the resolution.' ) ),
                array( 'number' ) ),
            'centre_open_card' => array( 'Reopen a card', true, 'Moves a done card back to Ready; a help desk ticket card is reopened.', array( 'number' => $number ), array( 'number' ) ),
            'centre_activity' => array( 'Activity', false, 'The activity stream (who did what, newest first) of your projects, one project or one card.',
                array( 'prefix' => $prefix, 'number' => $number, 'limit' => $limit ), array() ),
            'centre_overview' => array( 'What needs me', false, 'What needs you now (overdue, due soon, to review, to decide, assigned) and your projects with their lane counts, per customer.', array(), array() ),
            'centre_workload' => array( 'Team workload', false, 'In the projects you lead: who holds which open cards per lane, overdue counts, unassigned cards, the review queue.', array(), array() ),
            'centre_notifications' => array( 'Notifications', false, 'Your notifications (the bell), newest first.', array( 'limit' => $limit ), array() ),
            'centre_desk' => array( 'Staff desk', false, 'Staff only: open help desk tickets per customer with their SLA timers, decisions waiting, unbilled time, sign-ups waiting.', array(), array() ),
            'centre_customer' => array( 'Customer', false, 'A customer\'s services and plans, latest invoices and open tickets (staff, or the customer\'s own users; your own account without customer_id).',
                array( 'customer_id' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 2147483647 ) ), array() ),
            'centre_sync_status' => array( 'Sync status', false, 'Staff only: the Kanboard sync, on or off, linked cards, queued webhooks, the latest steps.', array(), array() ),
            'centre_status' => array( 'Status page', false, 'The public status page: services, their state and since when.', array(), array() ),
        );
    }

    public static function register( tronRegistry $registry, callable $api ): void
    {
        foreach ( self::contract() as $name => $c )
        {
            list( $title, $write, $description, $props, $required ) = $c;
            $schema = array( 'type' => 'object', 'additionalProperties' => false, 'properties' => $props );
            if ( $required )
                $schema['required'] = $required;
            $registry->tool( tronTool::define( array(
                'name' => $name, 'title' => $title, 'description' => $description, 'inputSchema' => $schema,
                'policy' => self::POLICY, 'write' => $write, 'idempotent' => $write && in_array( $name, array( 'centre_move_card', 'centre_set_flags', 'centre_update_card', 'centre_set_tags', 'centre_close_card', 'centre_open_card' ), true ),
                'handler' => function ( array $args, tronCallContext $ctx ) use ( $api, $name ) {
                    return self::call( $api, $name, $args, $ctx->principal->userId );
                },
                // centre owns the answer's shape; tron promises an object
                'outputSchema' => array( 'type' => 'object' ),
                'plan' => $write ? function ( array $args, tronCallContext $ctx ) use ( $api, $name ) {
                    return self::plan( $api, $name, $args, $ctx->principal->userId );
                } : null,
            ) ) );
        }
    }

    /**
     * The computed dry run of a write through centre (doc/dry-run.md): centre's change happens in centre, outside
     * tron's transaction, so the plan is the call that would be made and, for a card, the card as it is now (its
     * state: the apply refuses when the card changed in between). Each requested field is listed with its current value.
     */
    public static function plan( callable $api, string $tool, array $args, int $userId ): array
    {
        $card = null;
        if ( isset( $args['number'] ) )
            $card = self::call( $api, 'centre_get_card', array( 'number' => $args['number'] ), $userId );
        $fields = $tool === 'centre_update_card' ? (array)( $args['fields'] ?? array() ) : $args;
        unset( $fields['number'], $fields['idempotency_key'] );
        ksort( $fields );
        $target = isset( $args['number'] ) ? (string)$args['number'] : 'new card';
        $changes = array();
        foreach ( $fields as $f => $to )
            $changes[] = array( 'kind' => 'card', 'target' => $target, 'op' => $tool, 'field' => (string)$f,
                                'from' => is_array( $card ) && array_key_exists( $f, $card ) ? $card[$f] : null, 'to' => $to );
        if ( !$changes )
            $changes[] = array( 'kind' => 'card', 'target' => $target, 'op' => $tool, 'field' => null, 'from' => null, 'to' => null );
        return array( 'changes' => $changes, 'state' => $card,
                      'effects' => array( "centre runs $tool as this user and checks its own policies again",
                                          'centre records the change in the card activity; followers of a help desk ticket may get mail' ) );
    }

    public static function call( callable $api, string $tool, array $args, int $userId )
    {
        $answer = call_user_func( $api, $tool, $args, $userId );
        if ( !is_array( $answer ) || !array_key_exists( 'ok', $answer ) )
            throw new RuntimeException( "centre answered $tool without an ok field" );
        if ( $answer['ok'] !== true )
        {
            $code = (string)( $answer['error']['code'] ?? 'internal' );
            $message = (string)( $answer['error']['message'] ?? 'centre refused the call' );
            throw new tronToolError( "$code: $message", array( 'code' => $code ) );
        }
        $result = $answer['result'] ?? null;
        return is_array( $result ) && ( $result === array() || !tronSchemaValidator::isList( $result ) ) ? $result : array( 'result' => $result );
    }
}
