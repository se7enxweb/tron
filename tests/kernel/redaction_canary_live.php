<?php
/**
 * The redaction proof on a real installation (doc/redaction.md): canary secrets planted in content, in the INI and in
 * a user's account, then every tool, resource and prompt the administrator sees called, and every answer and every
 * audit row of the run searched for the canaries.
 *
 *   TRON_PROOF_NODE=<scratch node> TRON_PROOF_ADMIN=<login> TRON_PROOF_CANARY_USER=<login whose e-mail is a canary> \
 *       [TRON_PROOF_CALLS=6] php bin/php/ezexec.php <root>/extension/tron/tests/kernel/redaction_canary_live.php --allow-root-user
 *
 * Planted: the scratch node's short_name (through content_node_update), a ticket and a comment on the board
 * tronproof-redaction (closed at the end), the tron.ini instruction (in memory only, never written to a file); the
 * user's e-mail address is the account's own. Read tools are called with arguments made from their schema
 * (TRON_PROOF_CALLS times each); write tools only as dry runs, so nothing else changes. Prints the counts; never
 * prints a canary.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

require_once dirname( __DIR__ ) . '/lib/fixture.php';
require_once dirname( __DIR__ ) . '/lib/fuzz.php';

$fails = 0;
$check = function ( bool $ok, string $what ) use ( &$fails ) {
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $what . "\n";
    if ( !$ok )
        $fails++;
};
$nodeId = (int)getenv( 'TRON_PROOF_NODE' );
$users = new tronKernelUserDirectory();
$admin = $users->byLogin( (string)getenv( 'TRON_PROOF_ADMIN' ) );
$canaryUser = eZUser::fetchByName( (string)getenv( 'TRON_PROOF_CANARY_USER' ) );
$perTool = (int)( getenv( 'TRON_PROOF_CALLS' ) ?: 6 );
if ( $nodeId <= 0 || !$admin || !$canaryUser instanceof eZUser )
{
    echo "FAIL: set TRON_PROOF_NODE, TRON_PROOF_ADMIN and TRON_PROOF_CANARY_USER\n";
    return 1;
}
$canaries = TronFuzz::canaries();
$needles = array_values( $canaries );
$needles[] = (string)$canaryUser->attribute( 'email' );
$needles[] = 'CanaryIniPw-55';
$start = time();

// the INI canary, in memory: what tronKernel::settings() reads
eZINI::instance( 'tron.ini' )->setVariable( 'ServerSettings', 'Instructions', 'Ops note: the deploy password=CanaryIniPw-55 is rotated weekly.' );
$server = tronKernel::server();
$session = new tronSession( new tronPrincipal( (int)$admin['id'], $admin['login'] ), 'stdio', false, '2025-11-25', 'proof' );
$answers = array();
$send = function ( string $method, array $params ) use ( $server, $session, &$answers ) {
    $r = $server->handle( array( 'jsonrpc' => '2.0', 'id' => count( $answers ) + 1, 'method' => $method, 'params' => $params ), $session );
    $answers[] = $r;
    return $r;
};
$call = function ( string $tool, array $args ) use ( $send ) {
    $r = $send( 'tools/call', array( 'name' => $tool, 'arguments' => $args ) );
    return json_decode( (string)( $r['result']['content'][0]['text'] ?? 'null' ), true );
};

// plant
$source = new tronContentKernelSource();
$original = (string)( $source->node( $nodeId )['attributes']['short_name']['value'] ?? '' );
$plantedText = 'contact canary.person@example.org, password=CanaryPw-7f3a91, call +49 30 1234 5678';
$call( 'content_node_update', array( 'node_id' => $nodeId, 'attributes' => array( 'short_name' => $plantedText ) ) );
$check( (string)$source->node( $nodeId )['attributes']['short_name']['value'] === $plantedText, 'canary planted in the content node' );
$ticket = $call( 'centre_ticket_create', array( 'title' => 'redaction proof ' . $canaries['aws AKIACANARY1234567890 key'], 'board' => 'tronproof-redaction',
                                                'body' => implode( "\n", array_keys( $canaries ) ) ) );
$tid = (int)( $ticket['id'] ?? 0 );
$call( 'centre_ticket_comment', array( 'id' => $tid, 'body' => 'from ' . (string)$canaryUser->attribute( 'email' ) . ' ' . array_keys( $canaries )[4] ) );
$check( $tid > 0, 'canaries planted in a ticket and a comment' );
$answers = array();     // the planting calls echo what they were given; the search starts here

// every tool, resource and prompt
$send( 'initialize', array( 'protocolVersion' => '2025-11-25' ) );
$fz = new TronFuzz( 20261125 );
$hints = array( 'node_id' => array( $nodeId, 2, 43 ), 'id' => array( $tid ), 'board' => array( 'tronproof-redaction', 'main' ),
                'tool' => array( 'content_node_update', 'centre_ticket_list', 'tron_whoami' ), 'user' => array( $admin['login'] ),
                'path' => array( '/' ), 'file' => array( 'site.ini', 'tron.ini' ), 'identifier' => array( 'folder', 'article' ),
                '@attributes' => array( array( 'short_name' => 'x' ) ), '@arguments' => array( array( 'node_id' => $nodeId, 'attributes' => array( 'short_name' => 'y' ) ) ) );
$tools = 0;
$dry = 0;
$calls = 0;
$skipped = array();
foreach ( $server->visibleTools( $session ) as $t )
{
    if ( in_array( $t->name, array( 'tron_apply' ), true ) )
        continue;
    $tools++;
    for ( $i = 0; $i < $perTool; $i++ )
    {
        $args = $fz->valid( $t->inputSchema, $hints, 0 );
        $args = is_array( $args ) ? $args : array();
        if ( $t->write )
        {
            if ( !$t->hasDryRun() )
            {
                $skipped[$t->name] = 'a write without a dry run';
                break;
            }
            $send( 'tools/call', array( 'name' => $t->name, 'arguments' => $args, '_meta' => array( 'tron/dryRun' => true ) ) );
            $dry++;
        }
        else
            $send( 'tools/call', array( 'name' => $t->name, 'arguments' => $args ) );
        $calls++;
    }
}
// the planted things themselves, read directly
$send( 'tools/call', array( 'name' => 'centre_ticket_get', 'arguments' => array( 'id' => $tid ) ) );
$send( 'tools/call', array( 'name' => 'tron_plan', 'arguments' => array( 'tool' => 'content_node_update', 'arguments' => array( 'node_id' => $nodeId, 'attributes' => array( 'short_name' => 'z' ) ) ) ) );
$resources = 0;
foreach ( array( 'centre://ticket/' . $tid, 'centre://board/tronproof-redaction', 'tron://status', 'tron://programs' ) as $uri )
{
    $send( 'resources/read', array( 'uri' => $uri ) );
    $resources++;
}
$prompts = 0;
foreach ( $server->registry()->prompts() as $p )
{
    $send( 'prompts/get', array( 'name' => $p->name, 'arguments' => array( 'board' => 'tronproof-redaction' ) ) );
    $prompts++;
}

$leaks = array();
$masked = 0;
$withData = 0;
foreach ( $answers as $a )
{
    $blob = implode( "\n", TronFuzz::strings( $a ) );
    $masked += substr_count( $blob, '[redacted:' );
    if ( isset( $a['result'] ) && empty( $a['result']['isError'] ) )
        $withData++;
    foreach ( $needles as $n )
        if ( $n !== '' && strpos( $blob, $n ) !== false )
            $leaks[] = substr( (string)json_encode( $a ), 0, 120 );
}
$check( !$leaks, count( $answers ) . " answers ($withData with data) of $tools tools ($calls calls, $dry dry runs), $resources resources, $prompts prompts and the instructions: no canary"
                 . ( $leaks ? ': ' . implode( ' | ', array_slice( $leaks, 0, 3 ) ) : '' ) );
$check( $masked > 0, "the canaries were reached: $masked masked values in the answers" );
foreach ( $skipped as $name => $why )
    echo "      not called: $name ($why)\n";

// the audit rows of the run: counts of what was masked, never a canary
$db = tronKernel::db();
$rows = $db->select( 'SELECT detail FROM tron_audit WHERE created >= :s AND user_id = :u', array( 's' => $start, 'u' => (int)$admin['id'] ) );
$kinds = array();
$auditLeaks = 0;
foreach ( $rows as $r )
{
    $d = json_decode( (string)$r['detail'], true );
    foreach ( (array)( $d['redacted'] ?? array() ) as $k => $n )
        $kinds[$k] = ( $kinds[$k] ?? 0 ) + (int)$n;
    foreach ( $needles as $n )
        if ( $n !== '' && strpos( (string)$r['detail'], $n ) !== false )
            $auditLeaks++;
}
ksort( $kinds );
$check( $auditLeaks === 0, count( $rows ) . ' audit rows of the run hold no canary' );
$check( count( $kinds ) >= 4, 'the audit records the kinds masked: ' . json_encode( $kinds ) );

// clean up what was planted
$call( 'content_node_update', array( 'node_id' => $nodeId, 'attributes' => array( 'short_name' => $original ) ) );
$call( 'centre_ticket_close', array( 'id' => $tid, 'reason' => 'proof done' ) );
$check( (string)$source->node( $nodeId )['attributes']['short_name']['value'] === $original, 'the content node has its value back, the ticket is closed' );
echo ( $fails ? 'FAIL' : 'PASS' ) . "\n";
return $fails ? 1 : 0;
