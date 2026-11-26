<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * One skill: data, never code. A skill names the units (tools) it runs, how their arguments are wired from the
 * skill's parameters and from earlier steps, the declarative steps between them (where, sort, group ...), its
 * guards and the schema of what it answers. The authoritative description is doc/extension-conventions.md; this
 * class enforces it, so a skill that breaks the standard is refused with every reason at once.
 *
 *   {
 *     "name": "centre.tickets.overdue",          required, lower-case dotted, 2-6 parts, at most 57 characters
 *     "title": "Overdue tickets",                required
 *     "purpose": "What it answers, for whom",    required, at least 20 characters
 *     "version": "0.0.0.1",                      required, X.Y.Z.N
 *     "author": "7x", "license": "GPL-2.0-or-later",   required (SPDX id)
 *     "tags": [ "centre", "report" ],           required, 1-12 tags, ^[a-z][a-z0-9-]{1,31}$
 *     "use_case": "Monday stand-up",            required
 *     "parameters": { "<name>": { "type": ..., "description": ..., "default": ..., "examples": [ ... ],
 *                                 "option": "--board", "short": "-b", "minimum" ... } },
 *     "requires": { "tron": "0.0.0.3", "units": [ "centre_ticket_list" ], "programs": [ "centre" ], "wave": "A" },
 *     "guards": [ { "param": "days", "op": "le", "value": 365, "message": "..." }, { "policy": "tron/centre_read" } ],
 *     "steps": [ { "id": "list", "unit": "centre_ticket_list", "args": { "board": "$param.board" } },
 *                { "id": "late", "op": "where", "from": "$steps.list.items", "field": "due", "cmp": "lt", "value": "$today" } ],
 *     "output": { "value": { "count": "$steps.late|count", "tickets": "$steps.late" }, "schema": { "type": "object", ... } },
 *     "examples": [ { "title": "...", "params": { "board": "ops" } } ],
 *     "i18n": { "ger-DE": { "title": "...", "purpose": "..." } },        optional
 *     "derived_from": { "name": ..., "version": ..., "pack": ..., "license": ... },   optional (a fork)
 *     "deprecated": { "since": "0.0.0.2", "replacement": "other.skill", "reason": "..." }   optional
 *   }
 */
final class tronSkillManifest
{
    const NAME_PATTERN = '/^[a-z][a-z0-9]*(\.[a-z][a-z0-9-]*){1,5}$/';
    const NAME_MAX = 57;
    /** Options of the skill command line; no parameter may take these names */
    const RESERVED_OPTIONS = array( 'user', 'json', 'use-siteaccess', 'fixtures', 'args', 'write-proof' );
    const TAG_PATTERN = '/^[a-z][a-z0-9-]{1,31}$/';
    const PARAM_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';
    const STEP_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';
    const TYPES = array( 'string', 'integer', 'number', 'boolean', 'array' );
    const WAVES = array( 'A', 'B', 'C', 'D', 'E' );
    const KEYS = array( 'name', 'title', 'purpose', 'version', 'author', 'license', 'tags', 'use_case', 'parameters', 'requires',
                        'guards', 'steps', 'output', 'examples', 'i18n', 'derived_from', 'deprecated', 'notes' );
    const LICENSES = array( 'GPL-2.0-or-later', 'GPL-2.0-only', 'GPL-3.0-or-later', 'LGPL-2.1-or-later', 'MIT', 'BSD-3-Clause',
                            'Apache-2.0', 'CC0-1.0', 'CC-BY-4.0', 'CC-BY-SA-4.0', 'proprietary' );
    /** At most so many steps, and so many calls of one "each" step */
    const MAX_STEPS = 16;
    const MAX_EACH = 50;

    /** @var array the manifest as read */ public $data;
    /** @var string */ public $name;
    /** @var string */ public $version;
    /** @var string where it came from (a file, or "draft:<id>") */ public $source;

    private function __construct()
    {
    }

