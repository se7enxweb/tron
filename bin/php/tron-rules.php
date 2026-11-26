#!/usr/bin/env php
<?php
/**
 * The agent rules layer on the command line (doc/agent-rules.md). Run from the installation root.
 *
 * Without an installation:
 *   php extension/tron/bin/php/tron-rules.php sanitize --in=<AGENTS.md> --out=<MASTER.md> [--secrets-file=<file>]
 *         write a shippable MASTER.md from a private AGENTS.md; refuses to write when check finds anything.
 *         --secrets-file: literal secrets to replace, one per line, kept outside the repository (never printed)
 *   php extension/tron/bin/php/tron-rules.php check <file> [--secrets-file=<file>]
 *         what in a file still looks private: pattern names and line numbers, never the text
 *   php extension/tron/bin/php/tron-rules.php generate [--answers=<answers.json>]
 *         a starter AGENTS.md from the questionnaire's answers (doc/agent-rules.md, "The questionnaire")
 *
 * Inside the installation (--siteaccess=<sa>):
 *   status   [--user=<login>] [--json]       which MASTER.md and AGENTS.md are active, from where, and every switch
 *   combined [--user=<login>] [--master=disabled]   the text an agent receives
 *
 * Exit codes: 0 PASS, 1 FAIL, 2 usage.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

ini_set( 'display_errors', 'stderr' );
$tronDir = dirname( __DIR__, 2 );

$command = '';
$args = array();
$opts = array();
foreach ( array_slice( $argv, 1 ) as $a )
{
    if ( strpos( $a, '--' ) === 0 )
    {
        $eq = strpos( $a, '=' );
        if ( $eq === false )
            $opts[substr( $a, 2 )] = true;
        else
            $opts[substr( $a, 2, $eq - 2 )] = substr( $a, $eq + 1 );
    }
    else if ( $command === '' )
        $command = $a;
    else
        $args[] = $a;
}
$say = function ( string $s ) { fwrite( STDERR, $s . "\n" ); };
$out = function ( string $s ) { fwrite( STDOUT, $s . "\n" ); };

$standalone = in_array( $command, array( 'sanitize', 'check', 'generate', 'help', '' ), true );
$kernel = false;
if ( $standalone )
    require_once $tronDir . '/autoload.php';
else
{
    ob_start();
    if ( !is_file( 'autoload.php' ) && is_file( dirname( __DIR__, 4 ) . '/autoload.php' ) )
        chdir( dirname( __DIR__, 4 ) );
    require_once $tronDir . '/autoload.php';
    require_once 'autoload.php';
    $script = eZScript::instance( array( 'description' => 'tron rules', 'use-session' => false, 'use-modules' => true,
                                         'use-extensions' => true, 'debug-output' => false ) );
    $script->startup();
    if ( isset( $opts['siteaccess'] ) )
        $script->setUseSiteAccess( (string)$opts['siteaccess'] );
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

$sanitizer = function () use ( $opts, $finish ): tronRulesSanitizer {
    $literals = array();
    if ( isset( $opts['secrets-file'] ) )
    {
        try
        {
            $literals = tronRulesSanitizer::literalsFromFile( (string)$opts['secrets-file'] );
        }
        catch ( RuntimeException $e )
        {
            $finish( 2, 'FAIL: ' . $e->getMessage() );
        }
    }
    return new tronRulesSanitizer( $literals );
};
$report = function ( array $leaks ) use ( $say ) {
    $by = array();
    foreach ( $leaks as $l )
        $by[$l['pattern']][] = $l['line'];
    foreach ( $by as $pattern => $lines )
        $say( "  $pattern: line " . implode( ', ', array_slice( $lines, 0, 20 ) ) . ( count( $lines ) > 20 ? ' ...' : '' ) );
};

switch ( $command )
{
    case 'sanitize':
        $in = (string)( $opts['in'] ?? '' );
        $to = (string)( $opts['out'] ?? '' );
        if ( $in === '' || $to === '' || !is_file( $in ) )
            $finish( 2, 'usage: tron-rules.php sanitize --in=<AGENTS.md> --out=<MASTER.md> [--secrets-file=<file>]' );
        $s = $sanitizer();
        $text = $s->sanitize( (string)file_get_contents( $in ) );
        $leaks = $s->leaks( $text );
        if ( $leaks )
        {
            $say( 'FAIL: the sanitized text still has ' . count( $leaks ) . ' private match(es); nothing was written:' );
            $report( $leaks );
            $finish( 1 );
        }
        if ( file_put_contents( $to, $text ) !== strlen( $text ) )
            $finish( 1, "FAIL: $to could not be written" );
        $out( 'PASS: wrote ' . $to . ' (' . strlen( $text ) . ' bytes, ' . substr_count( $text, "\n" ) . ' lines, '
              . preg_match_all( '/^## /m', $text ) . ' sections, sha256 ' . substr( hash( 'sha256', $text ), 0, 12 ) . '); check found nothing private' );
        $finish( 0 );

    case 'check':
        $file = $args[0] ?? '';
        if ( $file === '' || !is_file( $file ) )
            $finish( 2, 'usage: tron-rules.php check <file> [--secrets-file=<file>]' );
        $leaks = $sanitizer()->leaks( (string)file_get_contents( $file ) );
        if ( $leaks )
        {
            $say( "FAIL: $file has " . count( $leaks ) . ' private match(es):' );
            $report( $leaks );
            $finish( 1 );
        }
        $out( "PASS: $file has nothing private by tron's patterns" );
        $finish( 0 );

    case 'generate':
        $answers = array();
        if ( isset( $opts['answers'] ) )
        {
            $answers = json_decode( (string)@file_get_contents( (string)$opts['answers'] ), true );
            if ( !is_array( $answers ) )
                $finish( 2, 'FAIL: --answers must name a JSON file with an object of answers' );
        }
        fwrite( STDOUT, tronRulesGenerator::generate( $answers ) );
        $finish( 0 );

    case 'status':
    case 'combined':
        $userId = null;
        if ( isset( $opts['user'] ) )
        {
            $u = ( new tronKernelUserDirectory() )->byLogin( (string)$opts['user'] );
            if ( !$u )
                $finish( 1, "FAIL: no user '{$opts['user']}'" );
            $userId = (int)$u['id'];
        }
        $session = isset( $opts['master'] ) ? array( 'master' => (string)$opts['master'] ) : array();
        $rules = tronRules::fromEnvironment( new tronEnvironment( tronKernel::settings() ) );
        if ( $command === 'combined' )
        {
            fwrite( STDOUT, $rules->combined( $userId, $session ) );
            $finish( 0 );
        }
        $st = $rules->state( $userId, $session, true );
        if ( isset( $opts['json'] ) )
            $out( json_encode( $st, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        else
        {
            $out( 'MASTER.md: ' . $st['master']['source'] . ' (decided by ' . $st['master']['decided_by'] . ') ' . $st['master']['path'] );
            $out( 'AGENTS.md: ' . $st['agents']['source'] . ' (strength ' . $st['agents']['strength'] . ') ' . (string)$st['agents']['path'] );
            foreach ( $st['switches'] as $r )
                $out( sprintf( '  %-12s %-9s %s%s', $r['name'], $r['value'] !== '' ? $r['value'] : '-', $r['label'], $r['decides'] ? '   <- decides' : '' ) );
        }
        $finish( 0 );

    default:
        $finish( 2, 'usage: tron-rules.php sanitize|check|generate|status|combined (see the head of this file)' );
}
