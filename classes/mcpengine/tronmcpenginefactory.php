<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Builds the MCP engines of an installation and puts their tools into tron's registry.
 *
 * Where engines come from:
 *   1. INI registration: every group [McpEngine_<id>] of tron.ini with a Class (an extension ships it in its own
 *      settings/tron.ini.append.php, so activating the extension is enough). Enabled=disabled leaves it out.
 *   2. The interface scan (scan()): every class of the autoload array that implements tronMcpEngine or
 *      tronMcpBridgeApi, or has the bridge API's methods. It is a diagnostic (tron-engines.php, Setup > tron); with
 *      [McpEngineSettings] ScanRegister=enabled a class it finds and no INI group names is registered too, under an
 *      id made from its class name.
 *
 * The id "tron" is tron itself (tronMcpTronEngine, the programs); "mcp" is reserved for hosts that reach remote MCP
 * servers. Every other engine becomes a program of the registry named after its id, so its tools are <id>__<tool>,
 * a token's scopes can name it, Setup > tron can switch it off, and tron checks the caller's policies and writes the
 * audit log for every call. A write tool needs a policy: its own, or the group's WritePolicy; without one it is left
 * out (fail closed).
 */
final class tronMcpEngineFactory
{
    const ID_PATTERN = '/^[a-z][a-z0-9]{0,19}$/';
    const TOOL_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/';
    const SEPARATOR = '__';
    const RESERVED = array( 'tron', 'mcp' );

    /**
     * The INI registrations: id => the group (Class, Enabled, Label, ReadPolicy, WritePolicy), sorted by id.
     */
    public static function definitions( array $settings ): array
    {
        $out = array();
        foreach ( $settings as $group => $values )
        {
            if ( strpos( (string)$group, 'McpEngine_' ) !== 0 || !is_array( $values ) )
                continue;
            $out[substr( (string)$group, 10 )] = $values;
        }
        ksort( $out );
        return $out;
    }

    /** Whether an INI group switches its engine on (Enabled missing counts as enabled). */
    public static function enabled( array $group ): bool
    {
        return !in_array( strtolower( trim( (string)( $group['Enabled'] ?? 'enabled' ) ) ), array( 'disabled', 'false', '0', 'no', 'off' ), true );
    }

    /**
     * What a class is: "engine" (tronMcpEngine), "bridge" (the bridge API) or null. Loads the class.
     */
    public static function kind( string $class ): ?string
    {
        if ( $class === '' || !class_exists( $class ) )
            return null;
        if ( is_subclass_of( $class, 'tronMcpEngine' ) )
            return 'engine';
        if ( tronMcpBridgeEngine::matches( $class ) )
            return 'bridge';
        return null;
    }

    /** Builds one engine from its INI group. Throws when the class is missing or of neither kind. */
    public static function create( string $id, array $group, ?callable $currentUserId = null ): tronMcpEngine
    {
        $class = trim( (string)( $group['Class'] ?? '' ) );
        if ( $class === '' )
            throw new InvalidArgumentException( "[McpEngine_$id] names no Class" );
        if ( !class_exists( $class ) )
            throw new InvalidArgumentException( "the class $class does not exist (autoloads regenerated?)" );
        $kind = self::kind( $class );
        if ( $kind === 'engine' )
            return method_exists( $class, 'fromSettings' ) ? $class::fromSettings( $group ) : new $class( $group );
        if ( $kind === 'bridge' )
            return new tronMcpBridgeEngine( $class, $group, $currentUserId );
        throw new InvalidArgumentException( "the class $class implements neither tronMcpEngine nor the bridge API" );
    }

