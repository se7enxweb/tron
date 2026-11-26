#!/usr/bin/env php
<?php
/**
 * tron skills on the command line (what xsh tron:skill:* calls). Run from the installation root.
 *
 * Without an installation (data only, no database):
 *   php extension/tron/bin/php/tron-skills.php lint [<pack dir>...]          check packs against the standard
 *   php extension/tron/bin/php/tron-skills.php harness --fixtures [--pack=<id>] [--write-proof] [--json]
 *                                         prove every skill on tron's SQLite test world (the fixtures)
 *   php extension/tron/bin/php/tron-skills.php export <pack id> --to=<dir|file.tar.gz>
 *
 * Inside the installation (--use-siteaccess=<sa> picks the installation's siteaccess; --siteaccess is a skill parameter):
 *   list   [--state=<s>] [--pack=<id>] [--query=<words>] [--tag=<t>] [--json]
 *   show   <name> [--json]
 *   packs  [--json]
 *   counts [--json]                       per state and per pack
 *   run    <name> --user=<login> [--<param> <value> ...] [--json]     a live skill, as that user
 *   validate <name> --user=<login>        the proof as that user (schema, units, policy simulation, runs, redaction)
 *   harness --live --user=<login> [--pack=<id>] [--write-proof] [--json]   prove every skill read-only on this installation
 *   install <dir|file.tar.gz> --extension=<ext>   copy a pack into extension/<ext>/tron/skills/<id>/ and register it
 *   export-local <pack id> --to=<dir>     write a local pack's approved drafts and forks as files
 *   unit   <tool> --user=<login> [--args=<json>]  run one read unit (while writing a skill)
 *
 * Approving is a person's act in Setup > tron > Skills ("Verify it's you"); there is no command for it.
 * Exit codes: 0 PASS, 1 FAIL, 2 usage.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

ini_set( 'display_errors', 'stderr' );
$tronDir = dirname( __DIR__, 2 );

// ------------------------------------------------------------------ arguments: <command> [<arg>] --key=value --key value --flag
$argvIn = array_slice( $argv, 1 );
$command = '';
$args = array();
$opts = array();
for ( $i = 0; $i < count( $argvIn ); $i++ )
{
    $a = $argvIn[$i];
    if ( strpos( $a, '--' ) === 0 && strlen( $a ) > 2 )
    {
        $eq = strpos( $a, '=' );
        if ( $eq !== false )
            $opts[substr( $a, 2, $eq - 2 )][] = substr( $a, $eq + 1 );
        else if ( isset( $argvIn[$i + 1] ) && strpos( $argvIn[$i + 1], '-' ) !== 0 && !in_array( substr( $a, 2 ), array( 'json', 'fixtures', 'live', 'write-proof', 'all' ), true ) )
            $opts[substr( $a, 2 )][] = $argvIn[++$i];
        else
            $opts[substr( $a, 2 )][] = true;
    }
    else if ( strlen( $a ) === 2 && $a[0] === '-' && isset( $argvIn[$i + 1] ) )
        $opts[$a][] = $argvIn[++$i];
    else if ( $command === '' )
        $command = $a;
    else
        $args[] = $a;
}
$opt = function ( string $k, $default = null ) use ( $opts ) {
    return isset( $opts[$k] ) ? end( $opts[$k] ) : $default;
};
$json = (bool)$opt( 'json', false );
$say = function ( string $s ) { fwrite( STDERR, $s . "\n" ); };
$out = function ( string $s ) { fwrite( STDOUT, $s . "\n" ); };
$emit = function ( $data, callable $text ) use ( $json, $out ) {
    if ( $json )
        $out( json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    else
        $text( $data );
};

$standalone = in_array( $command, array( 'lint', 'export', 'help', '' ), true ) || ( in_array( $command, array( 'harness', 'try' ), true ) && $opt( 'fixtures', false ) );
$kernel = false;
if ( $standalone )
{
    require_once $tronDir . '/autoload.php';
}
else
{
    ob_start();
    if ( !is_file( 'autoload.php' ) && is_file( dirname( __DIR__, 4 ) . '/autoload.php' ) )
        chdir( dirname( __DIR__, 4 ) );
    // tron's own loader first: run from a clone, its classes win over the installation's copy of tron
    require_once $tronDir . '/autoload.php';
    require_once 'autoload.php';
    $script = eZScript::instance( array( 'description' => 'tron skills', 'use-session' => false, 'use-modules' => true,
                                         'use-extensions' => true, 'debug-output' => false ) );
    $script->startup();
    if ( $opt( 'use-siteaccess' ) )
        $script->setUseSiteAccess( (string)$opt( 'use-siteaccess' ) );
    $script->initialize();
    $script->setUseDebugOutput( false );
    while ( ob_get_level() > 0 )
        ob_end_clean();
    $kernel = true;
}
$finish = function ( int $code, string $line = '' ) use ( $say, &$kernel ) {
    if ( $line !== '' )
        $say( $line );
    if ( $kernel )
        eZScript::instance()->shutdown( $code );
    exit( $code );
};

/** The server and a session as a user, inside the installation. */
$kernelServer = function ( string $login ) use ( $finish ) {
    $u = ( new tronKernelUserDirectory() )->byLogin( $login );
    if ( !$u || !$u['enabled'] )
        $finish( 1, "FAIL: no enabled user '$login'" );
    $access = new tronKernelAccessChecker();
    $principal = new tronPrincipal( (int)$u['id'], (string)$u['login'] );
    if ( $access->check( $principal, 'tron', 'use' ) !== tronAccessChecker::YES )
        $finish( 1, "FAIL: the user $login does not hold tron/use" );
    $server = tronKernel::server( null, $access );
    return array( $server, new tronSession( $principal, 'stdio', false, '2025-11-25', 'cli' ) );
};

