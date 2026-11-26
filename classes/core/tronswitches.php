<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Programs and tools an administrator switched off (Setup > tron > Programs and tools), in the table tron_switch:
 * one row per program or tool that was ever switched, enabled 0 or 1, with who and when. A switched-off program is not
 * loaded; a switched-off tool is not registered and tron_status names it with the reason. The core program "tron"
 * and its tools cannot be switched off.
 */
final class tronSwitches
{
    const KINDS = array( 'program', 'tool' );

    /** @var tronDb */ private $db;
    /** @var callable */ private $clock;

    public function __construct( tronDb $db, ?callable $clock = null )
    {
        $this->db = $db;
        $this->clock = $clock ?: 'time';
    }

    /** @return array array( 'program' => array( name => row ), 'tool' => array( name => row ) ) */
    public function all(): array
    {
        $out = array( 'program' => array(), 'tool' => array() );
        foreach ( $this->db->select( 'SELECT * FROM tron_switch ORDER BY kind, name' ) as $r )
            $out[$r['kind']][$r['name']] = $r;
        return $out;
    }

    /** @return string[] names of the kind that are switched off */
    public function off( string $kind ): array
    {
        $names = array();
        foreach ( $this->db->select( 'SELECT name FROM tron_switch WHERE kind = :k AND enabled = 0', array( 'k' => $kind ) ) as $r )
            $names[] = (string)$r['name'];
        return $names;
    }

    public function set( string $kind, string $name, bool $enabled, int $userId ): void
    {
        if ( !in_array( $kind, self::KINDS, true ) )
            throw new InvalidArgumentException( "kind must be program or tool" );
        if ( !preg_match( $kind === 'program' ? '/^[a-z][a-z0-9]{1,31}$/' : tronTool::NAME_PATTERN, $name ) )
            throw new InvalidArgumentException( "'$name' is not a $kind name" );
        if ( $name === 'tron' || strpos( $name, 'tron_' ) === 0 )
            throw new InvalidArgumentException( 'The core program tron and its tools cannot be switched off' );
        $now = (int)call_user_func( $this->clock );
        $p = array( 'k' => $kind, 'n' => $name );
        if ( $this->db->select( 'SELECT id FROM tron_switch WHERE kind = :k AND name = :n', $p ) )
            $this->db->execute( 'UPDATE tron_switch SET enabled = :e, changed = :c, changed_by = :u WHERE kind = :k AND name = :n',
                                $p + array( 'e' => $enabled ? 1 : 0, 'c' => $now, 'u' => $userId ) );
        else
            $this->db->insert( 'tron_switch', array( 'kind' => $kind, 'name' => $name, 'enabled' => $enabled ? 1 : 0, 'changed' => $now, 'changed_by' => $userId ) );
    }
}
