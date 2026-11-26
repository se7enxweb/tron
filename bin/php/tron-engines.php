#!/usr/bin/env php
<?php
/**
 * The MCP engines of this installation. Run from the installation root:
 *
 *   php extension/tron/bin/php/tron-engines.php --siteaccess=<sa>                 the registered engines and their tools
 *   php extension/tron/bin/php/tron-engines.php --siteaccess=<sa> --scan          also the interface scan of the autoload array
 *   php extension/tron/bin/php/tron-engines.php --siteaccess=<sa> --user=<login>  the prefixed tools that user may use
 *
 * The scan lists every class that implements tronMcpEngine or tronMcpBridgeApi, or has the bridge API's methods,
 * and the [McpEngine_<id>] group that registers it ("not registered" when none does). To register one, add the group
 * to the extension's settings/tron.ini.append.php (doc/mcp-engines.md).
 *
 * Exit codes: 0 PASS, 1 FAIL (an engine failed to load or conflicts), 2 usage.
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
$script = eZScript::instance( array( 'description' => 'The MCP engines of tron',
                                     'use-session' => false, 'use-modules' => true, 'use-extensions' => true ) );
$script->startup();
$options = $script->getOptions( '[scan][user:]', '', array( 'scan' => 'also list what the interface scan finds',
                                                            'user' => 'list the prefixed tools this user may use' ) );
$script->initialize();

$all = new tronRoleAccessChecker( array( 'all' => array( '*' . '/*' ) ), array( 0 => array( 'all' ) ) );
$server = tronKernel::server( null, $all );
$engines = (array)$server->option( 'engines' );
$bad = 0;
$cli->output( 'tron ' . tronVersion::VERSION . ': engines' );
$cli->output( sprintf( '  %-20s %-10s %-9s %s', 'tron', 'loaded', '', 'tron itself (its programs, tron__<tool>)' ) );
foreach ( (array)$server->option( 'programs' ) as $row )
{
    if ( strpos( (string)$row['origin'], 'engine:' ) !== 0 )
        continue;
    if ( in_array( $row['state'], array( 'failed', 'conflict' ), true ) )
        $bad++;
    $tools = 0;
    foreach ( $server->registry()->tools() as $t )
        if ( $t->program === $row['name'] )
            $tools++;
    $cli->output( sprintf( '  %-20s %-10s %-9s %s%s', $row['name'], $row['state'], $tools . ' tools', substr( (string)$row['origin'], 7 ),
                           $row['reason'] !== '' ? ' (' . $row['reason'] . ')' : '' ) );
}
foreach ( $server->registry()->unavailableTools() as $name => $why )
{
    if ( isset( $engines[tronMcpEngineHub::engineOf( $name )] ) || strpos( $why, 'McpEngine_' ) !== false )
        $cli->output( "  not registered: $name ($why)" );
}

if ( $options['scan'] )
{
    $cli->output( 'interface scan (the extension autoload array):' );
    $rows = tronKernel::scanEngines();
    if ( !$rows )
        $cli->output( '  no class found' );
    foreach ( $rows as $row )
        $cli->output( sprintf( '  %-32s %-7s %-22s %s', $row['class'], $row['kind'], $row['id'] !== '' ? '[McpEngine_' . $row['id'] . ']' : 'not registered', $row['file'] ) );
}

if ( $options['user'] )
{
    $u = ( new tronKernelUserDirectory() )->byLogin( (string)$options['user'] );
    if ( !$u )
    {
        $cli->error( "FAIL: no user '{$options['user']}'" );
        $script->shutdown( 1 );
    }
    $settings = tronKernel::settings();
    $hub = new tronMcpEngineHub( new tronMcpTronEngine( tronKernel::server( null, null, true ), tronKernel::audit( $settings ), 'cli' ) );
    $tools = $hub->tools( new tronPrincipal( (int)$u['id'], (string)$u['login'] ) );
    $cli->output( "tools of {$u['login']}: " . count( $tools ) );
    foreach ( $tools as $t )
        $cli->output( sprintf( '  %-48s %s', $t['name'], $t['write'] ? 'write' : 'read' ) );
}

$cli->output( $bad ? "FAIL: $bad engine(s) failed or conflict" : 'PASS' );
$script->shutdown( $bad ? 1 : 0 );
