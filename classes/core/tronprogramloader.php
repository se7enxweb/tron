<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Finds programs by convention and loads them, each in isolation.
 *
 * Where it looks, in this order (the first program of a name wins, a later one is reported as a conflict):
 *   1. tron's own programs: <tron>/programs/<name>/program.json
 *   2. every active extension: extension/<ext>/tron/program.json, and extension/<ext>/tron/programs/<name>/program.json
 *      for an extension that ships several
 *
 * What it does with each: reads the manifest (no program code runs), skips it when [ProgramSettings] Disabled[]
 * names it, checks the version it requires, loads the class (autoload, else the manifest's file), checks that it
 * implements tronProgram, and lets it register inside a registry transaction. Any failure, Throwable included,
 * leaves that one program out with the reason; tron and the other programs carry on. That is the microkernel rule:
 * a broken program can cost only itself.
 *
 * Each program gets a status row: name, origin, version, state (loaded, disabled, failed, conflict,
 * incompatible), reason, counts and the milliseconds it took.
 */
final class tronProgramLoader
{
    /** @var array[] */ private $status = array();

    /**
     * @param string   $builtinDir  <tron>/programs
     * @param array    $extensions  extension name => extension directory, in load order
     * @param array    $disabled    program names to leave out: a list, or name => the reason shown
     */
    public function load( tronRegistry $registry, tronEnvironment $env, string $builtinDir, array $extensions, array $disabled = array() ): array
    {
        if ( tronSchemaValidator::isList( $disabled ) )
            $disabled = array_fill_keys( array_map( 'strval', $disabled ), '[ProgramSettings] Disabled[]' );
        $this->status = array();
        $seen = array();
        foreach ( $this->discover( $builtinDir, $extensions ) as $found )
        {
            list( $path, $baseDir, $origin ) = $found;
            $t0 = microtime( true );
            try
            {
                $m = tronProgramManifest::read( $path, $baseDir, $origin );
            }
            catch ( Throwable $e )
            {
                $this->status[] = self::row( basename( dirname( $path ) ), $origin, '', 'failed', 'manifest ' . $path . ': ' . $e->getMessage(), $t0 );
                continue;
            }
            if ( isset( $seen[$m->name] ) )
            {
                $this->status[] = self::row( $m->name, $origin, $m->version, 'conflict', "the name is already taken by the program from {$seen[$m->name]}", $t0 );
                continue;
            }
            $seen[$m->name] = $origin;
            if ( isset( $disabled[$m->name] ) )
            {
                $this->status[] = self::row( $m->name, $origin, $m->version, 'disabled', (string)$disabled[$m->name], $t0 );
                continue;
            }
            if ( $m->requires !== '' && tronVersion::compare( tronVersion::VERSION, $m->requires ) < 0 )
            {
                $this->status[] = self::row( $m->name, $origin, $m->version, 'incompatible', "needs tron {$m->requires} or later, this is " . tronVersion::VERSION, $t0 );
                continue;
            }
            $this->status[] = $this->loadOne( $m, $registry, $env, $t0 );
        }
        return $this->status;
    }

    public function status(): array
    {
        return $this->status;
    }

    /** @return array[] array( manifest path, base dir, origin ) in load order */
    public function discover( string $builtinDir, array $extensions ): array
    {
        $found = array();
        if ( is_dir( $builtinDir ) )
        {
            $dirs = glob( rtrim( $builtinDir, '/' ) . '/*/program.json' ) ?: array();
            sort( $dirs );
            foreach ( $dirs as $p )
                $found[] = array( $p, dirname( dirname( dirname( $p ) ) ), 'builtin' );
        }
        foreach ( $extensions as $ext => $dir )
        {
            $dir = rtrim( (string)$dir, '/' );
            if ( is_file( $dir . '/tron/program.json' ) )
                $found[] = array( $dir . '/tron/program.json', $dir, (string)$ext );
            if ( is_dir( $dir . '/tron/programs' ) )
            {
                $more = glob( $dir . '/tron/programs/*/program.json' ) ?: array();
                sort( $more );
                foreach ( $more as $p )
                    $found[] = array( $p, $dir, (string)$ext );
            }
        }
        return $found;
    }

    private function loadOne( tronProgramManifest $m, tronRegistry $registry, tronEnvironment $env, float $t0 ): array
    {
        try
        {
            if ( !class_exists( $m->class ) && $m->file !== '' )
            {
                $file = realpath( $m->baseDir . '/' . $m->file );
                $base = realpath( $m->baseDir );
                if ( $file === false || $base === false || strpos( $file, $base . DIRECTORY_SEPARATOR ) !== 0 )
                    return self::row( $m->name, $m->origin, $m->version, 'failed', "the file {$m->file} does not exist inside the extension", $t0 );
                require_once $file;
            }
            if ( !class_exists( $m->class ) )
                return self::row( $m->name, $m->origin, $m->version, 'failed', "the class {$m->class} was not found (regenerate the autoloads, or name its file in the manifest)", $t0 );
            if ( !in_array( 'tronProgram', class_implements( $m->class ) ?: array(), true ) )
                return self::row( $m->name, $m->origin, $m->version, 'failed', "the class {$m->class} does not implement tronProgram", $t0 );
            $program = new $m->class();
            $registry->begin( $m->name );
            try
            {
                $program->register( $registry, $env );
                $counts = $registry->commit();
            }
            catch ( Throwable $e )
            {
                $registry->rollback();
                throw $e;
            }
            $row = self::row( $m->name, $m->origin, $m->version, 'loaded', '', $t0 );
            return $row + $counts + array( 'title' => $m->title, 'description' => $m->description );
        }
        catch ( Throwable $e )
        {
            return self::row( $m->name, $m->origin, $m->version, 'failed', get_class( $e ) . ': ' . $e->getMessage(), $t0 );
        }
    }

    private static function row( string $name, string $origin, string $version, string $state, string $reason, float $t0 ): array
    {
        return array( 'name' => $name, 'origin' => $origin, 'version' => $version, 'state' => $state, 'reason' => $reason,
                      'ms' => round( ( microtime( true ) - $t0 ) * 1000, 3 ) );
    }
}
