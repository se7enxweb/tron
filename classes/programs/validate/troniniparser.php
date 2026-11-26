<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * An Exponential INI file read the way eZINI reads it, with every deviation reported as a finding.
 *
 * - The optional PHP wrapper of .ini.append.php files: "<?php /* #?ini charset="utf-8"?" first, "*\/ ?>" last.
 * - "# comment" lines and blank lines; "[Block]"; "Name=value"; "Name[]" (empties the list); "Name[]=value"
 *   (appends); "Name[key]=value" (a hash entry).
 * - Findings: ini.syntax (a line eZINI cannot read), ini.outside-block, ini.wrapper, ini.type-mix (a name used as a
 *   single value and as a list in one block), ini.duplicate-block, ini.duplicate-key (a single value set twice: the
 *   last one wins), ini.name (a setting name with characters eZINI does not expect), ini.whitespace (spaces around
 *   the name or the "=").
 */
final class tronIniParser
{
    /** @return array( 'blocks' => block => name => value|list|hash, 'findings' => list ) */
    public static function parse( string $text, string $fileName = '' ): array
    {
        $f = array();
        $add = function ( string $sev, string $rule, string $msg, ?int $line ) use ( &$f ) {
            $f[] = array( 'severity' => $sev, 'rule' => $rule, 'message' => $msg, 'location' => array( 'line' => $line, 'column' => null, 'path' => null ) );
        };
        $lines = preg_split( '/\r\n|\r|\n/', $text );
        $isAppend = (bool)preg_match( '/\.php$/', $fileName );
        $first = null;
        foreach ( $lines as $i => $l )
            if ( trim( $l ) !== '' )
            {
                $first = $i;
                break;
            }
        $wrapped = $first !== null && preg_match( '/^<\?php\s*\/\*/', trim( $lines[$first] ) );
        if ( $wrapped )
        {
            if ( !preg_match( '/^<\?php\s*\/\*\s*#\?ini\s+charset="[A-Za-z0-9_-]+"\s*\?>?\s*$/', trim( $lines[$first] ) ) )
                $add( 'warning', 'ini.wrapper', 'The first line should be <?php /* #?ini charset="utf-8"?', $first + 1 );
            $lines[$first] = '';
            $last = null;
            for ( $i = count( $lines ) - 1; $i >= 0; $i-- )
                if ( trim( $lines[$i] ) !== '' )
                {
                    $last = $i;
                    break;
                }
            if ( $last === null || !preg_match( '/^\*\/\s*\?>\s*$/', trim( $lines[$last] ) ) )
                $add( 'error', 'ini.wrapper', 'The PHP wrapper is not closed: the last line must be */ ?>', ( $last ?? 0 ) + 1 );
            else
                $lines[$last] = '';
        }
        else if ( $isAppend )
            $add( 'warning', 'ini.wrapper', 'A .php settings file should start with <?php /* #?ini charset="utf-8"? so the web server never shows it', 1 );

        $blocks = array();
        $kinds = array();     // block => name => single|list
        $seenBlock = array();
        $block = null;
        foreach ( $lines as $i => $raw )
        {
            $n = $i + 1;
            $line = rtrim( $raw, " \t" );
            $t = ltrim( $line );
            if ( $t === '' || $t[0] === '#' )
                continue;
            if ( $t[0] === '[' )
            {
                if ( !preg_match( '/^\[([^\[\]]+)\]$/', $t, $m ) )
                {
                    $add( 'error', 'ini.syntax', 'A block line must be [Name]', $n );
                    continue;
                }
                $block = $m[1];
                if ( isset( $seenBlock[$block] ) )
                    $add( 'warning', 'ini.duplicate-block', "The block [$block] appears again (first on line {$seenBlock[$block]}); eZINI merges both", $n );
                else
                    $seenBlock[$block] = $n;
                $blocks += array( $block => array() );
                continue;
            }
            if ( !preg_match( '/^([^=\[\]]+)(\[([^\]]*)\])?(=(.*))?$/s', $t, $m ) || ( !isset( $m[4] ) || $m[4] === '' ) && ( !isset( $m[2] ) || $m[2] !== '[]' ) )
            {
                $add( 'error', 'ini.syntax', 'Not a setting: expected Name=value, Name[]=value, Name[key]=value or Name[]', $n );
                continue;
            }
            if ( $block === null )
            {
                $add( 'error', 'ini.outside-block', 'A setting before the first [Block]', $n );
                continue;
            }
            $name = $m[1];
            if ( $name !== trim( $name ) || isset( $m[5] ) && preg_match( '/^\s/', (string)$m[5] ) && trim( (string)$m[5] ) !== '' )
                $add( 'warning', 'ini.whitespace', "Spaces around the name or after '=' become part of the setting ('" . trim( $name ) . "')", $n );
            $name = trim( $name );
            if ( !preg_match( '/^[A-Za-z0-9_.\-]+$/', $name ) )
                $add( 'warning', 'ini.name', "The name '$name' has characters eZINI does not expect (letters, digits, _ . -)", $n );
            $value = isset( $m[5] ) ? (string)$m[5] : null;
            if ( isset( $m[2] ) && $m[2] !== '' )
            {
                if ( ( $kinds[$block][$name] ?? 'list' ) === 'single' )
                    $add( 'error', 'ini.type-mix', "$name is a single value earlier in [$block] and a list here", $n );
                $kinds[$block][$name] = 'list';
                if ( !isset( $blocks[$block][$name] ) || !is_array( $blocks[$block][$name] ) )
                    $blocks[$block][$name] = array();
                if ( $value === null )
                    $blocks[$block][$name] = array();          // Name[]: empties the list
                else if ( $m[3] === '' )
                    $blocks[$block][$name][] = $value;
                else
                    $blocks[$block][$name][$m[3]] = $value;
            }
            else
            {
                if ( ( $kinds[$block][$name] ?? 'single' ) === 'list' )
                    $add( 'error', 'ini.type-mix', "$name is a list earlier in [$block] and a single value here", $n );
                else if ( array_key_exists( $name, $blocks[$block] ) )
                    $add( 'warning', 'ini.duplicate-key', "$name is set again in [$block]; the last value wins", $n );
                $kinds[$block][$name] = 'single';
                $blocks[$block][$name] = $value;
            }
        }
        return array( 'blocks' => $blocks, 'findings' => $f );
    }
}
