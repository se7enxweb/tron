<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Setup > tron > Programs and tools: every program with its state, every tool (also the switched-off ones) with its
 * policy, the roles that grant that policy (from the kernel's ezrole and ezpolicy tables) and its switch.
 */
final class tronAdminProgramsData
{
    /** @var tronAdminContext */ private $c;

    public function __construct( tronAdminContext $c )
    {
        $this->c = $c;
    }

    public function data(): array
    {
        $db = $this->c->db();
        $switches = $db->tableExists( 'tron_switch' ) ? $this->c->switches()->all() : array( 'program' => array(), 'tool' => array() );
        $full = $this->c->server( false );
        $live = $this->c->server( true )->registry();
        $roleCache = array();
        $tools = array();
        $counts = array( 'tools' => 0, 'on' => 0, 'write' => 0, 'ungranted' => 0 );
        foreach ( $full->registry()->tools() as $t )
        {
            $policy = $t->policy ? implode( '/', $t->policy ) : '';
            if ( $policy !== '' && !isset( $roleCache[$policy] ) )
                $roleCache[$policy] = $this->rolesGranting( $t->policy[0], $t->policy[1] );
            $roles = $policy !== '' ? $roleCache[$policy] : array();
            $enabled = $live->findTool( $t->name ) !== null;
            $tools[$t->program][] = array(
                'name' => $t->name, 'title' => $t->title, 'write' => $t->write, 'policy' => $policy,
                'policy_module' => $t->policy[0] ?? '', 'policy_function' => $t->policy[1] ?? '',
                'roles' => $roles, 'enabled' => $enabled,
                'switch' => $switches['tool'][$t->name] ?? null,
                'core' => $t->program === 'tron',
            );
            $counts['tools']++;
            $counts['on'] += $enabled ? 1 : 0;
            $counts['write'] += $t->write ? 1 : 0;
            $counts['ungranted'] += ( $policy !== '' && !$roles ) ? 1 : 0;
        }
        $programs = array();
        foreach ( (array)$full->option( 'programs' ) as $p )
        {
            $p['switch'] = $switches['program'][$p['name']] ?? null;
            $p['switched_off'] = isset( $switches['program'][$p['name']] ) && !(int)$switches['program'][$p['name']]['enabled'];
            $p['tools_list'] = $tools[$p['name']] ?? array();
            $p['core'] = $p['name'] === 'tron';
            $programs[] = $p;
        }
        return array( 'programs' => $programs, 'unavailable' => $full->registry()->unavailableTools(), 'counts' => $counts );
    }

    /**
     * The roles with module/function, module/* or * / * (published roles only).
     * @return array[] array( 'id' => role id, 'name' => role name, 'limited' => bool )
     */
    public function rolesGranting( string $module, string $function ): array
    {
        $db = $this->c->db();
        if ( !$db->tableExists( 'ezrole' ) || !$db->tableExists( 'ezpolicy' ) )
            return array();
        $rows = $db->select(
            "SELECT r.id, r.name, p.id AS policy_id FROM ezrole r, ezpolicy p WHERE p.role_id = r.id AND r.version = 0 AND "
            . "( p.module_name = '*' OR ( p.module_name = :m AND ( p.function_name = '*' OR p.function_name = :f ) ) ) ORDER BY r.name, p.id",
            array( 'm' => $module, 'f' => $function ) );
        $limited = array();
        if ( $rows && $db->tableExists( 'ezpolicy_limitation' ) )
        {
            $ids = implode( ',', array_map( 'intval', array_column( $rows, 'policy_id' ) ) );
            foreach ( $db->select( "SELECT DISTINCT policy_id FROM ezpolicy_limitation WHERE policy_id IN ($ids)" ) as $l )
                $limited[(int)$l['policy_id']] = true;
        }
        $out = array();
        foreach ( $rows as $r )
        {
            $id = (int)$r['id'];
            $isLimited = isset( $limited[(int)$r['policy_id']] );
            // a role with one unlimited policy grants fully
            if ( isset( $out[$id] ) )
                $out[$id]['limited'] = $out[$id]['limited'] && $isLimited;
            else
                $out[$id] = array( 'id' => $id, 'name' => (string)$r['name'], 'limited' => $isLimited );
        }
        return array_values( $out );
    }

    /** Switches a program or tool. Returns array( 'notice' ) or array( 'error' ). */
    public function setSwitch( string $kind, string $name, bool $on ): array
    {
        try
        {
            $db = $this->c->db();
            if ( !$db->tableExists( 'tron_switch' ) )
                tronSchema::install( $db, array( 'tron_switch' ) );
            $this->c->switches()->set( $kind, $name, $on, (int)$this->c->actor()['id'] );
        }
        catch ( InvalidArgumentException $e )
        {
            return array( 'error' => $e->getMessage() );
        }
        $this->c->recordAction( $on ? 'switch.on' : 'switch.off', $name, "$kind $name switched " . ( $on ? 'on' : 'off' ) );
        return array( 'notice' => $on ? $this->c->tr( '%name is switched on.', array( '%name' => $name ) )
                                      : $this->c->tr( '%name is switched off.', array( '%name' => $name ) ) );
    }
}
