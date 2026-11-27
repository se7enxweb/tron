<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The type catalog of the units (doc/units.md, "Types"). Every unit takes one of a few input types and gives one
 * output type, and the pipe (tron_pipe) chains units by these types: what one unit gives is turned into what the next
 * one takes.
 *
 * Three kinds of type:
 *
 *   ref     what a unit takes: a few fields that name a thing (node-ref is array( 'node_id' => 42 )). "none" is the
 *           input of a unit that only starts a pipe (it takes its arguments, nothing piped).
 *   value   what a unit gives: a node, a class, a template chain ... Its "provides" says which refs it can be turned
 *           into, and from which of its fields (a node provides a node-ref from node_id and a class-ref from
 *           class_identifier).
 *   list    a value with "total" and "items", whose items are of the type "item". A list piped into a unit runs the
 *           unit once per item (fan out), like xargs.
 *
 * "schema" is the JSON schema of a value (its required top-level fields); a unit's outputSchema is the schema of its
 * output type, so the contract tests check every answer against it.
 */
final class tronUnitTypes
{
    const REFS = array(
        'none' => array( 'title' => 'Nothing: the unit only starts a pipe', 'fields' => array() ),
        'node-ref' => array( 'title' => 'A content node, by node id', 'fields' => array( 'node_id' => 'integer' ) ),
        'path-ref' => array( 'title' => 'A page path (URL alias, or content/view/full/N)', 'fields' => array( 'path' => 'string' ) ),
        'class-ref' => array( 'title' => 'A content class, by identifier', 'fields' => array( 'identifier' => 'string' ) ),
        'template-ref' => array( 'title' => 'A design template, by name (node/view/full.tpl)', 'fields' => array( 'template' => 'string' ) ),
        'template-file' => array( 'title' => 'A template file inside a design directory, by path from the installation root', 'fields' => array( 'path' => 'string' ) ),
        'layout-ref' => array( 'title' => 'An Exponential Layouts layout, by id', 'fields' => array( 'layout_id' => 'integer' ) ),
        'role-ref' => array( 'title' => 'A role, by name or id', 'fields' => array( 'role' => 'string' ) ),
    );

    const VALUES = array(
        'node' => array( 'title' => 'One node with its attributes rendered to plain values',
            'required' => array( 'node_id', 'object_id', 'name', 'class_identifier', 'url_alias', 'attributes' ),
            'provides' => array( 'node-ref' => array( 'node_id' => 'node_id' ), 'class-ref' => array( 'identifier' => 'class_identifier' ),
                                 'path-ref' => array( 'path' => 'url_alias' ) ) ),
        'node-summary' => array( 'title' => 'A node in a list: ids, name, class, path',
            'required' => array( 'node_id', 'object_id', 'name', 'class_identifier', 'url_alias' ),
            'provides' => array( 'node-ref' => array( 'node_id' => 'node_id' ), 'class-ref' => array( 'identifier' => 'class_identifier' ),
                                 'path-ref' => array( 'path' => 'url_alias' ) ) ),
        'version' => array( 'title' => 'A version of a content object',
            'required' => array( 'version', 'status' ), 'provides' => array() ),
        'ini-value' => array( 'title' => 'A setting: its value and every file that sets it, in load order',
            'required' => array( 'file', 'block', 'setting', 'siteaccess', 'found', 'value', 'files' ), 'provides' => array() ),
        'ini-block' => array( 'title' => 'A block of an INI file and its settings',
            'required' => array( 'block', 'settings' ), 'provides' => array() ),
        'diff' => array( 'title' => 'What differs between a and b',
            'required' => array( 'a', 'b', 'counts', 'items' ), 'provides' => array() ),
        'template-chain' => array( 'title' => 'The override chain of a template: which file wins and why',
            'required' => array( 'template', 'siteaccess', 'winner', 'overrides', 'keys' ),
            'provides' => array( 'template-file' => array( 'path' => 'winner.path' ), 'template-ref' => array( 'template' => 'template' ),
                                 'node-ref' => array( 'node_id' => 'node_id' ) ) ),
        'template-source' => array( 'title' => 'The text of a template file',
            'required' => array( 'path', 'lines', 'text' ), 'provides' => array( 'template-file' => array( 'path' => 'path' ) ) ),
        'lint-report' => array( 'title' => 'The syntax check of a template: errors, warnings, unknown operators and functions',
            'required' => array( 'path', 'ok', 'errors', 'warnings', 'unknown' ), 'provides' => array( 'template-file' => array( 'path' => 'path' ) ) ),
        'class' => array( 'title' => 'A content class with its attributes',
            'required' => array( 'id', 'identifier', 'name', 'attributes' ), 'provides' => array( 'class-ref' => array( 'identifier' => 'identifier' ) ) ),
        'class-summary' => array( 'title' => 'A content class in a list',
            'required' => array( 'id', 'identifier', 'name' ), 'provides' => array( 'class-ref' => array( 'identifier' => 'identifier' ) ) ),
        'layout' => array( 'title' => 'A layout with its zones and blocks; linked zones show the blocks of the shared layout they link to',
            'required' => array( 'id', 'identifier', 'name', 'layout_type', 'shared', 'zones' ), 'provides' => array( 'layout-ref' => array( 'layout_id' => 'id' ) ) ),
        'layout-summary' => array( 'title' => 'A layout in a list',
            'required' => array( 'id', 'identifier', 'name', 'shared' ), 'provides' => array( 'layout-ref' => array( 'layout_id' => 'id' ) ) ),
        'rule' => array( 'title' => 'A layout rule: its targets, conditions and layout',
            'required' => array( 'id', 'priority', 'enabled', 'layout_id', 'targets', 'conditions' ), 'provides' => array( 'layout-ref' => array( 'layout_id' => 'layout_id' ) ) ),
        'rule-trace' => array( 'title' => 'Which layout a page gets, and every rule tried on the way',
            'required' => array( 'path', 'layout_id', 'source', 'tried' ), 'provides' => array( 'layout-ref' => array( 'layout_id' => 'layout_id' ) ) ),
        'block-definition' => array( 'title' => 'A block type of Exponential Layouts (explayouts.ini)',
            'required' => array( 'identifier', 'name' ), 'provides' => array() ),
        'role' => array( 'title' => 'A role: its policies with limitations, and who has it',
            'required' => array( 'id', 'name', 'policies', 'assignments' ), 'provides' => array( 'role-ref' => array( 'role' => 'name' ) ) ),
        'user' => array( 'title' => 'A user account: id, login, name, enabled, roles (nothing else)',
            'required' => array( 'user_id', 'login', 'name' ), 'provides' => array() ),
        'search-hit' => array( 'title' => 'A search hit: ids, name, class, path, dates and a short excerpt',
            'required' => array( 'node_id', 'object_id', 'name', 'class_identifier', 'url_alias', 'modified', 'excerpt' ),
            'provides' => array( 'node-ref' => array( 'node_id' => 'node_id' ), 'class-ref' => array( 'identifier' => 'class_identifier' ),
                                 'path-ref' => array( 'path' => 'url_alias' ) ) ),
        'search-index' => array( 'title' => 'The search engine, its indexing queue, and whether one object is indexed',
            'required' => array( 'engine', 'pending', 'object' ), 'provides' => array() ),
    );

