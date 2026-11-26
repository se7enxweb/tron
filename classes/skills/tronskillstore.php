<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * What people decided about skills, in two tables of their own (created on first use, on every engine tron supports):
 *
 *   tron_skill       one row per skill version someone drafted, validated, approved or revoked: name, version, the
 *                    manifest's hash (an approval holds for exactly that manifest), the pack, the state and, for a
 *                    draft or fork, the manifest itself; who and when for each change. Rows are never deleted, so
 *                    every version is kept.
 *   tron_skill_pack  packs switched off in Setup > tron > Skills.
 *
 * States: draft (written by a person or by the AI through skill_draft), validated (schema, policy simulation and a
 * trial run passed), approved (a person approved it after "Verify it's you"), revoked (taken back).
 */
final class tronSkillStore
{
    const STATES = array( 'draft', 'validated', 'approved', 'revoked' );

    /** @var tronDb */ private $db;
    /** @var callable */ private $clock;
    /** @var bool */ private $ready = false;

    public function __construct( tronDb $db, ?callable $clock = null )
    {
        $this->db = $db;
        $this->clock = $clock ?: 'time';
    }

    public static function tables(): array
    {
        return array(
            'tron_skill' => array(
                'columns' => array( 'id' => 'serial', 'name' => 'string:64', 'version' => 'string:20', 'hash' => 'string:64',
                                    'pack' => 'string:64', 'state' => 'string:16', 'manifest' => 'text', 'report' => 'text',
                                    'derived_from' => 'string:100', 'created' => 'bigint', 'created_by' => 'int',
                                    'changed' => 'bigint', 'changed_by' => 'int' ),
                'index' => array( 'tron_skill_name' => array( 'name', 'state' ), 'tron_skill_state' => array( 'state', 'changed' ) ),
            ),
            'tron_skill_pack' => array(
                'columns' => array( 'id' => 'serial', 'pack' => 'string:64', 'enabled' => 'int', 'changed' => 'bigint', 'changed_by' => 'int' ),
                'unique' => array( 'tron_skill_pack_id' => array( 'pack' ) ),
            ),
        );
    }

    /** Creates the tables that are missing. @return array table => created|present */
    public function install(): array
    {
        $report = array();
        foreach ( self::tables() as $table => $def )
        {
            if ( $this->db->tableExists( $table ) )
            {
                $report[$table] = 'present';
                continue;
            }
            foreach ( self::createStatements( $table, $def, $this->db->driver() ) as $st )
                $this->db->execute( $st );
            $report[$table] = 'created';
        }
        $this->ready = true;
        return $report;
    }

    /** The statements tron's schema generator would write (same column types as tronSchema). */
    public static function createStatements( string $table, array $def, string $driver ): array
    {
        $cols = array();
        foreach ( $def['columns'] as $name => $type )
        {
            if ( $type === 'serial' )
                $t = $driver === 'mysql' ? 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY' : ( $driver === 'pgsql' ? 'SERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT' );
            else if ( $type === 'int' )
                $t = ( $driver === 'mysql' ? 'INT' : 'INTEGER' ) . ' NOT NULL DEFAULT 0';
            else if ( $type === 'bigint' )
                $t = ( $driver === 'sqlite' ? 'INTEGER' : 'BIGINT' ) . ' NOT NULL DEFAULT 0';
            else if ( $type === 'text' )
                $t = $driver === 'mysql' ? 'MEDIUMTEXT' : 'TEXT';
            else
                $t = 'VARCHAR(' . (int)substr( $type, 7 ) . ") NOT NULL DEFAULT ''";
            $cols[] = '  ' . $name . ' ' . $t;
        }
        $out = array( 'CREATE TABLE ' . $table . " (\n" . implode( ",\n", $cols ) . "\n)" . ( $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '' ) );
        foreach ( (array)( $def['unique'] ?? array() ) as $idx => $icols )
            $out[] = 'CREATE UNIQUE INDEX ' . $idx . ' ON ' . $table . ' (' . implode( ', ', $icols ) . ')';
        foreach ( (array)( $def['index'] ?? array() ) as $idx => $icols )
            $out[] = 'CREATE INDEX ' . $idx . ' ON ' . $table . ' (' . implode( ', ', $icols ) . ')';
        return $out;
    }

    private function ensure(): void
    {
        if ( !$this->ready )
            $this->install();
    }

    private function now(): int
    {
        return (int)call_user_func( $this->clock );
    }

    /** @return array name => hash of every approved skill (the newest approval of a name wins) */
    public function approved(): array
    {
        $this->ensure();
        $out = array();
        foreach ( $this->db->select( "SELECT name, hash, state FROM tron_skill WHERE state IN ('approved','revoked') ORDER BY changed, id" ) as $r )
        {
            if ( $r['state'] === 'approved' )
                $out[(string)$r['name']] = (string)$r['hash'];
            else
                unset( $out[(string)$r['name']] );
        }
        return $out;
    }

    /** @return array pack id => true for the packs switched off */
    public function packsOff(): array
    {
        $this->ensure();
        $out = array();
        foreach ( $this->db->select( 'SELECT pack FROM tron_skill_pack WHERE enabled = 0' ) as $r )
            $out[(string)$r['pack']] = true;
        return $out;
    }

