<?php
/**
 * Example 7: a program of your own. examples/hello is laid out like an extension (tron/program.json + its class);
 * tron finds it by convention, loads it in isolation and offers its tool, resource and prompt.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

require __DIR__ . '/../bootstrap.php';

// In an installation: extension/hello/... and ActiveExtensions[]=hello; here the active extensions are given directly.
$w = TronFixture::build( array( 'extensions' => array( 'hello' => dirname( __DIR__ ) . '/hello' ) ) );
foreach ( $w['server']->option( 'programs' ) as $p )
    echo "program {$p['name']} from {$p['origin']}: {$p['state']}\n";

$s = TronFixture::session( TronFixture::EDITOR );
echo json_encode( example_tool( $w['server'], $s, 'hello_greet' ) ) . "\n";
echo json_encode( example_tool( $w['server'], $s, 'hello_greet', array( 'name' => 'Ada', 'times' => 2 ) ) ) . "\n";
echo json_encode( example_tool( $w['server'], $s, 'hello_greet', array( 'times' => 9 ) ) ) . "\n";
echo json_encode( example_tool( $w['server'], $s, 'hello_remember', array( 'name' => 'plan', 'text' => 'Ship the preview.' ) ) ) . "\n";
echo json_encode( example_tool( $w['server'], $s, 'hello_remember', array( 'name' => 'secret', 'text' => 'x' ) ) ) . "\n";
echo json_encode( example_tool( $w['server'], TronFixture::session( TronFixture::VIEWER ), 'hello_remember', array( 'name' => 'plan', 'text' => 'x' ) ) ) . "\n";

$note = example_rpc( $w['server'], $s, 'resources/read', array( 'uri' => 'hello://note/plan' ) );
echo 'resource: ' . $note['result']['contents'][0]['text'] . "\n";
$missing = example_rpc( $w['server'], $s, 'resources/read', array( 'uri' => 'hello://note/nothing' ) );
echo 'missing resource: ' . $missing['error']['code'] . ' ' . $missing['error']['message'] . "\n";
$prompt = example_rpc( $w['server'], $s, 'prompts/get', array( 'name' => 'hello_introduce', 'arguments' => array( 'audience' => 'the ops team' ) ) );
echo 'prompt: ' . $prompt['result']['messages'][0]['content']['text'] . "\n";
