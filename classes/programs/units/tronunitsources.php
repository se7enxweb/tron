<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Finds the source of a units area: the environment service "units.<area>" when one is given (the tests give
 * fixtures), else the kernel source when tron runs inside an Exponential installation, else none (the area's tools
 * are left out).
 */
final class tronUnitSources
{
    const AREAS = array( 'ini', 'tpl', 'node', 'class', 'layout', 'role' );

    const KERNEL = array(
        'ini' => 'tronUnitIniKernelSource', 'tpl' => 'tronUnitTplKernelSource', 'node' => 'tronUnitNodeKernelSource',
        'class' => 'tronUnitClassKernelSource', 'layout' => 'tronUnitLayoutKernelSource', 'role' => 'tronUnitRoleKernelSource',
    );

    /** @return tronUnitSource|null */
    public static function get( tronEnvironment $env, string $area )
    {
        if ( $env->has( 'units.' . $area ) )
            return $env->get( 'units.' . $area );
        if ( !self::inKernel() || !isset( self::KERNEL[$area] ) )
            return null;
        $class = self::KERNEL[$area];
        $source = new $class();
        $env->set( 'units.' . $area, $source );
        return $source;
    }

    /** Whether any area has a source here (tron_pipe is offered only then). */
    public static function any( tronEnvironment $env ): bool
    {
        if ( self::inKernel() )
            return true;
        foreach ( self::AREAS as $a )
            if ( $env->has( 'units.' . $a ) )
                return true;
        return false;
    }

    /** Whether tron runs inside an Exponential installation (the kernel classes are loadable). */
    public static function inKernel(): bool
    {
        return class_exists( 'eZINI' ) && class_exists( 'eZContentObjectTreeNode' );
    }
}
