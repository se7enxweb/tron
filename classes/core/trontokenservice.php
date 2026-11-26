<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Per-user access tokens.
 *
 * A token reads tron_<prefix>_<secret>: the prefix (12 characters) finds the row, the secret (43 characters,
 * 256 random bits) proves it. Only the SHA-256 of the whole token is stored, so the table never holds a usable
 * token; the plain token is shown once, when it is made. A token belongs to one Exponential user and acts with that
 * user's roles and policies; it can be limited to some programs (scopes), expires, and can be revoked.
 */
final class tronTokenService
{
    const PATTERN = '/^tron_([A-Za-z0-9]{12})_([A-Za-z0-9_-]{43})$/';
    /** last_used is written at most this often per token, so a busy agent does not write on every request */
    const TOUCH_INTERVAL = 60;

    /** @var tronDb */ private $db;
    /** @var tronUserDirectory */ private $users;
    /** @var callable */ private $clock;

    public function __construct( tronDb $db, tronUserDirectory $users, ?callable $clock = null )
    {
        $this->db = $db;
        $this->users = $users;
        $this->clock = $clock ?: 'time';
    }

    /**
     * Makes a token for a user. Returns array( 'token' => plain token (show once), 'id' => row id, 'prefix', 'expires' ).
     * @param string[] $scopes program names, or array( '*' )
     */
    public function create( int $userId, string $name, int $lifetimeDays, array $scopes = array( '*' ), int $createdBy = 0 ): array
    {
        $user = $this->users->byId( $userId );
        if ( !$user )
            throw new InvalidArgumentException( "There is no user with the id $userId" );
        if ( !$user['enabled'] )
            throw new InvalidArgumentException( "The user {$user['login']} is disabled" );
        $name = trim( $name );
        if ( $name === '' || strlen( $name ) > 100 )
            throw new InvalidArgumentException( 'A token needs a name of 1 to 100 characters (what it is for, e.g. "claude@cloud")' );
        if ( $lifetimeDays < 1 )
            throw new InvalidArgumentException( 'A token lives at least one day' );
        $scopes = self::normaliseScopes( $scopes );

        $prefix = self::randomString( 12, false );
        $secret = self::randomString( 43, true );
        $token = 'tron_' . $prefix . '_' . $secret;
        $now = $this->now();
        $expires = $now + $lifetimeDays * 86400;
        $id = $this->db->insert( 'tron_token', array(
            'user_id' => $userId, 'name' => $name, 'token_prefix' => $prefix, 'token_hash' => hash( 'sha256', $token ),
            'scopes' => implode( ',', $scopes ), 'created' => $now, 'expires' => $expires, 'last_used' => 0,
            'revoked' => 0, 'created_by' => $createdBy,
        ) );
        return array( 'token' => $token, 'id' => $id, 'prefix' => $prefix, 'expires' => $expires, 'scopes' => $scopes );
    }

    /**
     * The principal a token stands for, or null with $reason set (malformed, unknown, revoked, expired, user gone or
     * disabled). Never says more than that to the caller; the reason goes to the audit log.
     */
    public function authenticate( string $token, ?string &$reason = null ): ?tronPrincipal
    {
        $reason = null;
        if ( !preg_match( self::PATTERN, $token, $m ) )
        {
            $reason = 'malformed token';
            return null;
        }
        $rows = $this->db->select( 'SELECT * FROM tron_token WHERE token_prefix = :p', array( 'p' => $m[1] ) );
        if ( !$rows || !hash_equals( (string)$rows[0]['token_hash'], hash( 'sha256', $token ) ) )
        {
            $reason = 'unknown token ' . $m[1];
            return null;
        }
        $row = $rows[0];
        $now = $this->now();
        if ( (int)$row['revoked'] > 0 )
        {
            $reason = 'revoked token ' . $m[1];
            return null;
        }
        if ( (int)$row['expires'] > 0 && (int)$row['expires'] <= $now )
        {
            $reason = 'expired token ' . $m[1];
            return null;
        }
        $user = $this->users->byId( (int)$row['user_id'] );
        if ( !$user || !$user['enabled'] )
        {
            $reason = 'token ' . $m[1] . ' of a missing or disabled user';
            return null;
        }
        if ( $now - (int)$row['last_used'] >= self::TOUCH_INTERVAL )
            $this->db->execute( 'UPDATE tron_token SET last_used = :t WHERE id = :id', array( 't' => $now, 'id' => (int)$row['id'] ) );
        $scopes = $row['scopes'] === '' ? array( '*' ) : explode( ',', (string)$row['scopes'] );
        return new tronPrincipal( (int)$user['id'], (string)$user['login'], (int)$row['id'], $scopes );
    }

