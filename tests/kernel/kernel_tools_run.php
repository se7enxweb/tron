<?php
/**
 * The proving run in a real installation: every tool against the real kernel and database, as users with real
 * roles, with the failure cases, the audit and timings.
 *
 *   php bin/php/ezexec.php <root>/extension/tron/tests/kernel/kernel_tools_run.php --allow-root-user
 *
 * Needs tron installed (tables present) and these users (made by the 7x throwaway helpers, removed afterwards):
 *   TRON_RUN_ADMIN (default tron01admin), an administrator;
 *   tron01viewer, tron01editor, tron01decider, tron01operator, tron01detail, tron01useonly, tron01norole with
 *   roles granting exactly: use+centre_read; +centre_write; +centre_decide; use+katalyst_read; +katalyst_detail; use; none.
 *
 * Writes: tickets on a board of its own (tronproof-<time>, all closed at the end), two tokens (revoked at the end),
 * audit rows. Prints PASS/FAIL per check, a summary, and the median time per tool. Never prints a token, and never
 * prints Katalyst rows (only counts and masking checks).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

$fails = 0;
$passes = 0;
$failed = array();
$check = function ( bool $ok, string $what ) use ( &$fails, &$passes, &$failed ) {
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $what . "\n";
    if ( $ok )
        $passes++;
    else
    {
        $fails++;
        $failed[] = $what;
    }
};
$t0run = microtime( true );
$startTime = time();

$users = new tronKernelUserDirectory();
$adminLogin = getenv( 'TRON_RUN_ADMIN' ) ?: 'tron01admin';
$logins = array( 'admin' => $adminLogin );
foreach ( array( 'viewer', 'editor', 'decider', 'operator', 'detail', 'useonly', 'norole' ) as $p )
    $logins[$p] = 'tron01' . $p;
$principals = array();
foreach ( $logins as $p => $login )
{
    $u = $users->byLogin( $login );
    if ( !$u )
    {
        echo "FAIL: the user $login is missing; make the throwaway users first\n";
        return 1;
    }
    $principals[$p] = new tronPrincipal( (int)$u['id'], $u['login'] );
}

$db = tronKernel::db();
$check( tronSchema::missing( $db ) === array(), 'tables present' );
$auditBefore = (int)$db->select( 'SELECT max(id) AS m FROM tron_audit' )[0]['m'];
$auditFile = (string)( tronKernel::settings()['AuditSettings']['FilePath'] ?? 'var/log/tron-audit.log' );
$fileLinesBefore = is_file( $auditFile ) ? count( file( $auditFile ) ) : 0;

$t = microtime( true );
$server = tronKernel::server( function ( $level, $message ) { fwrite( STDERR, "      [log] $level $message\n" ); } );
printf( "     server built in %.1f ms\n", ( microtime( true ) - $t ) * 1000 );
$check( count( $server->registry()->tools() ) === 17, 'registry: 17 tools (tron 2, centre 12, katalyst 3)' );

$timings = array();
$sessions = array();
foreach ( $principals as $p => $pr )
    $sessions[$p] = new tronSession( $pr, 'stdio', false, tronProtocol::LATEST, 'proof' );
$call = function ( string $p, string $tool, array $args = array() ) use ( $server, $sessions, &$timings ) {
    $t = microtime( true );
    $r = $server->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array( 'name' => $tool, 'arguments' => $args ) ), $sessions[$p] );
    $timings[$tool][] = ( microtime( true ) - $t ) * 1000;
    return $r;
};
$data = function ( array $r ) {
    if ( isset( $r['error'] ) || !empty( $r['result']['isError'] ) )
        return null;
    return $r['result']['structuredContent'] ?? json_decode( $r['result']['content'][0]['text'], true );
};
$errText = function ( array $r ): string {
    if ( isset( $r['error'] ) )
        return 'rpc ' . $r['error']['code'] . ' ' . $r['error']['message'];
    $d = json_decode( $r['result']['content'][0]['text'] ?? '', true );
    return is_array( $d ) && isset( $d['error'] ) ? (string)$d['error'] : (string)( $r['result']['content'][0]['text'] ?? '' );
};
$rpc = function ( string $p, string $method, array $params = array() ) use ( $server, $sessions ) {
    return $server->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ), $sessions[$p] );
};

// ---------------------------------------------------------------- centre, every tool, as the administrator
$board = 'tronproof-' . date( 'His' );
echo "== centre tools on PostgreSQL (board $board)\n";
$a = $data( $call( 'admin', 'centre_ticket_create', array( 'title' => 'proof A 50%_off', 'body' => "body with 'quotes' and \\ backslash", 'board' => $board,
                                                           'labels' => array( 'Proof', 'db' ), 'assignee' => $logins['editor'], 'due' => '2026-12-01', 'priority' => 'high' ) ) );
$check( is_array( $a ) && $a['board'] === $board && $a['lane'] === 'inbox' && $a['labels'] === array( 'db', 'proof' ) && $a['assignee'] === $logins['editor'] && $a['due'] === '2026-12-01', 'create: ticket with labels, assignee, due' );
$check( is_array( $a ) && $a['body'] === "body with 'quotes' and \\ backslash", 'create: quotes and backslashes stored as given' );
$b = $data( $call( 'admin', 'centre_ticket_create', array( 'title' => 'proof B card', 'kind' => 'card', 'board' => $board, 'lane' => 'doing' ) ) );
$check( is_array( $b ) && $b['kind'] === 'card' && $b['lane'] === 'doing' && $b['position'] === 0, 'create: card in a lane' );
$d = $data( $call( 'admin', 'centre_ticket_create', array( 'title' => 'proof D decision', 'kind' => 'decision', 'board' => $board ) ) );
$week = tronCentreStore::isoWeek( time() );
$check( is_array( $d ) && $d['decision_week'] === $week, "create: decision defaults to this week ($week)" );
$o = $data( $call( 'admin', 'centre_ticket_create', array( 'title' => 'proof O overdue', 'kind' => 'decision', 'board' => $board, 'decision_week' => '2026-W01' ) ) );
$check( is_array( $o ), 'create: decision with an earlier week' );
$ids = array( (int)$a['id'], (int)$b['id'], (int)$d['id'], (int)$o['id'] );

$u = $data( $call( 'admin', 'centre_ticket_update', array( 'id' => $ids[0], 'title' => 'proof A2 50%_off', 'priority' => 'urgent', 'assignee' => '' ) ) );
$check( is_array( $u ) && $u['title'] === 'proof A2 50%_off' && $u['assignee'] === null && $u['priority'] === 'urgent', 'update: title, priority, clear assignee' );
$c = $data( $call( 'admin', 'centre_ticket_comment', array( 'id' => $ids[0], 'body' => 'comment from the proving run' ) ) );
$check( is_array( $c ) && $c['comment']['by'] === $adminLogin, 'comment' );
$m1 = $data( $call( 'admin', 'centre_ticket_move', array( 'id' => $ids[0], 'lane' => 'doing' ) ) );
$check( is_array( $m1 ) && $m1['lane'] === 'doing' && $m1['position'] === 1, 'move: to the end of a lane' );
$m2 = $data( $call( 'admin', 'centre_ticket_move', array( 'id' => $ids[0], 'lane' => 'doing', 'position' => 0 ) ) );
$check( is_array( $m2 ) && $m2['position'] === 0, 'move: to the top' );
$cl = $data( $call( 'admin', 'centre_ticket_close', array( 'id' => $ids[1], 'reason' => 'duplicate' ) ) );
$check( is_array( $cl ) && $cl['status'] === 'closed' && $cl['closed_reason'] === 'duplicate', 'close' );
$cl2 = $data( $call( 'admin', 'centre_ticket_close', array( 'id' => $ids[1], 'reason' => 'other' ) ) );
$check( is_array( $cl2 ) && $cl2['closed_reason'] === 'duplicate', 'close again changes nothing' );
$re = $data( $call( 'admin', 'centre_ticket_update', array( 'id' => $ids[1], 'status' => 'open' ) ) );
$check( is_array( $re ) && $re['status'] === 'open' && $re['closed'] === null, 'reopen' );

$list = function ( array $f ) use ( $call, $data, $board ) {
    $r = $data( $call( 'admin', 'centre_ticket_list', $f + array( 'board' => $board ) ) );
    return is_array( $r ) ? $r['total'] : -1;
};
$check( $list( array() ) === 4, 'list: open on the board' );
$check( $list( array( 'kind' => 'decision' ) ) === 2, 'list: by kind' );
$check( $list( array( 'label' => 'proof' ) ) === 1, 'list: by label' );
$check( $list( array( 'query' => '50%_' ) ) === 1 && $list( array( 'query' => '%' ) ) === 1 && $list( array( 'query' => 'PROOF A2' ) ) === 1 && $list( array( 'query' => '5_%' ) ) === 0, 'list: query with literal % and _, case-insensitive' );
$check( $list( array( 'query' => 'proof' ) ) === 4, 'list: query words' );
$check( $list( array( 'lane' => 'doing' ) ) === 2, 'list: by lane' );
$pg = $data( $call( 'admin', 'centre_ticket_list', array( 'board' => $board, 'limit' => 1, 'offset' => 1 ) ) );
$check( is_array( $pg ) && count( $pg['items'] ) === 1 && $pg['total'] === 4, 'list: limit and offset on PostgreSQL' );
$bv = $data( $call( 'admin', 'centre_board_view', array( 'board' => $board ) ) );
$check( is_array( $bv ) && array_column( $bv['lanes'], 'lane' ) === array( 'inbox', 'next', 'doing', 'waiting', 'done' ) && $bv['lanes'][2]['total'] === 2
        && $bv['lanes'][2]['items'][0]['id'] === $ids[0], 'board view: lanes in order, by position' );

$rv = $data( $call( 'admin', 'centre_decision_review', array() ) );
$in = function ( $group, $id ) use ( $rv ) { return is_array( $rv ) && in_array( $id, array_column( $rv[$group], 'id' ), true ); };
$check( $in( 'due', $ids[2] ) && $in( 'overdue', $ids[3] ), 'decision review: due and overdue groups' );
$rec = $data( $call( 'admin', 'centre_decision_record', array( 'id' => $ids[2], 'decision' => 'decided in the proving run' ) ) );
$check( is_array( $rec ) && $rec['status'] === 'closed' && $rec['closed_reason'] === 'decided', 'decision record closes' );
$rv2 = $data( $call( 'admin', 'centre_decision_review', array( 'week' => $week ) ) );
$check( is_array( $rv2 ) && in_array( $ids[2], array_column( $rv2['decided'], 'id' ), true ), 'decision review: decided this week' );

$gb = 'gb-' . gmdate( 'Ymd\THis\Z' ) . '-cluster-' . substr( bin2hex( random_bytes( 2 ) ), 0, 4 );
$okLinks = 0;
foreach ( array( array( 'gnubus', $gb ), array( 'todo', 'claude@cluster#301' ), array( 'url', 'https://example.com/proof' ),
                 array( 'ticket', (string)$ids[1] ), array( 'commit', 'se7enxweb/tron@ecce75d' ) ) as $l )
{
    $r = $data( $call( 'admin', 'centre_ticket_link', array( 'id' => $ids[0], 'kind' => $l[0], 'ref' => $l[1], 'note' => 'proof' ) ) );
    $okLinks += is_array( $r ) && $r['existed'] === false ? 1 : 0;
}
$check( $okLinks === 5, 'link: gnubus, todo, url, ticket, commit' );
$again = $data( $call( 'admin', 'centre_ticket_link', array( 'id' => $ids[0], 'kind' => 'gnubus', 'ref' => $gb ) ) );
$check( is_array( $again ) && $again['existed'] === true, 'link again returns the existing link' );
$fl = $data( $call( 'admin', 'centre_links_find', array( 'kind' => 'gnubus', 'ref' => $gb ) ) );
$check( is_array( $fl ) && array_column( $fl['tickets'], 'ticket_id' ) === array( $ids[0] ), 'links find: the ticket of a gnubus message' );
$g = $data( $call( 'admin', 'centre_ticket_get', array( 'id' => $ids[0] ) ) );
$check( is_array( $g ) && count( $g['comments'] ) === 1 && count( $g['links'] ) === 5 && in_array( 'priority', array_column( $g['history'], 'field' ), true ), 'get: comments, links, history' );

$res = $rpc( 'admin', 'resources/read', array( 'uri' => 'centre://ticket/' . $ids[0] ) );
$check( isset( $res['result']['contents'][0]['text'] ) && json_decode( $res['result']['contents'][0]['text'], true )['id'] === $ids[0], 'resource centre://ticket/{id}' );
$resb = $rpc( 'admin', 'resources/read', array( 'uri' => 'centre://board/' . $board ) );
$check( isset( $resb['result']['contents'][0]['text'] ), 'resource centre://board/{board}' );
$check( ( $rpc( 'admin', 'resources/read', array( 'uri' => 'centre://ticket/999999999' ) )['error']['code'] ?? 0 ) === tronProtocol::RESOURCE_NOT_FOUND, 'resource: unknown ticket is -32002' );
$pr = $rpc( 'admin', 'prompts/get', array( 'name' => 'centre_weekly_review' ) );
$check( isset( $pr['result']['messages'][0]['content']['text'] ), 'prompt centre_weekly_review' );
$pt = $rpc( 'admin', 'prompts/get', array( 'name' => 'centre_triage', 'arguments' => array( 'board' => $board ) ) );
$check( isset( $pt['result']['messages'][0] ) && strpos( $pt['result']['messages'][0]['content']['text'], $board ) !== false, 'prompt centre_triage' );
foreach ( array( 'tron://status', 'tron://programs' ) as $uri )
    $check( isset( $rpc( 'admin', 'resources/read', array( 'uri' => $uri ) )['result'] ), "resource $uri" );

// ---------------------------------------------------------------- failure cases
echo "== failure cases\n";
$fail = function ( string $what, string $tool, array $args, string $expect ) use ( $call, $errText, $check ) {
    $r = $call( 'admin', $tool, $args );
    $e = $errText( $r );
    $check( ( isset( $r['error'] ) || !empty( $r['result']['isError'] ) ) && strpos( $e, $expect ) !== false, "refused: $what" . ( strpos( $e, $expect ) === false ? " (got: " . substr( $e, 0, 120 ) . ')' : '' ) );
};
$fail( 'unknown ticket', 'centre_ticket_get', array( 'id' => 999999999 ), 'There is no ticket 999999999' );
$fail( 'unknown lane', 'centre_ticket_move', array( 'id' => $ids[0], 'lane' => 'nowhere' ), 'must be one of' );
$fail( 'empty title', 'centre_ticket_create', array( 'title' => '  ', 'board' => $board ), 'title must not be empty' );
$fail( 'bad date', 'centre_ticket_create', array( 'title' => 'x', 'board' => $board, 'due' => '2026-02-30' ), 'not a date' );
$fail( 'unknown assignee', 'centre_ticket_create', array( 'title' => 'x', 'board' => $board, 'assignee' => 'no_such_login_tron' ), 'no user with the login' );
$fail( 'argument of the wrong type', 'centre_ticket_get', array( 'id' => 'one' ), 'expected integer' );
$fail( 'unknown argument', 'centre_ticket_list', array( 'colour' => 'red' ), 'not a known argument' );
$fail( 'limit over 200', 'centre_ticket_list', array( 'limit' => 500 ), 'must be at most 200' );
$fail( 'bad gnubus id', 'centre_ticket_link', array( 'id' => $ids[0], 'kind' => 'gnubus', 'ref' => 'hello' ), 'gnubus message id' );
$fail( 'javascript url', 'centre_ticket_link', array( 'id' => $ids[0], 'kind' => 'url', 'ref' => 'javascript:alert(1)' ), 'http(s) URL' );
$fail( 'self link', 'centre_ticket_link', array( 'id' => $ids[0], 'kind' => 'ticket', 'ref' => (string)$ids[0] ), 'itself' );
$fail( 'decision on a ticket', 'centre_decision_record', array( 'id' => $ids[0], 'decision' => 'x' ), 'not a decision' );
$fail( 'close via update', 'centre_ticket_update', array( 'id' => $ids[0], 'status' => 'closed' ), 'must be one of' );
$fail( 'nothing to change', 'centre_ticket_update', array( 'id' => $ids[0] ), 'Nothing to change' );
$fail( 'unknown tool', 'no_such_tool', array(), 'Unknown tool' );
$sqlish = $data( $call( 'admin', 'centre_ticket_list', array( 'board' => $board, 'query' => "x' OR '1'='1" ) ) );
$check( is_array( $sqlish ) && $sqlish['total'] === 0, 'an SQL injection attempt in query matches nothing' );

// ---------------------------------------------------------------- permissions with real roles
echo "== permissions with real roles\n";
$core = array( 'tron_status', 'tron_whoami' );
$cr = array( 'centre_board_view', 'centre_decision_review', 'centre_links_find', 'centre_ticket_get', 'centre_ticket_list' );
$cw = array( 'centre_ticket_close', 'centre_ticket_comment', 'centre_ticket_create', 'centre_ticket_link', 'centre_ticket_move', 'centre_ticket_update' );
$cd = array( 'centre_decision_record' );
$ka = array( 'katalyst_orders_list', 'katalyst_plans_list', 'katalyst_services_list' );
$expect = array( 'admin' => array_merge( $core, $cr, $cw, $cd, $ka ), 'viewer' => array_merge( $core, $cr ), 'editor' => array_merge( $core, $cr, $cw ),
                 'decider' => array_merge( $core, $cr, $cw, $cd ), 'operator' => array_merge( $core, $ka ), 'detail' => array_merge( $core, $ka ),
                 'useonly' => $core, 'norole' => array() );
$probeTicket = $data( $call( 'admin', 'centre_ticket_create', array( 'title' => 'proof permissions', 'board' => $board, 'kind' => 'decision' ) ) );
$pid = (int)$probeTicket['id'];
$ids[] = $pid;
$args = array(
    'centre_ticket_create' => array( 'title' => 'proof by role', 'board' => $board ), 'centre_ticket_update' => array( 'id' => $pid, 'priority' => 'low' ),
    'centre_ticket_comment' => array( 'id' => $pid, 'body' => 'c' ), 'centre_ticket_move' => array( 'id' => $pid, 'lane' => 'next' ),
    'centre_ticket_close' => array( 'id' => $ids[3] ), 'centre_ticket_get' => array( 'id' => $pid ), 'centre_ticket_list' => array( 'board' => $board ),
    'centre_board_view' => array( 'board' => $board ), 'centre_decision_record' => array( 'id' => $pid, 'decision' => 'd', 'close' => false ),
    'centre_ticket_link' => array( 'id' => $pid, 'kind' => 'url', 'ref' => 'https://example.com/role' ),
    'centre_links_find' => array( 'kind' => 'url', 'ref' => 'https://example.com/role' ), 'katalyst_services_list' => array( 'limit' => 5 ),
    'katalyst_plans_list' => array( 'limit' => 5 ), 'katalyst_orders_list' => array( 'limit' => 5 ),
);
foreach ( $expect as $p => $allowed )
{
    sort( $allowed );
    $listed = array_column( $rpc( $p, 'tools/list' )['result']['tools'] ?? array(), 'name' );
    $check( $listed === $allowed, "tools/list for {$logins[$p]}: " . count( $listed ) . ' tools' . ( $listed === $allowed ? '' : ' (expected ' . count( $allowed ) . ': ' . implode( ',', array_diff( $allowed, $listed ) ) . ' missing, ' . implode( ',', array_diff( $listed, $allowed ) ) . ' extra)' ) );
    $wrong = array();
    foreach ( $expect['admin'] as $tool )
    {
        $r = $call( $p, $tool, $args[$tool] ?? array() );
        $denied = !empty( $r['result']['isError'] ) && strpos( $errText( $r ), 'Permission denied' ) === 0;
        if ( in_array( $tool, $allowed, true ) === $denied )
            $wrong[] = $tool . ( $denied ? ' denied' : ' allowed' );
    }
    $check( !$wrong, "calls as {$logins[$p]}: every one of 17 tools allowed or denied by the real role" . ( $wrong ? ' (' . implode( ', ', $wrong ) . ')' : '' ) );
}
$deniedRows = (int)$db->select( "SELECT count(*) AS n FROM tron_audit WHERE id > :a AND outcome = 'denied'", array( 'a' => $auditBefore ) )[0]['n'];
$expectDenied = 0;
foreach ( $expect as $p => $allowed )
    $expectDenied += 17 - count( $allowed );
$check( $deniedRows === $expectDenied, "audit: $deniedRows denied rows for $expectDenied refused calls" );
$check( empty( $rpc( 'norole', 'resources/list' )['result']['resources'] ) && empty( $rpc( 'operator', 'prompts/list' )['result']['prompts'] ), 'resources and prompts hidden without the policy' );

// ---------------------------------------------------------------- katalyst on the real tables (counts only)
echo "== katalyst on the installation's tables\n";
foreach ( $ka as $tool )
{
    $r = $call( 'operator', $tool, array( 'limit' => 50 ) );
    $d = $data( $r );
    $check( is_array( $d ) && isset( $d['total'] ), "$tool answers (total " . ( is_array( $d ) ? $d['total'] : '-' ) . ')' . ( is_array( $d ) ? '' : ': ' . substr( $errText( $r ), 0, 120 ) ) );
}
$plain = $data( $call( 'operator', 'katalyst_services_list', array( 'limit' => 200 ) ) );
$full = $data( $call( 'detail', 'katalyst_services_list', array( 'limit' => 200 ) ) );
if ( is_array( $plain ) && is_array( $full ) )
{
    $leaks = 0;
    $byId = array_column( $full['items'], 'domain', 'id' );
    foreach ( $plain['items'] as $row )
    {
        $f = (string)( $byId[$row['id']] ?? '' );
        if ( $f !== '' && $row['domain'] === $f )
            $leaks++;
    }
    $check( $leaks === 0, 'katalyst: no unmasked domain for katalyst_read (' . count( $plain['items'] ) . ' rows checked)' );
    $cols = $plain['items'] ? array_keys( $plain['items'][0] ) : tronKatalystProgram::SERVICE_COLUMNS;
    $check( $cols === tronKatalystProgram::SERVICE_COLUMNS, 'katalyst: only the listed columns leave the server' );
}
$dom = $call( 'operator', 'katalyst_services_list', array( 'domain' => 'ex' ) );
$check( strpos( $errText( $dom ), 'tron/katalyst_detail' ) !== false, 'katalyst: domain search refused without katalyst_detail' );

// ---------------------------------------------------------------- tokens on the real table
echo "== tokens\n";
$tokens = tronKernel::tokens();
$tk = $tokens->create( $principals['viewer']->userId, 'proof run', 1, array( 'centre' ) );
$reason = null;
$pp = $tokens->authenticate( $tk['token'], $reason );
$check( $pp !== null && $pp->login === $logins['viewer'] && $pp->scopes === array( 'centre' ), 'token: authenticates as its user with its scope' );
$hashRow = $db->select( 'SELECT token_hash FROM tron_token WHERE id = :i', array( 'i' => $tk['id'] ) );
$check( $hashRow && $hashRow[0]['token_hash'] === hash( 'sha256', $tk['token'] ) && strpos( json_encode( $hashRow ), substr( $tk['token'], 18 ) ) === false, 'token: only the hash is stored' );
$check( $tokens->authenticate( substr( $tk['token'], 0, -1 ) . 'x', $reason ) === null, 'token: a changed token is refused' );
$check( $tokens->revoke( (string)$tk['id'] ) && $tokens->authenticate( $tk['token'], $reason ) === null && strpos( (string)$reason, 'revoked' ) === 0, 'token: revoked at once' );
$tn = $tokens->create( $principals['norole']->userId, 'proof norole', 1 );
$hs = (array)( tronKernel::settings()['HTTPSettings'] ?? array() );
$sa = (string)( array_values( array_filter( (array)( $hs['SiteAccesses'] ?? array() ) ) )[0] ?? '' );
$host = (string)( array_values( array_filter( (array)( $hs['AllowedHosts'] ?? array() ) ) )[0] ?? 'localhost' );
$http = $sa !== '' ? tronKernel::httpTransport( $sa ) : null;
if ( $http )
{
    $req = new tronHttpRequest( 'POST', array( 'Host' => $host, 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $tn['token'] ),
                                '{"jsonrpc":"2.0","id":1,"method":"ping"}', 'proof' );
    $check( $http->handle( $req )->status === 403, 'HTTP transport in process: a user without tron/use gets 403' );
}
$tokens->revoke( (string)$tn['id'] );

// ---------------------------------------------------------------- audit
echo "== audit\n";
$rows = $db->select( 'SELECT outcome, count(*) AS n FROM tron_audit WHERE id > :a GROUP BY outcome ORDER BY outcome', array( 'a' => $auditBefore ) );
$byOutcome = array_column( $rows, 'n', 'outcome' );
echo '     audit rows of this run: ' . json_encode( array_map( 'intval', $byOutcome ) ) . "\n";
$check( (int)( $byOutcome['started'] ?? 0 ) > 0 && (int)( $byOutcome['ok'] ?? 0 ) > 0 && (int)( $byOutcome['invalid'] ?? 0 ) > 0 && (int)( $byOutcome['error'] ?? 0 ) > 0, 'audit: started, ok, invalid, error rows written' );
$writesStarted = (int)( $byOutcome['started'] ?? 0 );
$writesOk = (int)$db->select( "SELECT count(*) AS n FROM tron_audit a WHERE a.id > :a AND a.outcome IN ('ok','error') AND a.target IN ('centre_ticket_create','centre_ticket_update','centre_ticket_comment','centre_ticket_move','centre_ticket_close','centre_ticket_link','centre_decision_record')", array( 'a' => $auditBefore ) )[0]['n'];
$check( $writesStarted === $writesOk, "audit: every write tool that ran has a started row ($writesStarted started, $writesOk finished)" );
$fileLines = is_file( $auditFile ) ? count( file( $auditFile ) ) : 0;
$dbRows = array_sum( array_map( 'intval', $byOutcome ) );
$check( $fileLines - $fileLinesBefore === $dbRows, "audit: the file sink got the same entries (" . ( $fileLines - $fileLinesBefore ) . " lines, $dbRows rows)" );
$leak = $db->select( "SELECT count(*) AS n FROM tron_audit WHERE id > :a AND detail LIKE :p", array( 'a' => $auditBefore, 'p' => '%' . substr( $tk['token'], 18, 20 ) . '%' ) );
$check( (int)$leak[0]['n'] === 0, 'audit: no token secret in any row' );

// ---------------------------------------------------------------- tidy up the run's tickets
foreach ( $db->select( "SELECT id FROM tron_centre_ticket WHERE board = :b AND status = 'open'", array( 'b' => $board ) ) as $row )
    $call( 'admin', 'centre_ticket_close', array( 'id' => (int)$row['id'], 'reason' => 'proving run done' ) );
$check( (int)$db->select( "SELECT count(*) AS n FROM tron_centre_ticket WHERE board = :b AND status = 'open'", array( 'b' => $board ) )[0]['n'] === 0, "tidy: every ticket of $board closed" );

// ---------------------------------------------------------------- timings
echo "== median ms per tool (calls)\n";
ksort( $timings );
foreach ( $timings as $tool => $ms )
{
    sort( $ms );
    printf( "     %-26s %7.2f ms  (%d calls, max %.2f)\n", $tool, $ms[(int)floor( ( count( $ms ) - 1 ) / 2 )], count( $ms ), end( $ms ) );
}
printf( "%s: kernel tools run, %d passed, %d failed, %.1f s\n", $fails ? 'FAIL' : 'PASS', $passes, $fails, microtime( true ) - $t0run );
foreach ( $failed as $f )
    echo "  FAIL $f\n";
return $fails ? 1 : 0;
