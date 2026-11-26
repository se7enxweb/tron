<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Setup > tron > Tokens: list with filters, make, rotate, revoke. Every change is recorded in tron's audit log as an
 * administrator's action (transport "admin"), with the token's id and prefix, never the token.
 */
final class tronAdminTokensData
{
    const STATES = array( 'active', 'expired', 'revoked' );

    /** @var tronAdminContext */ private $c;

    public function __construct( tronAdminContext $c )
    {
        $this->c = $c;
    }

    public function maxDays(): int
    {
        return max( 1, (int)$this->c->setting( 'TokenSettings', 'MaxLifetimeDays', 365 ) );
    }

    public function defaultDays(): int
    {
        return min( $this->maxDays(), max( 1, (int)$this->c->setting( 'TokenSettings', 'DefaultLifetimeDays', 90 ) ) );
    }

    /** @return array[] tokens newest first, filtered by login ('' all) and state ('' all) */
    public function listing( string $login, string $state ): array
    {
        $uid = null;
        if ( $login !== '' )
        {
            $u = $this->c->users()->byLogin( $login );
            $uid = $u ? (int)$u['id'] : -1;
        }
        $rows = $this->c->tokens()->listTokens( $uid );
        if ( in_array( $state, self::STATES, true ) )
            $rows = array_values( array_filter( $rows, function ( $r ) use ( $state ) { return $r['state'] === $state; } ) );
        return $rows;
    }

    /** Program names a token may be scoped to (every loaded program but the core). */
    public function scopeChoices(): array
    {
        $names = array();
        foreach ( (array)$this->c->server()->option( 'programs' ) as $p )
        {
            if ( $p['name'] !== 'tron' && $p['state'] === 'loaded' )
                $names[] = $p['name'];
        }
        sort( $names );
        return $names;
    }

    /**
     * Makes a token. Returns array( 'token' => plain token (shown once), 'id', 'prefix', 'expires', 'scopes', 'login' )
     * or array( 'error' => message ).
     * @param string[] $scopes
     */
    public function create( string $login, string $name, int $days, array $scopes ): array
    {
        $max = $this->maxDays();
        if ( $days < 1 || $days > $max )
            return array( 'error' => $this->c->tr( 'A token lives 1 to %max days.', array( '%max' => $max ) ) );
        $u = $this->c->users()->byLogin( trim( $login ) );
        if ( !$u )
            return array( 'error' => $this->c->tr( 'There is no user with the login %login.', array( '%login' => $login ) ) );
        $scopes = array_values( array_intersect( $scopes, $this->scopeChoices() ) );
        try
        {
            $r = $this->c->tokens()->create( (int)$u['id'], $name, $days, $scopes ?: array( '*' ), (int)$this->c->actor()['id'] ) + array( 'login' => $u['login'] );
        }
        catch ( InvalidArgumentException $e )
        {
            return array( 'error' => $e->getMessage() );
        }
        $this->c->recordAction( 'token.create', 'token ' . $r['id'],
                                "token {$r['id']} ({$r['prefix']}) for {$u['login']}, " . trim( $name ) . ", $days days, scopes " . implode( ',', $r['scopes'] ) );
        return $r;
    }

    /**
     * Replaces a token: a new one for the same user, purpose, scopes and lifetime, and the old one revoked. Returns
     * the new token like create(), with 'replaced' => old id, or array( 'error' => message ).
     */
    public function rotate( int $id ): array
    {
        try
        {
            $r = $this->c->tokens()->rotate( $id, (int)$this->c->actor()['id'], $this->maxDays() );
        }
        catch ( InvalidArgumentException $e )
        {
            return array( 'error' => $this->c->tr( 'Token %id cannot be rotated: %reason', array( '%id' => $id, '%reason' => $e->getMessage() ) ) );
        }
        $u = $this->c->users()->byId( (int)$r['user_id'] );
        $r['login'] = $u ? $u['login'] : '';
        $this->c->recordAction( 'token.rotate', 'token ' . $id, "token $id replaced by token {$r['id']} ({$r['prefix']}) for {$r['login']}" );
        return $r;
    }

    /** Revokes a token. Returns the notice for the page. */
    public function revoke( int $id ): array
    {
        if ( $this->c->tokens()->revoke( (string)$id ) )
        {
            $this->c->recordAction( 'token.revoke', 'token ' . $id, "token $id revoked" );
            return array( 'notice' => $this->c->tr( 'Token %id revoked.', array( '%id' => $id ) ) );
        }
        return array( 'error' => $this->c->tr( 'Token %id was not active.', array( '%id' => $id ) ) );
    }
}
