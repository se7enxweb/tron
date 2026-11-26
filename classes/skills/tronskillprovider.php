<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Who provides a pack: the extension's name, version and license, read from its extension.xml (<metadata>) and,
 * where that lacks a field, its ezinfo.php. Only reads the files as text; no code of the extension runs.
 */
final class tronSkillProvider
{
    /** @return array name, version, license, info_url */
    public static function info( string $dir, string $name ): array
    {
        $out = array( 'name' => $name, 'version' => '', 'license' => '', 'info_url' => '' );
        $xml = @file_get_contents( rtrim( $dir, '/' ) . '/extension.xml' );
        if ( is_string( $xml ) )
        {
            foreach ( array( 'version', 'license', 'info_url' ) as $k )
                if ( preg_match( '#<' . $k . '>\s*([^<]*?)\s*</' . $k . '>#', $xml, $m ) && $m[1] !== '' && strpos( $m[1], '//autogen' ) === false )
                    $out[$k] = html_entity_decode( $m[1], ENT_QUOTES | ENT_XML1, 'UTF-8' );
        }
        $info = @file_get_contents( rtrim( $dir, '/' ) . '/ezinfo.php' );
        if ( is_string( $info ) )
        {
            $map = array( 'version' => 'Version', 'license' => 'License', 'info_url' => 'Info_url' );
            foreach ( $map as $k => $key )
                if ( $out[$k] === '' && preg_match( "#['\"]" . $key . "['\"]\s*=>\s*['\"]([^'\"]*)['\"]#", $info, $m ) )
                    $out[$k] = $m[1];
        }
        return $out;
    }
}
