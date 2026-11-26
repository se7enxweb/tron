<?php
/**
 * Example 1: the first call. Start a server, initialize a session, list the tools, ask who you are.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

require __DIR__ . '/../bootstrap.php';

$world = TronFixture::build();                                   // in an installation: tronKernel::server()
$session = new tronSession( new tronPrincipal( TronFixture::EDITOR, 'editor' ), 'stdio', true );

$init = example_rpc( $world['server'], $session, 'initialize', array(
    'protocolVersion' => '2025-11-25', 'capabilities' => new stdClass(), 'clientInfo' => array( 'name' => 'cookbook', 'version' => '1' ) ) );
echo 'protocol: ' . $init['result']['protocolVersion'] . "\n";
echo 'server:   ' . $init['result']['serverInfo']['name'] . ' ' . $init['result']['serverInfo']['version'] . "\n";

$tools = example_rpc( $world['server'], $session, 'tools/list' );
echo 'tools:    ' . count( $tools['result']['tools'] ) . " (what the editor's role allows)\n";

$me = example_tool( $world['server'], $session, 'tron_whoami' );
echo 'whoami:   ' . $me['login'] . ' (user ' . $me['user_id'] . ') over ' . $me['transport'] . "\n";
