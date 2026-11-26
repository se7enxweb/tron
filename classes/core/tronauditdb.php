<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/** Keeps audit entries in the table tron_audit, and reads them back (newest first). */
final class tronAuditDb implements tronAuditSink
{
    const COLUMNS = array( 'created', 'user_id', 'login', 'token_id', 'transport', 'remote', 'method', 'target',
                           'program', 'outcome', 'duration_ms', 'detail' );

    /** @var tronDb */ private $db;

    public function __construct( tronDb $db )
    {
        $this->db = $db;
    }

    public function record( array $entry ): void
    {
        $row = array();
        foreach ( self::COLUMNS as $c )
        {
            $v = $entry[$c] ?? null;
            if ( in_array( $c, array( 'created', 'user_id', 'token_id', 'duration_ms' ), true ) )
                $row[$c] = (int)$v;
            else
                $row[$c] = self::cut( (string)$v, $c === 'detail' ? 8000 : ( $c === 'target' ? 255 : 150 ) );
        }
        $this->db->insert( 'tron_audit', $row );
    }

    /** @return array[] */
    public function recent( int $limit = 50, int $offset = 0, ?int $userId = null ): array
    {
        $where = $userId !== null ? ' WHERE user_id = :u' : '';
        $params = $userId !== null ? array( 'u' => $userId ) : array();
        return $this->db->select( 'SELECT * FROM tron_audit' . $where . ' ORDER BY id DESC', $params, max( 1, min( 500, $limit ) ), $offset );
    }

    private static function cut( string $s, int $max ): string
    {
        if ( strlen( $s ) <= $max )
            return $s;
        $cut = function_exists( 'mb_strcut' ) ? mb_strcut( $s, 0, $max - 3, 'UTF-8' ) : substr( $s, 0, $max - 3 );
        return $cut . '...';
    }
}
