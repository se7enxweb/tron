<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * A skill pack: a directory with pack.json, README.md, skills/*.json and, once the harness has run, proof.json.
 * Packs are the loadable parts of the skill library: tron ships its own under share/skills/, and any extension ships
 * its own under extension/<ext>/tron/skills/<pack>/ with a [SkillPack_<id>] group in its settings/tron.ini.append.php
 * (doc/extension-conventions.md).
 *
 *   pack.json {
 *     "id": "centre-tickets",                 required, ^[a-z][a-z0-9-]{1,47}$, the same as the directory name
 *     "title": "...", "description": "...",   required
 *     "version": "0.0.0.1",                   required, X.Y.Z.N
 *     "author": "7x", "license": "GPL-2.0-or-later",   required; every skill carries the pack's license (or a fork's)
 *     "prefix": "centre",                     required: every skill of the pack is named <prefix>.<...>
 *     "requires": { "tron": "0.0.0.3", "programs": [ "centre" ] },   tron required
 *     "for_extension": "expcentre",           optional: the extension whose data the pack works on
 *     "local": false                          optional: true for a site's own pack that forks are written to
 *   }
 *
 * read() refuses a pack that breaks the standard, with every reason, and nothing of it is offered.
 */
final class tronSkillPack
{
    const ID_PATTERN = '/^[a-z][a-z0-9-]{1,47}$/';
    const KEYS = array( 'id', 'title', 'description', 'version', 'author', 'license', 'prefix', 'requires', 'for_extension', 'local', 'tags', 'i18n' );

    /** @var string */ public $id;
    /** @var array */ public $data;
    /** @var string */ public $dir;
    /** @var string "tron" or the providing extension */ public $origin;
    /** @var array name, version, license of the provider */ public $provider = array();
    /** @var tronSkillManifest[] name => manifest */ public $skills = array();
    /** @var array name => proof row */ public $proof = array();

    /** @throws tronSkillInvalid */
    public static function read( string $dir, string $origin = 'tron', array $provider = array() ): self
    {
        $dir = rtrim( $dir, '/' );
        $raw = @file_get_contents( $dir . '/pack.json' );
        $d = $raw === false ? null : json_decode( $raw, true );
        if ( !is_array( $d ) || tronSchemaValidator::isList( $d ) )
            throw new tronSkillInvalid( "the pack in $dir is refused", array( 'pack.json is missing or not a JSON object' ) );
        $e = self::errors( $d, $dir );
        $p = new self();
        $p->id = (string)( $d['id'] ?? basename( $dir ) );
        $p->data = $d;
        $p->dir = $dir;
        $p->origin = $origin;
        $p->provider = $provider;
        $files = glob( $dir . '/skills/*.json' ) ?: array();
        sort( $files );
        if ( !$files && empty( $d['local'] ) )
            $e[] = 'skills/: the pack has no skills (skills/<name>.json)';
        foreach ( $files as $f )
        {
            try
            {
                $m = tronSkillManifest::fromFile( $f );
            }
            catch ( tronSkillInvalid $x )
            {
                foreach ( $x->reasons() as $r )
                    $e[] = 'skills/' . basename( $f ) . ': ' . $r;
                continue;
            }
            if ( basename( $f ) !== $m->name . '.json' )
                $e[] = 'skills/' . basename( $f ) . ": the file is named after the skill ({$m->name}.json)";
            if ( isset( $d['prefix'] ) && is_string( $d['prefix'] ) && strpos( $m->name, $d['prefix'] . '.' ) !== 0 )
                $e[] = "skills/{$m->name}.json: the name must start with the pack prefix '{$d['prefix']}.'";
            if ( isset( $p->skills[$m->name] ) )
                $e[] = "skills: {$m->name} twice";
            if ( isset( $d['license'] ) && $m->data['license'] !== $d['license'] && !isset( $m->data['derived_from'] ) )
                $e[] = "skills/{$m->name}.json: license {$m->data['license']} differs from the pack's {$d['license']}";
            if ( isset( $d['requires']['tron'] ) && is_string( $d['requires']['tron'] ) && tronVersion::isValid( $d['requires']['tron'] )
                 && tronVersion::compare( $m->data['requires']['tron'], $d['requires']['tron'] ) > 0 )
                $e[] = "skills/{$m->name}.json: needs tron {$m->data['requires']['tron']}, more than the pack declares ({$d['requires']['tron']})";
            $p->skills[$m->name] = $m;
        }
        if ( $e )
            throw new tronSkillInvalid( "the pack '{$p->id}' ($dir) is refused", $e );
        $proof = @file_get_contents( $dir . '/proof.json' );
        $proof = $proof === false ? null : json_decode( $proof, true );
        if ( is_array( $proof ) && is_array( $proof['skills'] ?? null ) )
            $p->proof = $proof['skills'];
        return $p;
    }