    /**
     * Builds every enabled engine except tron itself.
     *
     * @param array $settings    the tron.ini groups
     * @param array $scanned     scan() rows to register when [McpEngineSettings] ScanRegister=enabled
     * @return array array( 'engines' => id => array( 'engine' => tronMcpEngine, 'settings' => group ),
     *                      'status' => rows in the shape of the program loader's, origin "engine:<class>" )
     */
    public static function build( array $settings, ?callable $currentUserId = null, array $scanned = array() ): array
    {
        $engines = array();
        $status = array();
        $definitions = self::definitions( $settings );
        if ( ( $settings['McpEngineSettings']['ScanRegister'] ?? 'disabled' ) === 'enabled' )
        {
            $named = array();
            foreach ( $definitions as $group )
                $named[strtolower( (string)( $group['Class'] ?? '' ) )] = true;
            foreach ( $scanned as $row )
            {
                $id = self::idFor( (string)$row['class'] );
                if ( !isset( $named[strtolower( (string)$row['class'] )] ) && !isset( $definitions[$id] ) )
                    $definitions[$id] = array( 'Class' => (string)$row['class'], 'Label' => (string)$row['class'], 'Origin' => 'scan' );
            }
        }
        foreach ( $definitions as $id => $group )
        {
            $id = (string)$id;
            $t0 = microtime( true );
            $class = (string)( $group['Class'] ?? '' );
            $origin = 'engine:' . $class;
            if ( $id === 'tron' )
                continue;                                   // tron itself: its programs, not an engine to register
            if ( !preg_match( self::ID_PATTERN, $id ) || in_array( $id, self::RESERVED, true ) )
            {
                $status[] = self::row( $id, $origin, '', 'failed', 'the id must match ' . self::ID_PATTERN . ' and not be ' . implode( ' or ', self::RESERVED ), $t0 );
                continue;
            }
            if ( !self::enabled( $group ) )
            {
                $status[] = self::row( $id, $origin, '', 'disabled', "[McpEngine_$id] Enabled=disabled", $t0 );
                continue;
            }
            try
            {
                $engine = self::create( $id, $group, $currentUserId );
                $engines[$id] = array( 'engine' => $engine, 'settings' => $group );
                $info = $engine->describeServer();
                $status[] = self::row( $id, $origin, (string)( $info['version'] ?? '' ), 'loaded', '', $t0 );
            }
            catch ( Throwable $e )
            {
                $status[] = self::row( $id, $origin, '', 'failed', $e->getMessage(), $t0 );
            }
        }
        return array( 'engines' => $engines, 'status' => $status );
    }

    /**
     * Puts the engines' tools into the registry, each engine as a program named by its id. A failing engine costs
     * only itself; its status row says why. $taken are the program names already loaded (an engine of the same
     * name is a conflict).
     *
     * A bridge-API engine runs only as the signed-in user of the request, so its tools are offered only by a server
     * built for a host in the same request ($inProcess); over stdio and HTTP they are listed as not available.
     *
     * @param array $engines what build() returned under "engines"
     * @param array $status  build()'s status rows; updated in place
     */
    public static function register( tronRegistry $registry, array $engines, array &$status, array $taken = array(), bool $inProcess = true ): void
    {
        foreach ( $engines as $id => $entry )
        {
            $t0 = microtime( true );
            $engine = $entry['engine'];
            $group = (array)$entry['settings'];
            $at = self::statusIndex( $status, (string)$id );
            if ( in_array( (string)$id, $taken, true ) )
            {
                self::setState( $status, $at, 'conflict', "the name is already taken by a program", $t0 );
                continue;
            }
            $label = (string)( $group['Label'] ?? $id );
            $registry->begin( (string)$id );
            try
            {
                $read = self::policy( (string)( $group['ReadPolicy'] ?? '' ) );
                $write = self::policy( (string)( $group['WritePolicy'] ?? '' ) );
                $tools = 0;
                foreach ( $engine->listTools() as $def )
                {
                    $name = (string)( $def['name'] ?? '' );
                    if ( !preg_match( self::TOOL_PATTERN, $name ) )
                    {
                        $registry->unavailable( $id . self::SEPARATOR . $name, 'the tool name must match ' . self::TOOL_PATTERN );
                        continue;
                    }
                    if ( !$inProcess && $engine instanceof tronMcpBridgeEngine )
                    {
                        $registry->unavailable( $id . self::SEPARATOR . $name, 'a bridge-API engine runs in process as the signed-in user only (hosts such as expai), not over stdio or HTTP' );
                        continue;
                    }
                    $isWrite = !empty( $def['write'] );
                    $policy = isset( $def['policy'] ) && is_array( $def['policy'] ) ? array_values( $def['policy'] ) : ( $isWrite ? $write : $read );
                    if ( $isWrite && $policy === null )
                    {
                        $registry->unavailable( $id . self::SEPARATOR . $name, "a write tool needs a policy: its own or [McpEngine_$id] WritePolicy" );
                        continue;
                    }
                    $registry->tool( tronTool::define( array(
                        'name' => $id . self::SEPARATOR . $name,
                        'title' => (string)( $def['title'] ?? $name ),
                        'description' => '[' . $label . '] ' . trim( (string)( $def['description'] ?? $name ) ),
                        'inputSchema' => (array)( $def['inputSchema'] ?? array( 'type' => 'object' ) ),
                        'policy' => $policy,
                        'write' => $isWrite,
                        'destructive' => !empty( $def['destructive'] ),
                        'idempotent' => !empty( $def['idempotent'] ),
                        'limitedOk' => !empty( $def['limitedOk'] ),
                        'handler' => function ( array $args, tronCallContext $ctx ) use ( $engine, $name ) {
                            return $engine->callTool( $name, $args, $ctx->principal );
                        },
                    ) ) );
                    $tools++;
                }
                $counts = $registry->commit();
                if ( $at !== null )
                    $status[$at] += $counts;
            }
            catch ( Throwable $e )
            {
                $registry->rollback();
                self::setState( $status, $at, 'failed', $e->getMessage(), $t0 );
            }
        }
    }