    const LISTS = array(
        'node[]' => 'node-summary', 'version[]' => 'version', 'ini-block[]' => 'ini-block', 'class[]' => 'class-summary',
        'layout[]' => 'layout-summary', 'rule[]' => 'rule', 'block-definition[]' => 'block-definition', 'user[]' => 'user',
        'search-hit[]' => 'search-hit',
    );

    public static function isRef( string $t ): bool
    {
        return isset( self::REFS[$t] );
    }

    public static function isList( string $t ): bool
    {
        return isset( self::LISTS[$t] );
    }

    public static function exists( string $t ): bool
    {
        return isset( self::REFS[$t] ) || isset( self::VALUES[$t] ) || isset( self::LISTS[$t] );
    }

    /** The item type of a list type, or the type itself. */
    public static function itemType( string $t ): string
    {
        return self::LISTS[$t] ?? $t;
    }

    /**
     * The arguments that turn a value of type $from into the ref $to, or null when it cannot. A ref turns into
     * itself; a value through its "provides" (a dotted field, winner.path, reads into nested objects). A field that is
     * missing or empty gives null too.
     */
    public static function convert( string $from, array $value, string $to ): ?array
    {
        if ( $from === $to && isset( self::REFS[$to] ) )
            $map = array_combine( array_keys( self::REFS[$to]['fields'] ), array_keys( self::REFS[$to]['fields'] ) );
        else
            $map = self::VALUES[$from]['provides'][$to] ?? null;
        if ( !$map )
            return null;
        $out = array();
        foreach ( $map as $field => $source )
        {
            $v = $value;
            foreach ( explode( '.', $source ) as $k )
                $v = is_array( $v ) && array_key_exists( $k, $v ) ? $v[$k] : null;
            if ( $v === null || $v === '' || is_array( $v ) || ( self::REFS[$to]['fields'][$field] === 'integer' && (int)$v <= 0 ) )
                return null;
            $out[$field] = self::REFS[$to]['fields'][$field] === 'integer' ? (int)$v : (string)$v;
        }
        return $out;
    }

    /** Whether a value of type $from can feed a unit that takes $to. */
    public static function feeds( string $from, string $to ): bool
    {
        return ( $from === $to && isset( self::REFS[$to] ) ) || isset( self::VALUES[$from]['provides'][$to] );
    }

    /** The JSON schema of a value or list type (an object schema with its required fields). */
    public static function schema( string $t ): array
    {
        if ( isset( self::LISTS[$t] ) )
        {
            $item = self::schema( self::LISTS[$t] );
            return array( 'type' => 'object', 'required' => array( 'total', 'items' ), 'properties' => array(
                'total' => array( 'type' => 'integer', 'minimum' => 0 ),
                'items' => array( 'type' => 'array', 'items' => $item ),
            ) );
        }
        if ( isset( self::VALUES[$t] ) )
            return array( 'type' => 'object', 'required' => self::VALUES[$t]['required'] );
        if ( isset( self::REFS[$t] ) )
        {
            $props = array();
            foreach ( self::REFS[$t]['fields'] as $f => $type )
                $props[$f] = array( 'type' => $type );
            return array( 'type' => 'object', 'required' => array_keys( $props ), 'properties' => $props );
        }
        throw new InvalidArgumentException( "Unknown unit type '$t'" );
    }

    /** The whole catalog, as doc/units.md and the resource tron://units show it. */
    public static function catalog(): array
    {
        $out = array();
        foreach ( self::REFS as $n => $d )
            $out[] = array( 'type' => $n, 'kind' => 'ref', 'title' => $d['title'], 'fields' => (object)$d['fields'] );
        foreach ( self::VALUES as $n => $d )
            $out[] = array( 'type' => $n, 'kind' => 'value', 'title' => $d['title'], 'required' => $d['required'],
                            'provides' => array_keys( $d['provides'] ) );
        foreach ( self::LISTS as $n => $item )
            $out[] = array( 'type' => $n, 'kind' => 'list', 'title' => 'A list of ' . $item, 'item' => $item );
        return $out;
    }
}
