<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Tickets, cards and decisions of the centre, with their comments, links and change history, in the tables
 * tron_centre_*. One row type serves all three kinds: a card is a ticket that lives on a board, a decision is a
 * ticket with a decision week and, once taken, the decision text.
 *
 * Every change writes one history row per changed field. Methods throw InvalidArgumentException for input the
 * caller can correct (unknown ticket, bad lane), which the program turns into a tool error.
 */
final class tronCentreStore
{
    const KINDS = array( 'ticket', 'card', 'decision' );
    const STATUSES = array( 'open', 'closed' );
    const PRIORITIES = array( 'low', 'normal', 'high', 'urgent' );
    const LINK_KINDS = array( 'gnubus', 'todo', 'url', 'ticket', 'commit' );
    const SLUG = '/^[a-z0-9][a-z0-9-]{0,63}$/';
    const LABEL = '/^[a-z0-9][a-z0-9-]{0,31}$/';
    const WEEK = '/^\d{4}-W(0[1-9]|[1-4]\d|5[0-3])$/';
    const GNUBUS_ID = '/^gb-\d{8}T\d{6}Z-[a-z0-9][a-z0-9.-]{0,62}-[0-9a-f]{4}$/';
    const TODO_REF = '/^[A-Za-z0-9@._-]{1,64}#\d{1,6}$/';
    const COMMIT_REF = '/^[A-Za-z0-9._\/-]{1,128}@[0-9a-f]{7,40}$/';

    const UPDATABLE = array( 'kind', 'title', 'body', 'priority', 'labels', 'assignee_id', 'due', 'decision_week', 'status' );

    /** @var tronDb */ private $db;
    /** @var callable */ private $clock;
    /** @var string[] */ private $lanes;
    /** @var string[] */ private $boards;

    /**
     * @param string[] $lanes  the lanes a ticket may be in (first: where new tickets go)
     * @param string[] $boards the boards that exist (empty: any board name that fits the pattern)
     */
    public function __construct( tronDb $db, array $lanes = array( 'inbox', 'next', 'doing', 'waiting', 'done' ), array $boards = array(), ?callable $clock = null )
    {
        $this->db = $db;
        $this->lanes = $lanes ? array_values( $lanes ) : array( 'inbox' );
        $this->boards = array_values( $boards );
        $this->clock = $clock ?: 'time';
    }

    public function lanes(): array
    {
        return $this->lanes;
    }

    public function create( array $f, int $userId ): array
    {
        $now = $this->now();
        $kind = $f['kind'] ?? 'ticket';
        self::oneOf( $kind, self::KINDS, 'kind' );
        $title = self::text( $f['title'] ?? '', 'title', 1, 255 );
        $board = $this->checkBoard( $f['board'] ?? 'main' );
        $lane = $this->checkLane( $f['lane'] ?? $this->lanes[0] );
        $priority = $f['priority'] ?? 'normal';
        self::oneOf( $priority, self::PRIORITIES, 'priority' );
        $week = (string)( $f['decision_week'] ?? '' );
        if ( $week !== '' && !preg_match( self::WEEK, $week ) )
            throw new InvalidArgumentException( "decision_week '$week' is not an ISO week like 2026-W48" );
        if ( $kind === 'decision' && $week === '' )
            $week = self::isoWeek( $now );
        $row = array(
            'kind' => $kind, 'board' => $board, 'lane' => $lane, 'position' => $this->nextPosition( $board, $lane ),
            'status' => 'open', 'priority' => $priority, 'title' => $title,
            'body' => self::text( $f['body'] ?? '', 'body', 0, 65000 ), 'labels' => self::labels( $f['labels'] ?? array() ),
            'assignee_id' => (int)( $f['assignee_id'] ?? 0 ), 'created_by' => $userId, 'created' => $now, 'modified' => $now,
            'closed' => 0, 'closed_reason' => '', 'due' => (int)( $f['due'] ?? 0 ), 'decision_week' => $week, 'decision' => '',
        );
        $this->db->begin();
        try
        {
            $id = $this->db->insert( 'tron_centre_ticket', $row );
            $this->history( $id, $userId, 'created', '', $kind . ' on ' . $board . '/' . $lane );
            $this->db->commit();
        }
        catch ( Throwable $e )
        {
            $this->db->rollback();
            throw $e;
        }
        return $this->get( $id );
    }

