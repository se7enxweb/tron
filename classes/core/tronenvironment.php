<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * What tron hands to every program: settings, the database, a clock and named services. A program asks for what
 * it needs and works the same in an installation and in a test, because only the services differ.
 *
 * Standard service names: "db" (tronDb), "users" (tronUserDirectory), "access" (tronAccessChecker),
 * "katalyst.source" (tronKatalystSource). A program may add its own under "<program>.<name>".
 */
final class tronEnvironment
{
    /** @var array */ private $settings;
    /** @var array<string,mixed> */ private $services = array();
    /** @var array<string,bool> services whose factory has run */ private $resolved = array();
    /** @var callable|null */ private $clock;

    public function __construct( array $settings = array(), array $services = array(), ?callable $clock = null )
    {
        $this->settings = $settings;
        $this->services = $services;
        $this->clock = $clock;
    }

    /** A setting: $env->setting( 'CentreSettings', 'Lanes', array( 'inbox' ) ). */
    public function setting( string $group, string $name, $default = null )
    {
        return $this->settings[$group][$name] ?? $default;
    }

    public function settings(): array
    {
        return $this->settings;
    }

    public function has( string $service ): bool
    {
        return isset( $this->services[$service] );
    }

    /**
     * A service. One given to the constructor as a Closure is a factory: it runs on the first get() (with the
     * environment as its argument) and its result is kept. A value given to set() is kept as it is, Closures too.
     */
    public function get( string $service )
    {
        if ( !isset( $this->services[$service] ) )
            throw new RuntimeException( "The service '$service' is not available here" );
        if ( !isset( $this->resolved[$service] ) )
        {
            if ( $this->services[$service] instanceof Closure )
                $this->services[$service] = call_user_func( $this->services[$service], $this );
            $this->resolved[$service] = true;
        }
        return $this->services[$service];
    }

    public function set( string $service, $value ): void
    {
        $this->services[$service] = $value;
        $this->resolved[$service] = true;
    }

    public function now(): int
    {
        return $this->clock ? (int)call_user_func( $this->clock ) : time();
    }
}
