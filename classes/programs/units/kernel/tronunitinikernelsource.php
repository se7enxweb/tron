<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The INI units in an installation: expIniLocator (which files set a setting, in a siteaccess's load order) and
 * expSettingsChain (every setting of a file with the file that decides it), both reading eZINI's own input files
 * of an uncached instance, so a setting changed a moment ago is seen before the INI cache is cleared.
 */
final class tronUnitIniKernelSource implements tronUnitIniSource
{
    public function available(): bool
    {
        return class_exists( 'expIniLocator' ) && class_exists( 'expSettingsChain' );
    }

    public function reason(): string
    {
        return 'this installation has no expIniLocator/expSettingsChain (Exponential 6.0.15 or later)';
    }

    public function siteAccesses(): array
    {
        return array_values( array_map( 'strval', (array)eZINI::instance( 'site.ini' )->variable( 'SiteAccessSettings', 'AvailableSiteAccessList' ) ) );
    }

    public function files(): array
    {
        $root = expIniEditor::root();
        $names = array();
        foreach ( array_merge( glob( $root . 'settings/*.ini' ) ?: array(), glob( $root . 'extension/*/settings/*.ini*' ) ?: array() ) as $p )
        {
            $b = preg_replace( '/\.ini(\.append)?(\.php)?$/', '', basename( $p ) );
            if ( preg_match( '/^[a-z0-9_.-]+$/', $b ) )
                $names[$b] = true;
        }
        ksort( $names );
        return array_keys( $names );
    }

    public function where( string $file, string $block, string $setting, string $siteAccess ): array
    {
        $sa = $siteAccess !== '' ? $siteAccess : self::current();
        $w = expIniLocator::where( $file, $block, $setting, $sa !== '' ? $sa : null );
        $chain = expSettingsChain::fromIni( expIniLocator::iniFor( $file . '.ini', $sa !== '' ? $sa : null ) );
        $s = $chain->setting( $block, $setting );
        $files = array();
        foreach ( (array)$w['files'] as $f )
            $files[] = array( 'path' => (string)$f['path'], 'scope' => (string)( $f['scope'] ?? '' ), 'value' => $f['value'] ?? null );
        $notLoaded = array();
        foreach ( (array)( $w['notLoaded'] ?? array() ) as $f )
            $notLoaded[] = array( 'path' => (string)$f['path'], 'scope' => (string)( $f['scope'] ?? '' ), 'reason' => (string)( $f['reason'] ?? '' ), 'value' => $f['value'] ?? null );
        $out = array( 'siteaccess' => (string)( $w['siteaccess'] ?? $sa ), 'found' => (bool)$w['found'], 'value' => $w['effective'],
                      'files' => $files, 'notLoaded' => $notLoaded );
        if ( $s !== null && isset( $s['winner'] ) )
            $out['winner'] = (string)$s['winner'];
        return $out;
    }

    public function settings( string $file, string $siteAccess ): array
    {
        $sa = $siteAccess !== '' ? $siteAccess : self::current();
        $chain = expSettingsChain::fromIni( expIniLocator::iniFor( $file . '.ini', $sa !== '' ? $sa : null ) );
        $blocks = array();
        foreach ( $chain->settings() as $block => $settings )
            foreach ( $settings as $name => $s )
                $blocks[$block][$name] = array( 'value' => $s['value'] ?? null, 'winner' => (string)( $s['winner'] ?? '' ), 'kind' => (string)( $s['kind'] ?? 'plain' ) );
        return array( 'siteaccess' => $sa, 'blocks' => $blocks );
    }

    private static function current(): string
    {
        return isset( $GLOBALS['eZCurrentAccess']['name'] ) ? (string)$GLOBALS['eZCurrentAccess']['name'] : '';
    }
}
