<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The program "ini": ini.resolve, ini.diff, ini.list. The values come from eZINI's own load order of a siteaccess
 * (settings/<file>.ini, then the extensions, the siteaccess directory and settings/override, later files winning).
 * A setting whose block or name looks secret (Password, SecretKey, ApiKey, Token ...) is shown as "[redacted]".
 */
final class tronUnitsIniProgram extends tronUnitProgram
{
    const POLICY = array( 'setup', 'setup' );
    const NAME = '^[A-Za-z0-9_.-]{1,100}$';

    protected function area(): string
    {
        return 'ini';
    }

    protected function sourceInterface(): string
    {
        return 'tronUnitIniSource';
    }

    protected function units( tronRegistry $registry, tronEnvironment $env, $source ): void
    {
        $file = array( 'type' => 'string', 'pattern' => '^[a-z0-9_.-]{1,80}$', 'description' => 'The INI file: site, content, explayouts (site.ini works too)' );
        $sa = array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{1,64}$', 'description' => 'A siteaccess (default: the current one)' );
        $block = array( 'type' => 'string', 'pattern' => '^[^\[\]\r\n]{1,150}$' );

        $this->unit( $registry, 'ini.resolve', array(
            'title' => 'Resolve a setting',
            'description' => 'The effective value of one setting in a siteaccess, the file that gives it (winner), every file of the load order that sets it with what it sets, and files of extensions not active there that would set it.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'file', 'block', 'setting' ), 'properties' => array(
                'file' => $file, 'block' => $block, 'setting' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_.-]{1,150}(\[\])?$' ), 'siteaccess' => $sa,
            ) ),
            'policy' => self::POLICY,
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source ) {
                $f = self::file( $source, $a['file'] );
                $sa = self::siteAccess( $source, $a['siteaccess'] ?? '' );
                $setting = preg_replace( '/\[\]$/', '', (string)$a['setting'] );
                $w = $source->where( $f, (string)$a['block'], $setting, $sa );
                $secret = tronUnitValue::isSecretName( $setting ) || tronUnitValue::isSecretName( (string)$a['block'] );
                $value = function ( $v ) use ( $secret ) {
                    return $secret && $v !== null && $v !== '' ? tronUnitValue::REDACTED : tronUnitValue::safe( tronUnitValue::plain( $v ) );
                };
                $files = array();
                foreach ( (array)( $w['files'] ?? array() ) as $row )
                    $files[] = array( 'path' => (string)$row['path'], 'scope' => (string)( $row['scope'] ?? '' ), 'value' => $value( $row['value'] ?? null ) );
                $notLoaded = array();
                foreach ( (array)( $w['notLoaded'] ?? array() ) as $row )
                    $notLoaded[] = array( 'path' => (string)$row['path'], 'scope' => (string)( $row['scope'] ?? '' ),
                                          'reason' => (string)( $row['reason'] ?? '' ), 'value' => $value( $row['value'] ?? null ) );
                return array(
                    'file' => $f, 'block' => (string)$a['block'], 'setting' => $setting, 'siteaccess' => (string)( $w['siteaccess'] ?? $sa ),
                    'found' => (bool)( $w['found'] ?? false ), 'value' => $value( $w['value'] ?? null ),
                    'winner' => isset( $w['winner'] ) ? (string)$w['winner'] : ( $files ? $files[count( $files ) - 1]['path'] : null ), 'redacted' => $secret,
                    'files' => $files, 'not_loaded' => $notLoaded,
                );
            },
        ) );

        $this->unit( $registry, 'ini.diff', array(
            'title' => 'Diff INI settings',
            'description' => 'The settings whose effective value differs: one INI file in two siteaccesses (a, b), or two INI files (file, other_file) in one siteaccess. Each item says changed, only_a or only_b, with both values and the file that decides each.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'file' ), 'properties' => array(
                'file' => $file, 'a' => $sa, 'b' => $sa, 'other_file' => $file, 'siteaccess' => $sa, 'block' => $block,
                'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100 ),
            ) ),
            'policy' => self::POLICY,
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source ) {
                $f = self::file( $source, $a['file'] );
                if ( isset( $a['other_file'] ) )
                {
                    $sa = self::siteAccess( $source, $a['siteaccess'] ?? '' );
                    $of = self::file( $source, $a['other_file'] );
                    $sa1 = $source->settings( $f, $sa );
                    $sb1 = $source->settings( $of, $sa );
                    $left = array( 'file' => $f, 'siteaccess' => (string)$sa1['siteaccess'] );
                    $right = array( 'file' => $of, 'siteaccess' => (string)$sb1['siteaccess'] );
                }
                else
                {
                    if ( !isset( $a['a'], $a['b'] ) )
                        throw new tronToolError( 'Give two siteaccesses (a and b), or other_file' );
                    $sa1 = $source->settings( $f, self::siteAccess( $source, $a['a'] ) );
                    $sb1 = $source->settings( $f, self::siteAccess( $source, $a['b'] ) );
                    $left = array( 'file' => $f, 'siteaccess' => (string)$sa1['siteaccess'] );
                    $right = array( 'file' => $f, 'siteaccess' => (string)$sb1['siteaccess'] );
                }
                return self::diff( (array)$sa1['blocks'], (array)$sb1['blocks'], $left, $right, $a['block'] ?? null, (int)$a['limit'] );
            },
        ) );

        $this->unit( $registry, 'ini.list', array(
            'title' => 'List INI blocks',
            'description' => 'The blocks of an INI file in a siteaccess with their settings (name, kind, the file that decides it). Values are included for one block, or with values=true.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'file' ), 'properties' => array(
                'file' => $file, 'siteaccess' => $sa, 'block' => $block, 'values' => array( 'type' => 'boolean', 'default' => false ),
            ) ),
            'policy' => self::POLICY,
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source ) {
                $f = self::file( $source, $a['file'] );
                $s = $source->settings( $f, self::siteAccess( $source, $a['siteaccess'] ?? '' ) );
                $withValues = !empty( $a['values'] ) || isset( $a['block'] );
                $items = array();
                foreach ( (array)$s['blocks'] as $block => $settings )
                {
                    if ( isset( $a['block'] ) && (string)$block !== (string)$a['block'] )
                        continue;
                    $rows = array();
                    foreach ( $settings as $name => $r )
                    {
                        $row = array( 'name' => (string)$name, 'kind' => (string)( $r['kind'] ?? 'plain' ), 'winner' => (string)( $r['winner'] ?? '' ) );
                        if ( $withValues )
                            $row['value'] = self::value( (string)$block, (string)$name, $r['value'] ?? null );
                        $rows[] = $row;
                    }
                    $items[] = array( 'block' => (string)$block, 'settings' => $rows );
                }
                if ( isset( $a['block'] ) && !$items )
                    throw new tronToolError( "The block [{$a['block']}] is not in $f.ini for this siteaccess" );
                return array( 'file' => $f, 'siteaccess' => (string)$s['siteaccess'], 'total' => count( $items ), 'offset' => 0, 'items' => $items );
            },
        ) );
    }

    /** A setting's value as it may leave the server. */
    public static function value( string $block, string $name, $v )
    {
        if ( ( tronUnitValue::isSecretName( $name ) || tronUnitValue::isSecretName( $block ) ) && $v !== null && $v !== '' && $v !== array() )
            return tronUnitValue::REDACTED;
        return tronUnitValue::safe( tronUnitValue::plain( $v ) );
    }

    /**
     * The diff of two settings maps (block => name => array( value, winner )).
     */
    public static function diff( array $sa, array $sb, array $left, array $right, ?string $onlyBlock, int $limit ): array
    {
        $items = array();
        $counts = array( 'changed' => 0, 'only_a' => 0, 'only_b' => 0 );
        $add = function ( string $block, string $name, ?array $x, ?array $y ) use ( &$items, &$counts ) {
            $change = $x === null ? 'only_b' : ( $y === null ? 'only_a' : 'changed' );
            $counts[$change]++;
            $items[] = array( 'key' => "$block/$name", 'block' => $block, 'setting' => $name, 'change' => $change,
                              'a' => $x === null ? null : self::value( $block, $name, $x['value'] ?? null ),
                              'b' => $y === null ? null : self::value( $block, $name, $y['value'] ?? null ),
                              'a_winner' => $x['winner'] ?? null, 'b_winner' => $y['winner'] ?? null );
        };
        foreach ( $sa as $block => $settings )
        {
            if ( $onlyBlock !== null && (string)$block !== $onlyBlock )
                continue;
            foreach ( $settings as $name => $x )
            {
                $y = $sb[$block][$name] ?? null;
                if ( $y !== null && ( $y['value'] ?? null ) === ( $x['value'] ?? null ) )
                    continue;
                $add( (string)$block, (string)$name, $x, $y );
            }
        }
        foreach ( $sb as $block => $settings )
        {
            if ( $onlyBlock !== null && (string)$block !== $onlyBlock )
                continue;
            foreach ( $settings as $name => $y )
                if ( !isset( $sa[$block][$name] ) )
                    $add( (string)$block, (string)$name, null, $y );
        }
        $total = count( $items );
        return array( 'a' => $left, 'b' => $right, 'counts' => $counts, 'total' => $total, 'truncated' => $total > $limit,
                      'items' => array_slice( $items, 0, $limit ) );
    }

    private static function file( tronUnitIniSource $source, string $file ): string
    {
        $base = preg_replace( '/\.ini(\.append(\.php)?)?$/', '', $file );
        if ( !in_array( $base, $source->files(), true ) )
            throw new tronToolError( "There is no INI file '$base' here (settings/$base.ini)" );
        return $base;
    }

    private static function siteAccess( tronUnitIniSource $source, string $sa ): string
    {
        if ( $sa !== '' && !in_array( $sa, $source->siteAccesses(), true ) )
            throw new tronToolError( "'$sa' is not a siteaccess of this installation; known: " . implode( ', ', $source->siteAccesses() ) );
        return $sa;
    }
}
