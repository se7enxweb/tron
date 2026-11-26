<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Setup > tron > Activity: the activity journal. Three pages from one view, all choices view parameters:
 *   list     sessions newest first: (user), (outcome), (transport), (kind), (tool), (from), (to), (offset)
 *   session  (session)/<id>: facts, the story, the timeline with diffs, background jobs it started, the chain check,
 *            and the exports (format)/text|markdown|json
 *   object   (object)/<kind:ref>: everything tron did to one object, across sessions
 */
final class tronAdminActivityData
{
    const OUTCOMES = array( 'ok', 'refused', 'error', 'started', 'dry-run' );
    const TRANSPORTS = array( 'stdio', 'http', 'cli', 'job' );
    const KINDS = array( 'mcp', 'job' );
    const FILTERS = array( 'user', 'outcome', 'transport', 'kind', 'tool', 'from', 'to' );
    const FORMATS = array( 'text' => array( 'text/plain; charset=utf-8', 'txt' ), 'markdown' => array( 'text/markdown; charset=utf-8', 'md' ),
                           'json' => array( 'application/json; charset=utf-8', 'json' ) );
    const PAGE = 50;

    /** @var tronAdminContext */ private $c;
    /** @var tronJournal|null|false */ private $journal = false;

    public function __construct( tronAdminContext $c )
    {
        $this->c = $c;
    }

    public static function encode( string $value ): string
    {
        return tronAdminAuditData::encode( $value );
    }

    public static function decode( string $value ): string
    {
        return tronAdminAuditData::decode( $value );
    }

    /** The journal, or null when its tables are missing. */
    public function journal(): ?tronJournal
    {
        if ( $this->journal === false )
        {
            $db = $this->c->db();
            $this->journal = $db->tableExists( 'tron_journal_step' )
                ? new tronJournal( $db, (array)( $this->c->settings()['JournalSettings'] ?? array() ), function () { return $this->c->now(); } )
                : null;
        }
        return $this->journal;
    }

    /** The valid filters among view parameters; anything else is dropped. */
    public static function filtersFrom( array $params ): array
    {
        $f = array();
        foreach ( self::FILTERS as $k )
        {
            if ( !isset( $params[$k] ) || !is_scalar( $params[$k] ) || (string)$params[$k] === '' )
                continue;
            $v = self::decode( (string)$params[$k] );
            if ( ( $k === 'outcome' && !in_array( $v, self::OUTCOMES, true ) )
                 || ( $k === 'transport' && !in_array( $v, self::TRANSPORTS, true ) )
                 || ( $k === 'kind' && !in_array( $v, self::KINDS, true ) )
                 || ( $k === 'tool' && !preg_match( '/^[a-z][a-z0-9_]{1,63}$/', $v ) )
                 || ( ( $k === 'from' || $k === 'to' ) && tronJournal::time( $v ) === null )
                 || ( $k === 'user' && strlen( $v ) > 150 ) )
                continue;
            $f[$k] = $v;
        }
        return $f;
    }

    /** tron/activity/(k)/v... for the filters, without one of them. */
    public static function base( array $filters, string $without = '' ): string
    {
        $url = 'tron/activity';
        foreach ( self::FILTERS as $k )
            if ( $k !== $without && isset( $filters[$k] ) && $filters[$k] !== '' )
                $url .= '/(' . $k . ')/' . self::encode( (string)$filters[$k] );
        return $url;
    }

    public function page( array $params ): array
    {
        $common = array( 'missing' => $this->journal() ? array() : array( 'tron_journal_session', 'tron_journal_step', 'tron_journal_object' ),
                         'enabled' => ( $this->c->setting( 'JournalSettings', 'Enabled', 'disabled' ) === 'enabled' ),
                         'formats' => array_keys( self::FORMATS ) );
        if ( isset( $params['session'] ) && (int)$params['session'] > 0 )
            return $common + $this->sessionPage( (int)$params['session'] );
        if ( isset( $params['object'] ) && is_scalar( $params['object'] ) && $params['object'] !== '' )
            return $common + $this->objectPage( self::decode( (string)$params['object'] ), $params );
        return $common + $this->listPage( $params );
    }

    private function listPage( array $params ): array
    {
        $filters = self::filtersFrom( $params );
        $offset = isset( $params['offset'] ) ? max( 0, (int)$params['offset'] ) : 0;
        $j = $this->journal();
        $data = $j ? $j->sessions( $filters, self::PAGE, $offset ) : array( 'rows' => array(), 'total' => 0 );
        $base = self::base( $filters );
        $bases = array( 'all' => $base );
        foreach ( self::FILTERS as $k )
            $bases[$k] = self::base( $filters, $k );
        $sessions = array();
        foreach ( $data['rows'] as $s )
            $sessions[] = $s + array( 'client' => trim( $s['client_name'] . ' ' . $s['client_version'] ) );
        return array(
            'mode' => 'list', 'sessions' => $sessions, 'total' => $data['total'], 'filters' => $filters, 'bases' => $bases,
            'outcomes' => self::OUTCOMES, 'transports' => self::TRANSPORTS, 'kinds' => self::KINDS,
            'first' => $data['total'] && $offset < $data['total'] ? $offset + 1 : 0, 'last' => min( $data['total'], $offset + self::PAGE ),
            'prev_url' => $offset > 0 ? $base . ( $offset - self::PAGE > 0 ? '/(offset)/' . ( $offset - self::PAGE ) : '' ) : '',
            'next_url' => $offset + self::PAGE < $data['total'] ? $base . '/(offset)/' . ( $offset + self::PAGE ) : '',
        );
    }

