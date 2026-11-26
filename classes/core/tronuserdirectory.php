<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/** Looks up Exponential users: array( 'id' => int, 'login' => string, 'enabled' => bool ) or null. */
interface tronUserDirectory
{
    public function byId( int $id ): ?array;

    public function byLogin( string $login ): ?array;
}