    /**
     * The interface scan: the classes of an autoload array (class => file, relative to $root) that are engines,
     * found by reading the files, without loading them. Rows: class, file, kind (engine, bridge), and id, the INI
     * registration that names the class ('' when none).
     */
    public static function scan( array $autoload, string $root, array $settings = array() ): array
    {
        $registered = array();
        foreach ( self::definitions( $settings ) as $id => $group )
            $registered[strtolower( (string)( $group['Class'] ?? '' ) )] = (string)$id;
        $byFile = array();
        foreach ( $autoload as $class => $file )
            $byFile[(string)$file][] = (string)$class;
        $out = array();
        foreach ( $byFile as $file => $classes )
        {
            $path = $file !== '' && $file[0] === '/' ? $file : rtrim( $root, '/' ) . '/' . $file;
            if ( !is_file( $path ) || filesize( $path ) > 2000000 )
                continue;
            $code = (string)@file_get_contents( $path );
            if ( stripos( $code, 'tronMcp' ) === false && stripos( $code, 'isBridgeSafe' ) === false )
                continue;
            foreach ( $classes as $class )
            {
                $kind = self::kindInCode( $class, $code );
                if ( $kind === null || in_array( strtolower( $class ), array( 'tronmcpbridgeengine', 'tronmcptronengine' ), true ) )
                    continue;
                $out[] = array( 'class' => $class, 'file' => $file, 'kind' => $kind, 'id' => $registered[strtolower( $class )] ?? '' );
            }
        }
        usort( $out, function ( $a, $b ) { return strcasecmp( $a['class'], $b['class'] ); } );
        return $out;
    }

    /** The kind of a class from its source: implements one of the interfaces, or declares every bridge API method. */
    public static function kindInCode( string $class, string $code ): ?string
    {
        $q = preg_quote( $class, '/' );
        if ( !preg_match( '/\b(?:final\s+|abstract\s+)?class\s+' . $q . '\b([^{]*)\{/i', $code, $m ) )
            return null;
        if ( preg_match( '/\bimplements\b[^{]*\btronMcpEngine\b/i', $m[1] ) )
            return 'engine';
        if ( preg_match( '/\bimplements\b[^{]*\btronMcpBridgeApi\b/i', $m[1] ) )
            return 'bridge';
        foreach ( array_keys( tronMcpBridgeEngine::API ) as $method )
        {
            if ( !preg_match( '/public\s+static\s+function\s+' . $method . '\s*\(/i', $code ) )
                return null;
        }
        return 'bridge';
    }

    /** The id a scanned class is registered under: its name in lower case, a-z0-9 only, at most 20 characters. */
    public static function idFor( string $class ): string
    {
        $id = substr( preg_replace( '/[^a-z0-9]/', '', strtolower( $class ) ), 0, 20 );
        if ( $id === '' || !preg_match( '/^[a-z]/', $id ) || in_array( $id, self::RESERVED, true ) )
            $id = 'e' . substr( md5( $class ), 0, 8 );
        return $id;
    }

    /** "module/function" as array( module, function ), or null for an empty value. */
    public static function policy( string $value ): ?array
    {
        $value = trim( $value );
        if ( $value === '' )
            return null;
        $p = explode( '/', $value, 2 );
        if ( count( $p ) !== 2 || $p[0] === '' || $p[1] === '' )
            throw new InvalidArgumentException( "the policy '$value' must be module/function" );
        return $p;
    }

    private static function row( string $name, string $origin, string $version, string $state, string $reason, float $t0 ): array
    {
        return array( 'name' => $name, 'origin' => $origin, 'version' => $version, 'state' => $state, 'reason' => $reason,
                      'ms' => round( ( microtime( true ) - $t0 ) * 1000, 3 ) );
    }

    private static function statusIndex( array $status, string $id ): ?int
    {
        foreach ( $status as $i => $row )
        {
            if ( $row['name'] === $id && $row['state'] === 'loaded' )
                return (int)$i;
        }
        return null;
    }

    private static function setState( array &$status, ?int $at, string $state, string $reason, float $t0 ): void
    {
        if ( $at === null )
            return;
        $status[$at]['state'] = $state;
        $status[$at]['reason'] = $reason;
        $status[$at]['ms'] += round( ( microtime( true ) - $t0 ) * 1000, 3 );
    }
}
