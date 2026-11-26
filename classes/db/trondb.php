<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The database as tron uses it: SQL with named parameters (:name), never a value written into the SQL text.
 * tronDbKernel runs it on the installation's own connection (eZDB); tronDbPdo on a PDO connection (the tests use
 * SQLite in memory). Both raise tronDbException on a failed statement.
 *
 * SQL written for tronDb stays portable across MySQL/MariaDB, PostgreSQL and SQLite: no casts, no literals, no
 * engine functions beyond lower(), count(), max().
 */
interface tronDb
{
    /** @return array[] rows as column => value */
    public function select( string $sql, array $params = array(), int $limit = 0, int $offset = 0 ): array;

    /** Runs an INSERT/UPDATE/DELETE/DDL statement. */
    public function execute( string $sql, array $params = array() ): void;

    /** Inserts a row and returns its new id (the table's "id" column). */
    public function insert( string $table, array $row ): int;

    public function begin(): void;

    public function commit(): void;

    public function rollback(): void;

    /** mysql, pgsql, sqlite, or the engine's own name for anything else */
    public function driver(): string;

    public function tableExists( string $table ): bool;
}