    /** @return string[] */
    public static function errors( array $d, string $dir ): array
    {
        $e = array();
        foreach ( array_keys( $d ) as $k )
            if ( !in_array( $k, self::KEYS, true ) )
                $e[] = "pack.json: unknown key '$k'";
        if ( !is_string( $d['id'] ?? null ) || !preg_match( self::ID_PATTERN, $d['id'] ) )
            $e[] = 'pack.json id: ^[a-z][a-z0-9-]{1,47}$';
        else if ( basename( $dir ) !== $d['id'] )
            $e[] = "pack.json id: '{$d['id']}' must equal the directory name '" . basename( $dir ) . "'";
        foreach ( array( 'title' => 3, 'description' => 20, 'author' => 2 ) as $k => $min )
            if ( !is_string( $d[$k] ?? null ) || strlen( trim( $d[$k] ) ) < $min )
                $e[] = "pack.json $k: required, at least $min characters";
        if ( !is_string( $d['version'] ?? null ) || !preg_match( '/^\d+\.\d+\.\d+\.\d+$/', $d['version'] ) )
            $e[] = 'pack.json version: X.Y.Z.N';
        if ( !is_string( $d['license'] ?? null ) || !in_array( $d['license'], tronSkillManifest::LICENSES, true ) )
            $e[] = 'pack.json license: an SPDX id, one of ' . implode( ', ', tronSkillManifest::LICENSES );
        if ( !is_string( $d['prefix'] ?? null ) || !preg_match( '/^[a-z][a-z0-9]*(\.[a-z][a-z0-9-]*)?$/', $d['prefix'] ) )
            $e[] = 'pack.json prefix: the first part (or two) of every skill name, e.g. centre';
        if ( !is_array( $d['requires'] ?? null ) || !is_string( $d['requires']['tron'] ?? null ) || !tronVersion::isValid( $d['requires']['tron'] ) )
            $e[] = 'pack.json requires.tron: the oldest tron the pack works with';
        foreach ( (array)( $d['requires']['programs'] ?? array() ) as $prog )
            if ( !is_string( $prog ) || !preg_match( '/^[a-z][a-z0-9]{1,31}$/', $prog ) )
                $e[] = 'pack.json requires.programs: ' . json_encode( $prog ) . ' is not a program name';
        if ( isset( $d['for_extension'] ) && ( !is_string( $d['for_extension'] ) || !preg_match( '/^[a-z][a-z0-9_]{1,63}$/', $d['for_extension'] ) ) )
            $e[] = 'pack.json for_extension: an extension name';
        if ( isset( $d['local'] ) && !is_bool( $d['local'] ) )
            $e[] = 'pack.json local: true or false';
        if ( !is_file( $dir . '/README.md' ) )
            $e[] = 'README.md: every pack documents itself (what it is for, every skill, the units it needs)';
        return $e;
    }

    public function isLocal(): bool
    {
        return !empty( $this->data['local'] );
    }

    public function version(): string
    {
        return (string)$this->data['version'];
    }

    public function requiresTron(): string
    {
        return (string)$this->data['requires']['tron'];
    }

    /** The proof of one skill, when the harness proved exactly this manifest. */
    public function proofOf( tronSkillManifest $m ): ?array
    {
        $p = $this->proof[$m->name] ?? null;
        if ( !is_array( $p ) || ( $p['hash'] ?? '' ) !== $m->hash() )
            return null;
        return $p;
    }

    /** Writes the harness results (only this file of a pack is ever written by tron, and only by the harness CLI). */
    public function writeProof( array $rows, string $tronVersion, int $at ): void
    {
        ksort( $rows );
        $doc = array( 'pack' => $this->id, 'pack_version' => $this->version(), 'tron' => $tronVersion, 'at' => gmdate( 'Y-m-d\TH:i:s\Z', $at ), 'skills' => $rows );
        $json = json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
        if ( @file_put_contents( $this->dir . '/proof.json', $json ) === false )
            throw new RuntimeException( "cannot write {$this->dir}/proof.json" );
        $this->proof = $rows;
    }
}
