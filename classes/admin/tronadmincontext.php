<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Everything the administration views (Setup > tron) read and write through: the database, tron.ini's groups, the
 * user directory, the active extensions, the environment services, the clock, the translator, the audit sink and the
 * administrator acting. Inside an installation fromKernel() fills it from the kernel; the tests fill it with SQLite
 * and arrays, so the view data classes (tronAdmin*Data) run without an installation.
 */
final class tronAdminContext
{
    /** @var tronDb */ private $db;
    /** @var array */ private $settings;
    /** @var tronUserDirectory */ private $users;
    /** @var array */ private $extensions;
    /** @var array */ private $services;
    /** @var callable */ private $clock;
    /** @var callable|null */ private $translator;
    /** @var tronAuditSink|null */ private $audit;
    /** @var array */ private $actor;
    /** @var string|null */ private $builtinDir;
    /** @var tronServer[] */ private $servers = array();
    /** @var string[]|null */ private $missing = null;

    /**
     * Options: extensions (name => dir), services (environment services), clock (callable), translator
     * (callable( text, params ) => string), audit (tronAuditSink for the administrators' own actions), actor
     * (array( 'id' => int, 'login' => string )), builtinDir.
     */
    public function __construct( tronDb $db, array $settings, tronUserDirectory $users, array $options = array() )
    {
        $this->db = $db;
        $this->settings = $settings;
        $this->users = $users;
        $this->extensions = (array)( $options['extensions'] ?? array() );
        $this->services = (array)( $options['services'] ?? array() );
        $this->clock = $options['clock'] ?? 'time';
        $this->translator = $options['translator'] ?? null;
        $this->audit = $options['audit'] ?? null;
        $this->actor = (array)( $options['actor'] ?? array( 'id' => 0, 'login' => '' ) );
        $this->builtinDir = $options['builtinDir'] ?? null;
    }

    /** The context of the current request inside an installation. */
    public static function fromKernel(): self
    {
        $settings = tronKernel::settings();
        $user = eZUser::currentUser();
        $audit = null;
        try
        {
            $audit = tronKernel::audit( $settings );
        }
        catch ( RuntimeException $e )
        {
            // no sink configured: the views still work, the administrators' actions are then not recorded
        }
        return new self( tronKernel::db(), $settings, new tronKernelUserDirectory(), array(
            'extensions' => tronKernel::extensions(),
            'services' => tronKernel::services( $settings ),
            'translator' => function ( string $text, array $params ) { return ezpI18n::tr( 'extension/tron', $text, null, $params ); },
            'audit' => $audit,
            'actor' => array( 'id' => (int)$user->attribute( 'contentobject_id' ), 'login' => (string)$user->attribute( 'login' ) ),
        ) );
    }

    public function db(): tronDb
    {
        return $this->db;
    }

    public function settings(): array
    {
        return $this->settings;
    }

    public function setting( string $group, string $name, $default = null )
    {
        return $this->settings[$group][$name] ?? $default;
    }

    public function users(): tronUserDirectory
    {
        return $this->users;
    }

    public function now(): int
    {
        return (int)call_user_func( $this->clock );
    }

    public function actor(): array
    {
        return $this->actor;
    }

    public function tokens(): tronTokenService
    {
        return new tronTokenService( $this->db, $this->users, $this->clock );
    }

    public function switches(): tronSwitches
    {
        return new tronSwitches( $this->db, $this->clock );
    }

    /** Missing tron tables (tronSchema), cached for the request. */
    public function missingTables(): array
    {
        if ( $this->missing === null )
            $this->missing = tronSchema::missing( $this->db );
        return $this->missing;
    }

    /**
     * A server with every program and tool as an administrator sees them. With $switches the switched-off programs and
     * tools are left out (what agents get); without, everything is listed (what the switches can turn back on).
     * Its audit sink is a memory one: the views only list, they never call a tool.
     */
    public function server( bool $switches = true ): tronServer
    {
        $key = $switches ? 'live' : 'full';
        if ( isset( $this->servers[$key] ) )
            return $this->servers[$key];
        $switchedOff = array();
        if ( $switches && $this->db->tableExists( 'tron_switch' ) )
        {
            $sw = $this->switches();
            $switchedOff = array( 'program' => $sw->off( 'program' ), 'tool' => $sw->off( 'tool' ) );
        }
        $parts = array(
            'settings' => $this->settings,
            'access' => new tronRoleAccessChecker( array( 'all' => array( '*' . '/*' ) ), array( 0 => array( 'all' ) ) ),
            'audit' => new tronAuditMemory(),
            'db' => $this->db,
            'users' => $this->users,
            'extensions' => $this->extensions,
            'services' => $this->services,
            'clock' => $this->clock,
            'switchedOff' => $switchedOff,
        );
        if ( $this->builtinDir !== null )
            $parts['builtinDir'] = $this->builtinDir;
        return $this->servers[$key] = tronFactory::build( $parts );
    }

    /** Translates a text of the context extension/tron; %name placeholders are replaced in either case. */
    public function tr( string $text, array $params = array() ): string
    {
        if ( $this->translator )
            return (string)call_user_func( $this->translator, $text, $params );
        return strtr( $text, array_map( 'strval', $params ) );
    }

    /**
     * Records an administrator's action (token made, rotated, revoked; switch changed) in tron's audit log, as
     * transport "admin". Never throws: the action itself has already happened and is shown on the page.
     */
    public function recordAction( string $action, string $target, string $note ): void
    {
        if ( !$this->audit )
            return;
        try
        {
            $this->audit->record( array(
                'created' => $this->now(), 'user_id' => (int)( $this->actor['id'] ?? 0 ), 'login' => (string)( $this->actor['login'] ?? '' ),
                'token_id' => 0, 'transport' => 'admin', 'remote' => '', 'method' => 'admin/' . $action, 'target' => $target,
                'program' => 'tron', 'outcome' => 'ok', 'duration_ms' => 0,
                'detail' => json_encode( array( 'note' => $note ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
            ) );
        }
        catch ( Throwable $e )
        {
            // see above
        }
    }
}
