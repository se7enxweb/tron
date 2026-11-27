<?php
/**
 * serve --user=<login> is disabled by default ([StdioSettings] AllowLoginOption), a token always works, and an
 * override can enable --user again: in tronStdioIdentity and over real stdio. call, token:create and the
 * tron-skills.php commands that act as a user follow the same rule (0.0.0.15).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

final class TronStdioIdentityTest extends TronTestCase
{
    private const ENABLED = array( 'StdioSettings' => array( 'AllowLoginOption' => 'enabled' ) );
    private const DISABLED = array( 'StdioSettings' => array( 'AllowLoginOption' => 'disabled' ) );

    /** @var array */ private $world;

    public function setUp(): void
    {
        $this->world = TronFixture::build();
    }

    private function resolve( array $settings, string $token, string $login ): array
    {
        return tronStdioIdentity::resolve( $settings, $token, $login, $this->world['tokens'], $this->world['users'] );
    }

    public function testShippedDefaultIsDisabled(): void
    {
        $ini = tronSystemInventory::parseIni( (string)file_get_contents( dirname( __DIR__, 2 ) . '/settings/tron.ini.append.php' ) );
        $this->assertSame( 'disabled', $ini['StdioSettings']['AllowLoginOption'] ?? null, 'settings/tron.ini.append.php ships it disabled' );
        $this->assertFalse( tronStdioIdentity::loginAllowed( $ini ) );
        $this->assertFalse( tronStdioIdentity::loginAllowed( array() ), 'no setting at all: disabled' );
        $this->assertFalse( tronStdioIdentity::loginAllowed( array( 'StdioSettings' => array( 'AllowLoginOption' => 'true' ) ) ), 'only "enabled" enables it' );
        $this->assertTrue( tronStdioIdentity::loginAllowed( array( 'StdioSettings' => array( 'AllowLoginOption' => ' Enabled ' ) ) ) );
    }

    public function testUserIsRefusedWhileDisabled(): void
    {
        foreach ( array( 'shipped' => self::DISABLED, 'absent' => array() ) as $case => $settings )
        {
            list( $p, $code, $line ) = $this->resolve( $settings, '', 'admin' );
            $this->assertSame( null, $p, "$case: no principal" );
            $this->assertSame( 1, $code, "$case: exit 1" );
            $this->assertContains( 'AllowLoginOption', $line, "$case: names the setting" );
            $this->assertContains( 'TRON_TOKEN', $line, "$case: says how to give a token" );
        }
        list( , $code, $line ) = $this->resolve( self::DISABLED, '', '' );
        $this->assertSame( 2, $code, 'nothing given: usage' );
        $this->assertNotContains( '--user', $line, 'the usage line does not offer --user while it is disabled' );
    }

    public function testUserWorksWhenAnOverrideEnablesIt(): void
    {
        list( $p, $code ) = $this->resolve( self::ENABLED, '', 'admin' );
        $this->assertSame( 0, $code );
        $this->assertSame( TronFixture::ADMIN, $p->userId );
        $this->assertSame( 0, $p->tokenId, 'named on the command line, no token' );
        list( $p, $code, $line ) = $this->resolve( self::ENABLED, '', 'gone' );
        $this->assertSame( null, $p, 'a disabled user stays refused' );
        $this->assertSame( 1, $code );
        $this->assertContains( "no enabled user 'gone'", $line );
        list( , , $line ) = $this->resolve( self::ENABLED, '', '' );
        $this->assertContains( '--user', $line, 'the usage line offers --user where it is enabled' );
    }

    public function testTokenWorksAndWinsOverUser(): void
    {
        $t = $this->world['tokens']->create( TronFixture::EDITOR, 'stdio test', 30, array( '*' ) );
        foreach ( array( 'disabled' => self::DISABLED, 'enabled' => self::ENABLED, 'absent' => array() ) as $case => $settings )
        {
            list( $p, $code ) = $this->resolve( $settings, $t['token'], 'admin' );
            $this->assertSame( 0, $code, "$case: token accepted" );
            $this->assertSame( TronFixture::EDITOR, $p->userId, "$case: the token's user, not --user" );
            $this->assertSame( $t['id'], $p->tokenId );
        }
        $this->world['tokens']->revoke( (string)$t['id'] );
        list( $p, $code, $line ) = $this->resolve( self::ENABLED, $t['token'], 'admin' );
        $this->assertSame( null, $p, 'a revoked token does not fall back to --user' );
        $this->assertSame( 1, $code );
        $this->assertContains( 'the token was refused', $line );
    }

    /** call and the tron-skills.php commands use the same rule as serve, and their refusals name the command. */
    public function testOtherCommandsNameThemselves(): void
    {
        foreach ( array( 'call', 'tron-skills.php run' ) as $what )
        {
            list( $p, $code, $line ) = tronStdioIdentity::resolve( self::DISABLED, '', 'admin', $this->world['tokens'], $this->world['users'], $what );
            $this->assertSame( null, $p, "$what --user: refused" );
            $this->assertSame( 1, $code );
            $this->assertContains( "$what --user is disabled", $line );
            list( , $code, $line ) = tronStdioIdentity::resolve( self::DISABLED, '', '', $this->world['tokens'], $this->world['users'], $what );
            $this->assertSame( 2, $code );
            $this->assertContains( "tron $what needs a token", $line );
        }
    }

    /** token:create: who may issue a token for whom, with which scopes (tronStdioIdentity::mayIssue). */
    public function testWhoMayIssueATokenForWhom(): void
    {
        $access = TronFixture::access();
        $editor = new tronPrincipal( TronFixture::EDITOR, 'editor', 5, array( '*' ) );
        $scoped = new tronPrincipal( TronFixture::EDITOR, 'editor', 6, array( 'centre' ) );
        $admin = new tronPrincipal( TronFixture::ADMIN, 'admin', 7, array( 'centre' ) );
        $this->assertSame( '', tronStdioIdentity::mayIssue( null, TronFixture::ADMIN, array( '*' ), $access ), 'AllowLoginOption enabled: as before' );
        $this->assertSame( '', tronStdioIdentity::mayIssue( $editor, TronFixture::EDITOR, array( '*' ), $access ), 'a token for oneself' );
        $this->assertContains( 'only for themselves', tronStdioIdentity::mayIssue( $editor, TronFixture::ADMIN, array( '*' ), $access ), 'not for another user without tron/admin' );
        $this->assertSame( '', tronStdioIdentity::mayIssue( $scoped, TronFixture::EDITOR, array( 'centre' ), $access ), 'within its own scopes' );
        $this->assertContains( 'cannot create one for the scope *', tronStdioIdentity::mayIssue( $scoped, TronFixture::EDITOR, array( '*' ), $access ), 'no wider scope than its own' );
        $this->assertSame( '', tronStdioIdentity::mayIssue( $admin, TronFixture::EDITOR, array( '*' ), $access ), 'tron/admin: for another user, any scope' );
    }

    /** Runs the stdio fixture, sends initialize and tools/list, and returns exit code, stderr and the answers. */
    private function serve( array $args, array $env, string $db ): array
    {
        $cmd = array_merge( array( PHP_BINARY, '-d', 'display_errors=stderr', dirname( __DIR__ ) . '/fixtures/stdio_identity_server.php' ), $args );
        $envAll = getenv() ?: array();
        unset( $envAll['TRON_TOKEN'], $envAll['TRON_FIXTURE_LOGIN_OPTION'] );
        $envAll = array_merge( $envAll, array( 'TRON_FIXTURE_DB' => $db ), $env );
        $proc = proc_open( $cmd, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, $envAll );
        if ( !is_resource( $proc ) )
            $this->fail( 'could not start the stdio identity fixture' );
        $init = array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array(
            'protocolVersion' => tronProtocol::LATEST, 'capabilities' => new stdClass(), 'clientInfo' => array( 'name' => 'test', 'version' => '1' ) ) );
        @fwrite( $pipes[0], json_encode( $init ) . "\n" . json_encode( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ) ) . "\n"
                            . json_encode( array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list' ) ) . "\n" );
        @fclose( $pipes[0] );
        $stdout = (string)stream_get_contents( $pipes[1] );
        $stderr = (string)stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        $code = proc_close( $proc );
        $answers = array();
        foreach ( array_filter( explode( "\n", $stdout ) ) as $l )
        {
            $d = json_decode( $l, true );
            if ( isset( $d['id'] ) )
                $answers[$d['id']] = $d;
        }
        return array( $code, $stderr, $answers, $stdout );
    }

    public function testOverRealStdio(): void
    {
        $db = TronFixture::tmp( 'stdio-identity' ) . '/tron.sqlite';
        if ( is_file( $db ) )
            unlink( $db );
        $pdo = new tronDbPdo( new PDO( 'sqlite:' . $db ) );
        tronSchema::install( $pdo );
        $t = ( new tronTokenService( $pdo, new tronArrayUserDirectory( TronFixture::users() ), function () { return TronFixture::NOW; } ) )
            ->create( TronFixture::EDITOR, 'stdio test', 30, array( '*' ) );

        foreach ( array( 'absent' => array(), 'disabled' => array( 'TRON_FIXTURE_LOGIN_OPTION' => 'disabled' ) ) as $case => $env )
        {
            list( $code, $err, $answers, $stdout ) = $this->serve( array( '--user=admin' ), $env, $db );
            $this->assertSame( 1, $code, "$case: --user=admin exits 1" );
            $this->assertContains( 'AllowLoginOption', $err, "$case: the reason is on stderr" );
            $this->assertSame( '', $stdout, "$case: nothing on stdout, the client sees no server" );
        }

        list( $code, $err, $answers ) = $this->serve( array( '--user=admin' ), array( 'TRON_FIXTURE_LOGIN_OPTION' => 'enabled' ), $db );
        $this->assertSame( 0, $code, 'enabled in an override: --user serves' );
        $this->assertContains( 'serving stdio as admin', $err );
        $this->assertTrue( isset( $answers[1]['result']['protocolVersion'] ), 'initialize answered' );
        $this->assertTrue( count( $answers[2]['result']['tools'] ?? array() ) > 0, 'tools/list answered' );

        list( $code, $err, $answers ) = $this->serve( array( '--user=admin' ), array( 'TRON_TOKEN' => $t['token'] ), $db );
        $this->assertSame( 0, $code, 'a token serves with the default settings' );
        $this->assertContains( 'serving stdio as editor', $err, "the token's user, not --user" );
        $this->assertTrue( isset( $answers[1]['result']['protocolVersion'] ), 'initialize answered' );
        $this->assertTrue( count( $answers[2]['result']['tools'] ?? array() ) > 0, 'tools/list answered' );

        list( $code, $err, , $stdout ) = $this->serve( array(), array( 'TRON_TOKEN' => 'tron_bogus' ), $db );
        $this->assertSame( 1, $code, 'a bad token exits 1' );
        $this->assertContains( 'the token was refused', $err );
        $this->assertSame( '', $stdout );
        unlink( $db );
    }
}
