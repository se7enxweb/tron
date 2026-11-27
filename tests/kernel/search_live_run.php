<?php
/**
 * The search units against a real installation (read only), signed in with short-lived tokens: one for an
 * editor-level user and one for an anonymous-level user, both scoped to the program "search" and revoked at the end.
 * Proves that search.query finds only what each user may read (every hit passes the kernel's own read check for that
 * user, and content the editor finds but the anonymous-level user may not read never reaches the latter, not in the
 * items and not in the total), the filters and paging, the facets answer of the engine, search.index, and that the
 * tokens' scope keeps other programs out. Prints PASS/FAIL per check with ids and counts only.
 *
 *   php bin/php/ezexec.php extension/tron/tests/kernel/search_live_run.php --allow-root-user
 *
 * TRON_RUN_EDITOR names the editor-level user (default trsearch-editor), TRON_RUN_ANON the anonymous-level user
 * (default trsearch-anon); both need tron/use. TRON_RUN_TEXT is a search text that finds content the editor may read
 * and the other may not (default archives), TRON_RUN_COMMON one with many hits for the paging and sort checks
 * (default exponential).
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
$t0 = microtime( true );
$users = new tronKernelUserDirectory();
$editor = $users->byLogin( getenv( 'TRON_RUN_EDITOR' ) ?: 'trsearch-editor' );
$anon = $users->byLogin( getenv( 'TRON_RUN_ANON' ) ?: 'trsearch-anon' );
if ( !$editor || !$anon )
{
    echo "FAIL: the test users are missing\n";
    return 1;
}
$text = getenv( 'TRON_RUN_TEXT' ) ?: 'archives';
$common = getenv( 'TRON_RUN_COMMON' ) ?: 'exponential';

$server = tronKernel::server( function ( $level, $message ) { fwrite( STDERR, "      [log] $level $message\n" ); } );
$tokens = tronKernel::tokens();
$issued = array();
$sessions = array();
foreach ( array( 'editor' => $editor, 'anon' => $anon ) as $k => $u )
{
    $t = $tokens->create( (int)$u['id'], 'search live check', 1, array( 'search' ) );
    $issued[$k] = $t;
    $reason = null;
    $p = $tokens->authenticate( (string)$t['token'], $reason );
    $check( $p instanceof tronPrincipal && $p->userId === (int)$u['id'] && $p->scopes === array( 'search' ),
            "token for the $k-level user: prefix {$t['prefix']}, 1 day, scope search" );
    $sessions[$k] = new tronSession( $p, 'stdio', false, tronProtocol::LATEST, 'search-proof' );
}
$call = function ( string $who, string $tool, array $args = array() ) use ( $server, &$sessions ) {
    $r = $server->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array( 'name' => $tool, 'arguments' => $args ) ), $sessions[$who] );
    $body = (string)( $r['result']['content'][0]['text'] ?? json_encode( $r['error'] ?? null ) );
    return array( 'ok' => empty( $r['result']['isError'] ) && !isset( $r['error'] ), 'data' => json_decode( $body, true ), 'text' => $body );
};
$typed = function ( string $tool, array $r ) {
    $u = tronUnitCatalog::byTool( $tool );
    return $r['ok'] && $u && is_array( $r['data'] ) && !tronSchemaValidator::validate( tronUnitTypes::schema( $u['out'] ), $r['data'] );
};
$readable = function ( int $nodeId, int $userId ) {
    $n = eZContentObjectTreeNode::fetch( $nodeId );
    return $n instanceof eZContentObjectTreeNode && (int)$n->checkAccess( 'read', false, false, false, false, $userId ) === 1;
};
$all = function ( string $who, array $args ) use ( $call ) {
    $ids = array();
    $total = null;
    for ( $offset = 0; $offset < 1000; $offset += 50 )
    {
        $r = $call( $who, 'search_query', $args + array( 'limit' => 50, 'offset' => $offset ) );
        if ( !$r['ok'] )
            return array( 'ok' => false, 'text' => $r['text'] );
        $total = $r['data']['total'];
        foreach ( $r['data']['items'] as $h )
            $ids[] = (int)$h['node_id'];
        if ( count( $r['data']['items'] ) < 50 )
            break;
    }
    return array( 'ok' => true, 'total' => $total, 'ids' => $ids );
};

try
{
    // the tools the tokens see
    foreach ( $sessions as $who => $s )
    {
        $list = $server->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => array() ), $s );
        $names = array_column( $list['result']['tools'] ?? array(), 'name' );
        $check( in_array( 'search_query', $names, true ) && in_array( 'search_index', $names, true ) && !in_array( 'node_read', $names, true ),
                "$who: tools/list shows search_query and search_index, not node_read (" . count( $names ) . ' tools)' );
    }
    $r = $call( 'editor', 'node_read', array( 'node_id' => 2 ) );
    $check( !$r['ok'], 'editor: node_read is refused outside the token scope' );

    // the search, as each user
    $e = $all( 'editor', array( 'text' => $text ) );
    $a = $all( 'anon', array( 'text' => $text ) );
    $check( $e['ok'] && $a['ok'], "search_query '$text': editor total " . ( $e['total'] ?? '?' ) . ', anonymous-level total ' . ( $a['total'] ?? '?' ) );
    $check( $e['ok'] && count( $e['ids'] ) === (int)$e['total'] && count( $a['ids'] ) === (int)$a['total'], 'the totals equal the hits paged through' );
    $badE = array_filter( $e['ids'] ?? array(), function ( $id ) use ( $readable, $editor ) { return !$readable( $id, (int)$editor['id'] ); } );
    $badA = array_filter( $a['ids'] ?? array(), function ( $id ) use ( $readable, $anon ) { return !$readable( $id, (int)$anon['id'] ); } );
    $check( !$badE, 'every editor hit passes the kernel read check for the editor' . ( $badE ? ': ' . implode( ', ', $badE ) : '' ) );
    $check( !$badA, 'every anonymous-level hit passes the kernel read check for that user' . ( $badA ? ': ' . implode( ', ', $badA ) : '' ) );
    $restricted = array_values( array_filter( $e['ids'] ?? array(), function ( $id ) use ( $readable, $anon ) { return !$readable( $id, (int)$anon['id'] ); } ) );
    $check( count( $restricted ) > 0, 'the editor finds restricted content the anonymous-level user may not read: nodes ' . implode( ', ', array_slice( $restricted, 0, 10 ) ) );
    $check( $restricted && !array_intersect( $restricted, $a['ids'] ?? array() ), 'none of it reaches the anonymous-level user' );
    $check( isset( $e['total'], $a['total'] ) && $a['total'] <= $e['total'] - count( $restricted ) + count( array_diff( $a['ids'], $e['ids'] ) ),
            'the anonymous-level total leaves the restricted hits out' );

    // filters, sort, paging, facets
    $first = $call( 'editor', 'search_query', array( 'text' => $common, 'limit' => 3 ) );
    $check( $typed( 'search_query', $first ), "'$common': " . 'search_query answers its output type search-hit[]: engine ' . ( $first['data']['engine'] ?? '?' ) . ', sort "' . ( $first['data']['sort'] ?? '' ) . '"' );
    $h = $first['data']['items'][0] ?? array();
    $check( isset( $h['node_id'], $h['object_id'], $h['name'], $h['class_identifier'], $h['url_alias'], $h['modified'] ) && array_key_exists( 'excerpt', $h ),
            'a hit has node_id, object_id, name, class, url_alias, modified and excerpt (node ' . ( $h['node_id'] ?? '?' ) . ', ' . ( $h['class_identifier'] ?? '?' ) . ', excerpt ' . strlen( (string)( $h['excerpt'] ?? '' ) ) . ' bytes)' );
    $page = $call( 'editor', 'search_query', array( 'text' => $common, 'limit' => 3, 'offset' => 3 ) );
    $check( $page['ok'] && $page['data']['total'] === $first['data']['total'] && $first['data']['total'] > 6 && count( $page['data']['items'] ) === 3 && !array_intersect( array_column( $page['data']['items'], 'node_id' ), array_column( $first['data']['items'], 'node_id' ) ),
            "'$common': offset 3 gives the next 3 of {$first['data']['total']} hits" );
    $sorted = $call( 'editor', 'search_query', array( 'text' => $common, 'sort' => 'modified', 'order' => 'desc', 'limit' => 10 ) );
    $mods = array_column( $sorted['data']['items'] ?? array(), 'modified' );
    $desc = $mods;
    rsort( $desc );
    $check( $sorted['ok'] && count( $mods ) > 1 && $mods === $desc, "'$common' sort modified desc: " . count( $mods ) . ' hits newest first' );
    // the database's collation orders names, not PHP: name asc must be name desc reversed (all hits on one page)
    $byName = $call( 'editor', 'search_query', array( 'text' => $common, 'sort' => 'name', 'order' => 'asc', 'limit' => 50 ) );
    $byNameDesc = $call( 'editor', 'search_query', array( 'text' => $common, 'sort' => 'name', 'order' => 'desc', 'limit' => 50 ) );
    $names = array_column( $byName['data']['items'] ?? array(), 'name' );
    $check( $byName['ok'] && $byNameDesc['ok'] && count( $names ) > 1 && $byName['data']['total'] <= 50
            && $names === array_reverse( array_column( $byNameDesc['data']['items'], 'name' ) ) && $names !== array_reverse( $names ),
            "'$common' sort name asc is name desc reversed: " . count( $names ) . ' hits' );
    if ( $restricted )
    {
        $rn = eZContentObjectTreeNode::fetch( $restricted[0] );
        $section = (int)$rn->object()->attribute( 'section_id' );
        $class = (string)$rn->attribute( 'class_identifier' );
        $es = $call( 'editor', 'search_query', array( 'text' => $text, 'section' => $section, 'class' => $class, 'limit' => 50 ) );
        $as = $call( 'anon', 'search_query', array( 'text' => $text, 'section' => $section, 'class' => $class, 'limit' => 50 ) );
        $check( $es['ok'] && in_array( $restricted[0], array_column( $es['data']['items'], 'node_id' ), true )
                && !array_diff( array_unique( array_column( $es['data']['items'], 'class_identifier' ) ), array( $class ) ),
                "section $section + class $class: the editor finds node {$restricted[0]} ({$es['data']['total']} hits, all $class)" );
        $check( $as['ok'] && !in_array( $restricted[0], array_column( $as['data']['items'], 'node_id' ), true ),
                "section $section + class $class: the anonymous-level user does not ({$as['data']['total']} hits)" );
        $parent = (int)$rn->attribute( 'parent_node_id' );
        $sub = $call( 'editor', 'search_query', array( 'text' => $text, 'node_id' => $parent, 'limit' => 50 ) );
        $outside = array_filter( array_column( $sub['data']['items'] ?? array(), 'node_id' ), function ( $id ) use ( $parent ) {
            $n = eZContentObjectTreeNode::fetch( $id );
            return !$n || strpos( (string)$n->attribute( 'path_string' ), '/' . $parent . '/' ) === false;
        } );
        $check( $sub['ok'] && !$outside, "node_id $parent (subtree): " . ( $sub['data']['total'] ?? 0 ) . ' hits, all below it' );
    }
    $lang = $call( 'editor', 'search_query', array( 'text' => $common, 'language' => 'eng-GB', 'limit' => 5 ) );
    $check( $lang['ok'], "'$common' language eng-GB: " . ( $lang['data']['total'] ?? '?' ) . ' hits' );
    $f = $call( 'editor', 'search_query', array( 'text' => $text, 'facets' => array( 'class', 'section' ), 'limit' => 1 ) );
    $check( $f['ok'] && ( $f['data']['facets_supported'] ? count( (array)$f['data']['facets'] ) > 0 : isset( $f['data']['note'] ) ),
            'facets: ' . ( $f['data']['facets_supported'] ?? false ? 'counted' : 'the engine has none, and the answer says so' ) );
    $bad = $call( 'editor', 'search_query', array( 'text' => $text, 'class' => 'no_such_class_x' ) );
    $check( !$bad['ok'] && strpos( $bad['text'], 'no_such_class_x' ) !== false, 'an unknown class is a tool error' );

    // the index
    $i = $call( 'editor', 'search_index' );
    $check( $typed( 'search_index', $i ), 'search_index: engine ' . json_encode( $i['data']['engine'] ?? null ) . ', pending ' . ( $i['data']['pending'] ?? '?' ) );
    $node = $h['node_id'] ?? 2;
    $o = $call( 'editor', 'search_index', array( 'node_id' => $node ) );
    $check( $typed( 'search_index', $o ) && ( $o['data']['object']['indexed'] ?? null ) === true, "search_index node $node: indexed " . json_encode( $o['data']['object']['indexed'] ?? null ) . ', queued ' . json_encode( $o['data']['object']['queued'] ?? null ) );
    if ( $restricted )
    {
        $x = $call( 'anon', 'search_index', array( 'node_id' => $restricted[0] ) );
        $check( !$x['ok'] && strpos( $x['text'], 'not found' ) !== false, "search_index node {$restricted[0]} as the anonymous-level user: not found" );
    }

    // audit
    $rows = tronKernel::db()->select( "SELECT COUNT(*) AS c FROM tron_audit WHERE target IN ('search_query','search_index') AND token_id IN (" . (int)$issued['editor']['id'] . ',' . (int)$issued['anon']['id'] . ')' );
    $check( (int)( $rows[0]['c'] ?? 0 ) > 0, 'the calls are in the audit log under the two tokens: ' . (int)( $rows[0]['c'] ?? 0 ) . ' rows' );
}
finally
{
    foreach ( $issued as $k => $t )
    {
        $tokens->revoke( (string)$t['id'] );
        $reason = null;
        $check( $tokens->authenticate( (string)$t['token'], $reason ) === null, "the $k token is revoked ($reason)" );
    }
}

printf( "%s: %d passed, %d failed, %d ms\n", $fails ? 'FAIL' : 'PASS', $passes, $fails, (int)( ( microtime( true ) - $t0 ) * 1000 ) );
return $fails ? 1 : 0;
