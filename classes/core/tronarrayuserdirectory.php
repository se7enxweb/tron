<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/** A user list in memory, for tests and standalone runs: array( id => array( 'login' => ..., 'enabled' => bool ) ). */
final class tronArrayUserDirectory implements tronUserDirectory
{
    /** @var array */ private $users;

    public function __construct( array $users )
    {
        $this->users = $users;
    }

    public function byId( int $id ): ?array
    {
        if ( !isset( $this->users[$id] ) )
            return null;
        return array( 'id' => $id, 'login' => (string)$this->users[$id]['login'],
                      'enabled' => (bool)( $this->users[$id]['enabled'] ?? true ) );
    }

    public function byLogin( string $login ): ?array
    {
        foreach ( $this->users as $id => $u )
        {
            if ( (string)$u['login'] === $login )
                return $this->byId( (int)$id );
        }
        return null;
    }
}
