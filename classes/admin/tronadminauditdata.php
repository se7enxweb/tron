<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Setup > tron > Audit: rows newest first with filters, paging and the live tail. Filters are view parameters only:
 * (outcome), (user), (transport), (program), (offset), (after), (part).
 */
final class tronAdminAuditData
{
    const OUTCOMES = array( 'started', 'ok', 'error', 'denied', 'invalid', 'unauthenticated' );
    const TRANSPORTS = array( 'stdio', 'http', 'cli', 'admin' );
    const FILTERS = array( 'outcome', 'user', 'transport', 'program' );
    const PAGE = 50;

    /** @var tronAdminContext */ private $c;

    public function __construct( tronAdminContext $c )
    {
        $this->c = $c;
    }

    /** A view parameter value as the address carries it: the kernel's one encoding (expViewParameters) where present. */
    public static function encode( string $value ): string
    {
        return class_exists( 'expViewParameters' ) ? (string)expViewParameters::encode( $value ) : rawurlencode( $value );
    }

    public static function decode( string $value ): string
    {
        return class_exists( 'expViewParameters' ) ? (string)expViewParameters::decode( $value ) : rawurldecode( $value );
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
                 || ( $k === 'program' && !preg_match( '/^[a-z][a-z0-9]{1,31}$/', $v ) )
                 || ( $k === 'user' && strlen( $v ) > 150 ) )
                continue;
            $f[$k] = $v;
        }
        return $f;
    }

    /**
     * Audit rows newest first. With $afterId > 0 only rows newer than that id (the live tail).
     * @return array array( 'rows', 'total', 'max_id' )
     */
    public function rows( array $filters, int $offset, int $limit, int $afterId = 0 ): array
    {
        if ( in_array( 'tron_audit', $this->c->missingTables(), true ) )
            return array( 'rows' => array(), 'total' => 0, 'max_id' => 0 );
        $filters = self::filtersFrom( $filters );
        $where = array();
        $p = array();
        $cols = array( 'outcome' => 'outcome', 'transport' => 'transport', 'program' => 'program', 'user' => 'login' );
        foreach ( $filters as $k => $v )
        {
            $where[] = $cols[$k] . ' = :' . $k;
            $p[$k] = $v;
        }
        if ( $afterId > 0 )
        {
            $where[] = 'id > :a';
            $p['a'] = $afterId;
        }
        $w = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
        $db = $this->c->db();
        $total = (int)$db->select( 'SELECT count(*) AS n FROM tron_audit' . $w, $p )[0]['n'];
        $rows = $db->select( 'SELECT id, created, login, token_id, transport, remote, method, target, program, outcome, duration_ms, detail FROM tron_audit'
                             . $w . ' ORDER BY id DESC', $p, max( 1, min( 500, $limit ) ), max( 0, $offset ) );
        foreach ( $rows as &$r )
        {
            $d = json_decode( (string)$r['detail'], true );
            $r['args'] = is_array( $d ) && isset( $d['args'] ) ? json_encode( $d['args'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '';
            $r['note'] = is_array( $d ) && isset( $d['note'] ) ? (string)$d['note'] : ( is_array( $d ) ? '' : (string)$r['detail'] );
            unset( $r['detail'] );
        }
        unset( $r );
        $max = (int)$db->select( 'SELECT max(id) AS m FROM tron_audit' )[0]['m'];
        return array( 'rows' => $rows, 'total' => $total, 'max_id' => max( $max, $afterId ) );
    }

    /** The view parameters of the filters as a URL part (tron/audit/(k)/v...), without one of them. */
    public static function base( array $filters, string $without = '' ): string
    {
        $url = 'tron/audit';
        foreach ( self::FILTERS as $k )
        {
            if ( $k !== $without && isset( $filters[$k] ) && $filters[$k] !== '' )
                $url .= '/(' . $k . ')/' . self::encode( (string)$filters[$k] );
        }
        return $url;
    }

    /** Everything the audit page shows, from its view parameters. */
    public function page( array $params ): array
    {
        $filters = self::filtersFrom( $params );
        $offset = isset( $params['offset'] ) ? max( 0, (int)$params['offset'] ) : 0;
        $data = $this->rows( $filters, $offset, self::PAGE );
        $base = self::base( $filters );
        $bases = array( 'all' => $base );
        foreach ( self::FILTERS as $k )
            $bases[$k] = self::base( $filters, $k );
        return array(
            'rows' => $data['rows'], 'total' => $data['total'], 'max_id' => $data['max_id'],
            'filters' => $filters, 'bases' => $bases,
            'outcomes' => self::OUTCOMES, 'transports' => self::TRANSPORTS,
            'programs' => array_column( (array)$this->c->server( false )->option( 'programs' ), 'name' ),
            'first' => $data['total'] && $offset < $data['total'] ? $offset + 1 : 0,
            'last' => min( $data['total'], $offset + self::PAGE ),
            'prev_url' => $offset > 0 ? $base . ( $offset - self::PAGE > 0 ? '/(offset)/' . ( $offset - self::PAGE ) : '' ) : '',
            'next_url' => $offset + self::PAGE < $data['total'] ? $base . '/(offset)/' . ( $offset + self::PAGE ) : '',
            'live_url' => $base . '/(part)/rows',
            'live' => $offset === 0,
            'missing' => $this->c->missingTables(),
        );
    }

    /** The live tail: only rows newer than (after), with the filters of the page. */
    public function tail( array $params ): array
    {
        $after = isset( $params['after'] ) ? max( 0, (int)$params['after'] ) : 0;
        return $this->rows( self::filtersFrom( $params ), 0, self::PAGE, $after );
    }
}
