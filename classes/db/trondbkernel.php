<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * tronDb on the installation's own connection (eZDB). eZDB has no bound parameters, so each :name is replaced by
 * the value written safely for the engine: integers as integers, null as NULL, everything else quoted with the
 * connection's escapeString(). Statements run with eZDB's exception error handling, so a failure reaches tron as a
 * tronDbException instead of an error page; the standard handling is restored after each statement (a persistent
 * Velocity worker keeps the connection for the next request).
 */
final class tronDbKernel implements tronDb
{
    /** @var object eZDBInterface */ private $db;
    /** @var bool[] one entry per open begin(): true when it is a savepoint */ private $levels = array();

    public function __construct( $db )
    {
        $this->db = $db;
    }

    public function select( string $sql, array $params = array(), int $limit = 0, int $offset = 0 ): array
    {
        $q = self::interpolate( $sql, $params, array( $this, 'quote' ) );
        $p = $limit > 0 ? array( 'limit' => $limit, 'offset' => max( 0, $offset ) ) : array();
        $rows = $this->guarded( function () use ( $q, $p ) { return $this->db->arrayQuery( $q, $p ); }, $sql );
        return is_array( $rows ) ? $rows : array();
    }

    public function execute( string $sql, array $params = array() ): void
    {
        $q = self::interpolate( $sql, $params, array( $this, 'quote' ) );
        $ok = $this->guarded( function () use ( $q ) { return $this->db->query( $q ); }, $sql );
        if ( $ok === false )
            throw new tronDbException( 'Statement failed (' . strtok( trim( $sql ), " \n" ) . ')' );
    }

    public function insert( string $table, array $row ): int
    {
        self::checkIdentifier( $table );
        $cols = array_keys( $row );
        foreach ( $cols as $c )
            self::checkIdentifier( $c );
        $this->execute( 'INSERT INTO ' . $table . ' (' . implode( ', ', $cols ) . ') VALUES (:' . implode( ', :', $cols ) . ')', $row );
        $id = $this->db->lastSerialID( $table, 'id' );
        if ( !$id )
            throw new tronDbException( "No id after the insert into $table" );
        return (int)$id;
    }

    /**
     * Transactions nest as savepoints: begin() opens eZDB's transaction when none is open, else a savepoint, so a
     * dry run can roll back a tool that opens and commits its own transaction inside it (doc/dry-run.md), and a
     * rollback of an inner level never ends a transaction someone else opened.
     */
    public function begin(): void
    {
        $open = method_exists( $this->db, 'transactionCounter' ) ? (int)$this->db->transactionCounter() > 0 : (bool)$this->levels;
        if ( $open )
        {
            $this->execute( 'SAVEPOINT tron_sp' . ( count( $this->levels ) + 1 ) );
            $this->levels[] = true;
        }
        else
        {
            $this->db->begin();
            $this->levels[] = false;
        }
    }

    public function commit(): void
    {
        $savepoint = $this->levels ? array_pop( $this->levels ) : false;
        if ( $savepoint )
            $this->execute( 'RELEASE SAVEPOINT tron_sp' . ( count( $this->levels ) + 1 ) );
        else
            $this->db->commit();
    }

    public function rollback(): void
    {
        $savepoint = $this->levels ? array_pop( $this->levels ) : false;
        if ( $savepoint )
        {
            $sp = 'tron_sp' . ( count( $this->levels ) + 1 );
            $this->execute( 'ROLLBACK TO SAVEPOINT ' . $sp );
            $this->execute( 'RELEASE SAVEPOINT ' . $sp );
        }
        else
            $this->db->rollback();
    }

    public function driver(): string
    {
        $name = (string)$this->db->databaseName();
        $map = array( 'mysql' => 'mysql', 'mysqli' => 'mysql', 'postgresql' => 'pgsql', 'sqlite' => 'sqlite', 'sqlite3' => 'sqlite' );
        return $map[$name] ?? $name;
    }

