<?php
/**
 * Versions, the schema validator, tool/resource/prompt declarations, the registry and the redactor.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

final class TronCoreUnitsTest extends TronTestCase
{
    public function testVersionComparesNumerically(): void
    {
        $this->assertSame( -1, tronVersion::compare( '0.0.0.9', '0.0.0.10' ) );
        $this->assertSame( 1, tronVersion::compare( '0.0.1', '0.0.0.99' ) );
        $this->assertSame( 0, tronVersion::compare( '0.3.0', '0.3.0.0' ) );
        $this->assertTrue( tronVersion::isValid( '0.0.0.1' ) );
        $this->assertTrue( tronVersion::isValid( '1.2.3' ) );
        $this->assertFalse( tronVersion::isValid( 'v1.2.3' ) );
        $this->assertSame( trim( (string)file_get_contents( dirname( __DIR__, 2 ) . '/VERSION' ) ), tronVersion::VERSION, 'VERSION file and constant agree' );
    }

    public function testVersionFilesAgree(): void
    {
        $root = dirname( __DIR__, 2 );
        $v = tronVersion::VERSION;
        $this->assertContains( "<version>$v</version>", (string)file_get_contents( "$root/extension.xml" ) );
        $this->assertContains( "'Version' => \"$v\"", (string)file_get_contents( "$root/ezinfo.php" ) );
        $composer = json_decode( (string)file_get_contents( "$root/composer.json" ), true );
        $this->assertSame( 'se7enxweb/tron', $composer['name'] );
        $this->assertSame( 'GPL-2.0-or-later', $composer['license'] );
        foreach ( glob( "$root/programs/*/program.json" ) as $m )
            $this->assertSame( $v, json_decode( (string)file_get_contents( $m ), true )['version'], basename( dirname( $m ) ) );
    }

    public function testProtocolNegotiation(): void
    {
        $this->assertSame( '2025-06-18', tronProtocol::negotiate( '2025-06-18' ) );
        $this->assertSame( '2024-11-05', tronProtocol::negotiate( '2024-11-05' ) );
        $this->assertSame( tronProtocol::LATEST, tronProtocol::negotiate( '1999-01-01' ) );
        $this->assertSame( tronProtocol::LATEST, tronProtocol::negotiate( null ) );
        $this->assertTrue( tronProtocol::allowsBatch( '2025-03-26' ) );
        $this->assertFalse( tronProtocol::allowsBatch( '2025-06-18' ) );
        $this->assertFalse( tronProtocol::hasStructuredContent( '2025-03-26' ) );
        $this->assertTrue( tronProtocol::hasStructuredContent( '2025-11-25' ) );
    }

    public function testSchemaValidator(): void
    {
        $s = array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'id' ), 'properties' => array(
            'id' => array( 'type' => 'integer', 'minimum' => 1 ),
            'name' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 5, 'pattern' => '^[a-z]+$' ),
            'kind' => array( 'type' => 'string', 'enum' => array( 'a', 'b' ), 'default' => 'a' ),
            'tags' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'maxItems' => 2 ),
            'flag' => array( 'type' => 'boolean' ),
        ) );
        $this->assertSame( array(), tronSchemaValidator::validate( $s, array( 'id' => 3, 'name' => 'abc', 'tags' => array( 'x' ) ) ) );
        $this->assertSame( array(), tronSchemaValidator::validate( $s, array( 'id' => 3.0 ) ), '3.0 is an integer' );
        $this->assertContains( 'id: is required', tronSchemaValidator::validate( $s, array() ) );
        $this->assertContains( 'id: must be at least 1', tronSchemaValidator::validate( $s, array( 'id' => 0 ) ) );
        $this->assertContains( 'id: expected integer, got string', tronSchemaValidator::validate( $s, array( 'id' => '1' ) ) );
        $this->assertContains( 'id: expected integer, got number', tronSchemaValidator::validate( $s, array( 'id' => 1.5 ) ) );
        $this->assertContains( 'other: is not a known argument', tronSchemaValidator::validate( $s, array( 'id' => 1, 'other' => 1 ) ) );
        $this->assertContains( 'name: must have at least 2 characters', tronSchemaValidator::validate( $s, array( 'id' => 1, 'name' => 'a' ) ) );
        $this->assertContains( 'name: must have at most 5 characters', tronSchemaValidator::validate( $s, array( 'id' => 1, 'name' => 'abcdef' ) ) );
        $this->assertContains( 'name: does not match the pattern ^[a-z]+$', tronSchemaValidator::validate( $s, array( 'id' => 1, 'name' => 'AB' ) ) );
        $this->assertContains( 'kind: must be one of "a", "b"', tronSchemaValidator::validate( $s, array( 'id' => 1, 'kind' => 'c' ) ) );
        $this->assertContains( 'tags: must have at most 2 items', tronSchemaValidator::validate( $s, array( 'id' => 1, 'tags' => array( 'a', 'b', 'c' ) ) ) );
        $this->assertContains( 'tags[0]: expected string, got integer', tronSchemaValidator::validate( $s, array( 'id' => 1, 'tags' => array( 1 ) ) ) );
        $this->assertContains( 'flag: expected boolean, got string', tronSchemaValidator::validate( $s, array( 'id' => 1, 'flag' => 'true' ) ) );
        $this->assertContains( 'arguments: expected object, got array', tronSchemaValidator::validate( $s, array( 1, 2 ) ) );
        $this->assertSame( 'a', tronSchemaValidator::applyDefaults( $s, array( 'id' => 1 ) )['kind'] );
        $this->assertSame( 'b', tronSchemaValidator::applyDefaults( $s, array( 'id' => 1, 'kind' => 'b' ) )['kind'] );
        // multi-byte length counts characters
        $this->assertSame( array(), tronSchemaValidator::validate( array( 'type' => 'string', 'maxLength' => 3 ), 'äöü' ) );
    }

    public function testToolDefinitionIsChecked(): void
    {
        $h = function () { return array(); };
        $this->assertThrows( function () use ( $h ) { tronTool::define( array( 'name' => 'Bad-Name', 'description' => 'x', 'handler' => $h ) ); }, 'InvalidArgumentException', 'must match' );
        $this->assertThrows( function () use ( $h ) { tronTool::define( array( 'name' => 'ok_name', 'handler' => $h ) ); }, 'InvalidArgumentException', 'description' );
        $this->assertThrows( function () { tronTool::define( array( 'name' => 'ok_name', 'description' => 'x' ) ); }, 'InvalidArgumentException', 'handler' );
        $this->assertThrows( function () use ( $h ) { tronTool::define( array( 'name' => 'ok_name', 'description' => 'x', 'handler' => $h, 'inputSchema' => array( 'type' => 'string' ) ) ); }, 'InvalidArgumentException', 'object schema' );
        $this->assertThrows( function () use ( $h ) { tronTool::define( array( 'name' => 'ok_name', 'description' => 'x', 'handler' => $h, 'policy' => array( 'tron' ) ) ); }, 'InvalidArgumentException', 'policy' );

        $t = tronTool::define( array( 'name' => 'x_write', 'description' => 'd', 'handler' => $h, 'write' => true, 'destructive' => true ) );
        $d = $t->definition();
        $this->assertFalse( $d['annotations']['readOnlyHint'] );
        $this->assertTrue( $d['annotations']['destructiveHint'] );
        $this->assertFalse( $d['annotations']['idempotentHint'] );
        $this->assertFalse( $d['annotations']['openWorldHint'] );
        $this->assertSame( '{}', json_encode( $d['inputSchema']['properties'] ), 'empty properties encode as an object' );
        $r = tronTool::define( array( 'name' => 'x_read', 'description' => 'd', 'handler' => $h ) )->definition();
        $this->assertTrue( $r['annotations']['readOnlyHint'] );
        $this->assertTrue( $r['annotations']['idempotentHint'] );
    }

    public function testResourceTemplatesMatch(): void
    {
        $r = tronResource::define( array( 'uri' => 'centre://ticket/{id}', 'reader' => function () { return array(); } ) );
        $this->assertTrue( $r->isTemplate() );
        $this->assertSame( 'centre', $r->scheme() );
        $this->assertSame( array( 'id' => '42' ), $r->match( 'centre://ticket/42' ) );
        $this->assertSame( null, $r->match( 'centre://ticket/42/x' ) );
        $this->assertSame( null, $r->match( 'centre://board/42' ) );
        $this->assertSame( 'centre://ticket/{id}', $r->definition()['uriTemplate'] );
        $f = tronResource::define( array( 'uri' => 'tron://status', 'reader' => function () { return array(); } ) );
        $this->assertFalse( $f->isTemplate() );
        $this->assertSame( array(), $f->match( 'tron://status' ) );
        $this->assertThrows( function () { tronResource::define( array( 'uri' => 'no-scheme', 'reader' => 'strlen' ) ); }, 'InvalidArgumentException', 'scheme' );
        $this->assertThrows( function () { tronPrompt::define( array( 'name' => 'x_p', 'arguments' => array( array( 'description' => 'no name' ) ), 'renderer' => 'strlen' ) ); }, 'InvalidArgumentException', 'name' );
    }

    public function testRegistryEnforcesNamesAndStaging(): void
    {
        $h = function () { return array(); };
        $reg = new tronRegistry();
        $this->assertThrows( function () use ( $reg, $h ) { $reg->tool( tronTool::define( array( 'name' => 'a_b', 'description' => 'd', 'handler' => $h ) ) ); }, 'LogicException' );

        $reg->begin( 'alpha' );
        $reg->tool( tronTool::define( array( 'name' => 'alpha_one', 'description' => 'd', 'handler' => $h ) ) );
        $this->assertThrows( function () use ( $reg, $h ) { $reg->tool( tronTool::define( array( 'name' => 'beta_one', 'description' => 'd', 'handler' => $h ) ) ); }, 'InvalidArgumentException', 'alpha_*' );
        $this->assertThrows( function () use ( $reg ) { $reg->resource( tronResource::define( array( 'uri' => 'beta://x', 'reader' => 'strlen' ) ) ); }, 'InvalidArgumentException', 'alpha://' );
        $this->assertThrows( function () use ( $reg, $h ) { $reg->tool( tronTool::define( array( 'name' => 'alpha_one', 'description' => 'd', 'handler' => $h ) ) ); }, 'InvalidArgumentException', 'already' );
        $this->assertSame( null, $reg->findTool( 'alpha_one' ), 'staged, not visible yet' );
        $this->assertThrows( function () use ( $reg ) { $reg->begin( 'other' ); }, 'LogicException' );
        $counts = $reg->commit();
        $this->assertSame( 1, $counts['tools'] );
        $this->assertSame( 'alpha', $reg->findTool( 'alpha_one' )->program );

        $reg->begin( 'beta' );
        $reg->tool( tronTool::define( array( 'name' => 'beta_one', 'description' => 'd', 'handler' => $h ) ) );
        $reg->unavailable( 'beta_two', 'missing part' );
        $reg->rollback();
        $this->assertSame( null, $reg->findTool( 'beta_one' ), 'rolled back' );
        $this->assertSame( array(), $reg->unavailableTools() );
        $this->assertCount( 1, $reg->tools() );
    }

    public function testRedactor(): void
    {
        $token = 'tron_ABCDEFabcdef_' . str_repeat( 'x', 43 );
        $r = tronRedactor::redact( array( 'password' => 'hunter2', 'api_key' => 'k', 'note' => "use $token now", 'auth' => 'Bearer abcdefghijkl',
                                          'long' => str_repeat( 'a', 300 ), 'nested' => array( 'secret' => 's', 'ok' => 1 ) ) );
        $this->assertSame( '[redacted]', $r['password'] );
        $this->assertSame( '[redacted]', $r['api_key'] );
        $this->assertSame( 'use tron_ABCDEFabcdef_[redacted] now', $r['note'] );
        $this->assertSame( 'Bearer [redacted]', $r['auth'] );
        $this->assertContains( '(300 chars)', $r['long'] );
        $this->assertSame( '[redacted]', $r['nested']['secret'] );
        $this->assertSame( 1, $r['nested']['ok'] );
        // secrets written into free text (a ticket body)
        $t = tronRedactor::redact( array( 'body' => "login with password=hunter2 and api_key: \"a b c\"; Secret = s3 then keep going" ) );
        $this->assertSame( 'login with password=[redacted] and api_key: [redacted]; Secret = [redacted] then keep going', $t['body'] );
        $this->assertSame( 'the passport office', tronRedactor::maskSecretsInText( 'the passport office' ), 'words that only start like a secret stay' );
    }

    public function testKernelInterpolation(): void
    {
        $q = function ( $v ) {
            if ( $v === null ) return 'NULL';
            if ( is_int( $v ) ) return (string)$v;
            return "'" . str_replace( "'", "''", (string)$v ) . "'";
        };
        $this->assertSame( "SELECT * FROM t WHERE a = 5 AND b = 'x''y' AND c = NULL",
                           tronDbKernel::interpolate( 'SELECT * FROM t WHERE a = :a AND b = :b AND c = :c', array( 'a' => 5, 'b' => "x'y", 'c' => null ), $q ) );
        $this->assertSame( "SELECT ':a' AS lit, 1::int, 'z'", tronDbKernel::interpolate( "SELECT ':a' AS lit, 1::int, :v", array( 'v' => 'z' ), $q ), 'quoted text and casts are left alone' );
        $this->assertSame( "x = 'a' OR y = 'a'", tronDbKernel::interpolate( 'x = :p OR y = :p', array( 'p' => 'a' ), $q ), 'a name may be used twice' );
        $this->assertThrows( function () use ( $q ) { tronDbKernel::interpolate( 'a = :missing', array(), $q ); }, 'tronDbException', ':missing' );
        $this->assertThrows( function () use ( $q ) { tronDbKernel::interpolate( 'a = 1', array( 'extra' => 1 ), $q ); }, 'tronDbException', 'extra' );
        $this->assertThrows( function () { tronDbKernel::checkIdentifier( 'x; DROP' ); }, 'tronDbException' );
    }

    public function testSchemaInstallsAndSqlFilesAreCurrent(): void
    {
        $db = new tronDbPdo( new PDO( 'sqlite::memory:' ) );
        $first = tronSchema::install( $db );
        $this->assertSame( array_fill_keys( array_keys( tronSchema::tables() ), 'created' ), $first );
        $second = tronSchema::install( $db );
        $this->assertSame( array_fill_keys( array_keys( tronSchema::tables() ), 'present' ), $second, 'a second run changes nothing' );
        $this->assertSame( array(), tronSchema::missing( $db ) );
        $root = dirname( __DIR__, 2 );
        foreach ( array( 'mysql' => 'mysql', 'pgsql' => 'postgresql', 'sqlite' => 'sqlite' ) as $driver => $dir )
            $this->assertSame( tronSchema::sql( $driver ), (string)@file_get_contents( "$root/sql/$dir/schema.sql" ), "sql/$dir/schema.sql is generated from tronSchema (php bin/php/tron-schema.php --write)" );
        $this->assertThrows( function () { tronSchema::createStatements( 'tron_token', 'oracle' ); }, 'tronDbException', 'oracle' );
        $this->assertContains( 'SERIAL PRIMARY KEY', tronSchema::sql( 'pgsql' ) );
        $this->assertContains( 'AUTO_INCREMENT', tronSchema::sql( 'mysql' ) );
    }

    public function testPdoDbRoundTrip(): void
    {
        $db = tronDbPdo::sqliteMemory();
        $id = $db->insert( 'tron_centre_comment', array( 'ticket_id' => 7, 'user_id' => 1, 'body' => "it's \"quoted\"", 'created' => 5 ) );
        $this->assertSame( 1, $id );
        $rows = $db->select( 'SELECT * FROM tron_centre_comment WHERE ticket_id = :t', array( 't' => 7 ) );
        $this->assertSame( "it's \"quoted\"", $rows[0]['body'] );
        $this->assertThrows( function () use ( $db ) { $db->select( 'SELECT * FROM nope' ); }, 'tronDbException', 'Statement failed' );
        $db->begin();
        $db->insert( 'tron_centre_comment', array( 'ticket_id' => 8, 'user_id' => 1, 'body' => 'b', 'created' => 5 ) );
        $db->rollback();
        $this->assertCount( 0, $db->select( 'SELECT * FROM tron_centre_comment WHERE ticket_id = :t', array( 't' => 8 ) ) );
        $this->assertSame( 2, count( $db->select( 'SELECT * FROM tron_centre_comment ORDER BY id', array(), 0 ) ) + 1 );
        $this->assertCount( 1, $db->select( 'SELECT * FROM tron_centre_comment ORDER BY id', array(), 1, 0 ) );
    }
}
