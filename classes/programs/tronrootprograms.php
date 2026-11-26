<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The switch of the root programs web, browser, validate, diff and api (doc/root-programs.md):
 * [RootProgramSettings] Enabled=enabled, which tron.ini ships. Without it (an installation that wants none of them,
 * a test world, a broken settings file) each of the five programs loads but offers no tool and names the reason, as
 * a program does when an optional part is missing. Fail closed, like [WebSettings] Network.
 */
final class tronRootPrograms
{
    const NAMES = array( 'web', 'browser', 'validate', 'diff', 'api' );

    public static function enabled( tronEnvironment $env ): bool
    {
        return strtolower( (string)$env->setting( 'RootProgramSettings', 'Enabled', 'disabled' ) ) === 'enabled';
    }

    /** true when the program may register its tools; otherwise every one of them is left out with the reason. */
    public static function open( tronRegistry $registry, tronEnvironment $env, array $tools ): bool
    {
        if ( self::enabled( $env ) )
            return true;
        foreach ( $tools as $t )
            $registry->unavailable( $t, 'the root programs are switched off ([RootProgramSettings] Enabled)' );
        return false;
    }
}
