<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The units: small read tools, one job each, with a declared input and output type (tronUnitTypes), so that they
 * compose into pipes (tron_pipe) and later into recipes. A unit is an ordinary tron tool of an ordinary program; what
 * makes it a unit is its line in the program's manifest:
 *
 *   "units": [
 *     { "id": "node.read", "tool": "node_read", "in": [ "node-ref" ], "out": "node",
 *       "summary": "One node, attributes rendered to plain values" }
 *   ]
 *
 * "in" lists the input types the unit takes, in the order the pipe tries them; "none" means it only starts a pipe.
 * Reading the manifests runs no program code. The catalog reads tron's own programs (<tron>/programs/<name>) and, when
 * given, the manifests of extension programs (the loader's discover()).
 */
final class tronUnitCatalog
{
    /** @var array|null id => unit */ private static $units = null;

    /** @return array id => array( id, tool, program, in, out, summary ) */
    public static function units( ?array $manifests = null ): array
    {
        if ( $manifests === null && self::$units !== null )
            return self::$units;
        if ( $manifests === null )
            $manifests = glob( tronFactory::builtinDir() . '/*/program.json' ) ?: array();
        sort( $manifests );
        $units = array();
        foreach ( $manifests as $path )
        {
            $d = json_decode( (string)@file_get_contents( $path ), true );
            if ( !is_array( $d ) || !isset( $d['units'] ) || !is_array( $d['units'] ) )
                continue;
            foreach ( $d['units'] as $u )
            {
                $unit = self::check( $u, (string)( $d['name'] ?? '' ), $path );
                if ( isset( $units[$unit['id']] ) )
                    throw new InvalidArgumentException( "The unit {$unit['id']} is declared twice ($path)" );
                $units[$unit['id']] = $unit;
            }
        }
        ksort( $units );
        if ( func_num_args() === 0 || $manifests === null )
            self::$units = $units;
        return $units;
    }

    public static function reset(): void
    {
        self::$units = null;
    }

    public static function byId( string $id ): ?array
    {
        return self::units()[$id] ?? null;
    }

    public static function byTool( string $tool ): ?array
    {
        foreach ( self::units() as $u )
            if ( $u['tool'] === $tool )
                return $u;
        return null;
    }

    /** The unit a pipe step names: by id (node.read) or by tool name (node_read). */
    public static function find( string $name ): ?array
    {
        return self::byId( $name ) ?? self::byTool( $name );
    }

    /** The output schema of a unit (the schema of its output type). */
    public static function outputSchema( string $id ): array
    {
        $u = self::byId( $id );
        if ( !$u )
            throw new InvalidArgumentException( "Unknown unit '$id'" );
        return tronUnitTypes::schema( $u['out'] );
    }

    /**
     * What the contract tests need of every unit: tool, input and output types, output schema. The input schema is
     * the tool's own (tools/list).
     *
     * @return array tool => array( unit, program, in, out, outputSchema )
     */
    public static function contracts(): array
    {
        $out = array();
        foreach ( self::units() as $u )
            $out[$u['tool']] = array( 'unit' => $u['id'], 'program' => $u['program'], 'in' => $u['in'], 'out' => $u['out'],
                                      'outputSchema' => tronUnitTypes::schema( $u['out'] ) );
        return $out;
    }

    private static function check( $u, string $program, string $path ): array
    {
        if ( !is_array( $u ) )
            throw new InvalidArgumentException( "A unit in $path is not an object" );
        $id = (string)( $u['id'] ?? '' );
        if ( !preg_match( '/^[a-z][a-z0-9]*(\.[a-z][a-z0-9_]*)+$/', $id ) )
            throw new InvalidArgumentException( "The unit id '$id' in $path is not area.name" );
        $tool = (string)( $u['tool'] ?? '' );
        if ( !preg_match( tronTool::NAME_PATTERN, $tool ) || ( $tool !== $program && strpos( $tool, $program . '_' ) !== 0 ) )
            throw new InvalidArgumentException( "The unit $id: tool '$tool' is not a tool name of the program $program" );
        $in = array_values( array_map( 'strval', (array)( $u['in'] ?? array() ) ) );
        if ( !$in )
            throw new InvalidArgumentException( "The unit $id names no input type" );
        foreach ( $in as $t )
            if ( !tronUnitTypes::isRef( $t ) )
                throw new InvalidArgumentException( "The unit $id: input type '$t' is not a ref type" );
        $out = (string)( $u['out'] ?? '' );
        if ( !tronUnitTypes::exists( $out ) || tronUnitTypes::isRef( $out ) )
            throw new InvalidArgumentException( "The unit $id: output type '$out' is not a value or list type" );
        return array( 'id' => $id, 'tool' => $tool, 'program' => $program, 'in' => $in, 'out' => $out,
                      'summary' => (string)( $u['summary'] ?? '' ) );
    }
}
