<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Row snapshots and the difference between two of them, for the dry run of a write tool that changes its own tables
 * (doc/dry-run.md). A table is read whole, keyed by its "id" column; the difference lists every inserted, updated and
 * deleted row with the changed columns as from/to.
 *
 * normalize() makes two runs of the same change comparable: values of volatile columns (timestamps) become "*", and
 * the ids of inserted rows, and the columns that refer to them, become "#<table>:<n>" (the n-th insert into that
 * table), because a dry run and its later apply can be given different new ids without the change being different.
 */
final class tronRowDiff
{
    /** Refuse to snapshot a table larger than this: a dry run reads whole tables. */
    const MAX_ROWS = 50000;

    /** @return array<string,array<string,array>> table => id => row */
    public static function snapshot( tronDb $db, array $tables ): array
    {
        $out = array();
        foreach ( $tables as $table )
        {
            tronDbKernel::checkIdentifier( (string)$table );
            $n = $db->select( 'SELECT count(*) AS n FROM ' . $table );
            if ( (int)( $n[0]['n'] ?? 0 ) > self::MAX_ROWS )
                throw new tronToolError( "A dry run reads whole tables, and $table has more than " . self::MAX_ROWS . ' rows' );
            $rows = array();
            foreach ( $db->select( 'SELECT * FROM ' . $table . ' ORDER BY id' ) as $row )
            {
                $clean = array();
                foreach ( $row as $k => $v )
                    $clean[strtolower( (string)$k )] = $v === null ? null : (string)$v;
                $rows[(string)$clean['id']] = $clean;
            }
            $out[$table] = $rows;
        }
        return $out;
    }

    /**
     * @return array[] one entry per changed row: table, op (insert, update, delete), id, and for an update the changed
     *                 columns as column => array( from, to ); for an insert or delete the whole row as column => value
     */
    public static function diff( array $before, array $after ): array
    {
        $changes = array();
        foreach ( $after as $table => $rows )
        {
            $old = $before[$table] ?? array();
            foreach ( $rows as $id => $row )
            {
                if ( !isset( $old[$id] ) )
                {
                    $changes[] = array( 'table' => $table, 'op' => 'insert', 'id' => (int)$id, 'row' => $row );
                    continue;
                }
                $cols = array();
                foreach ( $row as $c => $v )
                {
                    if ( ( $old[$id][$c] ?? null ) !== $v )
                        $cols[$c] = array( 'from' => $old[$id][$c] ?? null, 'to' => $v );
                }
                if ( $cols )
                    $changes[] = array( 'table' => $table, 'op' => 'update', 'id' => (int)$id, 'columns' => $cols );
            }
            foreach ( $old as $id => $row )
            {
                if ( !isset( $rows[$id] ) )
                    $changes[] = array( 'table' => $table, 'op' => 'delete', 'id' => (int)$id, 'row' => $row );
            }
        }
        return $changes;
    }

    /**
     * The comparable form of a diff. $volatile: column names (or "table.column") whose values are left out;
     * $refs: "table.column" => referenced table, for columns that hold the id of a row of that table.
     */
    public static function normalize( array $changes, array $volatile = array(), array $refs = array() ): array
    {
        $newIds = array();
        foreach ( $changes as $c )
        {
            if ( $c['op'] === 'insert' )
            {
                $n = isset( $newIds[$c['table']] ) ? count( $newIds[$c['table']] ) + 1 : 1;
                $newIds[$c['table']][(string)$c['id']] = '#' . $c['table'] . ':' . $n;
            }
        }
        $isVolatile = function ( string $table, string $col ) use ( $volatile ): bool {
            return in_array( $col, $volatile, true ) || in_array( $table . '.' . $col, $volatile, true );
        };
        $value = function ( string $table, string $col, $v ) use ( $isVolatile, $refs, $newIds ) {
            if ( $isVolatile( $table, $col ) )
                return '*';
            $ref = $refs[$table . '.' . $col] ?? null;
            if ( $ref !== null && $v !== null && isset( $newIds[$ref][(string)$v] ) )
                return $newIds[$ref][(string)$v];
            return $v;
        };
        $out = array();
        foreach ( $changes as $c )
        {
            $t = $c['table'];
            $entry = array( 'table' => $t, 'op' => $c['op'], 'id' => $c['op'] === 'insert' ? $newIds[$t][(string)$c['id']] : $c['id'] );
            if ( isset( $c['row'] ) )
            {
                $row = array();
                foreach ( $c['row'] as $col => $v )
                    $row[$col] = $col === 'id' ? $entry['id'] : $value( $t, $col, $v );
                ksort( $row );
                $entry['row'] = $row;
            }
            if ( isset( $c['columns'] ) )
            {
                $cols = array();
                foreach ( $c['columns'] as $col => $ft )
                {
                    if ( $isVolatile( $t, $col ) )
                        continue;
                    $cols[$col] = array( 'from' => $value( $t, $col, $ft['from'] ), 'to' => $value( $t, $col, $ft['to'] ) );
                }
                ksort( $cols );
                if ( !$cols )
                    continue;
                $entry['columns'] = $cols;
            }
            $out[] = $entry;
        }
        return $out;
    }

    /** Counts per operation, for the summary of a plan. */
    public static function summary( array $changes ): array
    {
        $s = array( 'insert' => 0, 'update' => 0, 'delete' => 0 );
        foreach ( $changes as $c )
            $s[$c['op']] = ( $s[$c['op']] ?? 0 ) + 1;
        return $s;
    }

    /** sha256 of a value's canonical JSON (object keys sorted, lists kept in order). */
    public static function hash( $value ): string
    {
        return hash( 'sha256', (string)json_encode( self::canonical( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION ) );
    }

    private static function canonical( $v )
    {
        if ( $v instanceof stdClass )
            $v = (array)$v;
        if ( !is_array( $v ) )
            return $v;
        $isList = $v === array() || array_keys( $v ) === range( 0, count( $v ) - 1 );
        $out = array();
        foreach ( $v as $k => $x )
            $out[$k] = self::canonical( $x );
        if ( !$isList )
            ksort( $out, SORT_STRING );
        return $out;
    }
}
