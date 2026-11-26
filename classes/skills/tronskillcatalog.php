<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Every skill pack this installation has, and the state of every skill in it. Reads data only: no code of a pack
 * runs, ever.
 *
 * Where packs come from, in this order (the first pack of an id wins; a later one is a conflict):
 *   1. tron's own packs: <tron>/share/skills/<id>/pack.json                    ([SkillPackSettings] TronPacks)
 *   2. the active extensions: extension/<ext>/tron/skills/<id>/pack.json, each registered by a group
 *      [SkillPack_<id>] Extension=<ext> in that extension's settings/tron.ini.append.php. A pack directory without
 *      its group is listed as "unregistered" and not loaded.
 *
 * The state of a skill, from first to last check:
 *   refused     its pack breaks the standard (the reasons are listed; nothing of the pack is offered)
 *   parked      a unit it needs is not here (waiting for its wave), or it writes (waits for wave B's dry-run twin)
 *   unproven    the harness has not proven this exact manifest (proof.json has no pass for its hash)
 *   failed      the harness ran it and it failed
 *   ready       proven; waits for a person to approve it in Setup > tron > Skills
 *   live        proven and approved: offered as the MCP tool skill_<name>, a prompt and xsh tron:skill:run
 *   disabled    its pack is switched off ([SkillPackSettings] Disabled[], [SkillPack_<id>] Enabled, Setup > tron)
 */
final class tronSkillCatalog
{
    /** @var array */ private $settings;
    /** @var string */ private $tronDir;
    /** @var array */ private $extensions;
    /** @var array[] */ private $packs = null;
    /** @var array[] */ private $skills = null;

    /** @var array process cache: key => packs, so a Velocity worker reads a pack once per change */
    private static $cache = array();

    /**
     * @param array $settings   the tron.ini groups
     * @param array $extensions active extension name => directory
     */
    public function __construct( array $settings, string $tronDir, array $extensions )
    {
        $this->settings = $settings;
        $this->tronDir = rtrim( $tronDir, '/' );
        $this->extensions = $extensions;
    }

    public static function fromEnvironment( tronEnvironment $env ): self
    {
        $ext = $env->has( 'extensions' ) ? (array)$env->get( 'extensions' ) : array();
        $dir = $env->has( 'skills.tronDir' ) ? (string)$env->get( 'skills.tronDir' ) : dirname( __DIR__, 2 );
        return new self( $env->settings(), $dir, $ext );
    }

    private function s( string $group, string $name, $default = null )
    {
        return $this->settings[$group][$name] ?? $default;
    }

    /** @return array[] pack rows: id, origin, provider, dir, state, reason, pack (tronSkillPack|null) */
    public function packs(): array
    {
        if ( $this->packs !== null )
            return $this->packs;
        $found = $this->discover();
        $key = md5( json_encode( array_map( function ( $f ) {
            $files = array_merge( array( $f['dir'] . '/pack.json', $f['dir'] . '/proof.json' ), glob( $f['dir'] . '/skills/*.json' ) ?: array() );
            $m = 0;
            foreach ( $files as $x )
                $m = max( $m, (int)@filemtime( $x ) );
            return array( $f['dir'], $m, count( $files ) );
        }, $found ) ) . json_encode( $this->settings['SkillPackSettings'] ?? array() ) );
        if ( isset( self::$cache[$key] ) )
            return $this->packs = self::$cache[$key];

        $disabled = array_flip( array_map( 'strval', (array)$this->s( 'SkillPackSettings', 'Disabled', array() ) ) );
        $rows = array();
        $seen = array();
        foreach ( $found as $f )
        {
            $row = $f + array( 'state' => 'loaded', 'reason' => '', 'pack' => null, 'skills' => 0 );
            if ( isset( $seen[$f['id']] ) )
            {
                $rows[] = array( 'state' => 'conflict', 'reason' => "the id is taken by the pack from {$seen[$f['id']]}" ) + $row;
                continue;
            }
            $seen[$f['id']] = $f['origin'];
            if ( $f['unregistered'] )
            {
                $rows[] = array( 'state' => 'unregistered', 'reason' => "no [SkillPack_{$f['id']}] group with Extension={$f['origin']} in the extension's settings/tron.ini.append.php" ) + $row;
                continue;
            }
            try
            {
                $pack = tronSkillPack::read( $f['dir'], $f['origin'], $f['provider'] );
                $row['pack'] = $pack;
                $row['skills'] = count( $pack->skills );
                if ( tronVersion::compare( tronVersion::VERSION, $pack->requiresTron() ) < 0 )
                    $row = array( 'state' => 'incompatible', 'reason' => 'needs tron ' . $pack->requiresTron() . ' or later, this is ' . tronVersion::VERSION ) + $row;
                else if ( isset( $disabled[$f['id']] ) )
                    $row = array( 'state' => 'disabled', 'reason' => '[SkillPackSettings] Disabled[]' ) + $row;
                else if ( $f['ini_disabled'] )
                    $row = array( 'state' => 'disabled', 'reason' => "[SkillPack_{$f['id']}] Enabled=disabled" ) + $row;
            }
            catch ( tronSkillInvalid $e )
            {
                $row['state'] = 'refused';
                $row['reason'] = $e->getMessage();
                $row['reasons'] = $e->reasons();
            }
            $rows[] = $row;
        }
        if ( count( self::$cache ) > 8 )
            self::$cache = array();
        self::$cache[$key] = $rows;
        return $this->packs = $rows;
    }

    /** @return array[] found pack directories: id, dir, origin, provider, unregistered, ini_disabled */
    public function discover(): array
    {
        $out = array();
        if ( $this->s( 'SkillPackSettings', 'TronPacks', 'enabled' ) !== 'disabled' )
        {
            $dirs = glob( $this->tronDir . '/share/skills/*/pack.json' ) ?: array();
            sort( $dirs );
            $prov = tronSkillProvider::info( $this->tronDir, 'tron' );
            foreach ( $dirs as $p )
                $out[] = array( 'id' => basename( dirname( $p ) ), 'dir' => dirname( $p ), 'origin' => 'tron', 'provider' => $prov,
                                'unregistered' => false, 'ini_disabled' => false );
        }
        if ( $this->s( 'SkillPackSettings', 'ExtensionScan', 'enabled' ) === 'disabled' )
            return $out;
        // the registrations: [SkillPack_<id>] Extension=<ext> Path=tron/skills/<id>
        $reg = array();
        foreach ( $this->settings as $group => $values )
        {
            if ( strpos( (string)$group, 'SkillPack_' ) !== 0 || !is_array( $values ) )
                continue;
            $id = substr( (string)$group, 10 );
            $ext = (string)( $values['Extension'] ?? '' );
            $path = trim( (string)( $values['Path'] ?? 'tron/skills/' . $id ), '/' );
            if ( strpos( $path, '..' ) !== false )
                continue;
            $reg[$ext][$path] = array( 'id' => $id, 'disabled' => ( $values['Enabled'] ?? 'enabled' ) === 'disabled' );
        }
        foreach ( $this->extensions as $ext => $dir )
        {
            $dir = rtrim( (string)$dir, '/' );
            $prov = null;
            $paths = array();
            foreach ( glob( $dir . '/tron/skills/*/pack.json' ) ?: array() as $p )
                $paths[substr( dirname( $p ), strlen( $dir ) + 1 )] = true;
            foreach ( array_keys( $reg[(string)$ext] ?? array() ) as $path )
                if ( is_file( $dir . '/' . $path . '/pack.json' ) )
                    $paths[$path] = true;
            ksort( $paths );
            foreach ( array_keys( $paths ) as $path )
            {
                $prov = $prov ?? tronSkillProvider::info( $dir, (string)$ext );
                $r = $reg[(string)$ext][$path] ?? null;
                $out[] = array( 'id' => $r['id'] ?? basename( $path ), 'dir' => $dir . '/' . $path, 'origin' => (string)$ext, 'provider' => $prov,
                                'unregistered' => $r === null, 'ini_disabled' => $r !== null && $r['disabled'] );
            }
        }
        return $out;
    }

    public function pack( string $id ): ?tronSkillPack
    {
        foreach ( $this->packs() as $row )
            if ( $row['id'] === $id && $row['pack'] && $row['state'] !== 'conflict' )
                return $row['pack'];
        return null;
    }

    /**
     * Every skill of the loaded packs with its state.
     *
     * @param tronRegistry|null $registry the units registered here (null: units are not checked, state stops at "unproven"/"ready")
     * @param array $approved name => hash of the approved skills (tronSkillStore::approved())
     * @param array $packOff pack ids switched off in Setup > tron
     * @return array[] name => row: name, pack, origin, provider, manifest, state, reason, waits (units), proof
     */
    public function skills( ?tronRegistry $registry = null, array $approved = array(), array $packOff = array() ): array
    {
        $out = array();
        foreach ( $this->packs() as $row )
        {
            if ( !$row['pack'] || $row['state'] === 'conflict' )
                continue;
            /** @var tronSkillPack $pack */
            $pack = $row['pack'];
            foreach ( $pack->skills as $name => $m )
            {
                if ( isset( $out[$name] ) )
                    continue;
                $r = array( 'name' => $name, 'pack' => $pack->id, 'origin' => $row['origin'], 'provider' => $row['provider'],
                            'manifest' => $m, 'hash' => $m->hash(), 'proof' => $pack->proofOf( $m ), 'waits' => array() );
                $out[$name] = $r + self::state( $m, $row['state'], $row['reason'], $registry, $r['proof'], $approved, isset( $packOff[$pack->id] ) );
            }
        }
        ksort( $out );
        return $out;
    }

    /** The state of one skill (also used for drafts, with $packState "loaded"). */
    public static function state( tronSkillManifest $m, string $packState, string $packReason, ?tronRegistry $registry, ?array $proof, array $approved, bool $packOff = false ): array
    {
        if ( $packState === 'disabled' || $packState === 'incompatible' )
            return array( 'state' => 'disabled', 'reason' => $packReason );
        if ( $packOff )
            return array( 'state' => 'disabled', 'reason' => 'the pack is switched off in Setup > tron > Skills' );
        $waits = array();
        $writes = array();
        if ( $registry )
        {
            $unavailable = $registry->unavailableTools();
            foreach ( $m->units() as $u )
            {
                $t = $registry->findTool( $u );
                if ( !$t )
                    $waits[$u] = isset( $unavailable[$u] ) ? $u . ' is not offered here: ' . $unavailable[$u] : 'waits for the unit ' . $u . ' (wave ' . $m->wave() . ')';
                else if ( $t->write )
                    $writes[] = $u;
            }
        }
        if ( $waits )
            return array( 'state' => 'parked', 'reason' => implode( '; ', $waits ), 'waits' => array_keys( $waits ) );
        if ( $writes || in_array( $m->wave(), array( 'B', 'C', 'D', 'E' ), true ) && !$registry )
            return array( 'state' => 'parked', 'reason' => $writes ? 'writes (' . implode( ', ', $writes ) . '): skills that write wait for wave B (dry run of the whole chain, approval of the plan)' : 'needs wave ' . $m->wave() );
        if ( !$proof )
            return array( 'state' => 'unproven', 'reason' => 'the harness has not proven this manifest (php extension/tron/bin/php/tron-skills.php harness)' );
        if ( ( $proof['result'] ?? '' ) !== 'pass' )
            return array( 'state' => 'failed', 'reason' => 'the harness failed it: ' . (string)( $proof['reason'] ?? '' ) );
        if ( ( $approved[$m->name] ?? null ) !== $m->hash() )
            return array( 'state' => 'ready', 'reason' => isset( $approved[$m->name] ) ? 'changed since it was approved: approve again' : 'proven; waits for approval in Setup > tron > Skills' );
        return array( 'state' => 'live', 'reason' => '' );
    }

    /** Counts per state, and per pack. */
    public static function counts( array $skills ): array
    {
        $c = array( 'total' => 0, 'states' => array(), 'packs' => array() );
        foreach ( $skills as $s )
        {
            $c['total']++;
            $c['states'][$s['state']] = ( $c['states'][$s['state']] ?? 0 ) + 1;
            $c['packs'][$s['pack']][$s['state']] = ( $c['packs'][$s['pack']][$s['state']] ?? 0 ) + 1;
        }
        ksort( $c['states'] );
        ksort( $c['packs'] );
        return $c;
    }

    /** Search by text and tags: every word must be in the name, title, purpose, use case or tags. */
    public static function search( array $skills, string $q = '', array $tags = array(), string $state = '', string $pack = '' ): array
    {
        $words = array_filter( preg_split( '/\s+/', strtolower( trim( $q ) ) ) ?: array(), 'strlen' );
        $out = array();
        foreach ( $skills as $name => $s )
        {
            $d = $s['manifest']->data;
            if ( $state !== '' && $s['state'] !== $state )
                continue;
            if ( $pack !== '' && $s['pack'] !== $pack )
                continue;
            if ( $tags && array_diff( $tags, (array)$d['tags'] ) )
                continue;
            $hay = strtolower( $name . ' ' . $d['title'] . ' ' . $d['purpose'] . ' ' . $d['use_case'] . ' ' . implode( ' ', $d['tags'] ) );
            foreach ( $words as $w )
                if ( strpos( $hay, $w ) === false )
                    continue 2;
            $out[$name] = $s;
        }
        return $out;
    }

    /** The local packs (a site's own, where forks go): id => pack. */
    public function localPacks(): array
    {
        $out = array();
        foreach ( $this->packs() as $row )
            if ( $row['pack'] && $row['state'] === 'loaded' && $row['pack']->isLocal() )
                $out[$row['id']] = $row['pack'];
        return $out;
    }
}
