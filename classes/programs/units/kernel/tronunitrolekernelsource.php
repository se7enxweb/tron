<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The role units in an installation: eZRole (published roles), its policies and assignments, and accounts from
 * ezuser by login or object name. Only id, login, name, enabled and role names of an account are read.
 */
final class tronUnitRoleKernelSource implements tronUnitRoleSource
{
    public function available(): bool
    {
        return class_exists( 'eZRole' ) && class_exists( 'eZUser' );
    }

    public function reason(): string
    {
        return 'no roles here';
    }

    public function roles(): array
    {
        $out = array();
        foreach ( (array)eZRole::fetchList() as $r )
            $out[] = array( 'id' => (int)$r->attribute( 'id' ), 'name' => (string)$r->attribute( 'name' ) );
        return $out;
    }

    public function role( string $role ): ?array
    {
        $r = ctype_digit( $role ) ? eZRole::fetch( (int)$role ) : eZRole::fetchByName( $role );
        if ( !$r instanceof eZRole || (int)$r->attribute( 'version' ) !== 0 )
            return null;
        $policies = array();
        foreach ( (array)$r->policyList() as $p )
        {
            $lims = array();
            foreach ( (array)$p->limitationList() as $l )
            {
                $values = array();
                foreach ( (array)$l->attribute( 'values' ) as $v )
                    $values[] = is_object( $v ) ? (string)$v->attribute( 'value' ) : (string)$v;
                $lims[] = array( 'identifier' => (string)$l->attribute( 'identifier' ), 'values' => $values );
            }
            $policies[] = array( 'module' => (string)$p->attribute( 'module_name' ), 'function' => (string)$p->attribute( 'function_name' ), 'limitations' => $lims );
        }
        $assignments = array();
        foreach ( (array)$r->fetchUserByRole() as $a )
        {
            $o = $a['user_object'] ?? null;
            if ( !$o instanceof eZContentObject )
                continue;
            $assignments[] = array( 'kind' => $o->attribute( 'class_identifier' ) === 'user_group' ? 'group' : ( eZUser::isUserObject( $o ) ? 'user' : 'group' ),
                                    'object_id' => (int)$o->attribute( 'id' ), 'name' => (string)$o->attribute( 'name' ),
                                    'limit_identifier' => (string)( $a['limit_ident'] ?? '' ), 'limit_value' => (string)( $a['limit_value'] ?? '' ) );
        }
        return array( 'id' => (int)$r->attribute( 'id' ), 'name' => (string)$r->attribute( 'name' ), 'policies' => $policies, 'assignments' => $assignments );
    }

    public function findUsers( string $text, int $limit, int $offset ): array
    {
        $db = eZDB::instance();
        $like = $db->escapeString( '%' . addcslashes( mb_strtolower( $text ), '%_' ) . '%' );
        $where = "FROM ezuser u JOIN ezcontentobject o ON o.id = u.contentobject_id WHERE o.status = 1 AND ( LOWER( u.login ) LIKE '$like' OR LOWER( o.name ) LIKE '$like' )";
        $count = $db->arrayQuery( "SELECT COUNT(*) AS n $where" );
        $rows = $db->arrayQuery( "SELECT u.contentobject_id AS id, u.login AS login, o.name AS name $where ORDER BY u.login", array( 'limit' => $limit, 'offset' => $offset ) );
        $items = array();
        foreach ( (array)$rows as $row )
        {
            $id = (int)$row['id'];
            $setting = class_exists( 'eZUserSetting' ) ? eZUserSetting::fetch( $id ) : null;
            $roles = array();
            $user = eZUser::fetch( $id );
            if ( $user instanceof eZUser )
                foreach ( (array)$user->roles() as $r )
                    $roles[] = (string)$r->attribute( 'name' );
            $items[] = array( 'user_id' => $id, 'login' => (string)$row['login'], 'name' => (string)$row['name'],
                              'enabled' => $setting ? (bool)$setting->attribute( 'is_enabled' ) : true, 'roles' => array_values( array_unique( $roles ) ) );
        }
        return array( 'total' => (int)( $count[0]['n'] ?? 0 ), 'items' => $items );
    }
}
