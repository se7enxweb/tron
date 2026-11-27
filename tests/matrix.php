<?php
/**
 * Lints every PHP file and runs the suite on each PHP binary given (or found):
 *
 *   php tests/matrix.php /opt/plesk/php/7.4/bin/php /opt/plesk/php/8.5/bin/php ...
 *   php tests/matrix.php                  # every /opt/plesk/php/<7.4 and newer>/bin/php and /opt/remi/php*\/root/usr/bin/php
 *
 * One line per binary: version, protocol layer (sdk where PHP is 8.2 or newer and the SDK is installed or named by
 * TRON_SDK_AUTOLOAD, else legacy; TRON_PROTOCOL forces one), lint result, suite result, contract fuzz result (tests/fuzz.php, TRON_MATRIX_FUZZ inputs
 * per tool, default 200, 0 skips it). Exit 0 only when every binary passed all three.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

$root = dirname( __DIR__ );
$bins = array_slice( $argv, 1 );
if ( !$bins )
{
    foreach ( array_merge( glob( '/opt/plesk/php/*/bin/php' ) ?: array(), glob( '/opt/remi/php*/root/usr/bin/php' ) ?: array() ) as $b )
    {
        $v = preg_match( '/^PHP (\d+\.\d+)/', (string)shell_exec( escapeshellarg( $b ) . ' -v' ), $mm ) ? $mm[1] : '';
        if ( $v !== '' && version_compare( $v, '7.4', '>=' ) )
            $bins[] = $b;
    }
}
$files = array();
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f )
{
    $p = $f->getPathname();
    $rel = substr( $p, strlen( $root ) + 1 );
    if ( substr( $p, -4 ) === '.php' && strpos( $rel, '.git/' ) !== 0 && strpos( $rel, 'var/' ) !== 0 )
        $files[] = $p;
}
sort( $files );

$allOk = true;
foreach ( $bins as $bin )
{
    $version = preg_match( '/^PHP (\S+)/', (string)shell_exec( escapeshellarg( $bin ) . ' -v' ), $mm ) ? $mm[1] : '?';
    $lintBad = array();
    $skipped = 0;
    foreach ( $files as $file )
    {
        // the SDK layer (PHP 8.2 syntax) is loaded only where the SDK runs (tronProtocolLayers::SDK_PHP)
        if ( preg_match( '#/classes/protocol/tronsdk[a-z]*\.php$#', $file ) && version_compare( $version, '8.2', '<' ) )
        {
            $skipped++;
            continue;
        }
        exec( escapeshellarg( $bin ) . ' -l ' . escapeshellarg( $file ) . ' 2>&1', $out, $code );
        if ( $code !== 0 )
            $lintBad[] = str_replace( $root . '/', '', $file );
        $out = array();
    }
    $suite = array();
    exec( escapeshellarg( $bin ) . ' ' . escapeshellarg( "$root/tests/run.php" ) . ' 2>&1', $suite, $suiteCode );
    $summary = '';
    foreach ( $suite as $line )
    {
        if ( preg_match( '/^(PASS|FAIL): \d+ passed/', $line ) )
            $summary = $line;
    }
    // the contract fuzzer on top of the suite's short run: TRON_MATRIX_FUZZ inputs per tool (default 200, 0 skips)
    $fuzzPer = getenv( 'TRON_MATRIX_FUZZ' ) === false ? 200 : (int)getenv( 'TRON_MATRIX_FUZZ' );
    $fuzz = array();
    $fuzzCode = 0;
    if ( $fuzzPer > 0 )
        exec( escapeshellarg( $bin ) . ' ' . escapeshellarg( "$root/tests/fuzz.php" ) . ' --quiet --per-tool=' . $fuzzPer . ' 2>&1', $fuzz, $fuzzCode );
    $fuzzLine = $fuzzPer > 0 ? (string)( preg_grep( '/^(PASS|FAIL): \d+ inputs/', $fuzz ) ? array_values( preg_grep( '/^(PASS|FAIL): \d+ inputs/', $fuzz ) )[0] : 'no summary' ) : 'skipped';
    $ok = !$lintBad && $suiteCode === 0 && $fuzzCode === 0;
    $allOk = $allOk && $ok;
    $layer = trim( (string)shell_exec( escapeshellarg( $bin ) . ' -r ' . escapeshellarg( 'require ' . var_export( "$root/autoload.php", true ) . '; echo tronProtocolLayers::resolve( "auto" );' ) ) );
    printf( "%s PHP %-8s layer %-6s lint %s (%d files)  suite: %s  fuzz: %s\n", $ok ? 'PASS' : 'FAIL', $version, $layer, $lintBad ? 'FAIL ' . implode( ', ', $lintBad ) : 'PASS', count( $files ) - $skipped, $summary ?: 'no summary', $fuzzLine );
    if ( $fuzzCode !== 0 )
        foreach ( array_slice( $fuzz, -12 ) as $line )
            echo "    $line\n";
    if ( $suiteCode !== 0 )
    {
        foreach ( $suite as $line )
        {
            if ( strpos( $line, 'FAIL ' ) === 0 )
                echo "    $line\n";
        }
    }
}
echo $allOk ? "PASS: all binaries\n" : "FAIL\n";
exit( $allOk ? 0 : 1 );
