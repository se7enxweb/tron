<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The program "katalyst": read-only views of Katalyst's services (hosting subscriptions), plans and orders.
 *
 * Only the columns listed in this class leave the server: ids, states, plan and server references, amounts and
 * dates. Names, addresses, e-mail addresses and IP addresses of customers are never among them. A service's domain
 * is shown in full only to holders of tron/katalyst_detail; everyone else with tron/katalyst_read sees it masked
 * (ex****e.com).
 *
 * The source is the environment service "katalyst.source" (a tronKatalystSource). Without one, or when it is not
 * available here, the tools are not registered and tron_status says why.
 */
final class tronKatalystProgram implements tronProgram
{
    const READ = array( 'tron', 'katalyst_read' );

    const PLAN_COLUMNS = array( 'id', 'source', 'server_id', 'external_id', 'name', 'group_name', 'type', 'pay_type',
                                'price_monthly', 'currency', 'hidden', 'gone', 'created', 'modified' );
    const SERVICE_COLUMNS = array( 'id', 'customer_id', 'server_id', 'domain', 'status', 'hosting_type', 'plan_id',
                                   'plesk_plan_id', 'service_kind', 'billing_cycle', 'next_due_date', 'disk_total_bytes',
                                   'traffic_bytes', 'gone', 'created', 'modified' );
    const ORDER_COLUMNS = array( 'id', 'source', 'shop_order_nr', 'customer_id', 'state', 'currency', 'total_ex_vat',
                                 'total_inc_vat', 'item_count', 'fraud_state', 'created', 'modified' );
    const INT_COLUMNS = array( 'id', 'server_id', 'hidden', 'gone', 'created', 'modified', 'customer_id', 'plan_id',
                               'plesk_plan_id', 'disk_total_bytes', 'traffic_bytes', 'shop_order_nr', 'item_count' );

