<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/** tronDb on a PDO connection, with real prepared statements. */
final class tronDbPdo implements tronDb
{
    /** @var PDO */ private $pdo;
    /** @var bool[] one entry per open begin(): true when it is a savepoint */ private $levels = array();

    public function __construct( PDO $pdo )
    {
        $pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
        $pdo->setAttribute( PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC );
        $this->pdo = $pdo;
    }

    /** An SQLite database in memory with tron's tables (the tests' database). */
    public static function sqliteMemory(): self
    {
        $db = new self( new PDO( 'sqlite::memory:' ) );
        tronSchema::install( $db );
        return $db;
    }

    public function select( string $sql, array $params = array(), int $limit = 0, int $offset = 0 ): array
    {
        if ( $limit > 0 )
            $sql .= ' LIMIT ' . (int)$limit . ' OFFSET ' . max( 0, (int)$offset );
        return $this->run( $sql, $params )->fetchAll();
    }

    public function execute( string $sql, array $params = array() ): void
    {
        $this->run( $sql, $params );
    }

    public function insert( string $table, array $row ): int
    {
        tronDbKernel::checkIdentifier( $table );
        $cols = array_keys( $row );
        foreach ( $cols as $c )
            tronDbKernel::checkIdentifier( $c );
        $sql = 'INSERT INTO ' . $table . ' (' . implode( ', ', $cols ) . ') VALUES (:' . implode( ', :', $cols ) . ')';
        $this->run( $sql, $row );
        $id = $this->driver() === 'pgsql' ? $this->pdo->lastInsertId( $table . '_id_seq' ) : $this->pdo->lastInsertId();
        return (int)$id;
    }

    /**
     * Transactions nest: begin() opens a transaction, or a savepoint when the connection is already inside one (an
     * outer begin(), or a transaction a test harness opened). commit() and rollback() end the innermost level only.
     * A dry run wraps a tool's own transaction this way and rolls all of it back (doc/dry-run.md).
     */
    public function begin(): void
    {
        if ( $this->pdo->inTransaction() )
        {
            $this->pdo->exec( 'SAVEPOINT tron_sp' . ( count( $this->levels ) + 1 ) );
            $this->levels[] = true;
        }
        else
        {
            $this->pdo->beginTransaction();
            $this->levels[] = false;
        }
    }

    public function commit(): void
    {
        if ( !$this->levels )
            return;
        $savepoint = array_pop( $this->levels );
        if ( $savepoint )
            $this->pdo->exec( 'RELEASE SAVEPOINT tron_sp' . ( count( $this->levels ) + 1 ) );
        else
            $this->pdo->commit();
    }

    public function rollback(): void
    {
        if ( !$this->levels )
            return;
        $savepoint = array_pop( $this->levels );
        if ( $savepoint )
        {
            $sp = 'tron_sp' . ( count( $this->levels ) + 1 );
            $this->pdo->exec( 'ROLLBACK TO SAVEPOINT ' . $sp );
            $this->pdo->exec( 'RELEASE SAVEPOINT ' . $sp );
        }
        else if ( $this->pdo->inTransaction() )
            $this->pdo->rollBack();
    }

    public function driver(): string
    {
        return (string)$this->pdo->getAttribute( PDO::ATTR_DRIVER_NAME );
    }

    public function tableExists( string $table ): bool
    {
        tronDbKernel::checkIdentifier( $table );
        $q = tronDbKernel::tableExistsQuery( $this->driver() );
        if ( $q === null )
            throw new tronDbException( 'tableExists() does not know the engine ' . $this->driver() );
        return count( $this->select( $q, array( 't' => $table ) ) ) > 0;
    }

    private function run( string $sql, array $params ): PDOStatement
    {
        try
        {
            $st = $this->pdo->prepare( $sql );
            foreach ( $params as $k => $v )
            {
                $type = is_int( $v ) ? PDO::PARAM_INT : ( $v === null ? PDO::PARAM_NULL : ( is_bool( $v ) ? PDO::PARAM_BOOL : PDO::PARAM_STR ) );
                $st->bindValue( ':' . $k, is_float( $v ) ? (string)$v : $v, $type );
            }
            $st->execute();
            return $st;
        }
        catch ( PDOException $e )
        {
            throw new tronDbException( 'Statement failed (' . strtok( trim( $sql ), " \n" ) . '): ' . $e->getMessage() );
        }
    }
}