    /**
     * @throws tronSkillInvalid with every reason the manifest breaks the standard
     */
    public static function fromArray( array $d, string $source = '' ): self
    {
        $errors = self::errors( $d );
        if ( $errors )
            throw new tronSkillInvalid( ( $source !== '' ? $source . ': ' : '' ) . 'the skill ' . ( is_string( $d['name'] ?? null ) ? "'{$d['name']}' " : '' ) . 'breaks the standard', $errors );
        $m = new self();
        $m->data = $d;
        $m->name = $d['name'];
        $m->version = $d['version'];
        $m->source = $source;
        return $m;
    }

    /** @throws tronSkillInvalid */
    public static function fromFile( string $path ): self
    {
        $raw = @file_get_contents( $path );
        if ( $raw === false )
            throw new tronSkillInvalid( "$path: cannot be read", array( 'the file cannot be read' ) );
        $d = json_decode( $raw, true );
        if ( !is_array( $d ) || tronSchemaValidator::isList( $d ) )
            throw new tronSkillInvalid( "$path: not a JSON object", array( 'the file is not a JSON object: ' . json_last_error_msg() ) );
        return self::fromArray( $d, $path );
    }

    /** @return string[] every way the manifest breaks the standard (empty: it keeps it) */
    public static function errors( array $d ): array
    {
        $e = array();
        foreach ( array_keys( $d ) as $k )
            if ( !in_array( $k, self::KEYS, true ) )
                $e[] = "unknown key '$k' (allowed: " . implode( ', ', self::KEYS ) . ')';

        $name = $d['name'] ?? null;
        if ( !is_string( $name ) || !preg_match( self::NAME_PATTERN, $name ) )
            $e[] = 'name: lower-case dotted, 2 to 6 parts, e.g. centre.tickets.overdue';
        else if ( strlen( $name ) > self::NAME_MAX )
            $e[] = 'name: at most ' . self::NAME_MAX . ' characters';
        foreach ( array( 'title' => 3, 'purpose' => 20, 'use_case' => 3, 'author' => 2 ) as $k => $min )
            if ( !is_string( $d[$k] ?? null ) || strlen( trim( $d[$k] ) ) < $min )
                $e[] = "$k: required, at least $min characters";
        if ( isset( $d['title'] ) && is_string( $d['title'] ) && strlen( $d['title'] ) > 80 )
            $e[] = 'title: at most 80 characters';
        if ( !is_string( $d['version'] ?? null ) || !preg_match( '/^\d+\.\d+\.\d+\.\d+$/', $d['version'] ) )
            $e[] = 'version: X.Y.Z.N (four positions; only the last moves, by one per release)';
        if ( !is_string( $d['license'] ?? null ) || !in_array( $d['license'], self::LICENSES, true ) )
            $e[] = 'license: an SPDX id, one of ' . implode( ', ', self::LICENSES );
        $tags = $d['tags'] ?? null;
        if ( !is_array( $tags ) || !$tags || count( $tags ) > 12 || !tronSchemaValidator::isList( $tags ) )
            $e[] = 'tags: a list of 1 to 12 tags';
        else
            foreach ( $tags as $t )
                if ( !is_string( $t ) || !preg_match( self::TAG_PATTERN, $t ) )
                    $e[] = 'tags: ' . json_encode( $t ) . ' is not a tag (^[a-z][a-z0-9-]{1,31}$)';

        $params = $d['parameters'] ?? array();
        if ( !is_array( $params ) || ( $params && tronSchemaValidator::isList( $params ) ) )
        {
            $e[] = 'parameters: an object of name => definition';
            $params = array();
        }
        $options = array();
        foreach ( $params as $pn => $p )
            $e = array_merge( $e, self::paramErrors( (string)$pn, $p, $options ) );

        $req = $d['requires'] ?? null;
        $units = array();
        if ( !is_array( $req ) || tronSchemaValidator::isList( $req ) )
            $e[] = 'requires: an object with tron, units (and programs, wave)';
        else
        {
            if ( !is_string( $req['tron'] ?? null ) || !tronVersion::isValid( $req['tron'] ) )
                $e[] = 'requires.tron: the oldest tron version the skill works with';
            if ( !is_array( $req['units'] ?? null ) || !$req['units'] )
                $e[] = 'requires.units: the list of every unit (tool) the steps run';
            else
                foreach ( $req['units'] as $u )
                    if ( !is_string( $u ) || !preg_match( tronTool::NAME_PATTERN, $u ) )
                        $e[] = 'requires.units: ' . json_encode( $u ) . ' is not a tool name';
                    else
                        $units[$u] = true;
            foreach ( (array)( $req['programs'] ?? array() ) as $p )
                if ( !is_string( $p ) || !preg_match( '/^[a-z][a-z0-9]{1,31}$/', $p ) )
                    $e[] = 'requires.programs: ' . json_encode( $p ) . ' is not a program name';
            if ( isset( $req['wave'] ) && !in_array( $req['wave'], self::WAVES, true ) )
                $e[] = 'requires.wave: one of ' . implode( ', ', self::WAVES );
            foreach ( array_keys( $req ) as $k )
                if ( !in_array( $k, array( 'tron', 'units', 'programs', 'wave' ), true ) )
                    $e[] = "requires: unknown key '$k'";
        }

        $guards = $d['guards'] ?? array();
        if ( !is_array( $guards ) || !tronSchemaValidator::isList( $guards ) )
            $e[] = 'guards: a list';
        else
            foreach ( $guards as $i => $g )
                $e = array_merge( $e, self::guardErrors( $i, $g, $params ) );

        $steps = $d['steps'] ?? null;
        $ids = array();
        $used = array();
        if ( !is_array( $steps ) || !$steps || !tronSchemaValidator::isList( $steps ) )
            $e[] = 'steps: a list of at least one step';
        else if ( count( $steps ) > self::MAX_STEPS )
            $e[] = 'steps: at most ' . self::MAX_STEPS;
        else
            foreach ( $steps as $i => $s )
                $e = array_merge( $e, self::stepErrors( $i, $s, $ids, $params, $used ) );
        if ( $units && $used )
        {
            foreach ( array_keys( $used ) as $u )
                if ( !isset( $units[$u] ) )
                    $e[] = "requires.units: the steps run $u, which is not listed";
            foreach ( array_keys( $units ) as $u )
                if ( !isset( $used[$u] ) )
                    $e[] = "requires.units: $u is listed but no step runs it";
        }

        $out = $d['output'] ?? null;
        if ( !is_array( $out ) || !isset( $out['value'] ) || !isset( $out['schema'] ) )
            $e[] = 'output: needs value (what the answer is made of) and schema (an object schema of the answer)';
        else
        {
            if ( !is_array( $out['schema'] ) || ( $out['schema']['type'] ?? null ) !== 'object' || empty( $out['schema']['properties'] ) )
                $e[] = 'output.schema: an object schema with properties';
            if ( !is_array( $out['value'] ) || tronSchemaValidator::isList( $out['value'] ) )
                $e[] = 'output.value: an object (the answer is always an object)';
            else
            {
                foreach ( self::refsIn( $out['value'] ) as $ref )
                    $e = array_merge( $e, self::refErrors( 'output.value', $ref, $ids, $params, false ) );
                if ( is_array( $out['schema'] ?? null ) && !empty( $out['schema']['properties'] ) )
                    foreach ( array_keys( $out['value'] ) as $k )
                        if ( !isset( $out['schema']['properties'][$k] ) )
                            $e[] = "output.schema: the answer key '$k' is not described";
            }
        }

        $ex = $d['examples'] ?? null;
        if ( !is_array( $ex ) || !$ex || !tronSchemaValidator::isList( $ex ) )
            $e[] = 'examples: at least one example ( { "title": ..., "params": { ... } } ); the harness runs every one';
        else
            foreach ( $ex as $i => $x )
            {
                if ( !is_array( $x ) || !is_string( $x['title'] ?? null ) || !is_array( $x['params'] ?? array() ) )
                {
                    $e[] = "examples[$i]: needs a title and params (an object)";
                    continue;
                }
                $errs = tronSchemaValidator::validate( self::inputSchemaOf( $params ), (array)( $x['params'] ?? array() ) );
                foreach ( $errs as $err )
                    $e[] = "examples[$i]: $err";
            }

        if ( isset( $d['i18n'] ) )
        {
            if ( !is_array( $d['i18n'] ) )
                $e[] = 'i18n: an object of locale => { title, purpose, use_case }';
            else
                foreach ( $d['i18n'] as $loc => $t )
                    if ( !preg_match( '/^[a-z]{3}-[A-Z]{2}$/', (string)$loc ) || !is_array( $t ) || array_diff( array_keys( $t ), array( 'title', 'purpose', 'use_case', 'parameters' ) ) )
                        $e[] = "i18n.$loc: a locale like ger-DE with title, purpose, use_case or parameters";
        }
        if ( isset( $d['derived_from'] ) )
        {
            $df = $d['derived_from'];
            if ( !is_array( $df ) || !is_string( $df['name'] ?? null ) || !is_string( $df['version'] ?? null ) || !is_string( $df['license'] ?? null ) )
                $e[] = 'derived_from: name, version and license of the skill it was forked from (and pack)';
            else if ( in_array( $df['license'], array( 'GPL-2.0-or-later', 'GPL-2.0-only', 'GPL-3.0-or-later', 'CC-BY-SA-4.0' ), true )
                      && isset( $d['license'] ) && $d['license'] !== $df['license'] && !( $df['license'] === 'GPL-2.0-or-later' && $d['license'] === 'GPL-3.0-or-later' ) )
                $e[] = "license: a fork of a {$df['license']} skill keeps that license";
        }
        if ( isset( $d['deprecated'] ) && ( !is_array( $d['deprecated'] ) || !is_string( $d['deprecated']['since'] ?? null ) || !is_string( $d['deprecated']['reason'] ?? null ) ) )
            $e[] = 'deprecated: since (version) and reason, optionally replacement';
        return $e;
    }

