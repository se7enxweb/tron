<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/** Katalyst rows held in memory, with the same filter semantics as the kernel source (tests, demos). */
final class tronKatalystArraySource implements tronKatalystSource
{
    /** @var array */ private $data;

    /** @param array $data array( 'plans' => rows, 'services' => rows, 'orders' => rows ) */
    public function __construct( array $data )
    {
        $this->data = $data + array( 'plans' => array(), 'services' => array(), 'orders' => array() );
    }

    public function available(): bool
    {
        return true;
    }

    public function reason(): string
    {
        return '';
    }

    public function plans( array $filters, int $limit, int $offset ): array
    {
        return $this->page( array_filter( $this->data['plans'], function ( $r ) use ( $filters ) {
            if ( isset( $filters['source'] ) && $r['source'] !== $filters['source'] )
                return false;
            if ( isset( $filters['hidden'] ) && (bool)$r['hidden'] !== (bool)$filters['hidden'] )
                return false;
            if ( isset( $filters['gone'] ) && (bool)$r['gone'] !== (bool)$filters['gone'] )
                return false;
            return true;
        } ), $limit, $offset, false );
    }

    public function services( array $filters, int $limit, int $offset ): array
    {
        return $this->page( array_filter( $this->data['services'], function ( $r ) use ( $filters ) {
            foreach ( array( 'status', 'server_id', 'plan_id', 'customer_id' ) as $f )
            {
                if ( isset( $filters[$f] ) && (string)$r[$f] !== (string)$filters[$f] )
                    return false;
            }
            if ( isset( $filters['gone'] ) && (bool)$r['gone'] !== (bool)$filters['gone'] )
                return false;
            if ( isset( $filters['domain'] ) && stripos( (string)$r['domain'], (string)$filters['domain'] ) === false )
                return false;
            return true;
        } ), $limit, $offset, false );
    }

    public function orders( array $filters, int $limit, int $offset ): array
    {
        return $this->page( array_filter( $this->data['orders'], function ( $r ) use ( $filters ) {
            if ( isset( $filters['state'] ) && $r['state'] !== $filters['state'] )
                return false;
            if ( isset( $filters['customer_id'] ) && (int)$r['customer_id'] !== (int)$filters['customer_id'] )
                return false;
            if ( isset( $filters['since'] ) && (int)$r['created'] < (int)$filters['since'] )
                return false;
            return true;
        } ), $limit, $offset, true );
    }

    private function page( array $rows, int $limit, int $offset, bool $newestFirst ): array
    {
        $rows = array_values( $rows );
        usort( $rows, function ( $a, $b ) use ( $newestFirst ) {
            return $newestFirst ? (int)$b['id'] <=> (int)$a['id'] : (int)$a['id'] <=> (int)$b['id'];
        } );
        return array( 'total' => count( $rows ), 'items' => array_slice( $rows, $offset, $limit ) );
    }
}
