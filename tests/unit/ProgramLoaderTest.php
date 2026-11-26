<?php
/**
 * The convention loader: where it looks, in which order, and that a broken program costs only itself.
 *
 * Fixture extensions are written under the test scratch directory on every run (fixed names, overwritten).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

final class TronProgramLoaderTest extends TronTestCase
{
    /** @var string */ private $dir;

    public function setUp(): void
    {
        $this->dir = TronFixture::tmp( 'loader' );
    }

    /** Writes an extension with a program; $code is the class body's register() content. */
    private function ext( string $ext, array $manifest, ?string $code = null, string $layout = 'single' ): string
    {
        $base = $this->dir . '/' . $ext;
        $mdir = $layout === 'single' ? "$base/tron" : "$base/tron/programs/{$manifest['name']}";
        TronFixture::write( "$mdir/program.json", json_encode( $manifest ) );
        if ( $code !== null && isset( $manifest['file'] ) )
            TronFixture::write( "$base/{$manifest['file']}", $code );
        return $base;
    }

    private static function program( string $class, string $body ): string
    {
        return "<?php\nfinal class $class implements tronProgram\n{\n    public function register( tronRegistry \$registry, tronEnvironment \$env ): void\n    {\n$body\n    }\n}\n";
    }

    private static function tool( string $name ): string
    {
        return "        \$registry->tool( tronTool::define( array( 'name' => '$name', 'description' => 'fixture', 'policy' => array( 'tron', 'use' ), 'handler' => function () { return array( 'ok' => '$name' ); } ) ) );";
    }

    private function load( array $extensions, array $disabled = array() ): array
    {
        $reg = new tronRegistry();
        $status = ( new tronProgramLoader() )->load( $reg, new tronEnvironment(), tronFactory::builtinDir(), $extensions, $disabled );
        $by = array();
        foreach ( $status as $row )
            $by[$row['name'] . '@' . $row['origin']] = $row;
        return array( $reg, $status, $by );
    }

    public function testBuiltinProgramsLoad(): void
    {
        list( $reg, $status ) = $this->load( array() );
        $names = array_column( $status, 'name' );
        $this->assertSame( self::builtinPrograms(), $names, 'builtin programs in name order' );
        foreach ( $status as $row )
            $this->assertSame( 'loaded', $row['state'], $row['name'] . ': ' . $row['reason'] );
        $this->assertTrue( $reg->findTool( 'tron_status' ) !== null );
    }

    public function testExtensionConventionsBothLayouts(): void
    {
        $a = $this->ext( 'ext_alpha', array( 'name' => 'alpha', 'class' => 'TronFixtureAlphaProgram', 'version' => '1.0.0', 'file' => 'classes/alpha.php' ),
                         self::program( 'TronFixtureAlphaProgram', self::tool( 'alpha_ping' ) ) );
        $b = $this->ext( 'ext_multi', array( 'name' => 'beta', 'class' => 'TronFixtureBetaProgram', 'version' => '1.0.0.3', 'file' => 'classes/beta.php' ),
                         self::program( 'TronFixtureBetaProgram', self::tool( 'beta_ping' ) ), 'multi' );
        $this->ext( 'ext_multi', array( 'name' => 'gamma', 'class' => 'TronFixtureGammaProgram', 'version' => '1.0.0', 'file' => 'classes/gamma.php' ),
                    self::program( 'TronFixtureGammaProgram', self::tool( 'gamma_ping' ) ), 'multi' );
        list( $reg, , $by ) = $this->load( array( 'ext_alpha' => $a, 'ext_multi' => $b ) );
        $this->assertSame( 'loaded', $by['alpha@ext_alpha']['state'], (string)$by['alpha@ext_alpha']['reason'] );
        $this->assertSame( 'loaded', $by['beta@ext_multi']['state'] );
        $this->assertSame( 'loaded', $by['gamma@ext_multi']['state'] );
        $this->assertSame( 1, $by['alpha@ext_alpha']['tools'] );
        $this->assertSame( 'alpha', $reg->findTool( 'alpha_ping' )->program );
        $this->assertTrue( $reg->findTool( 'gamma_ping' ) !== null );
    }

    public function testBrokenProgramsCostOnlyThemselves(): void
    {
        $exts = array();
        // register() throws half way: its first tool must not stay registered
        $exts['ext_throw'] = $this->ext( 'ext_throw', array( 'name' => 'thrower', 'class' => 'TronFixtureThrowerProgram', 'version' => '1.0.0', 'file' => 'p.php' ),
            self::program( 'TronFixtureThrowerProgram', self::tool( 'thrower_one' ) . "\n        throw new RuntimeException( 'boom' );" ) );
        // a file with a syntax error
        $exts['ext_parse'] = $this->ext( 'ext_parse', array( 'name' => 'parser', 'class' => 'TronFixtureParseProgram', 'version' => '1.0.0', 'file' => 'p.php' ),
            "<?php\nfinal class TronFixtureParseProgram implements tronProgram {\n  public function register( \$a, \$b ) { this is not php }\n}\n" );
        // a class that is no program
        $exts['ext_notprog'] = $this->ext( 'ext_notprog', array( 'name' => 'notprog', 'class' => 'TronFixtureNotProgram', 'version' => '1.0.0', 'file' => 'p.php' ),
            "<?php\nfinal class TronFixtureNotProgram {}\n" );
        // a class that does not exist
        $exts['ext_noclass'] = $this->ext( 'ext_noclass', array( 'name' => 'noclass', 'class' => 'TronFixtureMissingClass', 'version' => '1.0.0' ) );
        // a file outside the extension
        $exts['ext_escape'] = $this->ext( 'ext_escape', array( 'name' => 'escape', 'class' => 'TronFixtureEscape', 'version' => '1.0.0', 'file' => '../x.php' ) );
        // a broken manifest
        TronFixture::write( $this->dir . '/ext_badjson/tron/program.json', '{ not json' );
        $exts['ext_badjson'] = $this->dir . '/ext_badjson';
        // a bad name
        $exts['ext_badname'] = $this->ext( 'ext_badname', array( 'name' => 'Bad_Name', 'class' => 'X', 'version' => '1.0.0' ) );
        // needs a newer tron
        $exts['ext_newer'] = $this->ext( 'ext_newer', array( 'name' => 'newer', 'class' => 'TronFixtureNewer', 'version' => '1.0.0', 'requires' => '99.0.0.0' ) );
        // takes the name of a builtin program
        $exts['ext_clash'] = $this->ext( 'ext_clash', array( 'name' => 'centre', 'class' => 'TronFixtureClash', 'version' => '1.0.0' ) );
        // tools named outside its prefix
        $exts['ext_prefix'] = $this->ext( 'ext_prefix', array( 'name' => 'prefix', 'class' => 'TronFixturePrefixProgram', 'version' => '1.0.0', 'file' => 'p.php' ),
            self::program( 'TronFixturePrefixProgram', self::tool( 'centre_hijack' ) ) );
        // a healthy one after all of them
        $exts['ext_good'] = $this->ext( 'ext_good', array( 'name' => 'good', 'class' => 'TronFixtureGoodProgram', 'version' => '1.0.0', 'file' => 'p.php' ),
            self::program( 'TronFixtureGoodProgram', self::tool( 'good_ping' ) ) );

        list( $reg, , $by ) = $this->load( $exts );
        $this->assertSame( 'failed', $by['thrower@ext_throw']['state'] );
        $this->assertContains( 'boom', $by['thrower@ext_throw']['reason'] );
        $this->assertSame( null, $reg->findTool( 'thrower_one' ), 'staged tool of a failed program is rolled back' );
        $this->assertSame( 'failed', $by['parser@ext_parse']['state'] );
        $this->assertContains( 'ParseError', $by['parser@ext_parse']['reason'] );
        $this->assertContains( 'does not implement tronProgram', $by['notprog@ext_notprog']['reason'] );
        $this->assertContains( 'was not found', $by['noclass@ext_noclass']['reason'] );
        $this->assertSame( 'failed', $by['tron@ext_badjson']['state'] );
        $this->assertContains( 'not a JSON object', $by['tron@ext_badjson']['reason'] );
        $this->assertContains( 'does not match', $by['tron@ext_badname']['reason'] );
        $this->assertSame( 'failed', $by['escape@ext_escape']['state'] ?? ( $by['tron@ext_escape']['state'] ?? 'missing' ) );
        $this->assertSame( 'incompatible', $by['newer@ext_newer']['state'] );
        $this->assertSame( 'conflict', $by['centre@ext_clash']['state'] );
        $this->assertSame( 'failed', $by['prefix@ext_prefix']['state'] );
        $this->assertContains( 'prefix_*', $by['prefix@ext_prefix']['reason'] );
        $this->assertSame( 'loaded', $by['good@ext_good']['state'], 'a healthy program after broken ones still loads' );
        $this->assertSame( 'loaded', $by['centre@builtin']['state'], 'the builtin centre is untouched' );
        $this->assertSame( null, $reg->findTool( 'centre_hijack' ) );
    }

    public function testDisabledPrograms(): void
    {
        list( $reg, , $by ) = $this->load( array(), array( 'katalyst' ) );
        $this->assertSame( 'disabled', $by['katalyst@builtin']['state'] );
        $this->assertSame( null, $reg->findTool( 'katalyst_plans_list' ) );
        $this->assertSame( 'loaded', $by['centre@builtin']['state'] );
    }

    public function testFactoryNeverDisablesTheCore(): void
    {
        $f = TronFixture::build( array( 'settings' => array( 'ProgramSettings' => array( 'Disabled' => array( 'tron', 'centre' ) ) ) ) );
        $states = array_column( $f['server']->option( 'programs' ), 'state', 'name' );
        $this->assertSame( 'loaded', $states['tron'] );
        $this->assertSame( 'disabled', $states['centre'] );
    }

    public function testExtensionScanCanBeSwitchedOff(): void
    {
        $a = $this->ext( 'ext_alpha', array( 'name' => 'alpha', 'class' => 'TronFixtureAlphaProgram', 'version' => '1.0.0', 'file' => 'classes/alpha.php' ),
                         self::program( 'TronFixtureAlphaProgram', self::tool( 'alpha_ping' ) ) );
        $f = TronFixture::build( array( 'extensions' => array( 'ext_alpha' => $a ), 'settings' => array( 'ProgramSettings' => array( 'ExtensionScan' => 'disabled' ) ) ) );
        $this->assertSame( null, $f['server']->registry()->findTool( 'alpha_ping' ) );
    }

    /** Discovery over 300 extensions (most without a program) and loading 50 programs stays fast. */
    public function testDiscoveryIsFast(): void
    {
        $exts = array();
        for ( $i = 0; $i < 300; $i++ )
        {
            $name = sprintf( 'bench%03d', $i );
            if ( $i % 6 === 0 )
            {
                $cls = 'TronFixtureBench' . $i . 'Program';
                $pname = 'bench' . $i;
                $exts[$name] = $this->ext( $name, array( 'name' => $pname, 'class' => $cls, 'version' => '1.0.0', 'file' => 'p.php' ),
                                           self::program( $cls, self::tool( $pname . '_ping' ) ) );
            }
            else
            {
                if ( !is_dir( $this->dir . '/' . $name ) )
                    mkdir( $this->dir . '/' . $name, 0775, true );
                $exts[$name] = $this->dir . '/' . $name;
            }
        }
        $this->load( $exts ); // warm: class files compiled once
        $t0 = microtime( true );
        list( $reg, $status ) = $this->load( $exts );
        $ms = ( microtime( true ) - $t0 ) * 1000;
        $n = count( self::builtinPrograms() ) + 50;
        $this->assertSame( $n, count( $status ), 'the builtin programs + 50 extension programs' );
        $this->assertSame( $n, count( array_filter( $status, function ( $r ) { return $r['state'] === 'loaded'; } ) ) );
        $this->assertTrue( $ms < 250, sprintf( 'discovery + load took %.1f ms (limit 250)', $ms ) );
        echo sprintf( "      [bench] 300 extensions, %d programs: %.1f ms\n", $n, $ms );
    }
}
