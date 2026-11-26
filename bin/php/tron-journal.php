#!/usr/bin/env php
<?php
/**
 * The activity journal on the command line. Run from the installation root:
 *
 *   php extension/tron/bin/php/tron-journal.php list   --siteaccess=<sa> [--user=<login>] [--from=YYYY-MM-DD] [--to=...] [--tool=<t>] [--outcome=<o>] [--object=node:123] [--kind=mcp|job] [--limit=20]
 *   php extension/tron/bin/php/tron-journal.php show   <id> --siteaccess=<sa> [--format=narrative|markdown|json]
 *   php extension/tron/bin/php/tron-journal.php search --siteaccess=<sa> [the filters of list] [--text=<words>]
 *   php extension/tron/bin/php/tron-journal.php verify [<id>] --siteaccess=<sa>                  the hash chain of one or of every session
 *   php extension/tron/bin/php/tron-journal.php rotate --siteaccess=<sa> [--dry-run]             retention ([JournalSettings] RetentionDays)
 *   php extension/tron/bin/php/tron-journal.php job:run --siteaccess=<sa> --name=<what> --command=<shell command> [--user=<login>] [--parent=<session id>]
 *
 * job:run runs the command as a background job with a journal of its own: every output line that starts with
 * "progress: <n>% <text>" or "checkpoint: <label>" becomes a step, the last lines and the exit code its result, so
 * "what did it do in the background" can be read in Setup > tron > Activity afterwards. Its exit code is the command's.
 *
 * Exit codes: 0 PASS, 1 FAIL (a broken chain, a failed job), 2 usage.
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
$script = eZScript::instance( array( 'description' => 'The activity journal of tron',
                                     'use-session' => false, 'use-modules' => true, 'use-extensions' => true ) );
$script->startup();
$options = $script->getOptions( '[user:][from:][to:][tool:][outcome:][object:][kind:][text:][limit:][format:][name:][command:][parent:][dry-run]',
                                '[command][argument]', array(), false, true );
$script->initialize();

$finish = function ( int $code, string $line = '' ) use ( $script, $cli ) {
    if ( $line !== '' )
        $cli->output( $line );
    $script->shutdown( $code );
    exit( $code );
};

$settings = tronKernel::settings();
$db = tronKernel::db();
if ( !$db->tableExists( 'tron_journal_step' ) )
    $finish( 1, 'FAIL: the journal tables are missing (run tron.php install)' );
$journal = new tronJournal( $db, (array)( $settings['JournalSettings'] ?? array() ) );
$command = (string)( $options['arguments'][0] ?? '' );
$argument = (string)( $options['arguments'][1] ?? '' );
$filters = array();
foreach ( array( 'user', 'from', 'to', 'tool', 'outcome', 'object', 'kind', 'text' ) as $k )
    if ( $options[$k] )
        $filters[$k] = (string)$options[$k];
$limit = $options['limit'] ? max( 1, (int)$options['limit'] ) : 20;
$all = new tronRoleAccessChecker( array( 'all' => array( '*' . '/*' ) ), array( 0 => array( 'all' ) ) );

try
{
    switch ( $command )
    {
        case 'list':
            $res = $journal->sessions( $filters, $limit );
            foreach ( $res['rows'] as $s )
                $cli->output( sprintf( '%6d %s %-4s %-14s %-6s %-22s %4d steps %2d w %2d r %2d e  %s', $s['id'], date( 'Y-m-d H:i', $s['started'] ), $s['kind'],
                                       $s['login'], $s['transport'], trim( $s['client_name'] . ' ' . $s['client_version'] ), $s['steps'], $s['writes'], $s['refused'],
                                       $s['errors'], $s['title'] ) );
            $finish( 0, "PASS: {$res['total']} session(s)" );
            break;

        case 'show':
            $s = $journal->session( (int)$argument );
            if ( !$s )
                $finish( 2, 'show needs the id of a session (tron-journal.php list)' );
            $titles = tronJournalNarrative::titlesOf( tronKernel::server( null, $all ) );
            $format = in_array( $options['format'], array( 'markdown', 'json' ), true ) ? (string)$options['format'] : 'narrative';
            $verify = $journal->verify( $s['id'] );
            $cli->output( ( new tronJournalNarrative( null, $titles ) )->render( $format, $s, $journal->steps( $s['id'] ), $verify ) );
            $finish( $verify['ok'] ? 0 : 1 );
            break;

        case 'search':
            $res = $journal->search( $filters, $limit );
            foreach ( $res['rows'] as $r )
                $cli->output( sprintf( '%s s%-5d #%-3d %-12s %-10s %-32s %-8s %s', date( 'Y-m-d H:i:s', $r['created'] ), $r['session_id'], $r['seq'], $r['login'],
                                       $r['kind'], $r['tool'], $r['outcome'], ( new tronJournalNarrative() )->objectsText( $r['objects_list'] ) ) );
            $finish( 0, "PASS: {$res['total']} step(s)" );
            break;

        case 'verify':
            $ids = $argument !== '' ? array( (int)$argument ) : array_column( $db->select( 'SELECT id FROM tron_journal_session ORDER BY id' ), 'id' );
            $bad = 0;
            foreach ( $ids as $id )
            {
                $v = $journal->verify( (int)$id );
                if ( !$v['ok'] )
                {
                    $bad++;
                    $cli->output( "session $id: BROKEN at step {$v['broken_at']}: {$v['reason']}" );
                }
            }
            $finish( $bad ? 1 : 0, ( $bad ? 'FAIL' : 'PASS' ) . ': ' . count( $ids ) . " session(s) checked, $bad broken" );
            break;

        case 'rotate':
            $r = $journal->rotate( (bool)$options['dry-run'] );
            $finish( 0, sprintf( 'PASS: %s %d session(s), %d step(s)%s', $options['dry-run'] ? 'would remove' : 'removed', $r['sessions'], $r['steps'],
                                 $r['archive'] !== '' ? ', archived in ' . $r['archive'] : '' ) );
            break;

        case 'job:run':
            if ( !$options['name'] || !$options['command'] )
                $finish( 2, 'job:run needs --name=<what it does> and --command=<shell command>' );
            $meta = array( 'transport' => 'job', 'client_name' => 'tron-journal.php job:run' );
            if ( $options['user'] )
            {
                $u = ( new tronKernelUserDirectory() )->byLogin( (string)$options['user'] );
                if ( !$u )
                    $finish( 1, "FAIL: no user '{$options['user']}'" );
                $meta += array( 'user_id' => $u['id'], 'login' => $u['login'] );
            }
            else
                $meta['login'] = function_exists( 'posix_getpwuid' ) ? (string)( posix_getpwuid( posix_geteuid() )['name'] ?? '' ) : '';
            $job = $journal->job( (string)$options['name'], $meta, (int)$options['parent'] );
            $job->note( 'command: ' . (string)$options['command'] );
            $cli->output( "journal session {$job->id()}: " . (string)$options['name'] );
            $proc = proc_open( (string)$options['command'], array( 1 => array( 'pipe', 'w' ), 2 => array( 'redirect', 1 ) ), $pipes );
            if ( !is_resource( $proc ) )
            {
                $job->fail( 'the command could not be started' );
                $finish( 1, 'FAIL: the command could not be started' );
            }
            $tail = array();
            while ( ( $line = fgets( $pipes[1] ) ) !== false )
            {
                fwrite( STDOUT, $line );
                $line = rtrim( $line, "\r\n" );
                $tail[] = $line;
                if ( count( $tail ) > 5 )
                    array_shift( $tail );
                if ( preg_match( '/^progress:\s*(\d{1,3})\s*%?\s*(.*)$/i', $line, $m ) )
                    $job->progress( (int)$m[1], trim( $m[2] ) );
                else if ( preg_match( '/^checkpoint:\s*(.+)$/i', $line, $m ) )
                    $job->checkpoint( trim( $m[1] ) );
            }
            fclose( $pipes[1] );
            $exit = proc_close( $proc );
            $job->finish( $exit === 0 ? 'ok' : 'error', 'exit code ' . $exit . ( $tail ? '; last output: ' . implode( ' / ', $tail ) : '' ), array( 'exit' => $exit ) );
            $finish( $exit === 0 ? 0 : 1, ( $exit === 0 ? 'PASS' : 'FAIL' ) . ": job journal session {$job->id()}, exit code $exit" );
            break;

        default:
            $finish( 2, 'usage: tron-journal.php list|show|search|verify|rotate|job:run (see the file header)' );
    }
}
catch ( Throwable $e )
{
    $finish( 1, 'FAIL: ' . tronRedactor::maskTokens( get_class( $e ) . ': ' . $e->getMessage() ) );
}
