<?php
/**
 * Secrets-blind answers (doc/redaction.md): each kind masked and counted, no false alarms on what tron's answers are
 * made of (dates, weeks, ids, versions, ticket refs), the canaries planted in tickets, comments, links, content,
 * Katalyst rows and the INI never in any answer of any tool, resource, prompt or the initialize instructions, and
 * the audit records that redaction happened (counts and kinds, never the values).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

require_once dirname( __DIR__ ) . '/lib/fuzz.php';
require_once dirname( __DIR__ ) . '/lib/fuzzworld.php';

final class TronRedactionTest extends TronTestCase
{
    public function testEachKindIsMaskedAndCounted(): void
    {
        $cases = array(
            array( 'mail me at jane.doe+x@example.co.uk now', 'mail me at [redacted:email] now', 'email' ),
            array( 'call +1 (555) 010-9999 today', 'call [redacted:phone] today', 'phone' ),
            array( 'Tel: 030 1234567', 'Tel: [redacted:phone]', 'phone' ),
            array( 'password=hunter2', 'password=[redacted:secret]', 'secret' ),
            array( 'client_secret: "a b"', 'client_secret: [redacted:secret]', 'secret' ),
            array( 'redis://app:S3cret@cache.example.net:6379/0', 'redis://[redacted:dsn]@cache.example.net:6379/0', 'dsn' ),
            array( 'pgsql:host=db;dbname=x;password=y', '[redacted:dsn]', 'dsn' ),
            array( "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----", '[redacted:key]', 'key' ),
            array( 'AKIAABCDEFGHIJKLMNOP', '[redacted:key]', 'key' ),
            array( 'tron_abc123def456_ABCDEFGHIJKLMNOPQRSTUV', '[redacted:token]', 'token' ),
            array( 'glpat-abcdefghijklmnopqrst', '[redacted:token]', 'token' ),
            array( 'Bearer abc.def.ghi-jklmnop', 'Bearer [redacted:token]', 'token' ),
        );
        foreach ( $cases as $c )
        {
            $counts = array();
            $this->assertSame( $c[1], tronRedactor::maskText( $c[0], $counts ), $c[0] );
            $this->assertSame( array( $c[2] => 1 ), $counts, "count for {$c[0]}" );
        }
        $counts = array();
        $v = tronRedactor::output( array( 'email' => 'x', 'phone' => '1', 'password' => 'p', 'token_id' => 4, 'passed' => 'yes', 'n' => array( 'mail' => 'y' ) ), $counts );
        $this->assertSame( array( 'email' => '[redacted:email]', 'phone' => '[redacted:phone]', 'password' => '[redacted:secret]', 'token_id' => 4, 'passed' => 'yes', 'n' => array( 'mail' => '[redacted:email]' ) ), $v );
        $this->assertSame( array( 'email' => 2, 'phone' => 1, 'secret' => 1 ), $counts );
        $counts = array();
        $json = tronRedactor::output( '{"a":"x@example.org","b":{}}', $counts );
        $this->assertSame( '{"a":"[redacted:email]","b":{}}', $json, 'JSON text stays valid JSON' );
    }

    public function testNoFalseAlarms(): void
    {
        $clean = array( '2026-11-24', '2026-W48', '2026-11-24T12:00:00Z', 'EXP-123', '7X-2026-00012', 'gb-20261124T101500Z-cloud-1a2b',
                        'claude@cloud#12', 'se7enxweb/tron@1a2b3c4', 'version 0.0.0.3', 'PASS: 12 checks', 'the passport office',
                        'token_id 5', 'https://example.org/path?x=1', '+3 more', 'tron 0.0.0.2 over 2025-11-25', 'node 43, object 41, 1795521600',
                        'Ticket #1234567 and #7654321', 'a total of 10 000 000 rows' );
        foreach ( $clean as $s )
        {
            $counts = array();
            $this->assertSame( $s, tronRedactor::maskText( $s, $counts ), 'unchanged' );
            $this->assertSame( array(), $counts, $s );
        }
        // the tool definitions themselves pass unharmed
        $f = TronFixture::build();
        $counts = array();
        foreach ( $f['server']->registry()->tools() as $t )
            tronRedactor::output( $t->definition( true ), $counts );
        $this->assertSame( array(), $counts, 'no tool definition looks like a secret' );
    }

    /** Every tool, resource, prompt and the instructions, as the administrator: no canary leaves. */
    public function testCanariesNeverLeave(): void
    {
        $needles = TronFuzz::canaryNeedles();
        foreach ( array( 'tables', 'bridge' ) as $kind )
        {
            $w = TronFuzzWorld::build( $kind );
            $s = TronFixture::session( TronFixture::ADMIN );
            $answers = array( $w->server->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
                                                         'params' => array( 'protocolVersion' => '2025-11-25' ) ), $s ) );
            $hints = $w->hints();
            $fz = new TronFuzz( 7 );
            $calls = 0;
            foreach ( $w->server->registry()->tools() as $t )
            {
                foreach ( range( 1, 12 ) as $i )
                {
                    $args = $fz->valid( $t->inputSchema, $hints, 0 );
                    $answers[] = TronFixture::call( $w->server, $s, $t->name, is_array( $args ) ? $args : array() );
                    $calls++;
                }
            }
            foreach ( array( 'centre://ticket/1', 'centre://ticket/2', 'centre://board/main', 'tron://status', 'tron://programs' ) as $uri )
                $answers[] = $w->server->handle( array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/read', 'params' => array( 'uri' => $uri ) ), $s );
            foreach ( array( 'centre_weekly_review', 'centre_triage' ) as $p )
                $answers[] = $w->server->handle( array( 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'prompts/get', 'params' => array( 'name' => $p ) ), $s );
            $ok = 0;
            foreach ( $answers as $a )
            {
                $this->assertSame( array(), TronFuzz::check( $a, null, TronFixture::ADMIN, $needles ), "$kind: " . substr( (string)json_encode( $a ), 0, 300 ) );
                if ( isset( $a['result'] ) && empty( $a['result']['isError'] ) )
                    $ok++;
            }
            $this->assertTrue( $ok > 40, "$kind: $ok answers with data were searched" );
            $all = implode( "\n", TronFuzz::strings( $answers ) );
            $this->assertContains( '[redacted:', $all, 'the canaries were reached and masked' );

            // the audit says what was masked, never what
            $kinds = array();
            foreach ( $w->audit->entries as $e )
            {
                $d = json_decode( (string)$e['detail'], true );
                foreach ( (array)( $d['redacted'] ?? array() ) as $k => $n )
                    $kinds[$k] = ( $kinds[$k] ?? 0 ) + $n;
                foreach ( $needles as $n )
                    $this->assertNotContains( $n, (string)json_encode( $e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "audit entry {$e['target']}" );
            }
            foreach ( $kind === 'tables' ? array( 'secret', 'email', 'phone', 'dsn', 'key', 'token' ) : array( 'secret', 'email', 'token' ) as $k )
                $this->assertTrue( ( $kinds[$k] ?? 0 ) > 0, "$kind: the audit counts $k: " . json_encode( $kinds ) );
        }
    }

    public function testOutputRedactionCanBeNarrowedOrSwitchedOff(): void
    {
        $f = TronFixture::build( array( 'settings' => array( 'RedactionSettings' => array( 'Kinds' => array( 'email' ) ) ) ) );
        $t = json_decode( TronFixture::call( $f['server'], TronFixture::session( TronFixture::ADMIN ), 'centre_ticket_create',
                                             array( 'title' => 'x', 'body' => 'a@example.org password=p1' ) )['result']['content'][0]['text'], true );
        $this->assertSame( '[redacted:email] password=p1', $t['body'], 'only the listed kinds' );
        $f = TronFixture::build( array( 'settings' => array( 'RedactionSettings' => array( 'Output' => 'disabled' ) ) ) );
        $t = json_decode( TronFixture::call( $f['server'], TronFixture::session( TronFixture::ADMIN ), 'centre_ticket_create',
                                             array( 'title' => 'x', 'body' => 'a@example.org' ) )['result']['content'][0]['text'], true );
        $this->assertSame( 'a@example.org', $t['body'], 'switched off' );
    }
}
