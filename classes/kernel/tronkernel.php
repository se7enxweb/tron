<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * tron inside an Exponential installation: the parts come from the kernel (tron.ini, eZDB, eZUser, the active
 * extensions) and go through tronFactory like everywhere else.
 */
final class tronKernel
{
    /** The tron.ini groups, as group => name => value. */
    public static function settings(): array
    {
        $ini = eZINI::instance( 'tron.ini' );
        $out = array();
        foreach ( $ini->groups() as $group => $values )
            $out[$group] = $values;
        return $out;
    }

    public static function db(): tronDb
    {
        return new tronDbKernel( eZDB::instance() );
    }

    public static function tokens(): tronTokenService
    {
        return new tronTokenService( self::db(), new tronKernelUserDirectory() );
    }

    /** @return array<string,string> active extension name => directory, in load order */
    public static function extensions(): array
    {
        $base = eZExtension::baseDirectory();
        $out = array();
        foreach ( eZExtension::activeExtensions() as $ext )
            $out[$ext] = $base . '/' . $ext;
        return $out;
    }

    public static function audit( array $settings ): tronAuditSink
    {
        $a = (array)( $settings['AuditSettings'] ?? array() );
        $sinks = array();
        foreach ( (array)( $a['Sinks'] ?? array( 'database' ) ) as $name )
        {
            if ( $name === 'database' )
                $sinks[] = new tronAuditDb( self::db() );
            else if ( $name === 'file' )
                $sinks[] = new tronAuditFile( (string)( $a['FilePath'] ?? 'var/log/tron-audit.log' ) );
        }
        if ( !$sinks )
            throw new RuntimeException( 'tron.ini [AuditSettings] Sinks[] names no sink (database, file); tron does not run without an audit log' );
        return count( $sinks ) === 1 ? $sinks[0] : new tronAuditMulti( $sinks );
    }

    /** The environment services of the kernel: the Katalyst source, expcentre's in-process API where present. */
    public static function services( array $settings ): array
    {
        $services = array();
        $k = (array)( $settings['KatalystSettings'] ?? array() );
        if ( ( $k['Source'] ?? 'kernel' ) === 'kernel' )
            $services['katalyst.source'] = function () { return new tronKatalystKernelSource(); };
        // centre.se7enx.com: expcentre's in-process API (centre plan, "MCP contract for tron")
        if ( class_exists( 'expCentreApi' ) )
            $services['centre.api'] = function () {
                return function ( string $tool, array $args, int $userId ) {
                    return expCentreApi::call( $tool, $args, eZUser::fetch( $userId ) );
                };
            };
        return $services;
    }

    /** Builds the server for one stdio process or one HTTP request, or ($inProcess) for a host in this request. */
    public static function server( ?callable $logger = null, ?tronAccessChecker $access = null, bool $inProcess = false ): tronServer
    {
        $settings = self::settings();
        $services = self::services( $settings );
        // Programs and tools switched off in Setup > tron (table tron_switch)
        $db = self::db();
        $switchedOff = array();
        if ( $db->tableExists( 'tron_switch' ) )
        {
            $sw = new tronSwitches( $db );
            $switchedOff = array( 'program' => $sw->off( 'program' ), 'tool' => $sw->off( 'tool' ) );
        }
        return tronFactory::build( array(
            'settings' => $settings,
            'access' => $access ?: new tronKernelAccessChecker(),
            'audit' => self::audit( $settings ),
            'switchedOff' => $switchedOff,
            'db' => $db,
            'users' => new tronKernelUserDirectory(),
            'extensions' => self::extensions(),
            'services' => $services,
            'logger' => $logger,
            'scanned' => ( $settings['McpEngineSettings']['ScanRegister'] ?? 'disabled' ) === 'enabled' ? self::scanEngines( $settings ) : array(),
            'inProcess' => $inProcess,
        ) );
    }

    /**
     * The interface scan over the extension autoload array (var/autoload/ezp_extension.php, or autoload/ on older
     * installations): the classes that are MCP engines, registered or not (tronMcpEngineFactory::scan()).
     */
    public static function scanEngines( ?array $settings = null ): array
    {
        $autoload = array();
        foreach ( array( 'var/autoload/ezp_extension.php', 'autoload/ezp_extension.php' ) as $file )
        {
            if ( is_file( $file ) )
            {
                $autoload = include $file;
                break;
            }
        }
        return tronMcpEngineFactory::scan( is_array( $autoload ) ? $autoload : array(), getcwd(), $settings ?? self::settings() );
    }

    /** The HTTP transport, or null when [HTTPSettings] Enabled is not "true" for this siteaccess. */
    public static function httpTransport( string $siteAccess ): ?tronHttpTransport
    {
        $settings = self::settings();
        $h = (array)( $settings['HTTPSettings'] ?? array() );
        if ( ( $h['Enabled'] ?? 'false' ) !== 'true' )
            return null;
        if ( !in_array( $siteAccess, (array)( $h['SiteAccesses'] ?? array() ), true ) )
            return null;
        $access = new tronKernelAccessChecker();
        $logger = function ( $level, $message ) {
            if ( class_exists( 'eZDebug' ) )
                $level === 'error' ? eZDebug::writeError( $message, 'tron' ) : eZDebug::writeWarning( $message, 'tron' );
        };
        $server = self::server( $logger, $access );
        $tokens = self::tokens();
        return new tronHttpTransport( $server, function ( string $token, ?string &$reason = null ) use ( $tokens ) {
            return $tokens->authenticate( $token, $reason );
        }, $access, self::audit( $settings ), array(
            'allowedOrigins' => array_values( array_filter( (array)( $h['AllowedOrigins'] ?? array() ) ) ),
            'allowedHosts' => array_values( array_filter( (array)( $h['AllowedHosts'] ?? array() ) ) ),
            'maxBodyBytes' => (int)( $h['MaxBodyBytes'] ?? 1048576 ),
        ) );
    }
}
