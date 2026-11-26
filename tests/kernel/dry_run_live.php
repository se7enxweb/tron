<?php
/**
 * The dry-run proof on a real installation (doc/dry-run.md): one content node edited twice through tron_plan and
 * tron_apply, as a user with real roles.
 *
 *   TRON_PROOF_NODE=<node id> TRON_PROOF_USER=<login> [TRON_PROOF_ATTRIBUTE=short_name] \
 *       php bin/php/ezexec.php <root>/extension/tron/tests/kernel/dry_run_live.php --allow-root-user
 *
 * Each round: a checksum of every row of the object in ezcontentobject, ezcontentobject_version,
 * ezcontentobject_attribute and ezcontentobject_name; the dry run, after which the checksum must be unchanged; the
 * apply, after which the attribute and version must be exactly the planned ones and the rows that changed must be
 * exactly the new version's. Round 1 writes a new value, round 2 the original value back. Then a refused apply: a
 * plan whose target changed in between. Prints PASS/FAIL per check and the timings; changes only that node.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

$fails = 0;
$passes = 0;
$check = function ( bool $ok, string $what ) use ( &$fails, &$passes ) {
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $what . "\n";
    $ok ? $passes++ : $fails++;
};
$nodeId = (int)getenv( 'TRON_PROOF_NODE' );
$login = (string)getenv( 'TRON_PROOF_USER' );
$attr = (string)( getenv( 'TRON_PROOF_ATTRIBUTE' ) ?: 'short_name' );
$users = new tronKernelUserDirectory();
$u = $login !== '' ? $users->byLogin( $login ) : null;
if ( $nodeId <= 0 || !$u )
{
    echo "FAIL: set TRON_PROOF_NODE and TRON_PROOF_USER (an existing login)\n";
    return 1;
}
$server = tronKernel::server( function ( $level, $message ) { echo "      [log] $level $message\n"; } );
$session = new tronSession( new tronPrincipal( (int)$u['id'], $u['login'] ), 'stdio', false, '2025-11-25', 'proof' );
$db = tronKernel::db();
$source = new tronContentKernelSource();
$node = $source->node( $nodeId );
$check( $node !== null && isset( $node['attributes'][$attr] ), "node $nodeId has the attribute $attr" );
$objectId = (int)$node['object_id'];

$rows = function () use ( $db, $objectId ) {
    $out = array();
    foreach ( array( 'ezcontentobject' => 'SELECT * FROM ezcontentobject WHERE id = :o',
                     'ezcontentobject_version' => 'SELECT * FROM ezcontentobject_version WHERE contentobject_id = :o ORDER BY id',
                     'ezcontentobject_attribute' => 'SELECT * FROM ezcontentobject_attribute WHERE contentobject_id = :o ORDER BY id, version',
                     'ezcontentobject_name' => 'SELECT * FROM ezcontentobject_name WHERE contentobject_id = :o ORDER BY content_version, content_translation' ) as $t => $sql )
        $out[$t] = $db->select( $sql, array( 'o' => $objectId ) );
    return $out;
};
$sum = function ( array $r ) {
    $s = array();
    foreach ( $r as $t => $list )
        $s[$t] = count( $list ) . ' rows ' . substr( hash( 'sha256', json_encode( $list ) ), 0, 16 );
    return $s;
};
$call = function ( string $tool, array $args ) use ( $server, $session ) {
    $r = $server->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array( 'name' => $tool, 'arguments' => $args ) ), $session );
    $text = (string)( $r['result']['content'][0]['text'] ?? json_encode( $r['error'] ?? null ) );
    return array( empty( $r['result']['isError'] ) && !isset( $r['error'] ), json_decode( $text, true ), $text );
};

$original = (string)$node['attributes'][$attr]['value'];
foreach ( array( 1 => 'tron dry-run proof ' . gmdate( 'Y-m-d H:i:s' ), 2 => $original ) as $round => $value )
{
    $before = $rows();
    $n = $source->node( $nodeId );
    $args = array( 'node_id' => $nodeId, 'attributes' => array( $attr => $value ) );
    $t0 = microtime( true );
    list( $ok, $plan, $text ) = $call( 'tron_plan', array( 'tool' => 'content_node_update', 'arguments' => $args ) );
    $msPlan = ( microtime( true ) - $t0 ) * 1000;
    $check( $ok, "round $round: dry run answered" . ( $ok ? '' : ": $text" ) );
    $afterPlan = $rows();
    $check( $sum( $afterPlan ) === $sum( $before ), "round $round: rows unchanged by the dry run " . json_encode( $sum( $before ) ) );
    $planned = array();
    foreach ( (array)( $plan['changes'] ?? array() ) as $c )
        $planned[$c['field']] = $c;
    $check( ( $planned[$attr]['from'] ?? null ) === (string)$n['attributes'][$attr]['value'] && ( $planned[$attr]['to'] ?? null ) === $value,
            "round $round: the plan says $attr from " . json_encode( $planned[$attr]['from'] ?? null ) . ' to ' . json_encode( $planned[$attr]['to'] ?? null ) );
    $check( ( $planned['current_version']['from'] ?? null ) === $n['current_version'] && ( $planned['current_version']['to'] ?? null ) === $n['next_version'],
            "round $round: the plan says version {$n['current_version']} -> {$n['next_version']}" );

    $t0 = microtime( true );
    list( $ok, $applied, $text ) = $call( 'tron_apply', array( 'plan_id' => (string)( $plan['plan_id'] ?? '' ), 'tool' => 'content_node_update', 'arguments' => $args ) );
    $msApply = ( microtime( true ) - $t0 ) * 1000;
    $check( $ok, "round $round: apply answered" . ( $ok ? '' : ": $text" ) );
    $after = $source->node( $nodeId );
    $check( (string)$after['attributes'][$attr]['value'] === $value, "round $round: $attr is now the planned value" );
    $check( (int)$after['current_version'] === (int)$n['next_version'], "round $round: current version is the planned {$n['next_version']}" );
    $check( ( $applied['result']['changed'] ?? null ) === array( array( 'attribute' => $attr, 'from' => (string)$n['attributes'][$attr]['value'], 'to' => $value ) ),
            "round $round: the applied change equals the planned one" );
    // the rows that changed: the new version's, and the object's current version and modified
    $afterRows = $rows();
    $newAttr = array_values( array_filter( $afterRows['ezcontentobject_attribute'], function ( $r ) use ( $n ) { return (int)$r['version'] === (int)$n['next_version']; } ) );
    $values = array();
    foreach ( $newAttr as $r )
        if ( (string)$r['contentclassattribute_id'] !== '' )
            $values[] = $r;
    $check( count( $afterRows['ezcontentobject_version'] ) >= count( $before['ezcontentobject_version'] ) && (int)$afterRows['ezcontentobject'][0]['current_version'] === (int)$n['next_version'],
            "round $round: ezcontentobject.current_version " . $afterRows['ezcontentobject'][0]['current_version'] . ', ' . count( $newAttr ) . ' attribute rows of the new version' );
    printf( "      round %d: dry run %.0f ms, apply %.0f ms\n", $round, $msPlan, $msApply );
}

// the target changes between dry run and apply: refused, nothing changes
$args = array( 'node_id' => $nodeId, 'attributes' => array( $attr => 'never applied' ) );
list( $ok, $plan ) = $call( 'tron_plan', array( 'tool' => 'content_node_update', 'arguments' => $args ) );
list( $ok2 ) = $call( 'content_node_update', array( 'node_id' => $nodeId, 'attributes' => array( $attr => 'changed in between' ) ) );
$before = $rows();
list( $ok3, , $text ) = $call( 'tron_apply', array( 'plan_id' => (string)( $plan['plan_id'] ?? '' ), 'tool' => 'content_node_update', 'arguments' => $args ) );
$check( $ok && $ok2 && !$ok3 && strpos( $text, 'target changed' ) !== false, 'a plan whose target changed in between is refused' );
$check( $sum( $rows() ) === $sum( $before ), 'the refused apply changed no row' );
list( $ok4 ) = $call( 'content_node_update', array( 'node_id' => $nodeId, 'attributes' => array( $attr => $original ) ) );
$check( $ok4 && (string)$source->node( $nodeId )['attributes'][$attr]['value'] === $original, 'the original value is back' );

echo ( $fails ? 'FAIL' : 'PASS' ) . ": $passes passed, $fails failed\n";
return $fails ? 1 : 0;
