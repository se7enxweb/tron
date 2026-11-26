<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Moving packs around: export (a directory or a .tar.gz), install into an extension (copy plus its [SkillPack_<id>]
 * group), writing a local pack's approved drafts and forks as files, and reading skill parameters from CLI options.
 * Only data files move: pack.json, README.md, skills/*.json, proof.json and translations/*.
 */
final class tronSkillPacks
{
    const FILES = '/^(pack\.json|README\.md|proof\.json|skills\/[a-z0-9.-]+\.json|translations\/[a-z]{3}-[A-Z]{2}\.json)$/';

    /** @return string[] the pack's data files, relative to its directory */
    public static function files( string $dir ): array
    {
        $out = array();
        foreach ( array( 'pack.json', 'README.md', 'proof.json' ) as $f )
            if ( is_file( "$dir/$f" ) )
                $out[] = $f;
        foreach ( array( 'skills', 'translations' ) as $sub )
            foreach ( glob( "$dir/$sub/*.json" ) ?: array() as $f )
                $out[] = $sub . '/' . basename( $f );
        return array_values( array_filter( $out, function ( $f ) { return preg_match( self::FILES, $f ); } ) );
    }

    public static function export( tronSkillPack $pack, string $to ): void
    {
        if ( substr( $to, -7 ) === '.tar.gz' )
        {
            $tar = substr( $to, 0, -3 );
            if ( is_file( $tar ) || is_file( $to ) )
                throw new tronToolError( "$to exists already" );
            $phar = new PharData( $tar );
            foreach ( self::files( $pack->dir ) as $f )
                $phar->addFile( $pack->dir . '/' . $f, $pack->id . '/' . $f );
            $phar->compress( Phar::GZ );
            unset( $phar );
            @unlink( $tar );
            return;
        }
        $target = rtrim( $to, '/' ) . '/' . $pack->id;
        if ( file_exists( $target ) )
            throw new tronToolError( "$target exists already" );
        foreach ( self::files( $pack->dir ) as $f )
        {
            if ( !is_dir( dirname( "$target/$f" ) ) )
                mkdir( dirname( "$target/$f" ), 0775, true );
            copy( $pack->dir . '/' . $f, "$target/$f" );
        }
    }

    /**
     * Installs a pack (directory or .tar.gz) into extension/<ext>/tron/skills/<id>/ and registers it in that
     * extension's settings/tron.ini.append.php. The pack is checked against the standard first; a refused pack is not
     * copied.
     * @return array id, dir, ini
     */
    public static function install( string $src, string $extDir, string $ext ): array
    {
        if ( !is_dir( $extDir ) )
            throw new tronToolError( "no extension directory $extDir" );
        $work = null;
        if ( substr( $src, -7 ) === '.tar.gz' )
        {
            $work = ( is_dir( "var/tmp" ) ? realpath( "var/tmp" ) : sys_get_temp_dir() ) . '/tron-pack-' . bin2hex( random_bytes( 6 ) );
            mkdir( $work, 0700 );
            $phar = new PharData( $src );
            foreach ( new RecursiveIteratorIterator( $phar ) as $file )
            {
                $rel = substr( $file->getPathname(), strlen( 'phar://' . realpath( $src ) ) + 1 );
                $parts = explode( '/', $rel, 2 );
                if ( count( $parts ) !== 2 || !preg_match( self::FILES, $parts[1] ) || !preg_match( tronSkillPack::ID_PATTERN, $parts[0] ) )
                    continue;
                if ( !is_dir( dirname( "$work/$rel" ) ) )
                    mkdir( dirname( "$work/$rel" ), 0700, true );
                file_put_contents( "$work/$rel", file_get_contents( $file->getPathname() ) );
            }
            $dirs = glob( "$work/*/pack.json" ) ?: array();
            if ( count( $dirs ) !== 1 )
                throw new tronToolError( 'the archive must hold exactly one pack (<id>/pack.json)' );
            $src = dirname( $dirs[0] );
        }
        $pack = tronSkillPack::read( rtrim( $src, '/' ), 'install' );
        $target = rtrim( $extDir, '/' ) . '/tron/skills/' . $pack->id;
        if ( file_exists( $target ) )
            throw new tronToolError( "$target exists already: a pack is never overwritten; raise its version and install it under the same id after removing the old one" );
        foreach ( self::files( $pack->dir ) as $f )
        {
            if ( !is_dir( dirname( "$target/$f" ) ) )
                mkdir( dirname( "$target/$f" ), 0775, true );
            copy( $pack->dir . '/' . $f, "$target/$f" );
        }
        $ini = rtrim( $extDir, '/' ) . '/settings/tron.ini.append.php';
        $group = "\n[SkillPack_{$pack->id}]\nExtension=$ext\nPath=tron/skills/{$pack->id}\nEnabled=enabled\n";
        if ( !is_file( $ini ) )
        {
            if ( !is_dir( dirname( $ini ) ) )
                mkdir( dirname( $ini ), 0775, true );
            file_put_contents( $ini, "<?php /* #?ini charset=\"utf-8\"?\n$group\n*/ ?>\n" );
        }
        else
        {
            $text = (string)file_get_contents( $ini );
            if ( strpos( $text, "[SkillPack_{$pack->id}]" ) === false )
            {
                $pos = strrpos( $text, '*/' );
                if ( $pos === false )
                    throw new tronToolError( "$ini has no closing */; add the group by hand:$group" );
                file_put_contents( $ini, substr( $text, 0, $pos ) . ltrim( $group, "\n" ) . "\n" . substr( $text, $pos ) );
            }
        }
        return array( 'id' => $pack->id, 'dir' => $target, 'ini' => $ini );
    }

    /** Writes the approved drafts and forks of a local pack as skills/<name>.json into <to>/<pack>/. @return int skills written */
    public static function exportLocal( ?tronSkillStore $store, string $pack, string $to ): int
    {
        if ( !$store )
            throw new tronToolError( 'no database' );
        $dir = rtrim( $to, '/' ) . '/' . $pack . '/skills';
        if ( !is_dir( $dir ) )
            mkdir( $dir, 0775, true );
        $n = 0;
        foreach ( $store->approvedDrafts() as $name => $d )
        {
            if ( (string)$d['row']['pack'] !== $pack )
                continue;
            file_put_contents( "$dir/$name.json", json_encode( $d['manifest']->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
            $n++;
        }
        return $n;
    }

    /**
     * Skill parameters from CLI options: --board ops, --limit=10, -b ops, --labels a,b, --flag (true).
     * Unknown options are refused, so a typo is never ignored.
     */
    public static function paramsFromOptions( tronSkillManifest $m, array $opts ): array
    {
        $map = $m->cliOptions();
        $params = array();
        foreach ( $opts as $k => $values )
        {
            if ( in_array( $k, tronSkillManifest::RESERVED_OPTIONS, true ) )
                continue;
            $key = strpos( $k, '-' ) === 0 ? $k : '--' . $k;
            if ( !isset( $map[$key] ) )
                throw new tronToolError( "unknown option $key for {$m->name} (options: " . implode( ' ', array_keys( $map ) ) . ')' );
            $pn = $map[$key];
            $v = end( $values );
            $type = (string)$m->data['parameters'][$pn]['type'];
            $params[$pn] = $v === true ? ( $type === 'boolean' ? true : '' ) : tronSkillProgram::typed( (string)$v, $type );
        }
        return $params;
    }
}