    /**
     * Asks the engine's catalog (eZTableList() is not usable here: on PostgreSQL it lists only tables named ez*,
     * found on the real server 2026-11-24).
     */
    public function tableExists( string $table ): bool
    {
        self::checkIdentifier( $table );
        $q = self::tableExistsQuery( $this->driver() );
        if ( $q === null )
        {
            $list = $this->db->eZTableList();
            return is_array( $list ) && isset( $list[$table] );
        }
        return count( $this->select( $q, array( 't' => $table ) ) ) > 0;
    }

    /** The catalog query (one :t placeholder) that returns a row when the table exists, or null for other engines. */
    public static function tableExistsQuery( string $driver ): ?string
    {
        switch ( $driver )
        {
            case 'pgsql':  return 'SELECT c.relname FROM pg_class c WHERE c.relname = :t AND c.relkind = \'r\' AND pg_table_is_visible(c.oid)';
            case 'mysql':  return 'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t';
            case 'sqlite': return 'SELECT name FROM sqlite_master WHERE type = \'table\' AND name = :t';
        }
        return null;
    }

    public function quote( $v ): string
    {
        if ( $v === null )
            return 'NULL';
        if ( is_bool( $v ) )
            return $v ? '1' : '0';
        if ( is_int( $v ) )
            return (string)$v;
        return "'" . $this->db->escapeString( (string)$v ) . "'";
    }

    /**
     * Replaces :name placeholders with quoted values. Every placeholder must have a value and every value a
     * placeholder; a colon inside a quoted SQL literal is left alone (tron's SQL has none, but this keeps it safe).
     */
    public static function interpolate( string $sql, array $params, callable $quote ): string
    {
        $used = array();
        $out = '';
        $len = strlen( $sql );
        $quoteChar = null;
        for ( $i = 0; $i < $len; $i++ )
        {
            $c = $sql[$i];
            if ( $quoteChar !== null )
            {
                $out .= $c;
                if ( $c === $quoteChar )
                    $quoteChar = null;
                continue;
            }
            if ( $c === "'" || $c === '"' )
            {
                $quoteChar = $c;
                $out .= $c;
                continue;
            }
            if ( $c === ':' && $i + 1 < $len && preg_match( '/^[A-Za-z_][A-Za-z0-9_]*/', substr( $sql, $i + 1, 64 ), $m ) && ( $i === 0 || $sql[$i - 1] !== ':' ) )
            {
                $name = $m[0];
                if ( !array_key_exists( $name, $params ) )
                    throw new tronDbException( "No value for the placeholder :$name" );
                $out .= call_user_func( $quote, $params[$name] );
                $used[$name] = true;
                $i += strlen( $name );
                continue;
            }
            $out .= $c;
        }
        foreach ( array_keys( $params ) as $k )
        {
            if ( !isset( $used[$k] ) )
                throw new tronDbException( "The value $k has no placeholder" );
        }
        return $out;
    }

    public static function checkIdentifier( string $name ): void
    {
        if ( !preg_match( '/^[a-z_][a-z0-9_]{0,62}$/', $name ) )
            throw new tronDbException( "Not a valid identifier: $name" );
    }

    private function guarded( callable $fn, string $sql )
    {
        $canSwitch = method_exists( $this->db, 'setErrorHandling' ) && class_exists( 'eZDB', false );
        if ( $canSwitch )
            $this->db->setErrorHandling( eZDB::ERROR_HANDLING_EXCEPTIONS );
        try
        {
            return $fn();
        }
        catch ( Throwable $e )
        {
            throw new tronDbException( 'Statement failed (' . strtok( trim( $sql ), " \n" ) . '): ' . $e->getMessage() );
        }
        finally
        {
            if ( $canSwitch )
                $this->db->setErrorHandling( eZDB::ERROR_HANDLING_STANDARD );
        }
    }
}
