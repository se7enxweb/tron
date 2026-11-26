<?php
/**
 * The units against a real installation (read only): every unit with real nodes, classes, layouts, settings and
 * templates, for the siteaccesses site and admin, the permission checks with a user without the policies, and the
 * pipe "node.find class=article limit 3 | node.read | tpl.resolve". Prints PASS/FAIL per check and short summaries
 * (ids, identifiers, counts and paths; never attribute text of users).
 *
 *   php bin/php/ezexec.php extension/tron/tests/kernel/units_live_run.php --allow-root-user
 *
 * TRON_RUN_ADMIN names an administrator (default tron01admin), TRON_RUN_NOROLE a user without tron policies (default
 * tron01norole; the permission checks are skipped without one). TRON_RUN_SITE / TRON_RUN_ADMINSA name the two
 * siteaccesses (default site and admin), TRON_RUN_PATH the landing page (default Fit-Healthy).
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
$adminLogin = getenv( 'TRON_RUN_ADMIN' ) ?: 'tron01admin';
$admin = $users->byLogin( $adminLogin );
if ( !$admin )
{
    echo "FAIL: the user $adminLogin is missing\n";
    return 1;
}
$site = getenv( 'TRON_RUN_SITE' ) ?: 'site';
$adminSa = getenv( 'TRON_RUN_ADMINSA' ) ?: 'admin';
$landing = getenv( 'TRON_RUN_PATH' ) ?: 'Fit-Healthy';

$server = tronKernel::server( function ( $level, $message ) { fwrite( STDERR, "      [log] $level $message\n" ); } );
$session = new tronSession( new tronPrincipal( (int)$admin['id'], $admin['login'] ), 'stdio', false, tronProtocol::LATEST, 'units-proof' );
$call = function ( string $tool, array $args = array(), ?tronSession $s = null ) use ( $server, $session ) {
    $r = $server->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array( 'name' => $tool, 'arguments' => $args ) ), $s ?: $session );
    $text = (string)( $r['result']['content'][0]['text'] ?? json_encode( $r['error'] ?? null ) );
    return array( 'ok' => empty( $r['result']['isError'] ) && !isset( $r['error'] ), 'data' => json_decode( $text, true ), 'text' => $text );
};
$typed = function ( string $tool, array $r ) {
    $u = tronUnitCatalog::byTool( $tool );
    return $r['ok'] && $u && is_array( $r['data'] ) && !tronSchemaValidator::validate( tronUnitTypes::schema( $u['out'] ), $r['data'] )
        && strpos( $r['text'], '"Array"' ) === false;
};

// tools
$names = array_map( function ( $t ) { return $t->name; }, $server->registry()->tools() );
$units = tronUnitCatalog::units();
$missing = array_diff( array_column( $units, 'tool' ), $names );
$check( !$missing, count( $units ) . ' units registered' . ( $missing ? ' (missing: ' . implode( ', ', $missing ) . ')' : '' ) );
$check( in_array( 'tron_pipe', $names, true ), 'tron_pipe registered' );

// ini
foreach ( array( $site, $adminSa ) as $sa )
{
    $r = $call( 'ini_resolve', array( 'file' => 'site', 'block' => 'DesignSettings', 'setting' => 'SiteDesign', 'siteaccess' => $sa ) );
    $check( $typed( 'ini_resolve', $r ) && $r['data']['found'], "ini.resolve site.ini DesignSettings/SiteDesign [$sa] = " . json_encode( $r['data']['value'] ?? null ) . ' from ' . ( $r['data']['winner'] ?? '?' ) . ' (' . count( $r['data']['files'] ?? array() ) . ' files)' );
}
$r = $call( 'ini_resolve', array( 'file' => 'site', 'block' => 'DatabaseSettings', 'setting' => 'Password', 'siteaccess' => $site ) );
$check( $r['ok'] && in_array( $r['data']['value'], array( '[redacted]', '', null ), true ) && strpos( $r['text'], 'Password' ) !== false, 'ini.resolve DatabaseSettings/Password is redacted' );
$r = $call( 'ini_diff', array( 'file' => 'site', 'a' => $site, 'b' => $adminSa, 'limit' => 5 ) );
$check( $typed( 'ini_diff', $r ) && $r['data']['total'] > 0, "ini.diff site.ini $site vs $adminSa: " . json_encode( $r['data']['counts'] ?? null ) );
$r = $call( 'ini_list', array( 'file' => 'explayouts', 'siteaccess' => $site ) );
$check( $typed( 'ini_list', $r ) && $r['data']['total'] > 0, 'ini.list explayouts.ini: ' . ( $r['data']['total'] ?? 0 ) . ' blocks' );

// nodes
$r = $call( 'node_find', array( 'path' => $landing, 'depth' => 1, 'limit' => 5 ) );
$check( $typed( 'node_find', $r ), "node.find path=$landing: " . ( $r['data']['total'] ?? 0 ) . ' nodes below' );
$r = $call( 'node_find', array( 'class' => 'article', 'limit' => 3 ) );
$check( $typed( 'node_find', $r ) && ( $r['data']['total'] ?? 0 ) > 0, 'node.find class=article: ' . ( $r['data']['total'] ?? 0 ) . ' articles, first ' . implode( ', ', array_column( $r['data']['items'] ?? array(), 'node_id' ) ) );
$article = $r['data']['items'][0]['node_id'] ?? 2;
$r = $call( 'node_read', array( 'node_id' => $article ) );
$check( $typed( 'node_read', $r ), "node.read $article: class {$r['data']['class_identifier']}, " . count( $r['data']['attributes'] ?? array() ) . ' attributes (' . implode( ', ', array_map( function ( $a ) { return $a['identifier'] . ':' . gettype( $a['value'] ); }, $r['data']['attributes'] ?? array() ) ) . ')' );
$r = $call( 'node_read', array( 'node_id' => 2 ) );
$check( $typed( 'node_read', $r ), 'node.read 2 (front page): ' . ( $r['data']['class_identifier'] ?? '?' ) );
$r = $call( 'node_tree', array( 'node_id' => 2, 'limit' => 10 ) );
$check( $typed( 'node_tree', $r ) && $r['data']['total'] > 0, 'node.tree 2: ' . ( $r['data']['total'] ?? 0 ) . ' children' );
$r = $call( 'node_versions', array( 'node_id' => $article ) );
$check( $typed( 'node_versions', $r ), "node.versions $article: " . ( $r['data']['total'] ?? 0 ) . ' versions, current ' . ( $r['data']['current_version'] ?? '?' ) );

// templates
foreach ( array( $site, $adminSa ) as $sa )
{
    $r = $call( 'tpl_resolve', array( 'node_id' => $article, 'siteaccess' => $sa ) );
    $check( $typed( 'tpl_resolve', $r ), "tpl.resolve node $article full [$sa]: " . ( $r['data']['winner']['path'] ?? '?' ) . ' by ' . ( $r['data']['winner']['by'] ?? '?' ) . ', ' . count( $r['data']['overrides'] ?? array() ) . ' overrides tried, designs ' . implode( ' > ', array_slice( $r['data']['designs'] ?? array(), 0, 4 ) ) );
}
$r = $call( 'tpl_resolve', array( 'template' => 'pagelayout.tpl', 'siteaccess' => $site ) );
$check( $typed( 'tpl_resolve', $r ), "tpl.resolve pagelayout.tpl [$site]: " . ( $r['data']['winner']['path'] ?? '?' ) );
$winner = $call( 'tpl_resolve', array( 'node_id' => $article, 'siteaccess' => $site ) )['data']['winner']['path'] ?? null;
if ( $winner )
{
    $r = $call( 'tpl_source', array( 'path' => $winner, 'to_line' => 5 ) );
    $check( $typed( 'tpl_source', $r ), "tpl.source $winner: {$r['data']['lines']} lines" );
    $r = $call( 'tpl_lint', array( 'path' => $winner ) );
    $check( $typed( 'tpl_lint', $r ), "tpl.lint $winner: ok=" . json_encode( $r['data']['ok'] ?? null ) . ', ' . count( $r['data']['errors'] ?? array() ) . ' errors, ' . count( $r['data']['operators'] ?? array() ) . ' operators, unknown ' . json_encode( $r['data']['unknown'] ?? null ) );
}
$r = $call( 'tpl_lint', array( 'source' => "{if \$x}\n{\$x|wash}\n" ) );
$check( $r['ok'] && !$r['data']['ok'] && $r['data']['errors'], 'tpl.lint finds the missing {/if}: ' . ( $r['data']['errors'][0] ?? '' ) );
$r = $call( 'tpl_source', array( 'path' => 'settings/override/site.ini.append.php' ) );
$check( !$r['ok'] && strpos( $r['text'], 'not a .tpl file' ) !== false, 'tpl.source refuses a settings file' );
$r = $call( 'tpl_source', array( 'path' => 'design/standard/templates/../../../settings/override/site.ini.append.php' ) );
$check( !$r['ok'], 'tpl.source refuses .. paths' );

// classes
$r = $call( 'class_list', array( 'limit' => 500 ) );
$check( $typed( 'class_list', $r ) && $r['data']['total'] > 0, 'class.list: ' . ( $r['data']['total'] ?? 0 ) . ' classes' );
$r = $call( 'class_describe', array( 'identifier' => 'article' ) );
$check( $typed( 'class_describe', $r ), 'class.describe article: ' . count( $r['data']['attributes'] ?? array() ) . ' attributes' );
$r2 = $call( 'class_diff', array( 'identifier' => 'article', 'other_definition' => $r['data'] ) );
$check( $typed( 'class_diff', $r2 ) && $r2['data']['total'] === 0, 'class.diff article with its own describe: no difference' );
$r3 = $call( 'class_diff', array( 'identifier' => 'article', 'other' => 'folder' ) );
$check( $typed( 'class_diff', $r3 ) && $r3['data']['total'] > 0, 'class.diff article vs folder: ' . json_encode( $r3['data']['counts'] ?? null ) );

// layouts
$r = $call( 'layout_list' );
$check( $typed( 'layout_list', $r ) && $r['data']['total'] > 0, 'layout.list: ' . ( $r['data']['total'] ?? 0 ) . ' published layouts' );
$shared = $call( 'layout_list', array( 'shared' => true ) );
$check( $typed( 'layout_list', $shared ) && $shared['data']['total'] > 0, 'shared layouts: ' . implode( ', ', array_map( function ( $l ) { return $l['id'] . ' ' . $l['name']; }, $shared['data']['items'] ?? array() ) ) );
$t = $call( 'layout_rule_resolve', array( 'path' => $landing ) );
$check( $typed( 'layout_rule_resolve', $t ) && $t['data']['layout_id'] > 0, "rule.resolve $landing: layout {$t['data']['layout_id']} {$t['data']['layout_identifier']} by {$t['data']['source']} (rule {$t['data']['rule_id']}, " . count( $t['data']['tried'] ?? array() ) . ' rules tried)' );
$l = $call( 'layout_read', array( 'layout_id' => (int)( $t['data']['layout_id'] ?? 0 ) ) );
$linked = array_values( array_filter( $l['data']['zones'] ?? array(), function ( $z ) { return $z['linked'] !== null; } ) );
$check( $typed( 'layout_read', $l ), "layout.read {$t['data']['layout_id']}: " . count( $l['data']['zones'] ?? array() ) . ' zones, ' . count( $linked ) . ' linked (' . implode( ', ', array_map( function ( $z ) { return $z['identifier'] . ' -> ' . $z['source']['layout_identifier'] . '/' . $z['source']['zone'] . ' ' . count( $z['blocks'] ) . ' blocks'; }, $linked ) ) . ')' );
$r = $call( 'layout_rule_list', array( 'enabled' => true ) );
$check( $typed( 'layout_rule_list', $r ), 'rule.list: ' . ( $r['data']['total'] ?? 0 ) . ' enabled rules' );
$r = $call( 'layout_block_definitions' );
$check( $typed( 'layout_block_definitions', $r ) && $r['data']['total'] > 0, 'block.definitions: ' . ( $r['data']['total'] ?? 0 ) . ' block types' );

// roles
$r = $call( 'role_describe', array( 'role' => 'Anonymous' ) );
$check( $typed( 'role_describe', $r ), 'role.describe Anonymous: ' . count( $r['data']['policies'] ?? array() ) . ' policies, ' . count( $r['data']['assignments'] ?? array() ) . ' assignments' );
$r = $call( 'role_user_find', array( 'text' => 'admin' ) );
$check( $typed( 'role_user_find', $r ) && strpos( $r['text'], '@' ) === false, 'user.find admin: ' . ( $r['data']['total'] ?? 0 ) . ' accounts, no e-mail address in the answer' );

// pipe
$p = $call( 'tron_pipe', array( 'pipe' => 'node.find class=article limit 3 | node.read | tpl.resolve siteaccess=' . $site ) );
$check( $p['ok'] && ( $p['data']['count'] ?? 0 ) === 3 && ( $p['data']['out'] ?? '' ) === 'template-chain', 'pipe node.find class=article limit 3 | node.read | tpl.resolve: ' . ( $p['data']['count'] ?? 0 ) . ' template chains in ' . ( $p['data']['ms'] ?? '?' ) . ' ms, ' . ( $p['data']['calls'] ?? 0 ) . ' calls' );
foreach ( $p['data']['results'] ?? array() as $c )
    echo "     node {$c['node_id']}: {$c['winner']['path']} ({$c['winner']['by']})\n";
$p = $call( 'tron_pipe', array( 'pipe' => "node.find path=$landing depth=1 limit=3 | rule.resolve | layout.read" ) );
$check( $p['ok'] && ( $p['data']['out'] ?? '' ) === 'layout', 'pipe node.find path | rule.resolve | layout.read: layouts ' . implode( ', ', array_column( $p['data']['results'] ?? array(), 'identifier' ) ) );

// permissions
$noroleLogin = getenv( 'TRON_RUN_NOROLE' ) ?: 'tron01norole';
$norole = $users->byLogin( $noroleLogin );
if ( $norole )
{
    $s = new tronSession( new tronPrincipal( (int)$norole['id'], $norole['login'] ), 'stdio', false, tronProtocol::LATEST, 'units-proof' );
    $r = $call( 'ini_resolve', array( 'file' => 'site', 'block' => 'SiteSettings', 'setting' => 'SiteName' ), $s );
    $check( !$r['ok'] && strpos( $r['text'], 'Permission denied' ) !== false, "ini.resolve as $noroleLogin is denied" );
    $r = $call( 'tron_pipe', array( 'pipe' => 'class.list' ), $s );
    $check( !$r['ok'], "tron_pipe class.list as $noroleLogin is refused" );
}
else
    echo "SKIP permission checks: no user $noroleLogin\n";

printf( "\n%s: %d passed, %d failed, %.1f s\n", $fails ? 'FAIL' : 'PASS', $passes, $fails, microtime( true ) - $t0 );
return $fails ? 1 : 0;