    private static function paramErrors( string $pn, $p, array &$options ): array
    {
        $e = array();
        $w = "parameters.$pn";
        if ( !preg_match( self::PARAM_PATTERN, $pn ) )
            $e[] = "$w: the name must match ^[a-z][a-z0-9_]{0,31}$";
        else if ( in_array( str_replace( '_', '-', $pn ), self::RESERVED_OPTIONS, true ) )
            $e[] = "$w: the name is reserved for the command line (" . implode( ', ', self::RESERVED_OPTIONS ) . ')';
        if ( !is_array( $p ) )
            return array_merge( $e, array( "$w: a definition object" ) );
        if ( !in_array( $p['type'] ?? null, self::TYPES, true ) )
            $e[] = "$w.type: one of " . implode( ', ', self::TYPES );
        if ( !is_string( $p['description'] ?? null ) || strlen( trim( $p['description'] ) ) < 5 )
            $e[] = "$w.description: required";
        $allowed = array( 'type', 'description', 'default', 'minimum', 'maximum', 'minLength', 'maxLength', 'enum', 'pattern',
                          'items', 'maxItems', 'minItems', 'required', 'examples', 'option', 'short', 'secret' );
        foreach ( array_keys( $p ) as $k )
            if ( !in_array( $k, $allowed, true ) )
                $e[] = "$w: unknown key '$k'";
        if ( !empty( $p['secret'] ) )
            $e[] = "$w: a skill takes no secrets (data only; credentials stay in the installation)";
        $option = $p['option'] ?? '--' . str_replace( '_', '-', $pn );
        if ( !is_string( $option ) || !preg_match( '/^--[a-z][a-z0-9-]{0,31}$/', $option ) )
            $e[] = "$w.option: a long CLI option like --board";
        else if ( isset( $options[$option] ) )
            $e[] = "$w.option: $option is taken by {$options[$option]}";
        else
            $options[$option] = $pn;
        if ( isset( $p['short'] ) )
        {
            if ( !is_string( $p['short'] ) || !preg_match( '/^-[a-zA-Z]$/', $p['short'] ) )
                $e[] = "$w.short: one letter like -b";
            else if ( isset( $options[$p['short']] ) )
                $e[] = "$w.short: {$p['short']} is taken by {$options[$p['short']]}";
            else
                $options[$p['short']] = $pn;
        }
        $schema = self::paramSchema( $p );
        if ( array_key_exists( 'default', $p ) )
            foreach ( tronSchemaValidator::validate( $schema, $p['default'], "$w.default" ) as $err )
                $e[] = $err;
        if ( !isset( $p['examples'] ) || !is_array( $p['examples'] ) || !$p['examples'] )
            $e[] = "$w.examples: at least one example value";
        else
            foreach ( $p['examples'] as $i => $x )
                foreach ( tronSchemaValidator::validate( $schema, $x, "$w.examples[$i]" ) as $err )
                    $e[] = $err;
        if ( isset( $p['pattern'] ) && ( !is_string( $p['pattern'] ) || @preg_match( '/' . str_replace( '/', '\\/', $p['pattern'] ) . '/u', '' ) === false ) )
            $e[] = "$w.pattern: not a valid regular expression";
        if ( ( $p['type'] ?? '' ) === 'string' && !isset( $p['enum'] ) && !isset( $p['pattern'] ) && !isset( $p['maxLength'] ) )
            $e[] = "$w: a string parameter needs enum, pattern or maxLength";
        if ( in_array( $p['type'] ?? '', array( 'integer', 'number' ), true ) && ( !isset( $p['minimum'] ) || !isset( $p['maximum'] ) ) )
            $e[] = "$w: a number parameter needs minimum and maximum";
        if ( ( $p['type'] ?? '' ) === 'array' && ( !isset( $p['items'] ) || !isset( $p['maxItems'] ) ) )
            $e[] = "$w: an array parameter needs items and maxItems";
        return $e;
    }

