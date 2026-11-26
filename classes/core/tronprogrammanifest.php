<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * A program's manifest, tron/program.json in its extension (or programs/<name>/program.json inside tron):
 *
 *   {
 *     "name": "centre",                    required, ^[a-z][a-z0-9]{1,31}$: also the prefix of its tools
 *     "class": "tronCentreProgram",        required, implements tronProgram
 *     "file": "classes/centreprogram.php", optional, relative to the extension; for a class the autoload lacks
 *     "version": "0.0.0.1",                required, X.Y.Z or X.Y.Z.N
 *     "requires": "0.0.0.1",               optional, the oldest tron it works with
 *     "title": "Centre", "description": "Tickets and cards"   optional
 *   }
 *
 * Reading a manifest runs no code of the program, so a broken program is found and listed before anything of it
 * is loaded.
 */
final class tronProgramManifest
{
    /** @var string */ public $name;
    /** @var string */ public $class;
    /** @var string */ public $file = '';
    /** @var string */ public $version;
    /** @var string */ public $requires = '';
    /** @var string */ public $title = '';
    /** @var string */ public $description = '';
    /** @var string the manifest file */ public $path;
    /** @var string the directory "file" is relative to */ public $baseDir;
    /** @var string builtin, or the extension's name */ public $origin;

    /** @throws InvalidArgumentException with the reason the manifest is unusable */
    public static function read( string $path, string $baseDir, string $origin ): self
    {
        $raw = @file_get_contents( $path );
        if ( $raw === false )
            throw new InvalidArgumentException( 'cannot read the manifest' );
        $d = json_decode( $raw, true );
        if ( !is_array( $d ) )
            throw new InvalidArgumentException( 'the manifest is not a JSON object' );
        $m = new self();
        $m->path = $path;
        $m->baseDir = $baseDir;
        $m->origin = $origin;
        $m->name = (string)( $d['name'] ?? '' );
        if ( !preg_match( '/^[a-z][a-z0-9]{1,31}$/', $m->name ) )
            throw new InvalidArgumentException( "the name '{$m->name}' does not match ^[a-z][a-z0-9]{1,31}$" );
        $m->class = (string)( $d['class'] ?? '' );
        if ( !preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $m->class ) )
            throw new InvalidArgumentException( 'the manifest needs a class name' );
        $m->file = (string)( $d['file'] ?? '' );
        if ( $m->file !== '' && ( strpos( $m->file, '..' ) !== false || $m->file[0] === '/' || substr( $m->file, -4 ) !== '.php' ) )
            throw new InvalidArgumentException( "the file '{$m->file}' must be a .php path inside the extension" );
        $m->version = (string)( $d['version'] ?? '' );
        if ( !tronVersion::isValid( $m->version ) )
            throw new InvalidArgumentException( 'the manifest needs a version X.Y.Z or X.Y.Z.N' );
        $m->requires = (string)( $d['requires'] ?? '' );
        if ( $m->requires !== '' && !tronVersion::isValid( $m->requires ) )
            throw new InvalidArgumentException( "requires '{$m->requires}' is not a version" );
        $m->title = (string)( $d['title'] ?? $m->name );
        $m->description = (string)( $d['description'] ?? '' );
        return $m;
    }
}
