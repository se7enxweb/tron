<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The browser job queue: a directory the runner (bin/python/tron_browser_runner.py, started by systemd on the
 * runner node) watches. A job is a JSON file in queue/, claimed by the runner with an atomic rename into running/,
 * answered in done/<id>.json, its screenshots in artifacts/<id>/. tron only writes new jobs and reads answers.
 *
 * A job holds the kind, the engine, the arguments and who asked (user id and login, for the journal); never a
 * secret. Ids are 24 hex characters.
 */
final class tronBrowserQueue
{
    const ID = '/^[a-f0-9]{24}$/';

    /** @var string */ private $dir;
    /** @var callable */ private $now;
    /** @var callable */ private $sleep;

    public function __construct( string $dir, ?callable $now = null, ?callable $sleep = null )
    {
        $this->dir = rtrim( $dir, '/' );
        $this->now = $now ?: 'time';
        $this->sleep = $sleep ?: function ( int $ms ) { usleep( $ms * 1000 ); };
    }

    public static function fromEnv( tronEnvironment $env ): self
    {
        $sleep = $env->has( 'browser.sleep' ) ? $env->get( 'browser.sleep' ) : null;
        return new self( (string)$env->setting( 'BrowserSettings', 'QueueDir', 'var/tron/browser' ), function () use ( $env ) { return $env->now(); }, $sleep );
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function submit( string $kind, string $engine, array $spec, tronPrincipal $p, int $timeout ): string
    {
        foreach ( array( 'queue', 'running', 'done', 'artifacts' ) as $d )
            if ( !is_dir( $this->dir . '/' . $d ) && !@mkdir( $this->dir . '/' . $d, 02775, true ) && !is_dir( $this->dir . '/' . $d ) )
                throw new tronToolError( 'The browser queue directory cannot be created: ' . $this->dir );
        $id = bin2hex( random_bytes( 12 ) );
        $job = array( 'id' => $id, 'kind' => $kind, 'engine' => $engine, 'spec' => $spec, 'timeout' => $timeout,
                      'user_id' => $p->userId, 'login' => $p->login, 'created' => gmdate( 'c', (int)call_user_func( $this->now ) ) );
        // named by time first, so the runner takes jobs in the order they came
        $name = sprintf( '%010d-%s.json', (int)call_user_func( $this->now ), $id );
        $tmp = $this->dir . '/queue/.' . $id . '.tmp';
        if ( @file_put_contents( $tmp, json_encode( $job, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) ) === false || !@rename( $tmp, $this->dir . '/queue/' . $name ) )
            throw new tronToolError( 'The browser job could not be queued' );
        @chmod( $this->dir . '/queue/' . $name, 0664 );
        return $id;
    }

    /** The job's state: queued, running, done, failed, or null when unknown. With the answer when finished. */
    public function status( string $id ): ?array
    {
        if ( !preg_match( self::ID, $id ) )
            return null;
        $done = $this->dir . '/done/' . $id . '.json';
        if ( is_file( $done ) )
        {
            $r = json_decode( (string)@file_get_contents( $done ), true );
            if ( is_array( $r ) )
                return $r;
        }
        foreach ( array( 'running', 'queue' ) as $where )
            foreach ( glob( $this->dir . '/' . $where . '/*-' . $id . '.json' ) ?: array() as $f )
            {
                $j = json_decode( (string)@file_get_contents( $f ), true );
                return array( 'id' => $id, 'kind' => $j['kind'] ?? null, 'engine' => $j['engine'] ?? null,
                              'state' => $where === 'queue' ? 'queued' : 'running', 'user_id' => $j['user_id'] ?? null );
            }
        return null;
    }

    /** Waits up to $seconds for the job to finish; returns its last state. */
    public function wait( string $id, int $seconds ): ?array
    {
        // four looks a second, counted rather than timed, so a stubbed sleep in tests does not spin
        for ( $left = max( 0, $seconds ) * 4; ; $left-- )
        {
            $s = $this->status( $id );
            if ( $s === null || in_array( $s['state'], array( 'done', 'failed' ), true ) || $left <= 0 )
                return $s;
            call_user_func( $this->sleep, 250 );
        }
    }

    /** queued and running job counts */
    public function counts(): array
    {
        return array( 'queued' => count( glob( $this->dir . '/queue/*.json' ) ?: array() ), 'running' => count( glob( $this->dir . '/running/*.json' ) ?: array() ) );
    }
}
