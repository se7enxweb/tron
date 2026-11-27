#!/usr/bin/env php
<?php
/**
 * tron on the command line. Run from the installation root:
 *
 *   TRON_TOKEN=<token> php extension/tron/bin/php/tron.php serve --siteaccess=<sa>   (or --token-file=<f>)
 *   php extension/tron/bin/php/tron.php install     --siteaccess=<sa> [--dry-run]     create the missing tables
 *   php extension/tron/bin/php/tron.php status      --siteaccess=<sa>                 programs, tools, tables
 *   TRON_TOKEN=<token> php extension/tron/bin/php/tron.php token:create --siteaccess=<sa> --user=<login> --name=<what for> [--days=90] [--scopes=centre,katalyst] [--out=<file>]
 *   php extension/tron/bin/php/tron.php token:list  --siteaccess=<sa> [--user=<login>]
 *   php extension/tron/bin/php/tron.php token:revoke --siteaccess=<sa> <id|prefix>
 *   php extension/tron/bin/php/tron.php audit:tail  --siteaccess=<sa> [--limit=50] [--user=<login>]
 *   TRON_TOKEN=<token> php extension/tron/bin/php/tron.php call <tool> --siteaccess=<sa> [--json='{"a":1}']
 *
 * serve and call act as the token's user (TRON_TOKEN or --token-file). token:create issues as the token's user: a
 * token for themselves within their own scopes, for another user with tron/admin; the first token of an installation
 * comes from Setup > tron > Tokens. Without a token, --user=<login> (serve, call) and token:create are refused unless
 * an override sets [StdioSettings] AllowLoginOption=enabled (see doc/security.md).
 *
 * Exit codes: 0 PASS, 1 FAIL, 2 usage.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

// stdout belongs to the protocol in "serve": PHP's own warnings go to stderr, the kernel's output is captured.
ini_set( 'display_errors', 'stderr' );
ob_start();

// Started from elsewhere (an MCP client config with an absolute path): work from the installation root, which is
// four levels up from extension/tron/bin/php.
if ( !is_file( 'autoload.php' ) && is_file( dirname( __DIR__, 4 ) . '/autoload.php' ) )
    chdir( dirname( __DIR__, 4 ) );
require_once 'autoload.php';
require_once dirname( __DIR__, 2 ) . '/autoload.php';

$cli = eZCLI::instance();
$script = eZScript::instance( array( 'description' => 'tron, the MCP server layer of Exponential',
                                     'use-session' => false, 'use-modules' => true, 'use-extensions' => true,
                                     'debug-output' => false ) );
$script->startup();
$options = $script->getOptions( '[user:][token-file:][name:][days:][scopes:][limit:][json:][out:][dry-run]', '[command][argument]', array(), false, true );
$script->initialize();
$script->setUseDebugOutput( false );

$err = fopen( 'php://stderr', 'w' );
$say = function ( string $s ) use ( $err ) { fwrite( $err, $s . "\n" ); };
$out = function ( string $s ) { fwrite( STDOUT, $s . "\n" ); };
$finish = function ( int $code, string $line = '' ) use ( $script, $say ) {
    if ( $line !== '' )
        $say( $line );
    while ( ob_get_level() > 0 )
        ob_end_clean();
    ob_start();
    $script->shutdown( $code );
    exit( $code );
};

$command = (string)( $options['arguments'][0] ?? '' );
$argument = (string)( $options['arguments'][1] ?? '' );
$settings = tronKernel::settings();
// the token a command acts with: TRON_TOKEN, else the content of --token-file
$commandToken = function () use ( $options ): string {
    $token = getenv( 'TRON_TOKEN' ) ?: '';
    if ( $token === '' && $options['token-file'] )
        $token = trim( (string)@file_get_contents( $options['token-file'] ) );
    return $token;
};

try
{
    switch ( $command )
    {
        case 'serve':
            while ( ob_get_level() > 0 )
                ob_end_clean();
            $token = $commandToken();
            // a token decides; --user only where an override enables [StdioSettings] AllowLoginOption
            list( $principal, $code, $line ) = tronStdioIdentity::resolve( $settings, $token, (string)$options['user'],
                                                                           tronKernel::tokens(), new tronKernelUserDirectory() );
            if ( !$principal )
                $finish( $code, $line );

            $access = new tronKernelAccessChecker();
            if ( $access->check( $principal, 'tron', 'use' ) !== tronAccessChecker::YES )
                $finish( 1, "FAIL: tron: the user {$principal->login} does not hold the policy tron/use" );
            $missing = tronSchema::missing( tronKernel::db() );
            if ( $missing )
                $finish( 1, 'FAIL: tron: missing tables ' . implode( ', ', $missing ) . ' (run tron.php install)' );
            $server = tronKernel::server( function ( $level, $message ) use ( $say ) { $say( "tron $level: $message" ); }, $access );
            $session = new tronSession( $principal, 'stdio', true, null, 'stdio' );
            $say( 'tron ' . tronVersion::VERSION . " serving stdio as {$principal->login}, " . count( $server->visibleTools( $session ) ) . ' tools' );
            $code = ( new tronStdioTransport( $server, $session, STDIN, STDOUT ) )->run();
            if ( $session->journal instanceof tronJournalHandle )
                $session->journal->end( 'the client closed stdin' );      // the activity journal closes the session
            ob_start();
            $finish( $code );
            break;

        case 'install':
            $db = tronKernel::db();
            if ( !tronSchema::supports( $db->driver() ) )
                $finish( 1, 'FAIL: tron has no schema for the engine ' . $db->driver() . ' yet' );
            if ( $options['dry-run'] )
            {
                foreach ( tronSchema::missing( $db ) as $t )
                    $out( implode( ";\n", tronSchema::createStatements( $t, $db->driver() ) ) . ";\n" );
                $finish( 0, 'PASS: dry run, nothing changed' );
            }
            foreach ( tronSchema::install( $db ) as $t => $state )
                $out( sprintf( '%-22s %s', $t, $state ) );
            $finish( 0, 'PASS: tron tables present' );
            break;

        case 'status':
            $server = tronKernel::server( null, new tronRoleAccessChecker( array( 'all' => array( '*' . '/*' ) ), array( 0 => array( 'all' ) ) ) );
            $out( 'tron ' . tronVersion::VERSION . ', protocol layer ' . $server->protocol()->name() . ' (' . implode( ' ', $server->protocol()->versions() ) . ')' );
            foreach ( $server->option( 'programs' ) as $row )
                $out( sprintf( '  %-10s %-12s %-8s %-10s %6.2f ms %s', $row['name'], $row['origin'], $row['version'], $row['state'], $row['ms'], $row['reason'] ) );
            $out( '  tools: ' . count( $server->registry()->tools() ) . ', resources: ' . count( $server->registry()->resources() ) . ', prompts: ' . count( $server->registry()->prompts() ) );
            foreach ( $server->registry()->unavailableTools() as $n => $why )
                $out( "  not registered: $n ($why)" );
            $missing = tronSchema::missing( tronKernel::db() );
            $out( '  tables: ' . ( $missing ? 'missing ' . implode( ', ', $missing ) : 'all present' ) );
            $failed = array_filter( $server->option( 'programs' ), function ( $r ) { return $r['state'] === 'failed'; } );
            $finish( $missing || $failed ? 1 : 0, $missing || $failed ? 'FAIL' : 'PASS' );
            break;

        case 'token:create':
            if ( !$options['user'] || !$options['name'] )
                $finish( 2, 'token:create needs --user=<login> and --name=<what it is for>' );
            $u = ( new tronKernelUserDirectory() )->byLogin( (string)$options['user'] );
            if ( !$u )
                $finish( 1, "FAIL: no user '{$options['user']}'" );
            // Who issues it: the token given (TRON_TOKEN, --token-file); without one only where an override enables
            // [StdioSettings] AllowLoginOption, since then anyone who runs this could mint a token for any user.
            $issuer = null;
            $token = $commandToken();
            if ( $token !== '' || !tronStdioIdentity::loginAllowed( $settings ) )
            {
                if ( $token === '' )
                    $finish( 1, 'FAIL: tron: token:create without a token is disabled ([StdioSettings] AllowLoginOption=disabled, the default); '
                              . 'create the first token in Setup > tron > Tokens, then give it: TRON_TOKEN=<token> or --token-file=<file> '
                              . '(another user\'s token needs tron/admin)' );
                $reason = null;
                $issuer = tronKernel::tokens()->authenticate( $token, $reason );
                if ( !$issuer )
                    $finish( 1, "FAIL: tron: the token was refused ($reason)" );
            }
            $t = $settings['TokenSettings'] ?? array();
            $days = $options['days'] ? (int)$options['days'] : (int)( $t['DefaultLifetimeDays'] ?? 90 );
            $max = (int)( $t['MaxLifetimeDays'] ?? 365 );
            if ( $days > $max )
                $finish( 1, "FAIL: at most $max days ([TokenSettings] MaxLifetimeDays)" );
            $scopes = $options['scopes'] ? explode( ',', (string)$options['scopes'] ) : ( $issuer ? $issuer->scopes : array( '*' ) );
            $why = tronStdioIdentity::mayIssue( $issuer, (int)$u['id'], $scopes, new tronKernelAccessChecker() );
            if ( $why !== '' )
                $finish( 1, "FAIL: tron: $why" );
            $r = tronKernel::tokens()->create( $u['id'], (string)$options['name'], $days, $scopes, $issuer ? $issuer->userId : 0 );
            if ( $options['out'] )
            {
                $old = umask( 0077 );
                $ok = file_put_contents( $options['out'], $r['token'] . "\n" ) !== false;
                umask( $old );
                @chmod( $options['out'], 0600 );
                if ( !$ok )
                    $finish( 1, "FAIL: cannot write {$options['out']}" );
                $say( "token {$r['prefix']} (id {$r['id']}) for {$u['login']} written to {$options['out']} (0600)" );
            }
            else
            {
                $out( $r['token'] );
                $say( "token {$r['prefix']} (id {$r['id']}) for {$u['login']}: shown once, keep it in a 0600 file" );
            }
            $finish( 0, 'PASS: expires ' . gmdate( 'Y-m-d', $r['expires'] ) . ', scopes ' . implode( ',', $r['scopes'] ) );
            break;

        case 'token:list':
            $uid = null;
            if ( $options['user'] )
            {
                $u = ( new tronKernelUserDirectory() )->byLogin( (string)$options['user'] );
                if ( !$u )
                    $finish( 1, "FAIL: no user '{$options['user']}'" );
                $uid = $u['id'];
            }
            foreach ( tronKernel::tokens()->listTokens( $uid ) as $r )
                $out( sprintf( '%5d %-12s %-16s %-8s %-30s expires %s last used %s scopes %s', $r['id'], $r['token_prefix'], $r['login'], $r['state'],
                               $r['name'], gmdate( 'Y-m-d', (int)$r['expires'] ), (int)$r['last_used'] ? gmdate( 'Y-m-d H:i', (int)$r['last_used'] ) : 'never', $r['scopes'] ) );
            $finish( 0 );
            break;

        case 'token:revoke':
            if ( $argument === '' )
                $finish( 2, 'token:revoke needs the token id or prefix' );
            $ok = tronKernel::tokens()->revoke( $argument );
            $finish( $ok ? 0 : 1, $ok ? "PASS: token $argument revoked" : "FAIL: no active token $argument" );
            break;

        case 'audit:tail':
            $uid = null;
            if ( $options['user'] )
            {
                $u = ( new tronKernelUserDirectory() )->byLogin( (string)$options['user'] );
                $uid = $u ? $u['id'] : -1;
            }
            $rows = ( new tronAuditDb( tronKernel::db() ) )->recent( $options['limit'] ? (int)$options['limit'] : 50, 0, $uid );
            foreach ( array_reverse( $rows ) as $r )
                $out( sprintf( '%s %-12s %-6s %-15s %-32s %-16s %5d ms', gmdate( 'Y-m-d H:i:s', (int)$r['created'] ), $r['login'], $r['transport'],
                               $r['method'], $r['target'], $r['outcome'], $r['duration_ms'] ) );
            $finish( 0 );
            break;

        case 'call':
            if ( $argument === '' )
                $finish( 2, 'call needs a tool name and a token (TRON_TOKEN or --token-file)' );
            // the same identity rule as serve: a token; --user only where an override enables AllowLoginOption
            list( $principal, $code, $line ) = tronStdioIdentity::resolve( $settings, $commandToken(), (string)$options['user'],
                                                                           tronKernel::tokens(), new tronKernelUserDirectory(), 'call' );
            if ( !$principal )
                $finish( $code, $line );
            $access = new tronKernelAccessChecker();
            if ( $access->check( $principal, 'tron', 'use' ) !== tronAccessChecker::YES )
                $finish( 1, "FAIL: tron: the user {$principal->login} does not hold the policy tron/use" );
            $args = $options['json'] ? json_decode( (string)$options['json'], true ) : array();
            if ( !is_array( $args ) )
                $finish( 2, '--json must be a JSON object' );
            $server = tronKernel::server( function ( $level, $message ) use ( $say ) { $say( "tron $level: $message" ); }, $access );
            $session = new tronSession( $principal, 'cli', false, tronProtocol::LATEST, 'cli' );
            $answer = $server->call( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array( 'name' => $argument, 'arguments' => $args ) ), $session );
            if ( $session->journal instanceof tronJournalHandle )
                $session->journal->end( 'one call from the command line' );
            $out( tronServer::encode( $answer ) );
            $isError = isset( $answer['error'] ) || !empty( $answer['result']['isError'] );
            $finish( $isError ? 1 : 0, $isError ? 'FAIL' : 'PASS' );
            break;

        default:
            $finish( 2, 'usage: tron.php serve|install|status|token:create|token:list|token:revoke|audit:tail|call (see the file header)' );
    }
}
catch ( Throwable $e )
{
    $finish( 1, 'FAIL: tron: ' . tronRedactor::maskTokens( get_class( $e ) . ': ' . $e->getMessage() ) );
}