    public function get( int $id ): ?array
    {
        $rows = $this->db->select( 'SELECT * FROM tron_centre_ticket WHERE id = :id', array( 'id' => $id ) );
        return $rows ? $rows[0] : null;
    }

    public function mustGet( int $id ): array
    {
        $t = $this->get( $id );
        if ( !$t )
            throw new InvalidArgumentException( "There is no ticket $id" );
        return $t;
    }

    /** Changes the given fields. Closing goes through close(); status may only be set back to open here. */
    public function update( int $id, array $changes, int $userId ): array
    {
        $t = $this->mustGet( $id );
        $set = array();
        foreach ( $changes as $field => $value )
        {
            if ( !in_array( $field, self::UPDATABLE, true ) )
                throw new InvalidArgumentException( "The field $field cannot be changed" );
            switch ( $field )
            {
                case 'kind':     self::oneOf( $value, self::KINDS, 'kind' ); break;
                case 'title':    $value = self::text( $value, 'title', 1, 255 ); break;
                case 'body':     $value = self::text( $value, 'body', 0, 65000 ); break;
                case 'priority': self::oneOf( $value, self::PRIORITIES, 'priority' ); break;
                case 'labels':   $value = self::labels( $value ); break;
                case 'assignee_id':
                case 'due':      $value = (int)$value; break;
                case 'decision_week':
                    $value = (string)$value;
                    if ( $value !== '' && !preg_match( self::WEEK, $value ) )
                        throw new InvalidArgumentException( "decision_week '$value' is not an ISO week like 2026-W48" );
                    break;
                case 'status':
                    if ( $value !== 'open' )
                        throw new InvalidArgumentException( 'Close a ticket with centre_ticket_close; status can only be set back to open here' );
                    break;
            }
            if ( (string)$t[$field] !== (string)$value )
                $set[$field] = $value;
        }
        if ( isset( $set['status'] ) )
        {
            $set['closed'] = 0;
            $set['closed_reason'] = '';
        }
        return $this->apply( $t, $set, $userId );
    }

    public function move( int $id, ?string $board, string $lane, ?int $position, int $userId ): array
    {
        $t = $this->mustGet( $id );
        $board = $board === null || $board === '' ? (string)$t['board'] : $this->checkBoard( $board );
        $lane = $this->checkLane( $lane );
        $set = array();
        if ( $board !== $t['board'] )
            $set['board'] = $board;
        if ( $lane !== $t['lane'] )
            $set['lane'] = $lane;
        $pos = $position !== null ? max( 0, $position ) : ( $set ? $this->nextPosition( $board, $lane ) : (int)$t['position'] );
        if ( $pos !== (int)$t['position'] )
            $set['position'] = $pos;
        return $this->apply( $t, $set, $userId );
    }

    public function close( int $id, string $reason, int $userId ): array
    {
        $t = $this->mustGet( $id );
        if ( $t['status'] === 'closed' )
            return $t;
        return $this->apply( $t, array( 'status' => 'closed', 'closed' => $this->now(),
                                        'closed_reason' => self::text( $reason, 'reason', 0, 255 ) ), $userId );
    }

    /** Records the decision of a decision ticket and, by default, closes it. */
    public function decide( int $id, string $decision, bool $close, int $userId ): array
    {
        $t = $this->mustGet( $id );
        if ( $t['kind'] !== 'decision' )
            throw new InvalidArgumentException( "Ticket $id is a {$t['kind']}, not a decision" );
        $set = array( 'decision' => self::text( $decision, 'decision', 1, 65000 ) );
        if ( $t['decision_week'] === '' )
            $set['decision_week'] = self::isoWeek( $this->now() );
        if ( $close && $t['status'] !== 'closed' )
            $set += array( 'status' => 'closed', 'closed' => $this->now(), 'closed_reason' => 'decided' );
        return $this->apply( $t, $set, $userId );
    }

