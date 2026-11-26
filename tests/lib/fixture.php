<?php
/**
 * The test world: an SQLite database in memory (or in a file), a fixed clock, ten users with the roles the
 * permission tests check, an in-memory audit log and made-up Katalyst rows (example.* domains, no real data).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

final class TronFixture
{
    /** Tuesday 2026-11-24 12:00:00 UTC, ISO week 2026-W48 */
    const NOW = 1795521600;

    const ADMIN = 1, VIEWER = 2, EDITOR = 3, DECIDER = 4, OPERATOR = 5, DETAIL = 6, NOROLE = 7, DISABLED = 8, LIMITED = 9, USEONLY = 10;

    public static function users(): array
    {
        return array(
            self::ADMIN => array( 'login' => 'admin' ), self::VIEWER => array( 'login' => 'viewer' ),
            self::EDITOR => array( 'login' => 'editor' ), self::DECIDER => array( 'login' => 'decider' ),
            self::OPERATOR => array( 'login' => 'operator' ), self::DETAIL => array( 'login' => 'detail' ),
            self::NOROLE => array( 'login' => 'norole' ), self::DISABLED => array( 'login' => 'gone', 'enabled' => false ),
            self::LIMITED => array( 'login' => 'limited' ), self::USEONLY => array( 'login' => 'useonly' ),
        );
    }

    public static function access(): tronRoleAccessChecker
    {
        $viewer = array( 'tron/use', 'tron/centre_read' );
        $editor = array_merge( $viewer, array( 'tron/centre_write' ) );
        $operator = array( 'tron/use', 'tron/katalyst_read' );
        return new tronRoleAccessChecker(
            array( 'admin' => array( '*/*' ), 'viewer' => $viewer, 'editor' => $editor,
                   'decider' => array_merge( $editor, array( 'tron/centre_decide' ) ), 'operator' => $operator,
                   'detail' => array_merge( $operator, array( 'tron/katalyst_detail' ) ),
                   'limited' => array( 'tron/use', 'tron/centre_read:limited' ), 'useonly' => array( 'tron/use' ) ),
            array( self::ADMIN => array( 'admin' ), self::VIEWER => array( 'viewer' ), self::EDITOR => array( 'editor' ),
                   self::DECIDER => array( 'decider' ), self::OPERATOR => array( 'operator' ), self::DETAIL => array( 'detail' ),
                   self::DISABLED => array( 'admin' ), self::LIMITED => array( 'limited' ), self::USEONLY => array( 'useonly' ) ),
            array( self::DISABLED ) );
    }

    public static function katalyst(): tronKatalystArraySource
    {
        return new tronKatalystArraySource( array(
            'plans' => array(
                array( 'id' => 1, 'source' => 'plesk', 'server_id' => 2, 'external_id' => '7', 'name' => 'Starter', 'group_name' => '', 'type' => 'hosting',
                       'pay_type' => '', 'price_monthly' => '5.00', 'currency' => 'EUR', 'hidden' => 0, 'gone' => 0, 'created' => self::NOW - 86400, 'modified' => self::NOW ),
                array( 'id' => 2, 'source' => 'shop', 'server_id' => 0, 'external_id' => 'p-2', 'name' => 'Pro', 'group_name' => 'web', 'type' => 'product',
                       'pay_type' => 'recurring', 'price_monthly' => '19.00', 'currency' => 'EUR', 'hidden' => 1, 'gone' => 0, 'created' => self::NOW, 'modified' => self::NOW ),
                array( 'id' => 3, 'source' => 'whmcs', 'server_id' => 0, 'external_id' => '44', 'name' => 'Old', 'group_name' => '', 'type' => 'hosting',
                       'pay_type' => '', 'price_monthly' => '3.00', 'currency' => 'EUR', 'hidden' => 0, 'gone' => 1, 'created' => self::NOW, 'modified' => self::NOW ),
            ),
            'services' => array(
                array( 'id' => 11, 'customer_id' => 501, 'server_id' => 2, 'domain' => 'example.com', 'status' => 'active', 'hosting_type' => 'vrt_hst',
                       'plan_id' => 1, 'plesk_plan_id' => 1, 'service_kind' => 'hosting', 'billing_cycle' => 'monthly', 'next_due_date' => '2026-12-01',
                       'disk_total_bytes' => 1048576, 'traffic_bytes' => 0, 'gone' => 0, 'created' => self::NOW, 'modified' => self::NOW,
                       'whmcs_server_ip' => '192.0.2.10' ),
                array( 'id' => 12, 'customer_id' => 502, 'server_id' => 3, 'domain' => 'shop.example.org', 'status' => 'suspended', 'hosting_type' => 'vrt_hst',
                       'plan_id' => 2, 'plesk_plan_id' => 0, 'service_kind' => 'hosting', 'billing_cycle' => 'yearly', 'next_due_date' => '2027-01-15',
                       'disk_total_bytes' => 0, 'traffic_bytes' => 0, 'gone' => 0, 'created' => self::NOW, 'modified' => self::NOW,
                       'whmcs_server_ip' => '192.0.2.11' ),
            ),
            'orders' => array(
                array( 'id' => 31, 'source' => 'shop', 'shop_order_nr' => 1001, 'customer_id' => 501, 'state' => 'paid', 'currency' => 'EUR',
                       'total_ex_vat' => '19.00', 'total_inc_vat' => '22.61', 'item_count' => 1, 'fraud_state' => 'pass', 'created' => self::NOW - 7 * 86400,
                       'modified' => self::NOW, 'note' => 'internal note', 'domain' => 'example.com' ),
                array( 'id' => 32, 'source' => 'shop', 'shop_order_nr' => 1002, 'customer_id' => 502, 'state' => 'pending', 'currency' => 'EUR',
                       'total_ex_vat' => '5.00', 'total_inc_vat' => '5.95', 'item_count' => 2, 'fraud_state' => '', 'created' => self::NOW,
                       'modified' => self::NOW, 'note' => '', 'domain' => '' ),
            ),
        ) );
    }

    /**
     * Builds the whole stack. Options: db (tronDb), audit (tronAuditSink), extensions (name => dir), settings,
     * katalyst (false to leave the source out), builtinDir.
     * @return array( 'server' => tronServer, 'db' => tronDb, 'audit' => tronAuditSink, 'tokens' => tronTokenService, 'users' => tronUserDirectory )
     */
    public static function build( array $o = array() ): array
    {
        $db = $o['db'] ?? tronDbPdo::sqliteMemory();
        $audit = $o['audit'] ?? new tronAuditMemory();
        $users = new tronArrayUserDirectory( self::users() );
        $clock = function () { return TronFixture::NOW; };
        $services = (array)( $o['services'] ?? array() );
        if ( ( $o['katalyst'] ?? true ) !== false )
            $services['katalyst.source'] = self::katalyst();
        $parts = array(
            'settings' => $o['settings'] ?? array(),
            'access' => $o['access'] ?? self::access(),
            'audit' => $audit,
            'db' => $db,
            'users' => $users,
            'extensions' => $o['extensions'] ?? array(),
            'services' => $services,
            'clock' => $clock,
            'switchedOff' => $o['switchedOff'] ?? array(),
            'currentUserId' => $o['currentUserId'] ?? null,
            'inProcess' => $o['inProcess'] ?? false,
            'logger' => $o['logger'] ?? ( getenv( 'TRON_TEST_LOG' ) ? function ( $l, $m ) { fwrite( STDERR, "      [log] $l $m\n" ); } : null ),
        );
        if ( isset( $o['builtinDir'] ) )
            $parts['builtinDir'] = $o['builtinDir'];
        return array( 'server' => tronFactory::build( $parts ), 'db' => $db, 'audit' => $audit,
                      'tokens' => new tronTokenService( $db, $users, $clock ), 'users' => $users );
    }

    public static function session( int $userId, string $transport = 'stdio', ?string $version = '2025-11-25', array $scopes = array( '*' ) ): tronSession
    {
        $u = self::users()[$userId];
        $s = new tronSession( new tronPrincipal( $userId, $u['login'], 0, $scopes ), $transport, false, $version, 'test' );
        return $s;
    }

    /** One tools/call; returns the result array (or the error array). */
    public static function call( tronServer $server, tronSession $session, string $tool, array $args = array() ): array
    {
        $r = $server->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                                     'params' => array( 'name' => $tool, 'arguments' => $args ) ), $session );
        return $r;
    }

    /** The decoded JSON body of a successful tools/call result. */
    public static function data( array $response )
    {
        if ( isset( $response['error'] ) )
            throw new RuntimeException( 'rpc error: ' . json_encode( $response['error'] ) );
        return json_decode( $response['result']['content'][0]['text'], true );
    }

    public static function tmp( string $name ): string
    {
        $base = getenv( 'TRON_TEST_TMP' );
        if ( !$base )
        {
            // Inside an installation (extension/tron) the fixtures must not land in the extension: the autoload
            // generator would map their classes. Ask for a directory outside it instead.
            if ( strpos( str_replace( '\\', '/', dirname( __DIR__, 2 ) ), '/extension/' ) !== false )
                throw new RuntimeException( 'Set TRON_TEST_TMP to a directory outside the extension, e.g. <root>/var/tmp/tron-tests' );
            $base = dirname( __DIR__, 2 ) . '/var/tmp/tron-tests';
        }
        $dir = $base . '/' . $name;
        if ( !is_dir( $dir ) )
            mkdir( $dir, 0775, true );
        return $dir;
    }

    public static function write( string $path, string $content ): void
    {
        if ( !is_dir( dirname( $path ) ) )
            mkdir( dirname( $path ), 0775, true );
        file_put_contents( $path, $content );
    }
}
