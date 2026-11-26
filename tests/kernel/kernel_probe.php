<?php
/**
 * Read-only probe of tron's kernel binding in a real installation, before (or after) it is installed there:
 *
 *   cd <installation root>
 *   php bin/php/ezexec.php <tron>/tests/kernel/kernel_probe.php --allow-root-user [--siteaccess=<sa>]
 *
 * Writes nothing: no table is created, no token made, the audit goes to memory. It checks that
 *   - tron's classes load next to the kernel's (from this checkout, also when tron is not an active extension);
 *   - tronDbKernel runs parameterised statements on the installation's engine (quotes included) and sees tables;
 *   - tronKernelUserDirectory and tronKernelAccessChecker answer with the installation's users, roles and
 *     policies: the administrator holds tron/use, the anonymous user does not;
 *   - tronKernel::settings() and the convention scan over the installation's active extensions work, and how long
 *     building a server takes;
 *   - tools/list and tools/call through the real policy check, for the administrator and for anonymous.
 *
 * Environment: TRON_PROBE_ADMIN (default admin) names the administrator account to check.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

require_once dirname( __DIR__, 2 ) . '/autoload.php';

$fails = 0;
$check = function ( bool $ok, string $what ) use ( &$fails ) {
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $what . "\n";
    if ( !$ok )
        $fails++;
};

$db = tronKernel::db();
$driver = $db->driver();
$check( tronSchema::supports( $driver ), "engine '$driver' has a tron schema" );
$adminLogin = getenv( 'TRON_PROBE_ADMIN' ) ?: 'admin';
$n = $db->select( 'SELECT count(*) AS n FROM ezuser WHERE login = :l', array( 'l' => $adminLogin ) );
$check( (int)$n[0]['n'] === 1, "tronDbKernel: a parameterised select finds the account $adminLogin" );
$q = $db->select( 'SELECT count(*) AS n FROM ezuser WHERE login = :l', array( 'l' => "o'brien\\'; --" ) );
$check( (int)$q[0]['n'] === 0, 'tronDbKernel: quotes and backslashes in a value stay data' );
$lim = $db->select( 'SELECT contentobject_id FROM ezuser ORDER BY contentobject_id', array(), 2, 1 );
$check( count( $lim ) === 2, 'tronDbKernel: limit and offset through eZDB' );
$check( $db->tableExists( 'ezuser' ), 'tronDbKernel: tableExists(ezuser)' );
$missing = tronSchema::missing( $db );
echo '     tron tables here: ' . ( $missing ? 'not installed (' . count( $missing ) . ' missing)' : 'installed' ) . "\n";
$check( !$db->tableExists( 'tron_no_such_table_probe' ), 'tronDbKernel: tableExists is false for a missing table' );
// tableExists() must agree with a real query on every tron table (eZTableList() lists only ez* tables on PostgreSQL)
foreach ( array_keys( tronSchema::tables() ) as $t )
{
    $queryable = true;
    try
    {
        $db->select( "SELECT count(*) AS n FROM $t" );
    }
    catch ( tronDbException $e )
    {
        $queryable = false;
    }
    $check( $db->tableExists( $t ) === $queryable, "tronDbKernel: tableExists($t) agrees with the engine (" . ( $queryable ? 'present' : 'absent' ) . ')' );
}
$threw = false;
try
{
    $db->select( 'SELECT * FROM tron_no_such_table_probe' );
}
catch ( tronDbException $e )
{
    $threw = true;
}
$check( $threw, 'tronDbKernel: a failed statement raises tronDbException instead of an error page' );

$users = new tronKernelUserDirectory();
$admin = $users->byLogin( $adminLogin );
$check( $admin !== null && $admin['enabled'], "tronKernelUserDirectory: $adminLogin exists and is enabled" );
$anonId = (int)eZINI::instance()->variable( 'UserSettings', 'AnonymousUserID' );
$anon = $users->byId( $anonId );
$check( $anon !== null, "tronKernelUserDirectory: the anonymous user ($anonId) exists" );

$access = new tronKernelAccessChecker();
$pa = new tronPrincipal( (int)$admin['id'], $admin['login'] );
$pn = new tronPrincipal( $anonId, (string)$anon['login'] );
$check( $access->check( $pa, 'tron', 'use' ) === tronAccessChecker::YES, "roles: $adminLogin holds tron/use" );
$check( $access->check( $pa, 'tron', 'centre_write' ) === tronAccessChecker::YES, "roles: $adminLogin holds tron/centre_write" );
$check( $access->check( $pn, 'tron', 'use' ) === tronAccessChecker::NO, 'roles: anonymous does not hold tron/use' );
$check( $access->check( $pn, 'tron', 'centre_read' ) === tronAccessChecker::NO, 'roles: anonymous does not hold tron/centre_read' );

$t0 = microtime( true );
$settings = tronKernel::settings();
$extensions = tronKernel::extensions();
$audit = new tronAuditMemory();
$server = tronFactory::build( array(
    'settings' => $settings, 'access' => $access, 'audit' => $audit, 'db' => $db, 'users' => $users,
    'extensions' => $extensions, 'services' => array( 'katalyst.source' => function () { return new tronKatalystKernelSource(); } ),
) );
$ms = ( microtime( true ) - $t0 ) * 1000;
$states = array();
foreach ( $server->option( 'programs' ) as $row )
    $states[] = $row['name'] . '@' . $row['origin'] . '=' . $row['state'];
echo '     programs: ' . implode( ', ', $states ) . "\n";
printf( "     server built in %.1f ms over %d active extensions\n", $ms, count( $extensions ) );
$failed = array_filter( $server->option( 'programs' ), function ( $r ) { return $r['state'] === 'failed'; } );
$check( !$failed, 'no program failed to load' );
$check( $ms < 1000, 'building the server takes under a second' );

$sa = new tronSession( $pa, 'stdio', false, tronProtocol::LATEST, 'probe' );
$sn = new tronSession( $pn, 'stdio', false, tronProtocol::LATEST, 'probe' );
$list = $server->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' ), $sa );
$names = array_column( $list['result']['tools'], 'name' );
echo '     tools for ' . $adminLogin . ': ' . count( $names ) . "\n";
$check( in_array( 'tron_status', $names, true ) && in_array( 'centre_ticket_create', $names, true ), "tools/list as $adminLogin shows the core and centre tools" );
$listAnon = $server->handle( array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list' ), $sn );
$check( $listAnon['result']['tools'] === array(), 'tools/list as anonymous is empty' );
$who = $server->handle( array( 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => array( 'name' => 'tron_whoami' ) ), $sa );
$check( empty( $who['result']['isError'] ) && ( $who['result']['structuredContent']['login'] ?? '' ) === $adminLogin, "tools/call tron_whoami as $adminLogin" );
$denied = $server->handle( array( 'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => array( 'name' => 'tron_whoami' ) ), $sn );
$check( !empty( $denied['result']['isError'] ) && strpos( $denied['result']['content'][0]['text'], 'Permission denied' ) !== false, 'tools/call as anonymous is refused' );
$check( count( $audit->entries ) === 2 && $audit->entries[1]['outcome'] === 'denied', 'both calls audited (memory sink)' );

echo ( $fails ? 'FAIL' : 'PASS' ) . ": kernel probe, $fails failed\n";
return $fails ? 1 : 0;