    public function comment( int $id, string $body, int $userId ): array
    {
        $t = $this->mustGet( $id );
        $now = $this->now();
        $row = array( 'ticket_id' => $id, 'user_id' => $userId, 'body' => self::text( $body, 'body', 1, 65000 ), 'created' => $now );
        $this->db->begin();
        try
        {
            $row['id'] = $this->db->insert( 'tron_centre_comment', $row );
            $this->db->execute( 'UPDATE tron_centre_ticket SET modified = :m WHERE id = :id', array( 'm' => $now, 'id' => $id ) );
            $this->db->commit();
        }
        catch ( Throwable $e )
        {
            $this->db->rollback();
            throw $e;
        }
        return $row;
    }

    public function link( int $id, string $kind, string $ref, string $note, int $userId ): array
    {
        $this->mustGet( $id );
        self::oneOf( $kind, self::LINK_KINDS, 'kind' );
        $ref = trim( $ref );
        self::checkRef( $kind, $ref );
        if ( $kind === 'ticket' )
        {
            if ( (int)$ref === $id )
                throw new InvalidArgumentException( 'A ticket cannot link to itself' );
            $this->mustGet( (int)$ref );
        }
        $existing = $this->db->select( 'SELECT * FROM tron_centre_link WHERE ticket_id = :t AND kind = :k AND ref = :r',
                                       array( 't' => $id, 'k' => $kind, 'r' => $ref ) );
        if ( $existing )
            return $existing[0] + array( 'existed' => true );
        $row = array( 'ticket_id' => $id, 'kind' => $kind, 'ref' => $ref, 'note' => self::text( $note, 'note', 0, 255 ),
                      'user_id' => $userId, 'created' => $this->now() );
        $row['id'] = $this->db->insert( 'tron_centre_link', $row );
        $this->history( $id, $userId, 'link', '', $kind . ':' . $ref );
        return $row + array( 'existed' => false );
    }

    /** @return array[] the links of a kind and ref, with their tickets */
    public function findLinks( string $kind, string $ref ): array
    {
        self::oneOf( $kind, self::LINK_KINDS, 'kind' );
        return $this->db->select( 'SELECT l.id AS link_id, l.ticket_id, l.note, l.created AS linked, t.title, t.status, t.kind AS ticket_kind'
                                  . ' FROM tron_centre_link l, tron_centre_ticket t WHERE l.ticket_id = t.id AND l.kind = :k AND l.ref = :r ORDER BY l.id',
                                  array( 'k' => $kind, 'r' => trim( $ref ) ) );
    }

    public function comments( int $id ): array
    {
        return $this->db->select( 'SELECT * FROM tron_centre_comment WHERE ticket_id = :t ORDER BY id', array( 't' => $id ) );
    }

    public function links( int $id ): array
    {
        return $this->db->select( 'SELECT * FROM tron_centre_link WHERE ticket_id = :t ORDER BY id', array( 't' => $id ) );
    }

    public function historyOf( int $id ): array
    {
        return $this->db->select( 'SELECT * FROM tron_centre_history WHERE ticket_id = :t ORDER BY id', array( 't' => $id ) );
    }

    /**
     * Filters: status (open, closed, any; default open), kind, board, lane, assignee_id, label, query (in the title,
     * case-insensitive), decision_week. Newest change first.
     * @return array array( 'total' => int, 'items' => array[] )
     */
    public function search( array $filters, int $limit, int $offset ): array
    {
        $where = array();
        $p = array();
        $status = $filters['status'] ?? 'open';
        if ( $status !== 'any' )
        {
            self::oneOf( $status, self::STATUSES, 'status' );
            $where[] = 'status = :status';
            $p['status'] = $status;
        }
        foreach ( array( 'kind', 'board', 'lane', 'decision_week' ) as $f )
        {
            if ( isset( $filters[$f] ) && $filters[$f] !== '' )
            {
                $where[] = "$f = :$f";
                $p[$f] = (string)$filters[$f];
            }
        }
        if ( isset( $filters['assignee_id'] ) )
        {
            $where[] = 'assignee_id = :assignee_id';
            $p['assignee_id'] = (int)$filters['assignee_id'];
        }
        if ( isset( $filters['label'] ) && $filters['label'] !== '' )
        {
            if ( !preg_match( self::LABEL, (string)$filters['label'] ) )
                throw new InvalidArgumentException( "'{$filters['label']}' is not a label" );
            $where[] = 'labels LIKE :label';
            $p['label'] = '%,' . $filters['label'] . ',%';
        }
        if ( isset( $filters['query'] ) && trim( (string)$filters['query'] ) !== '' )
        {
            $where[] = "lower(title) LIKE :q ESCAPE '!'";
            $p['q'] = '%' . strtr( strtolower( trim( (string)$filters['query'] ) ), array( '!' => '!!', '%' => '!%', '_' => '!_' ) ) . '%';
        }
        $w = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
        $total = $this->db->select( 'SELECT count(*) AS n FROM tron_centre_ticket' . $w, $p );
        $items = $this->db->select( 'SELECT * FROM tron_centre_ticket' . $w . ' ORDER BY modified DESC, id DESC', $p, $limit, $offset );
        return array( 'total' => (int)( $total[0]['n'] ?? 0 ), 'items' => $items );
    }

