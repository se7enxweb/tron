<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The installation's own answer: the user's roles and policies through eZUser::hasAccessTo(), the same check the
 * admin interface makes. A missing or disabled user holds nothing. Answers are remembered for the lifetime of the
 * object (one request, or one stdio process: a role change then needs a new process, see doc/security.md).
 *
 * explain() (the policy simulator) reports the roles and policies that grant a module/function, with their
 * limitations and the limitation of the role assignment; the answer word is always hasAccessTo()'s.
 */
final class tronKernelAccessChecker implements tronAccessChecker, tronPolicyExplainer
{
    /** @var array */ private $cache = array();

    public function check( tronPrincipal $principal, string $module, string $function ): string
    {
        $key = $principal->userId . ':' . $module . '/' . $function;
        if ( isset( $this->cache[$key] ) )
            return $this->cache[$key];
        $user = eZUser::fetch( $principal->userId );
        if ( !$user instanceof eZUser || !$user->isEnabled() )
            return $this->cache[$key] = self::NO;
        $r = $user->hasAccessTo( $module, $function );
        $word = (string)( $r['accessWord'] ?? 'no' );
        return $this->cache[$key] = in_array( $word, array( self::YES, self::LIMITED ), true ) ? $word : self::NO;
    }

    public function explain( tronPrincipal $principal, string $module, string $function ): array
    {
        $user = eZUser::fetch( $principal->userId );
        if ( !$user instanceof eZUser )
            return array( 'access' => self::NO, 'matches' => array(), 'reason' => 'there is no such user' );
        if ( !$user->isEnabled() )
            return array( 'access' => self::NO, 'matches' => array(), 'reason' => 'the user is disabled, so the user holds no policy' );
        $word = $this->check( $principal, $module, $function );

        $matches = array();
        $ids = $user->groups();
        $ids[] = (int)$user->attribute( 'contentobject_id' );
        foreach ( (array)eZRole::fetchByUser( $ids, true ) as $role )
        {
            if ( !$role instanceof eZRole )
                continue;
            $assignment = array();
            $limitIdent = (string)$role->attribute( 'limit_identifier' );
            if ( $limitIdent !== '' )
                $assignment['User_' . $limitIdent] = array( (string)$role->attribute( 'limit_value' ) );
            foreach ( (array)$role->policyList() as $policy )
            {
                $m = (string)$policy->attribute( 'module_name' );
                $f = (string)$policy->attribute( 'function_name' );
                if ( ( $m !== '*' && $m !== $module ) || ( $f !== '*' && $f !== $function ) )
                    continue;
                $limitations = array();
                foreach ( (array)$policy->limitationList( true, true ) as $limitation )
                {
                    $values = array();
                    foreach ( (array)$limitation->attribute( 'values' ) as $v )
                        $values[] = is_object( $v ) ? (string)$v->attribute( 'value' ) : (string)$v;
                    $limitations[(string)$limitation->attribute( 'identifier' )] = $values;
                }
                $matches[] = array( 'role' => (string)$role->attribute( 'name' ), 'policy' => "$m/$f",
                                    'policy_id' => (int)$policy->attribute( 'id' ), 'limitations' => $limitations + $assignment );
            }
        }
        return array( 'access' => $word, 'matches' => $matches, 'reason' => tronRoleAccessChecker::reason( $word, $module, $function, $matches ) );
    }
}