    private static function guardErrors( int $i, $g, array $params ): array
    {
        $w = "guards[$i]";
        if ( !is_array( $g ) )
            return array( "$w: an object" );
        $e = array();
        if ( isset( $g['param'] ) )
        {
            if ( !isset( $params[$g['param']] ) )
                $e[] = "$w.param: no parameter {$g['param']}";
            if ( !in_array( $g['op'] ?? '', tronSkillOps::COMPARE, true ) )
                $e[] = "$w.op: one of " . implode( ', ', tronSkillOps::COMPARE );
            if ( !is_string( $g['message'] ?? null ) )
                $e[] = "$w.message: what the caller is told when the guard stops the run";
        }
        else if ( isset( $g['policy'] ) )
        {
            if ( !is_string( $g['policy'] ) || !preg_match( '/^[a-z][a-z0-9_]*\/[a-z*][a-z0-9_]*$/', $g['policy'] ) )
                $e[] = "$w.policy: module/function";
        }
        else if ( isset( $g['program'] ) )
        {
            if ( !is_string( $g['program'] ) || !preg_match( '/^[a-z][a-z0-9]{1,31}$/', $g['program'] ) )
                $e[] = "$w.program: a program name";
        }
        else
            $e[] = "$w: a guard checks a param (param, op, value, message), a policy (module/function) or a program";
        return $e;
    }

