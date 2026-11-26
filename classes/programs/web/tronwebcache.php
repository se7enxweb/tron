<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * A small file cache for public answers ([WebSettings] CacheDir, CacheTTL): GET answers with status 200 and
 * robots.txt files, keyed by the URL. Never an answer to a request that carried credentials (tronWebProgram does
 * not send any). A directory that cannot be written turns the cache off silently.
 */
final class tronWebCache
{
    /** @var string */ private $dir;
    /** @var int */ private $ttl;
    /** @var callable */ private $now;

    public function __construct( string $dir, int $ttl, ?callable $now = null )
    {
        $this->dir = rtrim( $dir, '/' );
        $this->ttl = max( 0, $ttl );
        $this->now = $now ?: 'time';
    }

    public static function fromEnv( tronEnvironment $env ): self
    {
        return new self( (string)$env->setting( 'WebSettings', 'CacheDir', 'var/cache/tron/web' ),
                         tronWebGuard::networkOn( $env ) ? (int)$env->setting( 'WebSettings', 'CacheTTL', 600 ) : 0, function () use ( $env ) { return $env->now(); } );
    }

    private function file( string $key ): string
    {
        return $this->dir . '/' . substr( sha1( $key ), 0, 2 ) . '/' . sha1( $key ) . '.json';
    }

    public function get( string $key ): ?array
    {
        if ( $this->ttl === 0 || $this->dir === '' )
            return null;
        $f = $this->file( $key );
        if ( !is_file( $f ) )
            return null;
        $row = json_decode( (string)@file_get_contents( $f ), true );
        if ( !is_array( $row ) || ( $row['key'] ?? null ) !== $key || (int)( $row['at'] ?? 0 ) < call_user_func( $this->now ) - $this->ttl )
            return null;
        return (array)$row['value'];
    }

    public function put( string $key, array $value ): void
    {
        if ( $this->ttl === 0 || $this->dir === '' )
            return;
        $f = $this->file( $key );
        if ( !is_dir( dirname( $f ) ) && !@mkdir( dirname( $f ), 0775, true ) )
            return;
        $json = json_encode( array( 'key' => $key, 'at' => call_user_func( $this->now ), 'value' => $value ), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
        if ( $json === false )
            return;
        $tmp = $f . '.' . getmypid() . '.tmp';
        if ( @file_put_contents( $tmp, $json ) !== false )
            @rename( $tmp, $f );
    }
}
