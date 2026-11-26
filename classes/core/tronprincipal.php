<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/** Who is calling: an Exponential user, and the token (if any) the call came with. */
final class tronPrincipal
{
    /** @var int */ public $userId;
    /** @var string */ public $login;
    /** @var int 0 when the caller was named on a local command line rather than by a token */ public $tokenId;
    /** @var string[] programs the token is limited to; array( '*' ) for all */ public $scopes;

    public function __construct( int $userId, string $login, int $tokenId = 0, array $scopes = array( '*' ) )
    {
        $this->userId = $userId;
        $this->login = $login;
        $this->tokenId = $tokenId;
        $this->scopes = $scopes ? array_values( $scopes ) : array( '*' );
    }

    /** Whether the token's scope covers the program (the core program "tron" is always covered). */
    public function inScope( string $program ): bool
    {
        return $program === 'tron' || in_array( '*', $this->scopes, true ) || in_array( $program, $this->scopes, true );
    }
}
