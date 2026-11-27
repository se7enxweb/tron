<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Picks the protocol layer. [ServerSettings] Protocol (or the environment variable TRON_PROTOCOL, which wins):
 *   auto    the official MCP PHP SDK when it can run (PHP 8.2 or newer and the package mcp/sdk installed), else legacy
 *   sdk     the SDK; refuses to start when it cannot run
 *   legacy  tron's own frozen layer
 */
final class tronProtocolLayers
{
    /** The PHP version the SDK needs (mcp/sdk 0.10 requires ^8.2). */
    const SDK_PHP = '8.2.0';

    /** Whether the SDK can run here, and if not, why. */
    public static function sdkAvailable( ?string &$why = null ): bool
    {
        if ( version_compare( PHP_VERSION, self::SDK_PHP, '<' ) )
        {
            $why = 'PHP ' . PHP_VERSION . ' is older than ' . self::SDK_PHP;
            return false;
        }
        self::loadSdkAutoload();
        if ( !class_exists( 'Mcp\\Server\\Server' ) || !class_exists( 'Nyholm\\Psr7\\Factory\\Psr17Factory' ) )
        {
            $why = 'the packages mcp/sdk and nyholm/psr7 are not installed';
            return false;
        }
        $why = null;
        return true;
    }

    /** The choice a setting makes: "sdk" or "legacy". */
    public static function resolve( string $setting ): string
    {
        $env = getenv( 'TRON_PROTOCOL' );
        $choice = strtolower( trim( is_string( $env ) && $env !== '' ? $env : $setting ) );
        if ( $choice === 'legacy' )
            return 'legacy';
        $why = null;
        if ( self::sdkAvailable( $why ) )
            return 'sdk';
        if ( $choice === 'sdk' )
            throw new RuntimeException( "tron: the protocol layer \"sdk\" was asked for, but $why" );
        return 'legacy';
    }

    public static function create( tronServer $server, string $setting ): tronProtocolLayer
    {
        return self::resolve( $setting ) === 'sdk' ? new tronSdkProtocol( $server ) : new tronLegacyProtocol( $server );
    }

    /**
     * A development aid: TRON_SDK_AUTOLOAD names a Composer autoload.php holding mcp/sdk, for a checkout whose
     * installation does not have the package in its own vendor/ (the test suite, a branch under review).
     */
    private static function loadSdkAutoload(): void
    {
        static $done = false;
        if ( $done )
            return;
        $done = true;
        $file = getenv( 'TRON_SDK_AUTOLOAD' );
        if ( is_string( $file ) && $file !== '' && is_file( $file ) && !class_exists( 'Mcp\\Server\\Server' ) )
            require_once $file;
    }
}