    /** The open tickets of a board, lane by lane in the configured order, each lane by position. */
    public function board( string $board, int $perLane = 50 ): array
    {
        $board = $this->checkBoard( $board );
        $out = array();
        foreach ( $this->lanes as $lane )
        {
            $p = array( 'b' => $board, 'l' => $lane );
            $n = $this->db->select( "SELECT count(*) AS n FROM tron_centre_ticket WHERE board = :b AND lane = :l AND status = 'open'", $p );
            $out[$lane] = array(
                'total' => (int)( $n[0]['n'] ?? 0 ),
                'items' => $this->db->select( "SELECT * FROM tron_centre_ticket WHERE board = :b AND lane = :l AND status = 'open' ORDER BY position, id", $p, $perLane ),
            );
        }
        return $out;
    }

    /**
     * The weekly decision review for an ISO week: open decisions overdue (an earlier week), due (this week) and
     * undated, and the decisions taken (closed) during the week.
     */
    public function decisionReview( string $week ): array
    {
        if ( !preg_match( self::WEEK, $week ) )
            throw new InvalidArgumentException( "'$week' is not an ISO week like 2026-W48" );
        list( $from, $to ) = self::weekRange( $week );
        $open = $this->db->select( "SELECT * FROM tron_centre_ticket WHERE kind = 'decision' AND status = 'open' ORDER BY decision_week, id" );
        $out = array( 'overdue' => array(), 'due' => array(), 'undated' => array(), 'later' => array() );
        foreach ( $open as $t )
        {
            if ( $t['decision_week'] === '' )
                $out['undated'][] = $t;
            else if ( strcmp( $t['decision_week'], $week ) < 0 )
                $out['overdue'][] = $t;
            else if ( $t['decision_week'] === $week )
                $out['due'][] = $t;
            else
                $out['later'][] = $t;
        }
        $out['decided'] = $this->db->select( "SELECT * FROM tron_centre_ticket WHERE kind = 'decision' AND status = 'closed' AND closed >= :f AND closed < :t ORDER BY closed, id",
                                             array( 'f' => $from, 't' => $to ) );
        return $out;
    }

    public static function isoWeek( int $ts ): string
    {
        return gmdate( 'o-\WW', $ts );
    }

    /** @return int[] array( monday 00:00 UTC, next monday 00:00 UTC ) */
    public static function weekRange( string $week ): array
    {
        $year = (int)substr( $week, 0, 4 );
        $w = (int)substr( $week, 6, 2 );
        $d = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
        $d->setISODate( $year, $w, 1 );
        $d->setTime( 0, 0, 0 );
        $from = $d->getTimestamp();
        return array( $from, $from + 7 * 86400 );
    }

    public static function checkRef( string $kind, string $ref ): void
    {
        $ok = true;
        switch ( $kind )
        {
            case 'gnubus': $ok = (bool)preg_match( self::GNUBUS_ID, $ref ); $hint = 'a gnubus message id like gb-20261124T101500Z-cloud-1a2b'; break;
            case 'todo':   $ok = (bool)preg_match( self::TODO_REF, $ref ); $hint = 'a session to-do like claude@cloud#12'; break;
            case 'url':    $ok = (bool)preg_match( '#^https?://[^\s]{1,240}$#', $ref ); $hint = 'an http(s) URL'; break;
            case 'ticket': $ok = ctype_digit( $ref ) && (int)$ref > 0; $hint = 'a ticket id'; break;
            case 'commit': $ok = (bool)preg_match( self::COMMIT_REF, $ref ); $hint = 'repository@sha like se7enxweb/tron@1a2b3c4'; break;
            default:       $hint = '';
        }
        if ( !$ok )
            throw new InvalidArgumentException( "The $kind reference '$ref' is not valid: expected $hint" );
    }

