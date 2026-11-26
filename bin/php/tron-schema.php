#!/usr/bin/env php
<?php
/**
 * Prints or writes tron's schema SQL, without an installation:
 *
 *   php bin/php/tron-schema.php mysql|pgsql|sqlite     print one engine's SQL
 *   php bin/php/tron-schema.php --write                (re)write sql/{mysql,postgresql,sqlite}/schema.sql
 *   php bin/php/tron-schema.php --check                PASS when the files equal tronSchema, FAIL otherwise
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

require_once dirname( __DIR__, 2 ) . '/autoload.php';

$root = dirname( __DIR__, 2 );
$map = array( 'mysql' => 'mysql', 'pgsql' => 'postgresql', 'sqlite' => 'sqlite' );
$arg = $argv[1] ?? '';

if ( isset( $map[$arg] ) )
{
    echo tronSchema::sql( $arg );
    exit( 0 );
}
if ( $arg === '--write' || $arg === '--check' )
{
    $stale = array();
    foreach ( $map as $driver => $dir )
    {
        $file = "$root/sql/$dir/schema.sql";
        $sql = tronSchema::sql( $driver );
        if ( (string)@file_get_contents( $file ) === $sql )
            continue;
        if ( $arg === '--check' )
        {
            $stale[] = $file;
            continue;
        }
        if ( !is_dir( dirname( $file ) ) )
            mkdir( dirname( $file ), 0775, true );
        file_put_contents( $file, $sql );
        echo "wrote $file\n";
    }
    if ( $stale )
    {
        fwrite( STDERR, 'FAIL: stale: ' . implode( ', ', $stale ) . "\n" );
        exit( 1 );
    }
    echo "PASS\n";
    exit( 0 );
}
fwrite( STDERR, "usage: tron-schema.php mysql|pgsql|sqlite|--write|--check\n" );
exit( 2 );
