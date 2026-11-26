<?php
/**
 * The policy simulator proof on a real installation (doc/policy-simulator.md): user x tool cases answered by
 * tron_policy_check (as an administrator, in process) and compared with the live call of each user over HTTP with a
 * token of that user.
 *
 *   TRON_PROOF_CASES=<json file> TRON_PROOF_URL=https://<host>/tron/mcp [TRON_PROOF_RESOLVE=<host>:443:<address>] \
 *       php bin/php/ezexec.php <root>/extension/tron/tests/kernel/policy_simulator_live.php --allow-root-user
 *
 * The cases file: {"admin": "<login>", "cases": [ {"user": "<login>", "tool": "<tool>", "args": {...}, "target": "<id>"} ]}.
 * Give args that cannot change anything when the call is allowed (a write tool without its required arguments stops at
 * the argument check, after the policy check; a content tool on a node the user may not edit is refused by the object
 * check). Live is "allowed" for HTTP 200 without a refusal, "denied" for HTTP 403 or a "Permission denied" answer.
 * A token per user is made for the run and revoked at its end; no token is printed.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

$fails = 0;
$passes = 0;
$cfg = json_decode( (string)@file_get_contents( (string)getenv( 'TRON_PROOF_CASES' ) ), true );
$url = (string)getenv( 'TRON_PROOF_URL' );
if ( !is_array( $cfg ) || $url === '' || !function_exists( 'curl_init' ) )
{
    echo "FAIL: set TRON_PROOF_CASES (a JSON file) and TRON_PROOF_URL; needs ext-curl\n";
    return 1;
}
$users = new tronKernelUserDirectory();
$admin = $users->byLogin( (string)$cfg['admin'] );
$server = tronKernel::server();
$adminSession = new tronSession( new tronPrincipal( (int)$admin['id'], $admin['login'] ), 'stdio', false, '2025-11-25', 'proof' );
$tokens = tronKernel::tokens();
$made = array();

$http = function ( string $token, string $tool, array $args ) use ( $url ) {
    $body = json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array( 'name' => $tool, 'arguments' => (object)$args ) ) );
    $c = curl_init( $url );
    $opt = array( CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
                  CURLOPT_HTTPHEADER => array( 'Content-Type: application/json', 'Accept: application/json, text/event-stream',
                                               'MCP-Protocol-Version: 2025-11-25', 'Authorization: Bearer ' . $token ) );
    if ( getenv( 'TRON_PROOF_RESOLVE' ) )
        $opt[CURLOPT_RESOLVE] = array( (string)getenv( 'TRON_PROOF_RESOLVE' ) );
    curl_setopt_array( $c, $opt );
    $out = (string)curl_exec( $c );
    $status = (int)curl_getinfo( $c, CURLINFO_RESPONSE_CODE );
    curl_close( $c );
    return array( $status, $out );
};

printf( "%-3s %-16s %-24s %-8s %-10s %-8s %s\n", '#', 'user', 'tool', 'target', 'simulated', 'live', 'decided by / reason' );
foreach ( $cfg['cases'] as $i => $case )
{
    $u = $users->byLogin( (string)$case['user'] );
    if ( !$u )
    {
        echo "FAIL case $i: no user {$case['user']}\n";
        $fails++;
        continue;
    }
    $args = array( 'user' => $u['login'], 'tool' => $case['tool'] );
    if ( isset( $case['target'] ) )
        $args['target'] = (string)$case['target'];
    $r = $server->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array( 'name' => 'tron_policy_check', 'arguments' => $args ) ), $adminSession );
    $sim = json_decode( (string)( $r['result']['content'][0]['text'] ?? '' ), true )['checks'][0] ?? null;
    if ( !isset( $made[$u['login']] ) )
    {
        $t = $tokens->create( (int)$u['id'], 'policy simulator proof', 1, array( '*' ), (int)$admin['id'] );
        $made[$u['login']] = $t;
    }
    list( $status, $out ) = $http( (string)$made[$u['login']]['token'], (string)$case['tool'], (array)( $case['args'] ?? array() ) );
    $answer = json_decode( $out, true );
    $text = (string)( $answer['result']['content'][0]['text'] ?? '' );
    $denied = $status === 403 || strpos( $text, 'Permission denied' ) !== false || strpos( $text, 'refuses edit' ) !== false
              || ( isset( $answer['error'] ) && strpos( (string)$answer['error']['message'], 'Unknown tool' ) !== false );
    $live = $status === 200 || $status === 403 ? ( $denied ? 'denied' : 'allowed' ) : "HTTP $status";
    $simWord = $sim === null ? 'none' : ( $sim['allowed'] ? 'allowed' : 'denied' );
    $ok = $sim !== null && $simWord === $live;
    $ok ? $passes++ : $fails++;
    printf( "%-3s %-16s %-24s %-8s %-10s %-8s %s (HTTP %d) %s\n", $i + 1, $u['login'], $case['tool'], $case['target'] ?? '', $simWord, $live,
            $sim['decided_by'] ?? '', $status, $ok ? 'PASS' : 'FAIL ' . substr( $text ?: $out, 0, 160 ) );
}
foreach ( $made as $login => $t )
    $tokens->revoke( (string)$t['id'] );
echo ( $fails ? 'FAIL' : 'PASS' ) . ": $passes of " . ( $passes + $fails ) . " cases match the live call; " . count( $made ) . " tokens made and revoked\n";
return $fails ? 1 : 0;
