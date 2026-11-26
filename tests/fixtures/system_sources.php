<?php
/**
 * The fixture world of the program "system": a fixture installation (fixtures/system/root) for the inventory, and a
 * tronSystemArraySource with fetch functions, operators, a view, commands and queue jobs for the adapters. Used by
 * tests/unit/SystemAdaptersTest.php and by the contract fuzz worlds (tests/lib/fuzzworld.php).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

final class TronSystemFixture
{
    public static function root(): string
    {
        return __DIR__ . '/system/root';
    }

    /** The catalog of the fixture installation, with the units the inventory may name. */
    public static function inventory( array $more = array(), bool $private = true ): array
    {
        // the installation's [SystemAdapterSettings] PrivateNames[], as settings/override would give them
        tronSystemInventory::setPrivateNames( $private ? array( 'privsrv*', 'canary' ) : array() );
        $units = array(
            'ini.list' => array( 'id' => 'ini.list', 'tool' => 'ini_list', 'program' => 'ini' ),
            'ini.resolve' => array( 'id' => 'ini.resolve', 'tool' => 'ini_resolve', 'program' => 'ini' ),
            'node.read' => array( 'id' => 'node.read', 'tool' => 'node_read', 'program' => 'node' ),
        );
        $inv = new tronSystemInventory( $more + array( 'root' => self::root(), 'units' => $units, 'coreTools' => array( 'tron_whoami' ),
            'console' => (string)file_get_contents( __DIR__ . '/system/console.txt' ), 'now' => TronFixture::NOW ) );
        return $inv->build();
    }

    /** The environment services: system.source and system.catalog. */
    public static function services(): array
    {
        return array( 'system.source' => self::source(), 'system.catalog' => new tronSystemCatalog( self::inventory() ) );
    }

    public static function source(): tronSystemArraySource
    {
        $mod = function ( string $name ) {
            $vars = ( static function ( $f ) { include $f; return get_defined_vars(); } )( self::root() . "/kernel/$name/module.php" );
            $fetch = ( static function ( $f ) { include $f; return get_defined_vars(); } )( self::root() . "/kernel/$name/function_definition.php" );
            return array( 'views' => $vars['ViewList'], 'functions' => $vars['FunctionList'], 'fetch' => $fetch['FunctionList'] );
        };
        return new tronSystemArraySource( array(
            'modules' => array( 'content' => $mod( 'content' ) ),
            'fetch' => array(
                'content/node' => function ( array $p, int $user ) {
                    return array( 'node_id' => $p['node_id'], 'name' => 'Home', 'password_hash' => 'x1y2', 'owner_mail' => 'jane.doe@example.com' );
                },
                'content/list' => function ( array $p ) {
                    return array_map( function ( $i ) { return array( 'node_id' => 100 + $i ); }, range( 1, min( 3, $p['limit'] ) ) );
                },
            ),
            'operators' => array(
                'upcase' => function ( $in ) { return is_scalar( $in ) ? strtoupper( (string)$in ) : $in; },
                'shorten' => function ( $in, $p ) { return substr( is_scalar( $in ) ? (string)$in : '', 0, max( 0, (int)( is_scalar( $p[0] ?? null ) ? $p[0] : 80 ) - 3 ) ) . '...'; },
                'ezini' => function () { return 'secret'; },
                'texttoimage' => function () { return 'img'; },
            ),
            'views' => array(
                'content/view' => function ( array $p ) {
                    return array( 'title' => 'Home', 'path' => array( array( 'text' => 'Home', 'url' => '/' ) ),
                                  'html' => '<h1>Home</h1><script>x()</script><p>Welcome <a href="/about">About</a> <a href="javascript:x()">no</a></p>' );
                },
            ),
            'canView' => function ( int $user, string $m, string $v ) { return $user !== TronFixture::USEONLY; },
            'commands' => function ( array $argv ) {
                return array( 'exit_code' => 0, 'stdout' => "\e[1mstatus\e[0m ok: " . implode( ' ', $argv ), 'ms' => 5 );
            },
            'queue' => array(
                array( 'id' => 1, 'uuid' => '0123456789abcdef0123456789abcdef', 'type' => 'expqueue.noop', 'state' => 'done', 'class' => 'bulk', 'payload' => array( 'a' => 1 ) ),
                array( 'id' => 2, 'uuid' => 'fedcba9876543210fedcba9876543210', 'type' => 'expqueue.sleep', 'state' => 'queued', 'class' => 'system', 'payload' => array() ),
            ),
        ) );
    }
}