    private function apply( array $t, array $set, int $userId ): array
    {
        if ( !$set )
            return $t;
        $now = $this->now();
        $set['modified'] = $now;
        $assign = array();
        $p = array( 'id' => (int)$t['id'] );
        foreach ( $set as $k => $v )
        {
            $assign[] = "$k = :$k";
            $p[$k] = $v;
        }
        $this->db->begin();
        try
        {
            $this->db->execute( 'UPDATE tron_centre_ticket SET ' . implode( ', ', $assign ) . ' WHERE id = :id', $p );
            foreach ( $set as $k => $v )
            {
                if ( $k !== 'modified' && $k !== 'closed' )
                    $this->history( (int)$t['id'], $userId, $k, (string)$t[$k], (string)$v );
            }
            $this->db->commit();
        }
        catch ( Throwable $e )
        {
            $this->db->rollback();
            throw $e;
        }
        return $this->mustGet( (int)$t['id'] );
    }

    private function history( int $id, int $userId, string $field, string $old, string $new ): void
    {
        $this->db->insert( 'tron_centre_history', array(
            'ticket_id' => $id, 'user_id' => $userId, 'created' => $this->now(), 'field' => $field,
            'old_value' => self::cut( $old ), 'new_value' => self::cut( $new ),
        ) );
    }

    private function nextPosition( string $board, string $lane ): int
    {
        $r = $this->db->select( 'SELECT max(position) AS m FROM tron_centre_ticket WHERE board = :b AND lane = :l', array( 'b' => $board, 'l' => $lane ) );
        return $r && $r[0]['m'] !== null ? (int)$r[0]['m'] + 1 : 0;
    }

    private function checkBoard( string $board ): string
    {
        if ( !preg_match( self::SLUG, $board ) )
            throw new InvalidArgumentException( "'$board' is not a board name (a-z, 0-9 and -)" );
        if ( $this->boards && !in_array( $board, $this->boards, true ) )
            throw new InvalidArgumentException( "There is no board '$board'; the boards are " . implode( ', ', $this->boards ) );
        return $board;
    }

    private function checkLane( string $lane ): string
    {
        if ( !in_array( $lane, $this->lanes, true ) )
            throw new InvalidArgumentException( "There is no lane '$lane'; the lanes are " . implode( ', ', $this->lanes ) );
        return $lane;
    }

    private static function oneOf( $value, array $allowed, string $what ): void
    {
        if ( !in_array( $value, $allowed, true ) )
            throw new InvalidArgumentException( "$what must be one of " . implode( ', ', $allowed ) );
    }

    private static function text( $value, string $what, int $min, int $max ): string
    {
        $s = trim( (string)$value );
        $len = function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
        if ( $len < $min )
            throw new InvalidArgumentException( "$what must not be empty" );
        if ( $len > $max )
            throw new InvalidArgumentException( "$what is longer than $max characters" );
        return $s;
    }

    /** @param string[]|string $labels */
    private static function labels( $labels ): string
    {
        $list = is_array( $labels ) ? $labels : explode( ',', (string)$labels );
        $out = array();
        foreach ( $list as $l )
        {
            $l = strtolower( trim( (string)$l ) );
            if ( $l === '' )
                continue;
            if ( !preg_match( self::LABEL, $l ) )
                throw new InvalidArgumentException( "'$l' is not a label (a-z, 0-9 and -, up to 32)" );
            $out[$l] = $l;
        }
        if ( count( $out ) > 8 )
            throw new InvalidArgumentException( 'A ticket has at most 8 labels' );
        sort( $out );
        return $out ? ',' . implode( ',', $out ) . ',' : '';
    }

    private static function cut( string $s ): string
    {
        if ( strlen( $s ) <= 255 )
            return $s;
        return ( function_exists( 'mb_strcut' ) ? mb_strcut( $s, 0, 252, 'UTF-8' ) : substr( $s, 0, 252 ) ) . '...';
    }

    private function now(): int
    {
        return (int)call_user_func( $this->clock );
    }
}