    private static function stepErrors( int $i, $s, array &$ids, array $params, array &$used ): array
    {
        $w = "steps[$i]";
        if ( !is_array( $s ) )
            return array( "$w: an object" );
        $e = array();
        $id = $s['id'] ?? null;
        if ( !is_string( $id ) || !preg_match( self::STEP_PATTERN, $id ) )
            $e[] = "$w.id: ^[a-z][a-z0-9_]{0,31}$";
        else if ( isset( $ids[$id] ) )
            $e[] = "$w.id: '$id' is used twice";
        $hasOp = isset( $s['op'] );
        $hasUnit = isset( $s['unit'] ) && !$hasOp;
        if ( !$hasUnit && !$hasOp )
            $e[] = "$w: either a unit (a tool) or an op (" . implode( ', ', tronSkillOps::OPS ) . ')';
        if ( $hasUnit )
        {
            if ( !is_string( $s['unit'] ) || !preg_match( tronTool::NAME_PATTERN, $s['unit'] ) )
                $e[] = "$w.unit: a tool name";
            else if ( strpos( $s['unit'], 'skill_' ) === 0 )
                $e[] = "$w.unit: a skill runs units, not other skills (no nesting)";
            else
                $used[$s['unit']] = true;
            if ( isset( $s['args'] ) && ( !is_array( $s['args'] ) || ( $s['args'] && tronSchemaValidator::isList( $s['args'] ) ) ) )
                $e[] = "$w.args: an object";
            foreach ( array_keys( $s ) as $k )
                if ( !in_array( $k, array( 'id', 'unit', 'args', 'when', 'note' ), true ) )
                    $e[] = "$w: unknown key '$k' for a unit step";
        }
        if ( $hasOp )
        {
            $opErrors = tronSkillOps::declarationErrors( $s );
            foreach ( $opErrors as $err )
                $e[] = "$w: $err";
            if ( ( $s['op'] ?? '' ) === 'each' && is_string( $s['unit'] ?? null ) )
                $used[$s['unit']] = true;
            if ( ( $s['op'] ?? '' ) === 'each' && isset( $s['each_unit'] ) && is_string( $s['each_unit'] ) )
                $used[$s['each_unit']] = true;
        }
        foreach ( self::refsIn( $s ) as $ref )
            $e = array_merge( $e, self::refErrors( $w, $ref, $ids, $params, ( $s['op'] ?? '' ) === 'each' ) );
        if ( is_string( $id ) )
            $ids[$id] = true;
        return $e;
    }