    private function sessionPage( int $id ): array
    {
        $j = $this->journal();
        $s = $j ? $j->session( $id ) : null;
        if ( !$s )
            return array( 'mode' => 'session', 'session' => null );
        $steps = $j->steps( $id );
        $verify = $j->verify( $id );
        $n = $this->narrator();
        $jobs = $j->sessions( array(), 50 )['rows'];
        $children = array_values( array_filter( $jobs, function ( $r ) use ( $id ) { return (int)$r['parent_id'] === $id; } ) );
        $timeline = $n->timeline( $steps );
        foreach ( $timeline as &$r )
        {
            $r['objects_links'] = array();
            foreach ( $r['objects_list'] as $o )
                if ( isset( $o['kind'], $o['ref'] ) )
                    $r['objects_links'][] = array( 'label' => $n->objectsText( array( $o ) ), 'action' => (string)( $o['action'] ?? 'read' ),
                                                   'url' => 'tron/activity/(object)/' . self::encode( $o['kind'] . ':' . $o['ref'] ) );
            $r['input_pretty'] = self::pretty( $r['input'] );
            $r['diff_pretty'] = self::pretty( $r['diff'] );
        }
        unset( $r );
        return array(
            'mode' => 'session', 'session' => $s + array( 'client' => trim( $s['client_name'] . ' ' . $s['client_version'] ) ),
            'timeline' => $timeline, 'narrative' => $n->narrative( $s, $steps, $verify ), 'verify' => $verify,
            'children' => $children, 'parent' => $s['parent_id'] > 0 ? $j->session( (int)$s['parent_id'] ) : null,
            'export_base' => 'tron/activity/(session)/' . $id . '/(format)/',
        );
    }

    private function objectPage( string $object, array $params ): array
    {
        $o = tronJournalObjects::parse( $object );
        $offset = isset( $params['offset'] ) ? max( 0, (int)$params['offset'] ) : 0;
        $j = $this->journal();
        $data = $o && $j ? $j->objectHistory( $o[0] . ':' . $o[1], self::PAGE, $offset ) : array( 'rows' => array(), 'total' => 0 );
        $n = $this->narrator();
        $rows = array();
        foreach ( $data['rows'] as $r )
            $rows[] = $r + array( 'label' => $n->timeline( array( $r ) )[0]['label'], 'objects_text' => $n->objectsText( $r['objects_list'] ) );
        $base = 'tron/activity/(object)/' . self::encode( $object );
        return array(
            'mode' => 'object', 'object' => $o ? $o[0] . ':' . $o[1] : $object, 'object_label' => $o ? $n->objectsText( array( array( 'kind' => $o[0], 'ref' => $o[1] ) ) ) : $object,
            'valid' => $o !== null, 'steps' => $rows, 'total' => $data['total'],
            'first' => $data['total'] && $offset < $data['total'] ? $offset + 1 : 0, 'last' => min( $data['total'], $offset + self::PAGE ),
            'prev_url' => $offset > 0 ? $base . ( $offset - self::PAGE > 0 ? '/(offset)/' . ( $offset - self::PAGE ) : '' ) : '',
            'next_url' => $offset + self::PAGE < $data['total'] ? $base . '/(offset)/' . ( $offset + self::PAGE ) : '',
        );
    }

    /**
     * A session exported: array( 'type' => content type, 'filename', 'body' ), or null when there is no such session.
     */
    public function export( int $id, string $format ): ?array
    {
        $j = $this->journal();
        $s = $j ? $j->session( $id ) : null;
        if ( !$s || !isset( self::FORMATS[$format] ) )
            return null;
        $steps = $j->steps( $id );
        $body = $this->narrator()->render( $format, $s, $steps, $j->verify( $id ) );
        list( $type, $ext ) = self::FORMATS[$format];
        return array( 'type' => $type, 'filename' => 'tron-session-' . $id . '.' . $ext, 'body' => (string)$body );
    }

    private function narrator(): tronJournalNarrative
    {
        $titles = array();
        try
        {
            $titles = tronJournalNarrative::titlesOf( $this->c->server( false ) );
        }
        catch ( Throwable $e )
        {
            // the names stand in for the titles
        }
        return new tronJournalNarrative( function ( string $text, array $params ) { return $this->c->tr( $text, $params ); }, $titles );
    }

    private static function pretty( string $s ): string
    {
        if ( $s === '' )
            return '';
        $d = json_decode( $s, true );
        return is_array( $d ) ? (string)json_encode( $d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : $s;
    }
}
