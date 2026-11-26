<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * tron's tables, declared once. The CREATE statements for MySQL/MariaDB, PostgreSQL and SQLite come from this
 * declaration (sql/<engine>/schema.sql are generated from it and a test keeps them equal), and install() creates
 * whichever tables are missing, so it can run again at any time.
 *
 * Column types: serial (the id), int, bigint (unix times), string:<n>, text.
 */
final class tronSchema
{
    public static function tables(): array
    {
        return array(
            'tron_token' => array(
                'columns' => array(
                    'id' => 'serial', 'user_id' => 'int', 'name' => 'string:100', 'token_prefix' => 'string:16',
                    'token_hash' => 'string:64', 'scopes' => 'string:255', 'created' => 'bigint', 'expires' => 'bigint',
                    'last_used' => 'bigint', 'revoked' => 'bigint', 'created_by' => 'int',
                ),
                'unique' => array( 'tron_token_prefix' => array( 'token_prefix' ) ),
                'index' => array( 'tron_token_user' => array( 'user_id' ) ),
            ),
            'tron_audit' => array(
                'columns' => array(
                    'id' => 'serial', 'created' => 'bigint', 'user_id' => 'int', 'login' => 'string:150',
                    'token_id' => 'int', 'transport' => 'string:16', 'remote' => 'string:64', 'method' => 'string:64',
                    'target' => 'string:255', 'program' => 'string:32', 'outcome' => 'string:16',
                    'duration_ms' => 'int', 'detail' => 'text',
                ),
                'index' => array( 'tron_audit_created' => array( 'created' ), 'tron_audit_user' => array( 'user_id', 'created' ) ),
            ),
            'tron_switch' => array(
                'columns' => array( 'id' => 'serial', 'kind' => 'string:16', 'name' => 'string:64', 'enabled' => 'int',
                                    'changed' => 'bigint', 'changed_by' => 'int' ),
                'unique' => array( 'tron_switch_name' => array( 'kind', 'name' ) ),
            ),
            'tron_centre_ticket' => array(
                'columns' => array(
                    'id' => 'serial', 'kind' => 'string:16', 'board' => 'string:64', 'lane' => 'string:64',
                    'position' => 'int', 'status' => 'string:16', 'priority' => 'string:16', 'title' => 'string:255',
                    'body' => 'text', 'labels' => 'string:255', 'assignee_id' => 'int', 'created_by' => 'int',
                    'created' => 'bigint', 'modified' => 'bigint', 'closed' => 'bigint', 'closed_reason' => 'string:255',
                    'due' => 'bigint', 'decision_week' => 'string:10', 'decision' => 'text',
                ),
                'index' => array( 'tron_centre_ticket_board' => array( 'board', 'lane', 'status' ),
                                  'tron_centre_ticket_status' => array( 'status', 'modified' ),
                                  'tron_centre_ticket_week' => array( 'kind', 'decision_week' ) ),
            ),
            'tron_centre_comment' => array(
                'columns' => array( 'id' => 'serial', 'ticket_id' => 'int', 'user_id' => 'int', 'body' => 'text', 'created' => 'bigint' ),
                'index' => array( 'tron_centre_comment_ticket' => array( 'ticket_id' ) ),
            ),
            'tron_centre_link' => array(
                'columns' => array( 'id' => 'serial', 'ticket_id' => 'int', 'kind' => 'string:16', 'ref' => 'string:255',
                                    'note' => 'string:255', 'user_id' => 'int', 'created' => 'bigint' ),
                'index' => array( 'tron_centre_link_ticket' => array( 'ticket_id' ), 'tron_centre_link_ref' => array( 'kind', 'ref' ) ),
            ),
            'tron_centre_history' => array(
                'columns' => array( 'id' => 'serial', 'ticket_id' => 'int', 'user_id' => 'int', 'created' => 'bigint',
                                    'field' => 'string:32', 'old_value' => 'string:255', 'new_value' => 'string:255' ),
                'index' => array( 'tron_centre_history_ticket' => array( 'ticket_id' ) ),
            ),
            // Dry runs waiting to be applied: hashes only, never the arguments or the change (doc/dry-run.md)
            'tron_plan' => array(
                'columns' => array( 'id' => 'serial', 'plan_key' => 'string:32', 'user_id' => 'int', 'token_id' => 'int',
                                    'tool' => 'string:64', 'mode' => 'string:16', 'input_hash' => 'string:64',
                                    'state_hash' => 'string:64', 'changes_hash' => 'string:64', 'created' => 'bigint',
                                    'expires' => 'bigint', 'used' => 'bigint' ),
                'unique' => array( 'tron_plan_key' => array( 'plan_key' ) ),
                'index' => array( 'tron_plan_expires' => array( 'expires' ) ),
            ),
        ) + tronJournalSchema::tables();    // the activity journal (classes/journal)
    }