    public function register( tronRegistry $registry, tronEnvironment $env ): void
    {
        $names = array( 'katalyst_plans_list', 'katalyst_services_list', 'katalyst_orders_list' );
        $source = $env->has( 'katalyst.source' ) ? $env->get( 'katalyst.source' ) : null;
        if ( !$source instanceof tronKatalystSource || !$source->available() )
        {
            $why = $source instanceof tronKatalystSource ? $source->reason() : 'no Katalyst source is configured here';
            foreach ( $names as $n )
                $registry->unavailable( $n, $why );
            return;
        }
        $limit = array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 );
        $offset = array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 );
        $idf = array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 2147483647 );

        $registry->tool( tronTool::define( array(
            'name' => 'katalyst_plans_list', 'title' => 'Katalyst plans',
            'description' => 'The plans and products Katalyst knows (Plesk service plans per server, shop products, archived WHMCS products), by id. By default only the ones still there (gone false).',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
                'source' => array( 'type' => 'string', 'maxLength' => 32 ), 'hidden' => array( 'type' => 'boolean' ),
                'gone' => array( 'type' => 'boolean', 'default' => false ), 'limit' => $limit, 'offset' => $offset,
            ) ),
            'policy' => self::READ, 'outputSchema' => self::pageSchema( self::PLAN_COLUMNS ),
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source ) {
                $r = $source->plans( $a, (int)$a['limit'], (int)$a['offset'] );
                return self::page( $r, $a, self::PLAN_COLUMNS, false );
            },
        ) ) );

        $registry->tool( tronTool::define( array(
            'name' => 'katalyst_services_list', 'title' => 'Katalyst services',
            'description' => 'Hosting services (subscriptions): status, server, plan, billing cycle, next due date, disk use. Filters: status, server_id, plan_id, customer_id, domain (part of it), gone (default false). The domain is shown in full only with the policy tron/katalyst_detail.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
                'status' => array( 'type' => 'string', 'maxLength' => 32 ), 'server_id' => $idf, 'plan_id' => $idf,
                'customer_id' => $idf, 'domain' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 253 ),
                'gone' => array( 'type' => 'boolean', 'default' => false ), 'limit' => $limit, 'offset' => $offset,
            ) ),
            'policy' => self::READ, 'outputSchema' => self::pageSchema( self::SERVICE_COLUMNS ),
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source ) {
                $detail = $ctx->can( 'tron', 'katalyst_detail' );
                if ( isset( $a['domain'] ) && !$detail )
                    throw new tronToolError( 'Searching by domain needs the policy tron/katalyst_detail' );
                $r = $source->services( $a, (int)$a['limit'], (int)$a['offset'] );
                return self::page( $r, $a, self::SERVICE_COLUMNS, !$detail );
            },
        ) ) );

        $registry->tool( tronTool::define( array(
            'name' => 'katalyst_orders_list', 'title' => 'Katalyst orders',
            'description' => 'Shop orders as Katalyst tracks them, newest first: state, totals, item count, fraud check state. Filters: state, customer_id, since (YYYY-MM-DD).',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
                'state' => array( 'type' => 'string', 'maxLength' => 32 ), 'customer_id' => $idf,
                'since' => array( 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ), 'limit' => $limit, 'offset' => $offset,
            ) ),
            'policy' => self::READ, 'outputSchema' => self::pageSchema( self::ORDER_COLUMNS ),
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source ) {
                if ( isset( $a['since'] ) )
                {
                    $d = DateTime::createFromFormat( '!Y-m-d', $a['since'], new DateTimeZone( 'UTC' ) );
                    if ( !$d )
                        throw new tronToolError( "'{$a['since']}' is not a date YYYY-MM-DD" );
                    $a['since'] = $d->getTimestamp();
                }
                $r = $source->orders( $a, (int)$a['limit'], (int)$a['offset'] );
                return self::page( $r, $a, self::ORDER_COLUMNS, false );
            },
        ) ) );
    }

    /** The answer of a list tool: total, offset and items with exactly the listed columns, nothing else. */
    public static function pageSchema( array $columns ): array
    {
        $props = array();
        foreach ( $columns as $c )
        {
            if ( $c === 'hidden' || $c === 'gone' )
                $props[$c] = array( 'type' => 'boolean' );
            else if ( $c === 'created' || $c === 'modified' )
                $props[$c] = array( 'type' => array( 'string', 'null' ) );
            else if ( in_array( $c, self::INT_COLUMNS, true ) )
                $props[$c] = array( 'type' => 'integer' );
            else
                $props[$c] = array( 'type' => array( 'string', 'number', 'null' ) );
        }
        return array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'total', 'offset', 'items' ), 'properties' => array(
            'total' => array( 'type' => 'integer', 'minimum' => 0 ), 'offset' => array( 'type' => 'integer', 'minimum' => 0 ),
            'items' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => $columns, 'properties' => $props ) ),
        ) );
    }

    private static function page( array $r, array $a, array $columns, bool $maskDomain ): array
    {
        $items = array();
        foreach ( (array)$r['items'] as $row )
        {
            if ( is_object( $row ) && method_exists( $row, 'attribute' ) )
            {
                $arr = array();
                foreach ( $columns as $c )
                    $arr[$c] = $row->attribute( $c );
                $row = $arr;
            }
            $out = array();
            foreach ( $columns as $c )
            {
                $v = $row[$c] ?? null;
                if ( in_array( $c, self::INT_COLUMNS, true ) )
                    $v = (int)$v;
                if ( $c === 'domain' && $maskDomain )
                    $v = self::maskDomain( (string)$v );
                if ( ( $c === 'created' || $c === 'modified' ) )
                    $v = $v > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $v ) : null;
                if ( $c === 'hidden' || $c === 'gone' )
                    $v = (bool)$v;
                $out[$c] = $v;
            }
            $items[] = $out;
        }
        return array( 'total' => (int)$r['total'], 'offset' => (int)$a['offset'], 'items' => $items );
    }

    /**
     * Masks the first label of a domain, keeping the rest: example.com -> ex****e.com (six characters or more keep
     * the first two and the last), shop.example.org -> s***.example.org (four or five keep the first), abc.x -> ***.x.
     */
    public static function maskDomain( string $d ): string
    {
        if ( $d === '' )
            return '';
        $dot = strpos( $d, '.' );
        $label = $dot === false ? $d : substr( $d, 0, $dot );
        $rest = $dot === false ? '' : substr( $d, $dot );
        $n = strlen( $label );
        if ( $n <= 3 )
            return str_repeat( '*', $n ) . $rest;
        if ( $n <= 5 )
            return substr( $label, 0, 1 ) . str_repeat( '*', $n - 1 ) . $rest;
        return substr( $label, 0, 2 ) . str_repeat( '*', $n - 3 ) . substr( $label, -1 ) . $rest;
    }
}
