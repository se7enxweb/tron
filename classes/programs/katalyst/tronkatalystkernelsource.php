<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Reads Katalyst through its own persistent classes (expKatalystPlan, expKatalystSubscription, expKatalystOrder),
 * when tron runs inside the Katalyst installation and the extension sevenx_katalyst is active there. Read only:
 * nothing here writes. Orders newest first, plans and services by id.
 */
final class tronKatalystKernelSource implements tronKatalystSource
{
    public function available(): bool
    {
        return class_exists( 'expKatalystPlan' ) && class_exists( 'expKatalystSubscription' ) && class_exists( 'expKatalystOrder' );
    }

    public function reason(): string
    {
        return $this->available() ? '' : 'needs the extension sevenx_katalyst (Katalyst) active in this installation';
    }

    public function plans( array $filters, int $limit, int $offset ): array
    {
        $conds = array();
        if ( isset( $filters['source'] ) )
            $conds['source'] = (string)$filters['source'];
        if ( isset( $filters['hidden'] ) )
            $conds['hidden'] = $filters['hidden'] ? 1 : 0;
        if ( isset( $filters['gone'] ) )
            $conds['gone'] = $filters['gone'] ? 1 : 0;
        $c = $conds ?: null;
        $rows = eZPersistentObject::fetchObjectList( expKatalystPlan::definition(), null, $c, array( 'id' => 'asc' ),
                                                     array( 'offset' => $offset, 'length' => $limit ), false );
        return array( 'total' => expKatalystPlan::countRows( $c ), 'items' => is_array( $rows ) ? $rows : array() );
    }

    public function services( array $filters, int $limit, int $offset ): array
    {
        $conds = array();
        foreach ( array( 'server_id', 'plan_id', 'customer_id' ) as $f )
        {
            if ( isset( $filters[$f] ) )
                $conds[$f] = (int)$filters[$f];
        }
        if ( isset( $filters['status'] ) )
            $conds['status'] = (string)$filters['status'];
        if ( isset( $filters['gone'] ) )
            $conds['gone'] = $filters['gone'] ? 1 : 0;
        if ( isset( $filters['domain'] ) && $filters['domain'] !== '' )
            $conds['domain'] = array( 'like', '%' . strtr( (string)$filters['domain'], array( '%' => '', '_' => '' ) ) . '%' );
        $c = $conds ?: null;
        $rows = eZPersistentObject::fetchObjectList( expKatalystSubscription::definition(), null, $c, array( 'id' => 'asc' ),
                                                     array( 'offset' => $offset, 'length' => $limit ), false );
        return array( 'total' => expKatalystSubscription::countRows( $c ), 'items' => is_array( $rows ) ? $rows : array() );
    }

    public function orders( array $filters, int $limit, int $offset ): array
    {
        $conds = array();
        if ( isset( $filters['state'] ) )
            $conds['state'] = (string)$filters['state'];
        if ( isset( $filters['customer_id'] ) )
            $conds['customer_id'] = (int)$filters['customer_id'];
        if ( isset( $filters['since'] ) )
            $conds['created'] = array( '>=', (int)$filters['since'] );
        $c = $conds ?: null;
        $rows = eZPersistentObject::fetchObjectList( expKatalystOrder::definition(), null, $c, array( 'id' => 'desc' ),
                                                     array( 'offset' => $offset, 'length' => $limit ), false );
        return array( 'total' => expKatalystOrder::countRows( $c ), 'items' => is_array( $rows ) ? $rows : array() );
    }
}
