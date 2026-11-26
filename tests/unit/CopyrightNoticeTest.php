<?php
/**
 * One copyright notice in every file of tron (owner, 2026-11-25):
 *
 *   Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 *
 * Where a line also states the license, the license follows as its own sentence. Every source and documentation file
 * carries the notice, and every line that claims the holders next to the word copyright uses exactly this wording.
 * Left out: LICENSE (the GPL text), JSON (no comments), the cookbook's .expected outputs, VERSION, .gitignore and
 * tests/fixtures (data that stands in for other people's files). tronVersion::NOTICE holds the wording.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

final class TronCopyrightNoticeTest extends TronTestCase
{
    const TYPES = '/\.(php|tpl|css|js|py|sh|md|ts|ini|rules|sql|xml|txt)$/';
    const HOLDERS = '/(?<![0-9A-Za-z])7x(?![0-9A-Za-z])|exponential foundation/i';

    private static function root(): string
    {
        return dirname( __DIR__, 2 );
    }

    /** The files of the clone (git ls-files), or of the folder (a walk without var/ and .git/) where git is not there. */
    private static function files(): array
    {
        $root = self::root();
        if ( is_dir( $root . '/.git' ) )
        {
            $list = shell_exec( 'git -c safe.directory=' . escapeshellarg( $root ) . ' -C ' . escapeshellarg( $root ) . ' ls-files -z 2>/dev/null' );
            if ( is_string( $list ) && $list !== '' )
                return array_values( array_filter( explode( "\0", $list ), 'strlen' ) );
        }
        $out = array();
        $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $file )
        {
            $rel = substr( $file->getPathname(), strlen( $root ) + 1 );
            if ( !preg_match( '#(^|/)(var|\.git|__pycache__)/#', $rel ) )
                $out[] = $rel;
        }
        sort( $out );
        return $out;
    }

    /** True for a path that carries no notice of its own. */
    public static function exempt( string $path ): bool
    {
        return $path === 'LICENSE' || $path === 'VERSION' || $path === '.gitignore' || strpos( $path, 'tests/fixtures/' ) === 0
            || (bool)preg_match( '/\.(json|expected)$/', $path );
    }

    /** True for a line that claims the holders next to the word copyright in any wording but the approved one. */
    public static function oldNotice( string $line ): bool
    {
        if ( stripos( $line, 'copyright' ) === false || !preg_match( self::HOLDERS, $line ) )
            return false;
        if ( !preg_match( '/(copyright|\(c\))\s*(\(c\)\s*)?((19|20)[0-9]{2}\s*-\s*(19|20)[0-9]{2}\s*)?(the\s+)?(7x|exponential foundation)/i', $line ) )
            return false;
        return strpos( $line, tronVersion::NOTICE ) === false;
    }

    public function testDetector(): void
    {
        $this->assertFalse( self::oldNotice( ' * @copyright ' . tronVersion::NOTICE ), 'the approved notice' );
        $this->assertFalse( self::oldNotice( tronVersion::NOTICE . ' ' . tronVersion::LICENSE ), 'the notice with its license sentence' );
        $this->assertTrue( self::oldNotice( ' * @copyright Copyright (C) 1998 - 2026 ' . 'Exponential Foundation and 7x. All rights reserved.' ), 'a full stop instead of the semicolon' );
        $this->assertTrue( self::oldNotice( '<copyright>Copyright (C) 1998 - 2026 ' . 'Exponential Foundation and 7x</copyright>' ), 'no rights sentence' );
        $this->assertTrue( self::oldNotice( 'Copyright (C) 1998 - 2026 ' . '7x & Exponential Foundation. All rights reserved.' ), 'the old order of the holders' );
        $this->assertFalse( self::oldNotice( 'Copyright (C) 2026 Example' ), 'another holder' );
        $this->assertFalse( self::oldNotice( '`static`, the class is `<extension>Info`. It also states `Name`, `Copyright`,' ), 'prose' );
        $this->assertTrue( self::exempt( 'LICENSE' ) && self::exempt( 'share/skills/seo/pack.json' ) && self::exempt( 'tests/fixtures/system/console.txt' ) );
        $this->assertFalse( self::exempt( 'design/standard/templates/tron/dashboard.tpl' ) );
    }

    public function testEveryFileCarriesTheNotice(): void
    {
        $root = self::root();
        $missing = array();
        $checked = 0;
        foreach ( self::files() as $path )
        {
            if ( self::exempt( $path ) || !preg_match( self::TYPES, $path ) || !is_file( $root . '/' . $path ) )
                continue;
            $checked++;
            if ( strpos( (string)file_get_contents( $root . '/' . $path ), tronVersion::NOTICE ) === false )
                $missing[] = $path;
        }
        $this->assertTrue( $checked > 300, "the walk found tron's files ($checked)" );
        $this->assertSame( array(), $missing, 'files without "' . tronVersion::NOTICE . '"' );
    }

    public function testNoOldWordingIsLeft(): void
    {
        $root = self::root();
        $bad = array();
        foreach ( self::files() as $path )
        {
            if ( $path === 'LICENSE' || !is_file( $root . '/' . $path ) )
                continue;
            $data = (string)file_get_contents( $root . '/' . $path );
            if ( stripos( $data, 'copyright' ) === false || strpos( substr( $data, 0, 8192 ), "\0" ) !== false )
                continue;
            foreach ( preg_split( '/\R/', $data ) as $no => $line )
            {
                if ( self::oldNotice( $line ) )
                    $bad[] = $path . ':' . ( $no + 1 ) . ': ' . substr( trim( $line ), 0, 160 );
            }
        }
        $this->assertSame( array(), $bad, 'notices in another wording' );
    }

    public function testReleaseFieldsCarryTheNotice(): void
    {
        $root = self::root();
        require_once $root . '/ezinfo.php';
        $info = tronInfo::info();
        $this->assertSame( tronVersion::NOTICE, $info['Copyright'], 'ezinfo.php Copyright' );
        $this->assertContains( '// COPYRIGHT NOTICE: ' . tronVersion::NOTICE, (string)file_get_contents( $root . '/ezinfo.php' ) );
        $xml = simplexml_load_file( $root . '/extension.xml' );
        $this->assertSame( tronVersion::NOTICE, (string)$xml->metadata->copyright, 'extension.xml <copyright>' );
        foreach ( array( 'mysql' => 'mysql', 'pgsql' => 'postgresql', 'sqlite' => 'sqlite' ) as $driver => $dir )
            $this->assertContains( '-- ' . tronVersion::NOTICE . ' ' . tronVersion::LICENSE, tronSchema::sql( $driver ) );
    }
}
