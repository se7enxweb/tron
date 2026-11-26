<?php
/**
 * The katalyst tools (columns that leave the server, masking, filters) and the core tools tron_whoami and
 * tron_status with their resources.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

final class TronKatalystCoreToolsTest extends TronTestCase
{
    private function data( array $f, int $user, string $tool, array $args = array() )
    {
        $r = TronFixture::call( $f['server'], TronFixture::session( $user ), $tool, $args );
        if ( !empty( $r['result']['isError'] ) )
            $this->fail( "$tool failed: " . $r['result']['content'][0]['text'] );
        return TronFixture::data( $r );
    }

    public function testPlans(): void
    {
        $f = TronFixture::build();
        $p = $this->data( $f, TronFixture::OPERATOR, 'katalyst_plans_list' );
        $this->assertSame( 2, $p['total'], 'gone plans are left out by default' );
        $this->assertSame( array( 1, 2 ), array_column( $p['items'], 'id' ) );
        $this->assertSame( false, $p['items'][0]['hidden'] );
        $this->assertSame( 1, $this->data( $f, TronFixture::OPERATOR, 'katalyst_plans_list', array( 'gone' => true ) )['total'] );
        $this->assertSame( array( 2 ), array_column( $this->data( $f, TronFixture::OPERATOR, 'katalyst_plans_list', array( 'hidden' => true ) )['items'], 'id' ) );
        $this->assertSame( array( 1 ), array_column( $this->data( $f, TronFixture::OPERATOR, 'katalyst_plans_list', array( 'source' => 'plesk' ) )['items'], 'id' ) );
        $this->assertSame( TronKatalystCoreToolsTest::keys( tronKatalystProgram::PLAN_COLUMNS ), TronKatalystCoreToolsTest::keys( array_keys( $p['items'][0] ) ) );
    }

    public function testServicesMaskDomainsWithoutDetail(): void
    {
        $f = TronFixture::build();
        $plain = $this->data( $f, TronFixture::OPERATOR, 'katalyst_services_list' );
        $this->assertSame( 2, $plain['total'] );
        $this->assertSame( 'ex****e.com', $plain['items'][0]['domain'] );
        $this->assertSame( 's***.example.org', $plain['items'][1]['domain'] );
        $this->assertSame( '***.example.net', tronKatalystProgram::maskDomain( 'abc.example.net' ) );
        $this->assertSame( 'ex****e', tronKatalystProgram::maskDomain( 'example' ) );
        $this->assertFalse( array_key_exists( 'whmcs_server_ip', $plain['items'][0] ), 'columns outside the list never leave the server' );
        $this->assertNotContains( '192.0.2.10', json_encode( $plain ) );
        $full = $this->data( $f, TronFixture::DETAIL, 'katalyst_services_list' );
        $this->assertSame( 'example.com', $full['items'][0]['domain'] );
        $this->assertSame( array( 12 ), array_column( $this->data( $f, TronFixture::DETAIL, 'katalyst_services_list', array( 'domain' => 'shop' ) )['items'], 'id' ) );
        $this->assertSame( array( 12 ), array_column( $this->data( $f, TronFixture::DETAIL, 'katalyst_services_list', array( 'status' => 'suspended' ) )['items'], 'id' ) );
        $this->assertSame( array( 11 ), array_column( $this->data( $f, TronFixture::DETAIL, 'katalyst_services_list', array( 'customer_id' => 501 ) )['items'], 'id' ) );
        $denied = TronFixture::call( $f['server'], TronFixture::session( TronFixture::OPERATOR ), 'katalyst_services_list', array( 'domain' => 'shop' ) );
        $this->assertTrue( $denied['result']['isError'] );
        $this->assertContains( 'tron/katalyst_detail', $denied['result']['content'][0]['text'] );
    }

    public function testOrders(): void
    {
        $f = TronFixture::build();
        $o = $this->data( $f, TronFixture::OPERATOR, 'katalyst_orders_list' );
        $this->assertSame( array( 32, 31 ), array_column( $o['items'], 'id' ), 'newest first' );
        $this->assertFalse( array_key_exists( 'note', $o['items'][0] ) );
        $this->assertFalse( array_key_exists( 'domain', $o['items'][0] ) );
        $this->assertSame( array( 32 ), array_column( $this->data( $f, TronFixture::OPERATOR, 'katalyst_orders_list', array( 'since' => '2026-11-20' ) )['items'], 'id' ) );
        $this->assertSame( array( 31 ), array_column( $this->data( $f, TronFixture::OPERATOR, 'katalyst_orders_list', array( 'state' => 'paid' ) )['items'], 'id' ) );
        $this->assertSame( 1, $this->data( $f, TronFixture::OPERATOR, 'katalyst_orders_list', array( 'limit' => 1 ) )['offset'] + 1 );
    }

    public function testKatalystLeftOutWithoutSource(): void
    {
        $f = TronFixture::build( array( 'katalyst' => false ) );
        $this->assertSame( null, $f['server']->registry()->findTool( 'katalyst_orders_list' ) );
        $status = $this->data( $f, TronFixture::ADMIN, 'tron_status' );
        $this->assertSame( 'no Katalyst source is configured here', $status['not_registered']['katalyst_orders_list'] );
        $kernelSource = new tronKatalystKernelSource();
        $this->assertFalse( $kernelSource->available(), 'without sevenx_katalyst the kernel source says so' );
        $this->assertContains( 'sevenx_katalyst', $kernelSource->reason() );
    }

    public function testWhoamiAndStatus(): void
    {
        $f = TronFixture::build();
        $w = $this->data( $f, TronFixture::VIEWER, 'tron_whoami' );
        $this->assertSame( array( 'user_id' => TronFixture::VIEWER, 'login' => 'viewer', 'token_id' => 0, 'scopes' => array( '*' ),
                                  'transport' => 'stdio', 'protocol_version' => '2025-11-25' ), $w );
        $s = $this->data( $f, TronFixture::VIEWER, 'tron_status' );
        $this->assertSame( tronVersion::VERSION, $s['tron'] );
        $this->assertSame( tronProtocol::VERSIONS, $s['protocol_versions'] );
        $this->assertSame( 28, $s['tools'] );
        $this->assertFalse( isset( $s['programs'][0]['origin'] ), 'origins only for tron/admin' );
        $a = $this->data( $f, TronFixture::ADMIN, 'tron_status' );
        $this->assertSame( 'builtin', $a['programs'][0]['origin'] );
        $read = $f['server']->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/read', 'params' => array( 'uri' => 'tron://programs' ) ), TronFixture::session( TronFixture::USEONLY ) );
        $this->assertSame( self::builtinPrograms(), array_column( json_decode( $read['result']['contents'][0]['text'], true )['programs'], 'name' ) );
        $list = $f['server']->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/list' ), TronFixture::session( TronFixture::USEONLY ) );
        $this->assertSame( array( 'tron://programs', 'tron://rules/agents', 'tron://rules/combined', 'tron://rules/master', 'tron://rules/status', 'tron://status' ), array_column( $list['result']['resources'], 'uri' ) );
    }

    public static function keys( array $k ): array
    {
        sort( $k );
        return $k;
    }
}