    /** @return string[] the $... references in a value */
    public static function refsIn( $v ): array
    {
        $out = array();
        if ( is_string( $v ) )
        {
            if ( strlen( $v ) > 1 && $v[0] === '$' && $v[1] !== '$' )
                $out[] = $v;
            if ( preg_match_all( '/\{\{\s*(\$[^}]+?)\s*\}\}/', $v, $m ) )
                foreach ( $m[1] as $r )
                    $out[] = $r;
        }
        else if ( is_array( $v ) )
            foreach ( $v as $x )
                $out = array_merge( $out, self::refsIn( $x ) );
        return $out;
    }

    private static function refErrors( string $w, string $ref, array $ids, array $params, bool $item ): array
    {
        $path = explode( '|', $ref, 2 )[0];
        $parts = explode( '.', substr( $path, 1 ) );
        $head = $parts[0];
        if ( isset( explode( '|', $ref, 2 )[1] ) && !in_array( explode( '|', $ref, 2 )[1], tronSkillExpr::FILTERS, true ) )
            return array( "$w: $ref: unknown filter (" . implode( ', ', tronSkillExpr::FILTERS ) . ')' );
        switch ( $head )
        {
            case 'param':
                if ( !isset( $parts[1] ) || !isset( $params[$parts[1]] ) )
                    return array( "$w: $ref names no parameter" );
                return array();
            case 'steps':
                if ( !isset( $parts[1] ) || !isset( $ids[$parts[1]] ) )
                    return array( "$w: $ref names no earlier step" );
                return array();
            case 'item':
                return $item ? array() : array( "$w: \$item exists only inside an each step" );
            case 'now': case 'today': case 'week': case 'user':
                return array();
            case 'ago': case 'ahead':
                return isset( $parts[1] ) && isset( $params[$parts[1]] ) && in_array( $params[$parts[1]]['type'] ?? '', array( 'integer', 'number' ), true ) ? array() : array( "$w: $ref names no number parameter" );
            case 'date':
                return isset( $parts[1] ) && preg_match( '/^[+-]\d{1,4}[dw]$/', $parts[1] ) ? array() : array( "$w: $ref: \$date.+7d or \$date.-2w" );
        }
        return array( "$w: $ref: a reference starts with \$param, \$steps, \$item, \$now, \$today, \$week, \$date, \$ago, \$ahead or \$user" );
    }