/** The fixtures: tron's SQLite test world, the units on their fixture sources when the tests provide them. */
$fixtureServer = function () use ( $tronDir ) {
    require_once $tronDir . '/tests/lib/testcase.php';
    require_once $tronDir . '/tests/lib/fixture.php';
    require_once $tronDir . '/tests/lib/skillfixtures.php';
    $services = array( 'skills.tronDir' => $tronDir );
    if ( is_file( $tronDir . '/tests/lib/unitfixtures.php' ) )
    {
        require_once $tronDir . '/tests/lib/unitfixtures.php';
        if ( class_exists( 'TronUnitFixtures' ) && method_exists( 'TronUnitFixtures', 'services' ) )
            $services += TronUnitFixtures::services();
    }
    $f = TronFixture::build( array( 'services' => $services, 'extensions' => array() ) );
    TronSkillFixtures::seed( $f['db'], TronFixture::NOW );
    return array( $f['server'], TronFixture::session( TronFixture::ADMIN ) );
};

$catalogStandalone = function () use ( $tronDir ) {
    return new tronSkillCatalog( array(), $tronDir, array() );
};

try
{
    switch ( $command )
    {
        case '':
        case 'help':
            $out( 'tron-skills: lint, harness, export, list, show, packs, counts, run, validate, install, export-local (see the head of this file)' );
            $finish( 2 );

        case 'lint':
            $dirs = $args ?: array_map( 'dirname', glob( $tronDir . '/share/skills/*/pack.json' ) ?: array() );
            $bad = 0;
            $skills = 0;
            foreach ( $dirs as $d )
            {
                try
                {
                    $p = tronSkillPack::read( $d, 'lint' );
                    $skills += count( $p->skills );
                    $out( sprintf( 'PASS %-28s %4d skills', $p->id, count( $p->skills ) ) );
                }
                catch ( tronSkillInvalid $e )
                {
                    $bad++;
                    $out( 'FAIL ' . $e->getMessage() );
                }
            }
            $finish( $bad ? 1 : 0, ( $bad ? 'FAIL' : 'PASS' ) . ": " . count( $dirs ) . " packs, $skills skills, $bad refused" );

        case 'harness':
            $live = (bool)$opt( 'live', false );
            if ( $live )
            {
                if ( !$opt( 'user' ) )
                    $finish( 2, 'harness --live needs --user=<login> (a test administrator, never the shared admin)' );
                list( $server, $session ) = $kernelServer( (string)$opt( 'user' ) );
                $env = tronSkillProgram::env( $server );
                $catalog = tronSkillProgram::catalog( $env );
            }
            else
            {
                list( $server, $session ) = $fixtureServer();
                $env = tronSkillProgram::env( $server );
                $catalog = tronSkillProgram::catalog( $env );
            }
            $mode = $live ? 'live' : 'fixtures';
            $packFilter = (string)$opt( 'pack', '' );
            $report = array( 'mode' => $mode, 'tron' => tronVersion::VERSION, 'packs' => array(), 'totals' => array( 'pass' => 0, 'fail' => 0, 'parked' => 0 ) );
            foreach ( $catalog->packs() as $row )
            {
                if ( $packFilter !== '' && $row['id'] !== $packFilter )
                    continue;
                if ( !$row['pack'] )
                {
                    $report['packs'][$row['id']] = array( 'state' => $row['state'], 'reason' => $row['reason'] );
                    continue;
                }
                /** @var tronSkillPack $pack */
                $pack = $row['pack'];
                $rows = array();
                $old = $pack->proof;
                foreach ( $pack->skills as $name => $m )
                {
                    $r = tronSkillValidator::prove( $server, $session, $m );
                    $prev = $old[$name] ?? array();
                    $runs = (array)( ( $prev['hash'] ?? '' ) === $m->hash() ? ( $prev['runs'] ?? array() ) : array() );
                    $runs[$mode] = $r['result'];
                    // a skill is proven when every mode that could run it passed and none failed
                    $result = in_array( 'fail', $runs, true ) ? 'fail' : ( in_array( 'pass', $runs, true ) ? 'pass' : 'parked' );
                    $rows[$name] = array( 'version' => $m->version, 'hash' => $m->hash(), 'result' => $result, 'runs' => $runs,
                                          'reason' => $r['reason'], 'waits' => $r['waits'] ?? array(),
                                          'checks' => array_intersect_key( $r['checks'], array_flip( array( 'schema', 'units', 'policy', 'output', 'redaction' ) ) ) );
                    $report['totals'][$r['result']]++;
                    $report['packs'][$pack->id][$name] = $r['result'] . ( $r['result'] !== 'pass' ? ': ' . $r['reason'] : '' );
                }
                if ( $opt( 'write-proof', false ) )
                    $pack->writeProof( $rows, tronVersion::VERSION, $env->now() );
            }
            $emit( $report, function ( $r ) use ( $out ) {
                foreach ( $r['packs'] as $id => $skills )
                {
                    if ( isset( $skills['state'] ) && is_string( $skills['state'] ) )
                    {
                        $out( "PACK $id {$skills['state']}: {$skills['reason']}" );
                        continue;
                    }
                    $c = array_count_values( array_map( function ( $v ) { return explode( ':', $v, 2 )[0]; }, $skills ) );
                    $out( sprintf( 'PACK %-28s pass %3d  fail %3d  parked %3d', $id, $c['pass'] ?? 0, $c['fail'] ?? 0, $c['parked'] ?? 0 ) );
                    foreach ( $skills as $n => $v )
                        if ( strpos( $v, 'fail' ) === 0 )
                            $out( "  FAIL $n $v" );
                }
            } );
            $t = $report['totals'];
            $finish( $t['fail'] ? 1 : 0, ( $t['fail'] ? 'FAIL' : 'PASS' ) . ": harness ($mode) pass {$t['pass']}, fail {$t['fail']}, parked {$t['parked']}" );

        case 'try':
            // any skill that keeps the standard, on the fixtures, without approval: for authors
            $name = (string)( $args[0] ?? '' );
            list( $server, $session ) = $opt( 'fixtures', false ) ? $fixtureServer() : $kernelServer( (string)$opt( 'user', '' ) );
            $all = tronSkillProgram::catalog( tronSkillProgram::env( $server ) )->skills();
            if ( !isset( $all[$name] ) )
                $finish( 1, "FAIL: no skill $name" );
            $m = $all[$name]['manifest'];
            $r = ( new tronSkillRunner( $server, $session ) )->run( $m, tronSkillPacks::paramsFromOptions( $m, array_diff_key( $opts, array( 'fixtures' => 1, 'user' => 1, 'use-siteaccess' => 1, 'json' => 1 ) ) ) );
            $out( json_encode( $json ? $r : $r['output'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
            $finish( 0, "PASS: $name on " . ( $opt( 'fixtures', false ) ? 'the fixtures' : 'this installation' ) . " in {$r['ms']} ms" );

        case 'export':
            $id = (string)( $args[0] ?? '' );
            $to = (string)$opt( 'to', '' );
            $pack = $catalogStandalone()->pack( $id );
            if ( !$pack || $to === '' )
                $finish( 2, 'export <pack id> --to=<dir|file.tar.gz> (a pack that loads)' );
            tronSkillPacks::export( $pack, $to );
            $finish( 0, "PASS: exported $id to $to" );

        case 'list':
        case 'counts':
        case 'packs':
        case 'show':
            $env = tronSkillProgram::env( tronKernel::server( null, new tronKernelAccessChecker() ) );
            if ( $command === 'packs' )
            {
                $rows = tronSkillProgram::packRows( $env );
                $emit( $rows, function ( $rows ) use ( $out ) {
                    foreach ( $rows as $r )
                        $out( sprintf( '%-28s %-13s %4d  %-12s %s %s %s', $r['id'], $r['state'], $r['skills'], $r['version'], $r['origin'],
                                       $r['provider']['version'] ?? '', $r['reason'] !== '' ? '- ' . $r['reason'] : '' ) );
                } );
                $finish( 0 );
            }
            $all = tronSkillProgram::all( $env );
            if ( $command === 'counts' )
            {
                $emit( tronSkillCatalog::counts( $all ), function ( $c ) use ( $out ) {
                    $out( 'total ' . $c['total'] . ': ' . json_encode( $c['states'] ) );
                    foreach ( $c['packs'] as $p => $s )
                        $out( sprintf( '  %-28s %s', $p, json_encode( $s ) ) );
                } );
                $finish( 0 );
            }
            if ( $command === 'show' )
            {
                $name = (string)( $args[0] ?? '' );
                if ( !isset( $all[$name] ) )
                    $finish( 1, "FAIL: no skill $name" );
                $s = $all[$name];
                $m = $s['manifest'];
                $data = tronSkillProgram::row( $s ) + array( 'reason' => $s['reason'], 'provider_info' => $s['provider'], 'proof' => $s['proof'],
                                                            'cli' => 'xsh tron:skill:run ' . $m->name . tronSkillProgram::cliExample( $m ), 'manifest' => $m->data );
                $emit( $data, function ( $d ) use ( $out, $m ) {
                    $out( "{$d['name']} {$d['version']}  [{$d['state']}] {$d['reason']}" );
                    $out( "  {$d['title']}: {$d['purpose']}" );
                    $p = (array)$d['provider_info'];
                    $out( '  pack ' . $d['pack'] . ' from ' . ( $p['name'] ?? '' ) . ' ' . ( $p['version'] ?? '' ) . ( !empty( $p['license'] ) ? ' (' . $p['license'] . ')' : '' ) );
                    $out( '  units: ' . implode( ', ', $d['units'] ) . '   tool: ' . $d['tool'] );
                    foreach ( (array)( $m->data['parameters'] ?? array() ) as $pn => $p )
                        $out( sprintf( '  %-22s %-8s %s%s', ( $p['option'] ?? '--' . str_replace( '_', '-', $pn ) ) . ( isset( $p['short'] ) ? ' ' . $p['short'] : '' ),
                                       $p['type'], $p['description'], array_key_exists( 'default', $p ) ? ' (default ' . json_encode( $p['default'] ) . ')' : '' ) );
                    $out( '  e.g. ' . $d['cli'] );
                } );
                $finish( 0 );
            }
            $hits = tronSkillCatalog::search( $all, (string)$opt( 'query', '' ), $opt( 'tag' ) ? array( (string)$opt( 'tag' ) ) : array(),
                                              (string)$opt( 'state', '' ), (string)$opt( 'pack', '' ) );
            $rows = array_values( array_map( array( 'tronSkillProgram', 'row' ), $hits ) );
            $emit( $rows, function ( $rows ) use ( $out ) {
                foreach ( $rows as $r )
                    $out( sprintf( '%-48s %-9s %-24s %s', $r['name'], $r['state'], $r['pack'], $r['title'] ) );
                $out( count( $rows ) . ' skills' );
            } );
            $finish( 0 );

        case 'run':
        case 'validate':
            $name = (string)( $args[0] ?? '' );
            if ( $name === '' || !$opt( 'user' ) )
                $finish( 2, "$command <name> --user=<login> [--<param> <value> ...]" );
            list( $server, $session ) = $kernelServer( (string)$opt( 'user' ) );
            $env = tronSkillProgram::env( $server );
            $all = tronSkillProgram::all( $env );
            if ( !isset( $all[$name] ) )
                $finish( 1, "FAIL: no skill $name" );
            $m = $all[$name]['manifest'];
            if ( $command === 'validate' )
            {
                $r = tronSkillValidator::prove( $server, $session, $m );
                $emit( $r, function ( $r ) use ( $out ) { $out( json_encode( $r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); } );
                $finish( $r['result'] === 'fail' ? 1 : 0, strtoupper( $r['result'] === 'pass' ? 'PASS' : ( $r['result'] === 'parked' ? 'PARKED' : 'FAIL' ) ) . ": $name {$r['reason']}" );
            }
            if ( $all[$name]['state'] !== 'live' )
                $finish( 1, "FAIL: $name is not live ({$all[$name]['state']}: {$all[$name]['reason']})" );
            $params = tronSkillPacks::paramsFromOptions( $m, $opts );
            $r = ( new tronSkillRunner( $server, $session ) )->run( $m, $params );
            $emit( $r, function ( $r ) use ( $out ) { $out( json_encode( $r['output'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); } );
            $finish( 0, "PASS: $name in {$r['ms']} ms, " . count( $r['trace'] ) . ' steps' );

        case 'unit':
            $tool = (string)( $args[0] ?? '' );
            if ( $tool === '' || !$opt( 'user' ) )
                $finish( 2, 'unit <tool> --user=<login> [--args=<json object>]   (try a unit while writing a skill)' );
            list( $server, $session ) = $kernelServer( (string)$opt( 'user' ) );
            $t = $server->registry()->findTool( $tool );
            if ( !$t )
                $finish( 1, "FAIL: no tool $tool here" );
            if ( $t->write )
                $finish( 1, "FAIL: $tool writes; this command runs read units only" );
            $a = json_decode( (string)$opt( 'args', '{}' ), true );
            $o = tronSkillProgram::invoke( $server, $t, is_array( $a ) ? $a : array(), $session );
            $out( json_encode( $o['ok'] ? $o['value'] : $o, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
            $finish( $o['ok'] ? 0 : 1 );

        case 'install':
            $src = (string)( $args[0] ?? '' );
            $ext = (string)$opt( 'extension', '' );
            if ( $src === '' || $ext === '' )
                $finish( 2, 'install <dir|file.tar.gz> --extension=<ext>' );
            $r = tronSkillPacks::install( $src, eZExtension::baseDirectory() . '/' . $ext, $ext );
            $finish( 0, "PASS: pack {$r['id']} installed into {$r['dir']} and registered in {$r['ini']}; approve its skills in Setup > tron > Skills" );

        case 'export-local':
            $id = (string)( $args[0] ?? '' );
            $to = (string)$opt( 'to', '' );
            if ( $id === '' || $to === '' )
                $finish( 2, 'export-local <pack id> --to=<dir>' );
            $env = tronSkillProgram::env( tronKernel::server( null, new tronKernelAccessChecker() ) );
            $n = tronSkillPacks::exportLocal( tronSkillProgram::store( $env ), $id, $to );
            $finish( 0, "PASS: $n skills of the local pack $id written to $to" );
    }
    $finish( 2, "unknown command $command (help)" );
}
catch ( tronToolError $e )
{
    $finish( 1, 'FAIL: ' . $e->getMessage() . ( $e->details() ? ' ' . json_encode( $e->details(), JSON_UNESCAPED_SLASHES ) : '' ) );
}
catch ( tronSkillInvalid $e )
{
    $finish( 1, 'FAIL: ' . $e->getMessage() );
}
