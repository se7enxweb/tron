<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Appends audit entries as JSON lines to a file (one write per entry, under an exclusive lock).
 *
 * The command line often runs as root while the web server runs as the site's user. A file this sink creates is
 * given mode 0660 and, when created as root, its directory's owner and group, so the web server can append too (found
 * on alpha 2026-11-24: a root-owned log made every HTTP write tool refuse to run, as designed, because its audit entry
 * could not be written).
 */
final class tronAuditFile implements tronAuditSink
{
    /** @var string */ private $path;

    public function __construct( string $path )
    {
        $this->path = $path;
    }

    public function record( array $entry ): void
    {
        $dir = dirname( $this->path );
        if ( !is_dir( $dir ) && !@mkdir( $dir, 0770, true ) && !is_dir( $dir ) )
            throw new RuntimeException( "Cannot create the audit log directory $dir" );
        $new = !is_file( $this->path );
        $line = json_encode( $entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ) . "\n";
        if ( @file_put_contents( $this->path, $line, FILE_APPEND | LOCK_EX ) === false )
            throw new RuntimeException( "Cannot write the audit log {$this->path} (its owner and mode must let this process append)" );
        if ( $new )
            self::shareWithDirectoryOwner( $this->path );
    }

    /** Mode 0660; as root also the owner and group of the file's directory. */
    public static function shareWithDirectoryOwner( string $file ): void
    {
        @chmod( $file, 0660 );
        if ( function_exists( 'posix_geteuid' ) && posix_geteuid() === 0 )
        {
            $dir = dirname( $file );
            @chown( $file, (int)fileowner( $dir ) );
            @chgrp( $file, (int)filegroup( $dir ) );
        }
    }
}