    /** The JSON schema of one parameter (what the tool's inputSchema says about it). */
    public static function paramSchema( array $p ): array
    {
        $s = array( 'type' => $p['type'] ?? 'string', 'description' => (string)( $p['description'] ?? '' ) );
        foreach ( array( 'default', 'minimum', 'maximum', 'minLength', 'maxLength', 'enum', 'pattern', 'items', 'maxItems', 'minItems', 'examples' ) as $k )
            if ( array_key_exists( $k, $p ) )
                $s[$k] = $p[$k];
        return $s;
    }

    public static function inputSchemaOf( array $params ): array
    {
        $props = array();
        $required = array();
        foreach ( $params as $n => $p )
        {
            if ( !is_array( $p ) )
                continue;
            $props[$n] = self::paramSchema( $p );
            if ( !empty( $p['required'] ) )
                $required[] = $n;
        }
        $s = array( 'type' => 'object', 'additionalProperties' => false, 'properties' => $props );
        if ( $required )
            $s['required'] = $required;
        return $s;
    }

    // ------------------------------------------------------------------ accessors

    public function inputSchema(): array
    {
        return self::inputSchemaOf( (array)( $this->data['parameters'] ?? array() ) );
    }

    public function outputSchema(): array
    {
        return $this->data['output']['schema'];
    }

    /** The MCP tool a live skill is offered as: skill_ and the name with dots and dashes as underscores. */
    public function toolName(): string
    {
        return self::toolNameOf( $this->name );
    }

    public static function toolNameOf( string $name ): string
    {
        return 'skill_' . str_replace( array( '.', '-' ), '_', $name );
    }

    /** @return string[] the units (tools) the steps run */
    public function units(): array
    {
        return array_values( array_map( 'strval', (array)$this->data['requires']['units'] ) );
    }

    public function wave(): string
    {
        return (string)( $this->data['requires']['wave'] ?? 'A' );
    }

    public function title( string $locale = '' ): string
    {
        return (string)( $this->data['i18n'][$locale]['title'] ?? $this->data['title'] );
    }

    public function purpose( string $locale = '' ): string
    {
        return (string)( $this->data['i18n'][$locale]['purpose'] ?? $this->data['purpose'] );
    }

    /** A content hash: an approval holds for exactly this manifest. */
    public function hash(): string
    {
        return hash( 'sha256', self::canonical( $this->data ) );
    }

    public static function canonical( $v ): string
    {
        $sort = function ( $x ) use ( &$sort ) {
            if ( !is_array( $x ) )
                return $x;
            if ( !tronSchemaValidator::isList( $x ) )
                ksort( $x );
            return array_map( $sort, $x );
        };
        return (string)json_encode( $sort( $v ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }

    /** The CLI form of the parameters: option => parameter name (long and short). */
    public function cliOptions(): array
    {
        $o = array();
        foreach ( (array)( $this->data['parameters'] ?? array() ) as $n => $p )
        {
            $o[(string)( $p['option'] ?? '--' . str_replace( '_', '-', $n ) )] = $n;
            if ( isset( $p['short'] ) )
                $o[(string)$p['short']] = $n;
        }
        return $o;
    }

    /** The description of the MCP tool and prompt. */
    public function description(): string
    {
        $d = trim( $this->data['purpose'] ) . ' Skill ' . $this->name . ' ' . $this->version . ' (use case: ' . trim( $this->data['use_case'] ) . ').'
           . ' Runs ' . implode( ', ', $this->units() ) . ' as you, each step checked against your policies and audited.';
        if ( isset( $this->data['deprecated'] ) )
            $d .= ' Deprecated since ' . $this->data['deprecated']['since'] . ': ' . $this->data['deprecated']['reason']
                . ( isset( $this->data['deprecated']['replacement'] ) ? ' Use ' . $this->data['deprecated']['replacement'] . '.' : '' );
        return $d;
    }
}
