#!/usr/bin/env php
<?php
/**
 * The API coverage inventory of this installation. Run from the installation root:
 *
 *   php extension/tron/bin/php/tron-inventory.php --siteaccess=<sa>                     summary per area
 *   php extension/tron/bin/php/tron-inventory.php --siteaccess=<sa> --write             also write var/tron/api-coverage.json
 *   php extension/tron/bin/php/tron-inventory.php --siteaccess=<sa> --doc=<dir>         also write <dir>/api-coverage.json and .md
 *   php extension/tron/bin/php/tron-inventory.php --siteaccess=<sa> --area=fetch_function --uncovered   list points
 *
 * It reads the installation's own files: module.php, function_definition.php, operation_definition.php, the template
 * operator autoloads, the merged content.ini, workflow.ini, cronjob.ini and expqueue.ini of the siteaccess, the INI
 * files, the autoload arrays (service classes), bin/php and "bin/php/console list". It writes nothing unless asked.
 * See doc/api-coverage.md.
 *
 * Exit codes: 0 PASS, 1 FAIL, 2 usage.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

if ( !is_file( 'autoload.php' ) && is_file( dirname( __DIR__, 4 ) . '/autoload.php' ) )
    chdir( dirname( __DIR__, 4 ) );
require_once 'autoload.php';
require_once dirname( __DIR__, 2 ) . '/autoload.php';

$cli = eZCLI::instance();
$script = eZScript::instance( array( 'description' => 'The API coverage inventory of tron',
                                     'use-session' => false, 'use-modules' => true, 'use-extensions' => true ) );
$script->startup();
$options = $script->getOptions( '[write][doc:][area:][uncovered][json][no-console]', '', array(
    'write' => 'write var/tron/api-coverage.json (what the system tools read)',
    'doc' => 'write api-coverage.json and api-coverage.md into this directory',
    'area' => 'list the points of one area',
    'uncovered' => 'with --area: only the points tron does not cover yet',
    'json' => 'print the summary as JSON',
    'no-console' => 'leave the console commands out (do not run bin/php/console list)' ) );
$script->initialize();

$root = getcwd();
$console = '';
if ( !$options['no-console'] )
{
    $cmd = array( PHP_BINARY, 'bin/php/console', 'list' );
    $proc = proc_open( $cmd, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $root, array( 'TERM' => 'dumb', 'NO_COLOR' => '1', 'PATH' => getenv( 'PATH' ) ?: '/usr/bin:/bin' ) );
    if ( is_resource( $proc ) )
    {
        $console = (string)stream_get_contents( $pipes[1] );
        stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        proc_close( $proc );
    }
    if ( !tronSystemInventory::parseConsoleList( $console ) )
        $cli->warning( 'bin/php/console list gave no commands; the console area stays empty' );
}

$inventory = new tronSystemInventory( array(
    'root' => $root,
    'extensions' => eZExtension::activeExtensions(),
    'ini' => function ( string $file ) {
        $ini = eZINI::instance( $file );
        return $ini->groups();
    },
    'console' => $console,
    'units' => tronUnitCatalog::units( glob( dirname( __DIR__, 2 ) . '/programs/*/program.json' ) ?: array() ),
    'allow' => new tronSystemAllow( (array)( tronKernel::settings()['SystemAdapterSettings'] ?? array() ) ),
) );
$t0 = microtime( true );
$data = $inventory->build();
$catalog = new tronSystemCatalog( $data );
$summary = $catalog->summary();
$ms = round( ( microtime( true ) - $t0 ) * 1000 );

$writeJson = function ( string $file ) use ( $data, $cli ) {
    if ( !is_dir( dirname( $file ) ) && !mkdir( dirname( $file ), 0775, true ) )
        throw new RuntimeException( 'cannot create ' . dirname( $file ) );
    $json = tronSystemCatalog::encode( $data );
    if ( file_put_contents( $file . '.tmp', $json . "\n" ) === false || !rename( $file . '.tmp', $file ) )
        throw new RuntimeException( "cannot write $file" );
    $cli->output( "wrote $file (" . strlen( $json ) . ' bytes)' );
};

try
{
    if ( $options['write'] )
        $writeJson( 'var/tron/api-coverage.json' );
    if ( $options['doc'] )
    {
        $dir = rtrim( (string)$options['doc'], '/' );
        $writeJson( $dir . '/api-coverage.json' );
        if ( file_put_contents( $dir . '/api-coverage.md', $catalog->markdown() ) === false )
            throw new RuntimeException( "cannot write $dir/api-coverage.md" );
        $cli->output( "wrote $dir/api-coverage.md" );
    }
}
catch ( Throwable $e )
{
    $cli->error( 'FAIL: ' . $e->getMessage() );
    $script->shutdown( 1 );
}

if ( $options['json'] )
{
    $cli->output( json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
    $script->shutdown( 0 );
}

if ( $options['area'] )
{
    $hits = $catalog->search( array( 'area' => (string)$options['area'] ) + ( $options['uncovered'] ? array( 'covered' => false ) : array() ), 100000 );
    foreach ( $hits['items'] as $p )
        $cli->output( sprintf( '  %-60s %-10s %-9s %s', $p['name'], $p['origin'], $p['risk'], $p['covered'] ? $p['by'] . ( $p['mode'] === 'dry_run' ? ' (dry run)' : '' ) : '- ' . $p['planned'] ) );
    $cli->output( "  {$hits['total']} points" );
}

$t = $summary['totals'];
$cli->output( sprintf( 'tron %s: API coverage of %s (%d ms)', tronVersion::VERSION, $root, $ms ) );
$cli->output( sprintf( '  %-26s %7s %7s %9s %7s %8s %9s', 'area', 'points', 'read', 'covered', '%', 'writes', 'dry run' ) );
foreach ( $summary['areas'] as $area => $r )
    $cli->output( sprintf( '  %-26s %7d %7d %9d %6.1f%% %8d %9d', $area, $r['points'], $r['read'], $r['read_covered'], $r['read_pct'], $r['write'], $r['write_covered'] ) );
$cli->output( sprintf( '  %-26s %7d %7d %9d %6.1f%% %8d %9d', 'total', $t['points'], $t['read'], $t['read_covered'], $t['read_pct'], $t['write'], $t['write_covered'] ) );
if ( $data['problems'] )
    $cli->output( '  ' . count( $data['problems'] ) . ' files could not be read (listed in the catalog)' );
$cli->output( $t['points'] > 0 ? 'PASS' : 'FAIL: no API points found' );
$script->shutdown( $t['points'] > 0 ? 0 : 1 );
