<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The agent rules layer (doc/agent-rules.md): which MASTER.md and which AGENTS.md an agent gets, from where, and
 * whether MASTER.md is switched on, all in one answer.
 *
 * Files. MASTER.md: the site override <dir>/MASTER.md when it exists, else tron's shipped doc/MASTER.md. AGENTS.md:
 * <dir>/AGENTS.md (there is no shipped default). <dir> is [PromptRules] Directory, by default
 * <VarDir>/storage/claude/prompt of the siteaccess (var/site/storage/claude/prompt on a standard installation).
 *
 * MASTER.md switches, in precedence order (the first one that says something decides for the site):
 *   1. file marker   <dir>/MASTER.disabled exists                      -> off
 *   2. environment   TRON_MASTER_RULES=enabled|disabled                 -> on or off
 *   3. admin toggle  Setup > tron > Agent rules (<dir>/rules-state.json) -> on or off
 *   4. INI           tron.ini [PromptRules] Master (siteaccess settings over settings/override over tron's default)
 *   5. default       enabled
 * then, only ever turning it off for themselves:
 *   6. user preference  tron_master_rules=disabled (eZPreferences of the session's user)
 *   7. session flag     initialize _meta "tron/masterRules": "disabled", the tool tron_rules or a prompt argument
 * A MASTER.md switched on but missing on disk is reported as not active (decided_by "missing").
 *
 * Without the kernel (tests), everything comes from the options: dir, defaultMaster, settings, getenv (callable),
 * userPreference (callable( userId ): ?string), iniPlacement (string), siteaccess (string).
 */
final class tronRules
{
    const ENV_VAR = 'TRON_MASTER_RULES';
    const MARKER = 'MASTER.disabled';
    const STATE = 'rules-state.json';
    const PREFERENCE = 'tron_master_rules';
    const SESSION_META = 'tron/masterRules';

    /** @var string|null */ private $dir;
    /** @var string */ private $defaultMaster;
    /** @var array */ private $settings;
    /** @var callable */ private $getenv;
    /** @var callable|null */ private $userPreference;
    /** @var string */ private $iniPlacement;
    /** @var string */ private $siteaccess;

    public function __construct( array $o = array() )
    {
        $this->settings = (array)( $o['settings'] ?? array() );
        $this->dir = isset( $o['dir'] ) && $o['dir'] !== '' ? rtrim( (string)$o['dir'], '/' ) : null;
        $this->defaultMaster = (string)( $o['defaultMaster'] ?? self::shippedMaster() );
        $this->getenv = $o['getenv'] ?? function ( string $name ) { $v = getenv( $name ); return $v === false ? null : $v; };
        $this->userPreference = $o['userPreference'] ?? null;
        $this->iniPlacement = (string)( $o['iniPlacement'] ?? '' );
        $this->siteaccess = (string)( $o['siteaccess'] ?? '' );
    }

    /** tron's own MASTER.md (doc/MASTER.md of the extension). */
    public static function shippedMaster(): string
    {
        return dirname( __DIR__, 2 ) . '/doc/MASTER.md';
    }

    /** The layer for a server's environment: its settings, plus the kernel where there is one. */
    public static function fromEnvironment( tronEnvironment $env ): self
    {
        $settings = $env->settings();
        $o = array( 'settings' => $settings );
        foreach ( array( 'dir', 'defaultMaster', 'getenv', 'userPreference', 'iniPlacement', 'siteaccess' ) as $k )
            if ( $env->has( 'rules.' . $k ) )
                $o[$k] = $env->get( 'rules.' . $k );
        return new self( $o + self::kernelOptions( $settings ) );
    }

    /** What only an installation knows: the storage directory, the user's preference, where the INI value came from. */
    public static function kernelOptions( array $settings ): array
    {
        $p = (array)( $settings['PromptRules'] ?? array() );
        $o = array();
        $configured = trim( (string)( $p['Directory'] ?? '' ) );
        if ( $configured !== '' )
            $o['dir'] = $configured;
        else if ( class_exists( 'eZSys', false ) || class_exists( 'eZSys' ) )
            $o['dir'] = eZSys::storageDirectory() . '/claude/prompt';
        if ( trim( (string)( $p['DefaultMaster'] ?? '' ) ) !== '' )
            $o['defaultMaster'] = (string)$p['DefaultMaster'];
        if ( class_exists( 'eZPreferences' ) && class_exists( 'eZUser' ) )
            $o['userPreference'] = function ( int $userId ) {
                $user = eZUser::fetch( $userId );
                if ( !$user )
                    return null;
                $v = eZPreferences::value( tronRules::PREFERENCE, $user );
                return $v === false || $v === null || $v === '' ? null : (string)$v;
            };
        if ( class_exists( 'eZINI' ) )
        {
            try
            {
                $ini = eZINI::instance( 'tron.ini' );
                $placements = method_exists( $ini, 'groupPlacements' ) ? $ini->groupPlacements() : array();
                $where = $placements['PromptRules']['Master'] ?? null;
                if ( $where !== null && method_exists( $ini, 'findSettingPlacement' ) )
                    $o['iniPlacement'] = (string)$ini->findSettingPlacement( $where );
            }
            catch ( Throwable $e )
            {
                // the placement is only reported, never needed
            }
        }
        if ( class_exists( 'eZSiteAccess' ) )
        {
            $current = eZSiteAccess::current();
            if ( is_array( $current ) && isset( $current['name'] ) )
                $o['siteaccess'] = (string)$current['name'];
        }
        return $o;
    }

    public function setting( string $name, $default = null )
    {
        return $this->settings['PromptRules'][$name] ?? $default;
    }

    /** The site's prompt directory, or null when none is known. */
    public function dir(): ?string
    {
        return $this->dir;
    }

    public function path( string $file ): ?string
    {
        return $this->dir === null ? null : $this->dir . '/' . $file;
    }

    public function maxBytes(): int
    {
        return max( 1000, (int)$this->setting( 'MaxBytes', 200000 ) );
    }

    // ------------------------------------------------------------------ files

    /** @return array path, source (override|default|missing), exists */
    public function masterFile(): array
    {
        $override = $this->path( 'MASTER.md' );
        if ( $override !== null && is_file( $override ) )
            return array( 'path' => $override, 'source' => 'override', 'exists' => true );
        if ( is_file( $this->defaultMaster ) )
            return array( 'path' => $this->defaultMaster, 'source' => 'default', 'exists' => true );
        return array( 'path' => $this->defaultMaster, 'source' => 'missing', 'exists' => false );
    }

    /** @return array path, source (override|none), exists */
    public function agentsFile(): array
    {
        $p = $this->path( 'AGENTS.md' );
        if ( $p !== null && is_file( $p ) )
            return array( 'path' => $p, 'source' => 'override', 'exists' => true );
        return array( 'path' => $p, 'source' => 'none', 'exists' => false );
    }

    private function read( ?string $path ): string
    {
        if ( $path === null || !is_file( $path ) || !is_readable( $path ) )
            return '';
        $s = (string)file_get_contents( $path, false, null, 0, $this->maxBytes() );
        return str_replace( "\r\n", "\n", $s );
    }

    /** The admin toggle as stored: enabled, disabled or '' (not set). */
    public function adminToggle(): string
    {
        $p = $this->path( self::STATE );
        if ( $p === null || !is_file( $p ) )
            return '';
        $d = json_decode( (string)file_get_contents( $p ), true );
        $v = is_array( $d ) ? (string)( $d['master'] ?? '' ) : '';
        return in_array( $v, array( 'enabled', 'disabled' ), true ) ? $v : '';
    }

    /** @return array the whole stored admin state (master, changed, changed_by) */
    public function adminState(): array
    {
        $p = $this->path( self::STATE );
        $d = $p !== null && is_file( $p ) ? json_decode( (string)file_get_contents( $p ), true ) : null;
        return is_array( $d ) ? $d : array();
    }

    public function markerExists(): bool
    {
        $p = $this->path( self::MARKER );
        return $p !== null && file_exists( $p );
    }

    // ------------------------------------------------------------------ switches

    private static function norm( $v ): string
    {
        $v = strtolower( trim( (string)$v ) );
        if ( in_array( $v, array( 'enabled', 'enable', 'on', '1', 'true', 'yes' ), true ) )
            return 'enabled';
        if ( in_array( $v, array( 'disabled', 'disable', 'off', '0', 'false', 'no' ), true ) )
            return 'disabled';
        return '';
    }

    /**
     * Whether MASTER.md is on for a user and a session, and which switch decided.
     *
     * @param array $session the session's own choices (array( 'master' => 'disabled' ))
     * @return array active (bool), decided_by, switches (list of name, label, value, decides, can)
     */
    public function decideMaster( ?int $userId = null, array $session = array() ): array
    {
        $marker = $this->markerExists() ? 'disabled' : '';
        $envValue = self::norm( call_user_func( $this->getenv, self::ENV_VAR ) );
        $admin = $this->adminToggle();
        $iniRaw = $this->setting( 'Master', null );
        $ini = self::norm( $iniRaw );
        $pref = '';
        if ( $userId !== null && $this->userPreference )
            $pref = self::norm( call_user_func( $this->userPreference, $userId ) );
        $sess = self::norm( $session['master'] ?? '' );

        $rows = array(
            array( 'name' => 'file-marker', 'label' => 'File marker ' . self::MARKER, 'value' => $marker, 'can' => 'off' ),
            array( 'name' => 'environment', 'label' => 'Environment variable ' . self::ENV_VAR, 'value' => $envValue, 'can' => 'on/off' ),
            array( 'name' => 'admin', 'label' => 'Admin toggle (Setup > tron > Agent rules)', 'value' => $admin, 'can' => 'on/off' ),
            array( 'name' => 'ini', 'label' => 'tron.ini [PromptRules] Master' . ( $this->iniPlacement !== '' ? ' (' . $this->iniPlacement . ( $this->siteaccess !== '' ? ', siteaccess ' . $this->siteaccess : '' ) . ')' : '' ), 'value' => $ini, 'can' => 'on/off' ),
            array( 'name' => 'default', 'label' => 'Default', 'value' => 'enabled', 'can' => 'on' ),
            array( 'name' => 'user', 'label' => 'User preference ' . self::PREFERENCE, 'value' => $pref, 'can' => 'off' ),
            array( 'name' => 'session', 'label' => 'Session flag ' . self::SESSION_META, 'value' => $sess, 'can' => 'off' ),
        );
        $active = true;
        $decidedBy = 'default';
        foreach ( array( 0, 1, 2, 3 ) as $i )
        {
            $v = $rows[$i]['value'];
            if ( $v === '' || ( $rows[$i]['can'] === 'off' && $v !== 'disabled' ) )
                continue;
            $active = $v === 'enabled';
            $decidedBy = $rows[$i]['name'];
            break;
        }
        if ( $active )
        {
            foreach ( array( 5, 6 ) as $i )
                if ( $rows[$i]['value'] === 'disabled' )
                {
                    $active = false;
                    $decidedBy = $rows[$i]['name'];
                    break;
                }
        }
        if ( $active && !$this->masterFile()['exists'] )
        {
            $active = false;
            $decidedBy = 'missing';
        }
        foreach ( $rows as $i => $r )
            $rows[$i]['decides'] = $r['name'] === $decidedBy;
        return array( 'active' => $active, 'decided_by' => $decidedBy, 'switches' => $rows );
    }

    public function agentsEnabled(): bool
    {
        return self::norm( $this->setting( 'Agents', 'enabled' ) ) !== 'disabled';
    }

    /**
     * How much an AGENTS.md says: missing, hollow (a skeleton: headings and placeholders, hardly a rule), weak (a few
     * rules) or solid. MASTER.md is there for the first three.
     */
    public static function strength( string $text ): string
    {
        if ( trim( $text ) === '' )
            return 'missing';
        $rules = 0;
        $words = 0;
        foreach ( explode( "\n", $text ) as $l )
        {
            $t = trim( $l );
            if ( $t === '' || $t[0] === '#' || preg_match( '/^(<!--|```)/', $t ) )
                continue;
            $plain = trim( preg_replace( '/^([-*+]|\d+\.)\s+/', '', $t ) );
            if ( $plain === '' || preg_match( '/^(TODO|TBD|\.\.\.|<[^>]+>|\[[^\]]*\])\.?$/i', $plain ) )
                continue;
            $n = str_word_count( $plain );
            $words += $n;
            if ( $n >= 4 )
                $rules++;
        }
        if ( $rules < 3 || $words < 40 )
            return 'hollow';
        if ( $rules < 12 || $words < 300 )
            return 'weak';
        return 'solid';
    }

    // ------------------------------------------------------------------ answers

    /**
     * Everything about the layer for one user and session.
     * @param bool $withPaths include file paths (administrators only)
     */
    public function state( ?int $userId = null, array $session = array(), bool $withPaths = false ): array
    {
        $decision = $this->decideMaster( $userId, $session );
        $mf = $this->masterFile();
        $af = $this->agentsFile();
        $masterText = $this->read( $mf['path'] );
        $agentsText = $this->agentsEnabled() ? $this->read( $af['path'] ) : '';
        $agentsActive = $this->agentsEnabled() && trim( $agentsText ) !== '';
        $out = array(
            'master' => array(
                'active' => $decision['active'], 'source' => $decision['active'] ? $mf['source'] : 'off',
                'file' => $mf['source'], 'decided_by' => $decision['decided_by'],
                'bytes' => strlen( $masterText ), 'sha256' => $masterText === '' ? null : hash( 'sha256', $masterText ),
            ),
            'agents' => array(
                'active' => $agentsActive, 'source' => $agentsActive ? $af['source'] : ( $af['exists'] && !$this->agentsEnabled() ? 'off' : 'none' ),
                'strength' => self::strength( $agentsText ), 'bytes' => strlen( $agentsText ),
                'sha256' => $agentsText === '' ? null : hash( 'sha256', $agentsText ),
            ),
            'precedence' => 'MASTER.md supersedes AGENTS.md: where the two conflict, MASTER.md wins.',
            'switches' => $decision['switches'],
        );
        if ( $withPaths )
        {
            $out['master']['path'] = $mf['path'];
            $out['master']['default_path'] = $this->defaultMaster;
            $out['master']['override_path'] = $this->path( 'MASTER.md' );
            $out['agents']['path'] = $this->path( 'AGENTS.md' );
            $out['dir'] = $this->dir;
            $out['marker_path'] = $this->path( self::MARKER );
        }
        return $out;
    }

    public function masterText(): string
    {
        return $this->read( $this->masterFile()['path'] );
    }

    public function agentsText(): string
    {
        return $this->read( $this->agentsFile()['path'] );
    }

    /**
     * The combined rules an agent receives: MASTER.md first, then the site's AGENTS.md, with the precedence stated.
     * '' when neither is active.
     */
    public function combined( ?int $userId = null, array $session = array() ): string
    {
        $st = $this->state( $userId, $session );
        $master = $st['master']['active'] ? trim( $this->masterText() ) : '';
        $agents = $st['agents']['active'] ? trim( $this->agentsText() ) : '';
        if ( $master === '' && $agents === '' )
            return '';
        $s = "# Project rules for agents\n\n"
           . 'Active: MASTER.md (' . $st['master']['source'] . ( $st['master']['active'] ? '' : ', decided by ' . $st['master']['decided_by'] ) . '), '
           . 'AGENTS.md (' . $st['agents']['source'] . ").\n";
        if ( $master !== '' && $agents !== '' )
            $s .= "Precedence: MASTER.md (the Exponential rules) SUPERSEDES the site's AGENTS.md. Where the two conflict, follow MASTER.md; "
                . "where they do not, follow both.\n";
        if ( $master !== '' )
            $s .= "\n<!-- MASTER.md (" . $st['master']['source'] . ") -->\n" . $master . "\n";
        if ( $agents !== '' )
            $s .= "\n<!-- AGENTS.md (site rules" . ( $master !== '' ? ', superseded by MASTER.md where they conflict' : '' ) . ") -->\n" . $agents . "\n";
        return $s;
    }

    /** The rules part of the initialize instructions, cut at [PromptRules] InstructionsMaxBytes. */
    public function instructions( ?int $userId = null, array $session = array() ): string
    {
        if ( self::norm( $this->setting( 'Instructions', 'enabled' ) ) === 'disabled' )
            return '';
        $text = $this->combined( $userId, $session );
        if ( $text === '' )
            return '';
        $max = (int)$this->setting( 'InstructionsMaxBytes', 40000 );
        if ( $max > 0 && strlen( $text ) > $max )
        {
            $cut = function_exists( 'mb_strcut' ) ? mb_strcut( $text, 0, $max, 'UTF-8' ) : substr( $text, 0, $max );
            $text = $cut . "\n\n[tron: the rules were cut at $max bytes; read the resource tron://rules/combined for all of them]";
        }
        return $text;
    }

    // ------------------------------------------------------------------ sessions

    /** The session's own choices: what initialize _meta said, then what the tool tron_rules set. */
    public static function sessionChoices( $session ): array
    {
        return is_object( $session ) && property_exists( $session, 'rules' ) && is_array( $session->rules ) ? $session->rules : array();
    }

    /** Takes the session flag from initialize params (_meta "tron/masterRules"). */
    public static function readInitialize( array $params, $session ): void
    {
        $meta = is_array( $params['_meta'] ?? null ) ? $params['_meta'] : array();
        if ( !isset( $meta[self::SESSION_META] ) || !is_object( $session ) || !property_exists( $session, 'rules' ) )
            return;
        $v = self::norm( $meta[self::SESSION_META] );
        if ( $v !== '' )
            $session->rules['master'] = $v;
    }

    /**
     * The initialize instructions with the rules appended. Never throws: a broken rules layer leaves the instructions
     * as they were.
     */
    public static function appendToInstructions( string $base, array $params, $session, tronEnvironment $env ): string
    {
        try
        {
            self::readInitialize( $params, $session );
            $rules = self::fromEnvironment( $env );
            $userId = is_object( $session ) && isset( $session->principal ) ? (int)$session->principal->userId : null;
            $text = $rules->instructions( $userId, self::sessionChoices( $session ) );
            return $text === '' ? $base : $base . "\n\n" . $text;
        }
        catch ( Throwable $e )
        {
            return $base;
        }
    }
}