    public function setPack( string $pack, bool $enabled, int $userId ): void
    {
        if ( !preg_match( tronSkillPack::ID_PATTERN, $pack ) )
            throw new InvalidArgumentException( "'$pack' is not a pack id" );
        $this->ensure();
        $p = array( 'p' => $pack );
        if ( $this->db->select( 'SELECT id FROM tron_skill_pack WHERE pack = :p', $p ) )
            $this->db->execute( 'UPDATE tron_skill_pack SET enabled = :e, changed = :c, changed_by = :u WHERE pack = :p',
                                $p + array( 'e' => $enabled ? 1 : 0, 'c' => $this->now(), 'u' => $userId ) );
        else
            $this->db->insert( 'tron_skill_pack', array( 'pack' => $pack, 'enabled' => $enabled ? 1 : 0, 'changed' => $this->now(), 'changed_by' => $userId ) );
    }

    /** Approves exactly this manifest (a later change needs a new approval). */
    public function approve( tronSkillManifest $m, string $pack, int $userId, string $report = '' ): int
    {
        $this->ensure();
        $now = $this->now();
        return $this->db->insert( 'tron_skill', array( 'name' => $m->name, 'version' => $m->version, 'hash' => $m->hash(), 'pack' => $pack,
                                                       'state' => 'approved', 'manifest' => '', 'report' => $report,
                                                       'derived_from' => (string)( $m->data['derived_from']['name'] ?? '' ),
                                                       'created' => $now, 'created_by' => $userId, 'changed' => $now, 'changed_by' => $userId ) );
    }

    public function revoke( string $name, int $userId, string $reason = '' ): int
    {
        $this->ensure();
        $now = $this->now();
        return $this->db->insert( 'tron_skill', array( 'name' => $name, 'version' => '', 'hash' => '', 'pack' => '', 'state' => 'revoked',
                                                       'manifest' => '', 'report' => $reason, 'derived_from' => '',
                                                       'created' => $now, 'created_by' => $userId, 'changed' => $now, 'changed_by' => $userId ) );
    }

    /**
     * Stores a draft (refused when it breaks the standard). A draft of the same name and manifest is not stored twice.
     * @return array the draft row
     * @throws tronSkillInvalid
     */
    public function draft( array $manifest, int $userId, string $pack = 'local' ): array
    {
        $m = tronSkillManifest::fromArray( $manifest, 'draft' );
        $this->ensure();
        $have = $this->db->select( "SELECT * FROM tron_skill WHERE name = :n AND hash = :h AND manifest <> '' ORDER BY id DESC", array( 'n' => $m->name, 'h' => $m->hash() ), 1 );
        if ( $have )
            return $have[0];
        $now = $this->now();
        $id = $this->db->insert( 'tron_skill', array( 'name' => $m->name, 'version' => $m->version, 'hash' => $m->hash(), 'pack' => $pack,
                                                      'state' => 'draft', 'manifest' => tronSkillManifest::canonical( $m->data ), 'report' => '',
                                                      'derived_from' => (string)( $m->data['derived_from']['name'] ?? '' ),
                                                      'created' => $now, 'created_by' => $userId, 'changed' => $now, 'changed_by' => $userId ) );
        return $this->draftById( $id );
    }

    public function draftById( int $id ): ?array
    {
        $this->ensure();
        $r = $this->db->select( "SELECT * FROM tron_skill WHERE id = :id AND manifest <> ''", array( 'id' => $id ), 1 );
        return $r ? $r[0] : null;
    }

    /** Marks a draft validated (with the report) or approved. */
    public function setDraftState( int $id, string $state, int $userId, string $report = '' ): void
    {
        if ( !in_array( $state, array( 'draft', 'validated', 'approved' ), true ) )
            throw new InvalidArgumentException( "unknown state $state" );
        $this->ensure();
        $set = 'state = :s, changed = :c, changed_by = :u' . ( $report !== '' ? ', report = :r' : '' );
        $p = array( 's' => $state, 'c' => $this->now(), 'u' => $userId, 'id' => $id );
        if ( $report !== '' )
            $p['r'] = $report;
        $this->db->execute( "UPDATE tron_skill SET $set WHERE id = :id", $p );
    }

    /** @return array[] the drafts (and forks), newest first */
    public function drafts( int $limit = 200 ): array
    {
        $this->ensure();
        return $this->db->select( "SELECT * FROM tron_skill WHERE manifest <> '' ORDER BY changed DESC, id DESC", array(), $limit );
    }

    /** @return tronSkillManifest[] name => the approved drafts and forks (they are live like a pack's skills) */
    public function approvedDrafts(): array
    {
        $this->ensure();
        $out = array();
        foreach ( $this->db->select( "SELECT * FROM tron_skill WHERE manifest <> '' AND state = 'approved' ORDER BY changed, id" ) as $r )
        {
            try
            {
                $m = tronSkillManifest::fromArray( (array)json_decode( (string)$r['manifest'], true ), 'draft:' . $r['id'] );
                $out[$m->name] = array( 'manifest' => $m, 'row' => $r );
            }
            catch ( tronSkillInvalid $e )
            {
                // a draft that no longer keeps the standard (the standard grew) is not offered
            }
        }
        return $out;
    }

    /** @return array[] the history of a name: every row, newest first */
    public function history( string $name, int $limit = 50 ): array
    {
        $this->ensure();
        return $this->db->select( 'SELECT id, name, version, hash, pack, state, report, derived_from, created, created_by, changed, changed_by FROM tron_skill WHERE name = :n ORDER BY id DESC',
                                  array( 'n' => $name ), $limit );
    }
}
