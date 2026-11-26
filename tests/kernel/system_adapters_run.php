<?php
/**
 * The proving run of the system adapters in a real installation: every adapter against the real kernel, as a real
 * administrator and as the anonymous user, with the refusals, the audit entries and the journal jobs.
 *
 *   php bin/php/ezexec.php <tron>/tests/kernel/system_adapters_run.php --allow-root-user
 *
 * TRON_RUN_ADMIN names the administrator's login (default admin). Writes nothing to the installation: fetches,
 * operators and views run inside a rolled-back transaction, the commands run read-only, and the audit and journal of
 * this run live in an in-memory database. Prints PASS/FAIL per check and a summary; never prints a result's values
 * beyond counts and names.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

// tron's classes from this checkout, ahead of any copy the installation's autoload knows
require_once dirname( __DIR__, 2 ) . '/autoload.php';
spl_autoload_unregister( array( 'tronAutoload', 'load' ) );
spl_autoload_register( array( 'tronAutoload', 'load' ), true, true );

$fails = 0;
$passes = 0;
$check = function ( bool $ok, string $what ) use ( &$fails, &$passes ) {
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $what . "\n";
    $ok ? $passes++ : $fails++;
};

$adminLogin = getenv( 'TRON_RUN_ADMIN' ) ?: 'admin';
$admin = eZUser::fetchByName( $adminLogin );
$anonId = (int)eZINI::instance()->variable( 'UserSettings', 'AnonymousUserID' );
if ( !$admin instanceof eZUser )
{
    echo "FAIL: no user $adminLogin\n";
    return 1;
}
$principals = array( 'admin' => new tronPrincipal( (int)$admin->attribute( 'contentobject_id' ), $adminLogin ),
                     'anonymous' => new tronPrincipal( $anonId, 'anonymous' ) );

$db = tronDbPdo::sqliteMemory();
tronSchema::install( $db );
$audit = new tronAuditMemory();
$access = new tronKernelAccessChecker();
$env = new tronEnvironment( array(), array( 'access' => $access, 'db' => $db ) );
$journal = class_exists( 'tronJournal' ) ? new tronJournal( $db ) : null;
if ( $journal )
    $env->set( 'tron.journal', $journal );
$registry = new tronRegistry();
$registry->begin( 'system' );
( new tronSystemProgram() )->register( $registry, $env );
$counts = $registry->commit();
$check( $counts['tools'] === count( tronSystemCatalog::ADAPTERS ), 'the program registers ' . $counts['tools'] . ' adapters in the kernel' );
$server = new tronServer( $registry, $access, $audit, $env );

$sessions = array();
$call = function ( string $who, string $tool, array $args = array() ) use ( $server, $principals, &$sessions, $env ) {
    if ( !isset( $sessions[$who] ) )
    {
        $sessions[$who] = new tronSession( $principals[$who], 'stdio', false, '2025-11-25', 'kernel-run' );
        if ( class_exists( 'tronJournalHandle' ) )
            tronJournalHandle::attach( $sessions[$who], $env );
    }
    $t0 = microtime( true );
    $r = $server->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array( 'name' => $tool, 'arguments' => $args ) ), $sessions[$who] );
    $ms = round( ( microtime( true ) - $t0 ) * 1000 );
    $text = (string)( $r['result']['content'][0]['text'] ?? json_encode( $r['error'] ?? null ) );
    $ok = empty( $r['result']['isError'] ) && !isset( $r['error'] );
    return array( $ok, $ok ? json_decode( $text, true ) : $text, $ms );
};
$schemaOk = function ( string $tool, $value ) use ( $registry ) {
    $t = $registry->findTool( $tool );
    if ( !$t || !property_exists( $t, 'outputSchema' ) )
        return true;              // a tron without output schemas: nothing to check against
    return $t->outputSchema ? tronSchemaValidator::validate( $t->outputSchema, $value ) === array() : false;
};

// catalog
list( $ok, $v, $ms ) = $call( 'admin', 'system_coverage' );
$check( $ok && $schemaOk( 'system_coverage', $v ) && $v['totals']['points'] > 1000, "system_coverage: {$v['totals']['points']} points, read {$v['totals']['read_pct']} % covered ($ms ms)" );
list( $ok, $v, $ms ) = $call( 'admin', 'system_catalog', array( 'area' => 'fetch_function', 'q' => 'content', 'limit' => 3 ) );
$check( $ok && $v['total'] > 10 && $schemaOk( 'system_catalog', $v ), "system_catalog fetch_function q=content: {$v['total']} hits ($ms ms)" );

// fetch
list( $ok, $v, $ms ) = $call( 'admin', 'system_fetch_describe', array( 'module' => 'content', 'function' => 'list' ) );
$check( $ok && $v['functions'][0]['callable'] === true && count( $v['functions'][0]['params'] ) > 5, 'system_fetch_describe content/list: ' . count( $v['functions'][0]['params'] ?? array() ) . " params ($ms ms)" );
list( $ok, $v, $ms ) = $call( 'admin', 'system_fetch_call', array( 'module' => 'content', 'function' => 'node', 'params' => array( 'node_id' => 2 ) ) );
$check( $ok && (int)$v['result']['node_id'] === 2 && $v['result']['_class'] === 'eZContentObjectTreeNode' && $v['ran_as'] === $adminLogin && $schemaOk( 'system_fetch_call', $v ),
    "system_fetch_call content/node 2 as $adminLogin: node {$v['result']['node_id']} ({$v['result']['class_identifier']}) ($ms ms)" );
list( $ok, $v, $ms ) = $call( 'admin', 'system_fetch_call', array( 'module' => 'content', 'function' => 'list', 'params' => array( 'parent_node_id' => 2, 'limit' => 3 ) ) );
$check( $ok && is_array( $v['result'] ) && count( $v['result'] ) <= 3, 'system_fetch_call content/list parent 2 limit 3: ' . count( (array)$v['result'] ) . " nodes ($ms ms)" );
list( $ok, $v, $ms ) = $call( 'admin', 'system_fetch_call', array( 'module' => 'content', 'function' => 'list_count', 'params' => array( 'parent_node_id' => 2 ) ) );
$check( $ok && is_numeric( $v['result'] ), "system_fetch_call content/list_count: {$v['result']} ($ms ms)" );
list( $ok, $v, $ms ) = $call( 'admin', 'system_fetch_call', array( 'module' => 'user', 'function' => 'current_user' ) );
$check( $ok && (int)$v['result']['contentobject_id'] === $principals['admin']->userId && !isset( $v['result']['password_hash'] ),
    "system_fetch_call user/current_user is the caller, without the password hash ($ms ms)" );
list( $ok, $v ) = $call( 'anonymous', 'system_fetch_call', array( 'module' => 'user', 'function' => 'current_user' ) );
$check( !$ok && strpos( (string)$v, 'Permission denied' ) !== false, 'anonymous has no tron/use: every adapter refuses it' );
list( $ok, $v ) = $call( 'admin', 'system_fetch_call', array( 'module' => 'content', 'function' => 'node', 'params' => array( 'nodeid' => 2 ) ) );
$check( !$ok && strpos( $v, "no parameter 'nodeid'" ) !== false, 'an unknown parameter is refused' );
list( $ok, $v ) = $call( 'admin', 'system_fetch_call', array( 'module' => 'content', 'function' => 'version', 'params' => array( 'object_id' => 1 ) ) );
$check( !$ok && strpos( $v, "needs the parameter 'version_id'" ) !== false, 'a missing required parameter is refused' );
$writeFetch = null;
foreach ( tronSystemProgram::catalog( $env )->search( array( 'area' => 'fetch_function', 'risk' => 'write' ), 1 )['items'] as $p )
    $writeFetch = $p['name'];
if ( $writeFetch )
{
    list( $m, $f ) = explode( '/', $writeFetch );
    list( $ok, $v ) = $call( 'admin', 'system_fetch_call', array( 'module' => $m, 'function' => $f ) );
    $check( !$ok && strpos( $v, 'dry-run twin' ) !== false, "the write fetch $writeFetch is refused" );
}

// operators
list( $ok, $v, $ms ) = $call( 'admin', 'system_operator_eval', array( 'operator' => 'upcase', 'input' => 'tron' ) );
$check( $ok && $v['result'] === 'TRON' && $schemaOk( 'system_operator_eval', $v ), "system_operator_eval upcase: {$v['result']} ($ms ms)" );
list( $ok, $v ) = $call( 'admin', 'system_operator_eval', array( 'operator' => 'shorten', 'input' => 'hello world', 'params' => array( 8 ) ) );
$check( $ok && $v['result'] === 'hello...', "system_operator_eval shorten 8: {$v['result']}" );
list( $ok, $v ) = $call( 'admin', 'system_operator_eval', array( 'operator' => 'wash', 'input' => '<b>x</b>' ) );
$check( $ok && $v['result'] === '&lt;b&gt;x&lt;/b&gt;', 'system_operator_eval wash escapes' );
list( $ok, $v ) = $call( 'admin', 'system_operator_eval', array( 'operator' => 'upcase', 'input' => '{$x|ezini("DatabaseSettings","Password")}' ) );
$check( $ok && $v['result'] === strtoupper( '{$x|ezini("DatabaseSettings","Password")}' ), 'template code in the input is a value, never parsed' );
list( $ok, $v ) = $call( 'admin', 'system_operator_eval', array( 'operator' => 'ezini', 'input' => 'x' ) );
$check( !$ok && strpos( $v, 'ini units' ) !== false, 'ezini is refused' );
list( $ok, $v ) = $call( 'admin', 'system_operator_eval', array( 'operator' => 'fetch', 'input' => 'x' ) );
$check( !$ok && strpos( $v, 'fetch.call' ) !== false, 'fetch as an operator is refused (fetch.call owns it)' );

// modules and views
list( $ok, $v, $ms ) = $call( 'admin', 'system_module_view_describe', array( 'module' => 'content' ) );
$check( $ok && count( $v['views'] ) > 20 && isset( $v['functions']['read'] ), 'system_module_view_describe content: ' . count( $v['views'] ?? array() ) . " views ($ms ms)" );
list( $ok, $v, $ms ) = $call( 'admin', 'system_module_view_read', array( 'module' => 'content', 'view' => 'view', 'params' => array( 'full', 2 ) ) );
$check( $ok && $v['text'] !== '' && $schemaOk( 'system_module_view_read', $v ), 'system_module_view_read content/view full 2: ' . strlen( (string)$v['text'] ) . ' chars, ' . count( $v['links'] ?? array() ) . " links ($ms ms)" );
list( $ok, $v ) = $call( 'admin', 'system_module_view_read', array( 'module' => 'content', 'view' => 'edit', 'params' => array( 1 ) ) );
$check( !$ok && strpos( $v, 'read views only' ) !== false, 'content/edit is refused' );
list( $ok, $v ) = $call( 'admin', 'system_module_view_read', array( 'module' => 'setup', 'view' => 'info' ) );
$check( !$ok, 'setup/info is refused' );

// commands
list( $ok, $v, $ms ) = $call( 'admin', 'system_console_command', array( 'command' => 'exp:exptemplateengines' ) );
$check( $ok && $v['exit_code'] === 0 && $v['stdout'] !== '' && $schemaOk( 'system_console_command', $v ),
    'system_console_command exp:exptemplateengines: exit ' . ( $v['exit_code'] ?? '?' ) . ', ' . strlen( (string)( $v['stdout'] ?? '' ) ) . " bytes, job " . json_encode( $v['job'] ?? null ) . " ($ms ms)" );
if ( $journal && !empty( $v['job'] ) )
{
    $steps = $journal->steps( (int)$v['job'] );
    $check( (bool)array_filter( $steps, function ( $s ) { return ( $s['kind'] ?? '' ) === 'result'; } ), 'the command is a journaled job with its result (' . count( $steps ) . ' steps)' );
}
list( $ok, $v, $ms ) = $call( 'admin', 'system_cli_script', array( 'script' => 'bin/php/exptemplateengines.php' ) );
$check( $ok && $v['exit_code'] === 0, 'system_cli_script bin/php/exptemplateengines.php: exit ' . ( is_array( $v ) ? $v['exit_code'] : $v ) . " ($ms ms)" );
list( $ok, $v ) = $call( 'admin', 'system_console_command', array( 'command' => 'exp:contentcheck', 'args' => array( '--apply' ) ) );
$check( !$ok && strpos( $v, 'applies or forces' ) !== false, '--apply is refused' );
list( $ok, $v ) = $call( 'admin', 'system_console_command', array( 'command' => 'exp:ezfixdata' ) );
$check( !$ok && strpos( $v, 'not allowed' ) !== false, 'a command off the list is refused' );
list( $ok, $v ) = $call( 'anonymous', 'system_console_command', array( 'command' => 'exp:exptemplateengines' ) );
$check( !$ok && strpos( $v, 'Permission denied' ) !== false, 'anonymous may not run commands (setup/setup)' );

// queue
list( $ok, $v, $ms ) = $call( 'admin', 'system_queue_jobs', array( 'limit' => 3 ) );
$check( $ok && $schemaOk( 'system_queue_jobs', $v ), 'system_queue_jobs: installed ' . json_encode( $v['installed'] ?? null ) . ', total ' . ( $v['total'] ?? '?' ) . " ($ms ms)" );
if ( $ok && !empty( $v['items'] ) )
{
    list( $ok2, $j ) = $call( 'admin', 'system_queue_job', array( 'job' => (string)$v['items'][0]['id'] ) );
    $check( $ok2 && isset( $j['job']['type'] ), 'system_queue_job ' . $v['items'][0]['id'] . ': ' . ( $j['job']['type'] ?? '?' ) );
}
list( $ok, $v ) = $call( 'anonymous', 'system_queue_jobs' );
$check( !$ok, 'anonymous may not list queue jobs (expqueue/view)' );

// audit
$entries = $audit->entries;
$check( count( $entries ) >= 25, count( $entries ) . ' audit entries, one per call' );
$denied = array_filter( $entries, function ( $e ) { return ( $e['outcome'] ?? '' ) === 'denied'; } );
$check( count( $denied ) >= 2, count( $denied ) . ' denied calls audited as denied' );

echo ( $fails ? 'FAIL' : 'PASS' ) . ": $passes passed, $fails failed\n";
return $fails ? 1 : 0;