    /** @return array[] tokens without their hash, newest first; all users when $userId is null */
    public function listTokens( ?int $userId = null ): array
    {
        $where = $userId !== null ? ' WHERE user_id = :u' : '';
        $rows = $this->db->select( 'SELECT id, user_id, name, token_prefix, scopes, created, expires, last_used, revoked, created_by FROM tron_token'
                                   . $where . ' ORDER BY id DESC', $userId !== null ? array( 'u' => $userId ) : array() );
        $now = $this->now();
        foreach ( $rows as &$r )
        {
            $r['state'] = (int)$r['revoked'] > 0 ? 'revoked' : ( (int)$r['expires'] > 0 && (int)$r['expires'] <= $now ? 'expired' : 'active' );
            $u = $this->users->byId( (int)$r['user_id'] );
            $r['login'] = $u ? $u['login'] : '';
        }
        return $rows;
    }

    /** Revokes a token by id or prefix. Returns false when there is no such active token. */
    public function revoke( string $idOrPrefix ): bool
    {
        $now = $this->now();
        if ( ctype_digit( $idOrPrefix ) )
            $rows = $this->db->select( 'SELECT id, revoked FROM tron_token WHERE id = :id', array( 'id' => (int)$idOrPrefix ) );
        else
            $rows = $this->db->select( 'SELECT id, revoked FROM tron_token WHERE token_prefix = :p', array( 'p' => $idOrPrefix ) );
        if ( !$rows || (int)$rows[0]['revoked'] > 0 )
            return false;
        $this->db->execute( 'UPDATE tron_token SET revoked = :t WHERE id = :id', array( 't' => $now, 'id' => (int)$rows[0]['id'] ) );
        return true;
    }

    /** One token without its hash (with state and login), or null. */
    public function find( int $id ): ?array
    {
        foreach ( $this->db->select( 'SELECT id, user_id FROM tron_token WHERE id = :id', array( 'id' => $id ) ) as $r )
        {
            foreach ( $this->listTokens( (int)$r['user_id'] ) as $t )
            {
                if ( (int)$t['id'] === $id )
                    return $t;
            }
        }
        return null;
    }

    /**
     * Replaces a token: makes a new one for the same user, name and scopes, living as long as the old one was made to
     * live (at most $maxDays), then revokes the old one. An expired token can be rotated (renewed); a revoked one not.
     * Returns create()'s answer plus 'user_id' and 'replaced' => the old id.
     */
    public function rotate( int $id, int $createdBy = 0, int $maxDays = 365 ): array
    {
        $old = $this->find( $id );
        if ( !$old )
            throw new InvalidArgumentException( "there is no token $id" );
        if ( $old['state'] === 'revoked' )
            throw new InvalidArgumentException( "token $id is revoked" );
        $days = (int)$old['expires'] > 0 ? (int)round( ( (int)$old['expires'] - (int)$old['created'] ) / 86400 ) : $maxDays;
        $days = max( 1, min( $maxDays, $days ) );
        $scopes = (string)$old['scopes'] === '' ? array( '*' ) : explode( ',', (string)$old['scopes'] );
        $new = $this->create( (int)$old['user_id'], (string)$old['name'], $days, $scopes, $createdBy );
        $this->db->execute( 'UPDATE tron_token SET revoked = :t WHERE id = :id AND revoked = 0', array( 't' => $this->now(), 'id' => $id ) );
        return $new + array( 'user_id' => (int)$old['user_id'], 'replaced' => $id, 'days' => $days );
    }

    /** @return string[] */
    public static function normaliseScopes( array $scopes ): array
    {
        $out = array();
        foreach ( $scopes as $s )
        {
            foreach ( explode( ',', (string)$s ) as $one )
            {
                $one = trim( $one );
                if ( $one === '' )
                    continue;
                if ( $one !== '*' && !preg_match( '/^[a-z][a-z0-9]{1,31}$/', $one ) )
                    throw new InvalidArgumentException( "'$one' is not a program name" );
                $out[$one] = $one;
            }
        }
        if ( !$out || isset( $out['*'] ) )
            return array( '*' );
        return array_values( $out );
    }

    private function now(): int
    {
        return (int)call_user_func( $this->clock );
    }

    private static function randomString( int $length, bool $urlSafeExtras ): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789' . ( $urlSafeExtras ? '-_' : '' );
        $n = strlen( $alphabet );
        $s = '';
        for ( $i = 0; $i < $length; $i++ )
            $s .= $alphabet[random_int( 0, $n - 1 )];
        return $s;
    }
}
