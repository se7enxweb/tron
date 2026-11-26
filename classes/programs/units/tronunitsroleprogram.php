<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The program "role": role.describe and user.find. Of an account only its id, login, name, enabled flag and roles
 * leave the server: no e-mail address, no other attribute of the user object. A login or name that looks like an
 * e-mail address is masked.
 */
final class tronUnitsRoleProgram extends tronUnitProgram
{
    const POLICY = array( 'role', '*' );

    protected function area(): string
    {
        return 'role';
    }

    protected function sourceInterface(): string
    {
        return 'tronUnitRoleSource';
    }

    protected function units( tronRegistry $registry, tronEnvironment $env, $source ): void
    {
        $this->unit( $registry, 'role.describe', array(
            'title' => 'Describe a role',
            'description' => 'One role (by name or id): its policies (module, function, limitations with their values) and its assignments (users and user groups, with a subtree or section limitation where there is one).',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'role' ), 'properties' => array(
                'role' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 255 ),
            ) ),
            'policy' => self::POLICY,
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source ) {
                $r = $source->role( (string)$a['role'] );
                if ( $r === null )
                    throw new tronToolError( "There is no role '{$a['role']}'; roles: " . implode( ', ', array_column( $source->roles(), 'name' ) ) );
                $assignments = array();
                foreach ( (array)$r['assignments'] as $as )
                    $assignments[] = array( 'kind' => (string)$as['kind'], 'object_id' => (int)$as['object_id'],
                                            'name' => tronUnitValue::safeText( (string)$as['name'] ),
                                            'limit_identifier' => (string)( $as['limit_identifier'] ?? '' ), 'limit_value' => (string)( $as['limit_value'] ?? '' ) );
                $policies = array();
                foreach ( (array)$r['policies'] as $p )
                {
                    $lims = array();
                    foreach ( (array)( $p['limitations'] ?? array() ) as $l )
                        $lims[] = array( 'identifier' => (string)$l['identifier'], 'values' => array_values( array_map( 'strval', (array)$l['values'] ) ) );
                    $policies[] = array( 'module' => (string)$p['module'], 'function' => (string)$p['function'], 'limitations' => $lims );
                }
                return array( 'id' => (int)$r['id'], 'name' => (string)$r['name'], 'policies' => $policies, 'assignments' => $assignments );
            },
        ) );

        $this->unit( $registry, 'user.find', array(
            'title' => 'Find user accounts',
            'description' => 'User accounts whose login or name contains the text: user id, login, name, enabled, role names. Nothing else of the account (no e-mail address).',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'text' ), 'properties' => array(
                'text' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 100 ),
            ) + self::paging( 20, 50 ) ),
            'policy' => self::POLICY,
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source ) {
                $r = $source->findUsers( (string)$a['text'], (int)$a['limit'], (int)$a['offset'] );
                $items = array();
                foreach ( (array)$r['items'] as $u )
                    $items[] = array( 'user_id' => (int)$u['user_id'], 'login' => tronUnitValue::maskEmails( (string)$u['login'] ),
                                      'name' => tronUnitValue::maskEmails( (string)$u['name'] ), 'enabled' => (bool)( $u['enabled'] ?? true ),
                                      'roles' => array_values( array_map( 'strval', (array)( $u['roles'] ?? array() ) ) ) );
                return array( 'total' => (int)$r['total'], 'offset' => (int)$a['offset'], 'items' => $items );
            },
        ) );
    }
}
