<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/** The installation's users (eZUser). */
final class tronKernelUserDirectory implements tronUserDirectory
{
    public function byId( int $id ): ?array
    {
        return $id > 0 ? self::row( eZUser::fetch( $id ) ) : null;
    }

    public function byLogin( string $login ): ?array
    {
        return $login !== '' ? self::row( eZUser::fetchByName( $login ) ) : null;
    }

    private static function row( $user ): ?array
    {
        if ( !$user instanceof eZUser )
            return null;
        return array( 'id' => (int)$user->attribute( 'contentobject_id' ), 'login' => (string)$user->attribute( 'login' ),
                      'enabled' => (bool)$user->isEnabled() );
    }
}
