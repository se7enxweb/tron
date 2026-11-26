<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * A role table with the kernel's semantics, for tests and runs without an installation:
 *
 *   new tronRoleAccessChecker(
 *       array( 'viewer' => array( 'tron/use', 'tron/centre_read' ), 'admin' => array( '*' . '/*' ),
 *              'scoped' => array( 'tron/use', 'tron/centre_read:limited' ) ),
 *       array( 14 => array( 'admin' ), 20 => array( 'viewer' ) ) );       // user id => roles
 *
 * "module/*" grants every function of a module, "*" + "/*" everything; a ":limited" suffix grants with limitations,
 * which the kernel reports as "limited". A disabled user (listed in $disabled) holds nothing.
 */
final class tronRoleAccessChecker implements tronAccessChecker, tronPolicyExplainer
{
    /** @var array */ private $roles;
    /** @var array */ private $assignments;
    /** @var int[] */ private $disabled;

    public function __construct( array $roles, array $assignments, array $disabled = array() )
    {
        $this->roles = $roles;
        $this->assignments = $assignments;
        $this->disabled = array_map( 'intval', $disabled );
    }

    public function check( tronPrincipal $principal, string $module, string $function ): string
    {
        return $this->explain( $principal, $module, $function )['access'];
    }

    /**
     * The grants that match, as the kernel would list policies. A grant "module/function:limited" is limited without
     * named values; "module/function:Subtree=/1/2/,/1/5/" is limited with the values of one limitation.
     */
    public function explain( tronPrincipal $principal, string $module, string $function ): array
    {
        if ( in_array( $principal->userId, $this->disabled, true ) )
            return array( 'access' => self::NO, 'matches' => array(), 'reason' => 'the user is disabled, so the user holds no policy' );
        $matches = array();
        $best = self::NO;
        foreach ( (array)( $this->assignments[$principal->userId] ?? array() ) as $role )
        {
            foreach ( (array)( $this->roles[$role] ?? array() ) as $grant )
            {
                $colon = strpos( $grant, ':' );
                $g = $colon === false ? $grant : substr( $grant, 0, $colon );
                if ( $g !== '*/*' && $g !== $module . '/*' && $g !== $module . '/' . $function )
                    continue;
                $limitations = array();
                if ( $colon !== false )
                {
                    $spec = substr( $grant, $colon + 1 );
                    if ( strpos( $spec, '=' ) !== false )
                    {
                        list( $ident, $values ) = explode( '=', $spec, 2 );
                        $limitations[$ident] = explode( ',', $values );
                    }
                    else
                        $limitations['Limited'] = array( $spec );
                }
                $matches[] = array( 'role' => (string)$role, 'policy' => $g, 'policy_id' => null, 'limitations' => $limitations );
                if ( !$limitations )
                    $best = self::YES;
                else if ( $best === self::NO )
                    $best = self::LIMITED;
            }
        }
        return array( 'access' => $best, 'matches' => $matches, 'reason' => self::reason( $best, $module, $function, $matches ) );
    }

    /** One sentence for an answer word and its matches (shared with the kernel's checker). */
    public static function reason( string $access, string $module, string $function, array $matches ): string
    {
        if ( $access === self::NO )
            return "no role of the user grants $module/$function";
        $roles = array_values( array_unique( array_map( function ( $m ) { return $m['role']; }, $matches ) ) );
        if ( $access === self::YES )
            return "$module/$function is granted without limitations by the role" . ( count( $roles ) > 1 ? 's ' : ' ' ) . implode( ', ', $roles );
        return "$module/$function is granted only with limitations, by the role" . ( count( $roles ) > 1 ? 's ' : ' ' ) . implode( ', ', $roles )
             . '; the object or subtree decides';
    }
}
