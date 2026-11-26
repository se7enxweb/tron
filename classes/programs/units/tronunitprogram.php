<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * What the units programs share: finding the area's source, leaving every tool out with the reason when there is
 * none, and declaring a unit's tool from its manifest line (name, types in the description, output schema).
 */
abstract class tronUnitProgram implements tronProgram
{
    /** The area: ini, tpl, node, class, layout, role (also the program's name). */
    abstract protected function area(): string;

    /** The source interface the area needs. */
    abstract protected function sourceInterface(): string;

    /** Declares the area's units; $source is available. */
    abstract protected function units( tronRegistry $registry, tronEnvironment $env, $source ): void;

    public function register( tronRegistry $registry, tronEnvironment $env ): void
    {
        $source = tronUnitSources::get( $env, $this->area() );
        $iface = $this->sourceInterface();
        if ( !$source instanceof $iface || !$source->available() )
        {
            $why = $source instanceof tronUnitSource ? $source->reason() : 'no ' . $this->area() . ' source here (the units read through an Exponential installation)';
            foreach ( tronUnitCatalog::units() as $u )
                if ( $u['program'] === $this->area() )
                    $registry->unavailable( $u['tool'], $why );
            return;
        }
        $this->units( $registry, $env, $source );
    }

    /**
     * Registers the tool of a unit. $d is a tronTool::define() declaration without the name; the description gets
     * the unit line ("Unit node.read: node-ref -> node.") and the output schema comes from the output type.
     */
    protected function unit( tronRegistry $registry, string $id, array $d ): void
    {
        $u = tronUnitCatalog::byId( $id );
        if ( !$u )
            throw new LogicException( "The unit $id is not in the manifest" );
        $d['name'] = $u['tool'];
        $d['title'] = $d['title'] ?? $id;
        $d['description'] = trim( (string)( $d['description'] ?? $u['summary'] ) ) . ' Unit ' . $id . ': ' . implode( ' | ', $u['in'] )
                          . ' -> ' . $u['out'] . ' (pipe with tron_pipe; doc/units.md). Read only.';
        $d['write'] = false;
        $d['outputSchema'] = tronUnitTypes::schema( $u['out'] );
        $registry->tool( tronTool::define( $d ) );
    }

    /** limit/offset properties for a list unit. */
    protected static function paging( int $default, int $max ): array
    {
        return array( 'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => $max, 'default' => $default ),
                      'offset' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ) );
    }

    /** A comma list argument as a list of non-empty strings. */
    protected static function commaList( $v ): array
    {
        if ( is_array( $v ) )
            return array_values( array_filter( array_map( 'trim', array_map( 'strval', $v ) ), 'strlen' ) );
        return array_values( array_filter( array_map( 'trim', explode( ',', (string)$v ) ), 'strlen' ) );
    }
}