    public static function supports( string $driver ): bool
    {
        return in_array( $driver, array( 'mysql', 'pgsql', 'sqlite' ), true );
    }

    /** @return string[] the CREATE TABLE and CREATE INDEX statements of one table */
    public static function createStatements( string $table, string $driver ): array
    {
        if ( !self::supports( $driver ) )
            throw new tronDbException( "tron's schema has no statements for the engine '$driver' yet (mysql, pgsql and sqlite are supported)" );
        $def = self::tables()[$table];
        $cols = array();
        foreach ( $def['columns'] as $name => $type )
            $cols[] = '  ' . $name . ' ' . self::columnType( $type, $driver );
        $tail = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $out = array( 'CREATE TABLE ' . $table . " (\n" . implode( ",\n", $cols ) . "\n)" . $tail );
        foreach ( (array)( $def['unique'] ?? array() ) as $idx => $icols )
            $out[] = 'CREATE UNIQUE INDEX ' . $idx . ' ON ' . $table . ' (' . implode( ', ', $icols ) . ')';
        foreach ( (array)( $def['index'] ?? array() ) as $idx => $icols )
            $out[] = 'CREATE INDEX ' . $idx . ' ON ' . $table . ' (' . implode( ', ', $icols ) . ')';
        return $out;
    }

    /** The whole schema as one SQL text (what sql/<engine>/schema.sql holds). */
    public static function sql( string $driver ): string
    {
        $s = "-- tron " . tronVersion::VERSION . " schema for $driver. Generated from tronSchema::tables(); do not edit by hand.\n"
            . "-- " . tronVersion::NOTICE . " " . tronVersion::LICENSE . "\n";
        foreach ( array_keys( self::tables() ) as $table )
        {
            $s .= "\n";
            foreach ( self::createStatements( $table, $driver ) as $st )
                $s .= $st . ";\n";
        }
        return $s;
    }

    /**
     * Creates the tables that do not exist yet. Returns table => 'created' or 'present'.
     * @param string[]|null $only limit to these tables
     */
    public static function install( tronDb $db, ?array $only = null ): array
    {
        $report = array();
        foreach ( array_keys( self::tables() ) as $table )
        {
            if ( $only !== null && !in_array( $table, $only, true ) )
                continue;
            if ( $db->tableExists( $table ) )
            {
                $report[$table] = 'present';
                continue;
            }
            foreach ( self::createStatements( $table, $db->driver() ) as $st )
                $db->execute( $st );
            $report[$table] = 'created';
        }
        return $report;
    }

    /** @return string[] tables that are missing */
    public static function missing( tronDb $db ): array
    {
        $m = array();
        foreach ( array_keys( self::tables() ) as $table )
        {
            if ( !$db->tableExists( $table ) )
                $m[] = $table;
        }
        return $m;
    }

    private static function columnType( string $type, string $driver ): string
    {
        if ( $type === 'serial' )
        {
            switch ( $driver )
            {
                case 'mysql': return 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY';
                case 'pgsql': return 'SERIAL PRIMARY KEY';
                default:      return 'INTEGER PRIMARY KEY AUTOINCREMENT';
            }
        }
        if ( $type === 'int' )
            return ( $driver === 'mysql' ? 'INT' : 'INTEGER' ) . ' NOT NULL DEFAULT 0';
        if ( $type === 'bigint' )
            return ( $driver === 'sqlite' ? 'INTEGER' : 'BIGINT' ) . ' NOT NULL DEFAULT 0';
        if ( $type === 'text' )
            return $driver === 'mysql' ? 'MEDIUMTEXT' : 'TEXT';
        if ( strpos( $type, 'string:' ) === 0 )
            return 'VARCHAR(' . (int)substr( $type, 7 ) . ") NOT NULL DEFAULT ''";
        throw new tronDbException( "Unknown column type $type" );
    }
}
