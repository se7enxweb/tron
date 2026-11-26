<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The program "class": class.list, class.describe, class.diff. class.diff compares two classes of this
 * installation, or a class with a copy of it from another installation (the class value another installation's
 * class.describe gave), so two installs can be compared without a connection between them.
 */
final class tronUnitsClassProgram extends tronUnitProgram
{
    const POLICY = array( 'class', '*' );
    const CLASS_FIELDS = array( 'name', 'groups', 'is_container', 'always_available', 'object_name_pattern', 'url_alias_pattern', 'sort_field', 'sort_order' );
    const ATTRIBUTE_FIELDS = array( 'name', 'datatype', 'required', 'searchable', 'information_collector', 'translatable', 'category', 'placement', 'settings' );

    protected function area(): string
    {
        return 'class';
    }

    protected function sourceInterface(): string
    {
        return 'tronUnitClassSource';
    }

    protected function units( tronRegistry $registry, tronEnvironment $env, $source ): void
    {
        $ident = array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_]{1,100}$', 'description' => 'A class identifier (or id)' );

        $this->unit( $registry, 'class.list', array(
            'title' => 'List content classes',
            'description' => 'The content classes: id, identifier, name, groups, whether they are containers, how many objects they have.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
                'group' => array( 'type' => 'string', 'maxLength' => 100, 'description' => 'Only classes of this group (name)' ),
            ) + self::paging( 100, 500 ) ),
            'policy' => self::POLICY,
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source ) {
                $items = array();
                foreach ( $source->classes() as $c )
                {
                    $groups = array_values( array_map( 'strval', (array)( $c['groups'] ?? array() ) ) );
                    if ( isset( $a['group'] ) && !in_array( (string)$a['group'], $groups, true ) )
                        continue;
                    $items[] = array( 'id' => (int)$c['id'], 'identifier' => (string)$c['identifier'], 'name' => (string)$c['name'],
                                      'groups' => $groups, 'is_container' => (bool)( $c['is_container'] ?? false ),
                                      'object_count' => (int)( $c['object_count'] ?? 0 ),
                                      'modified' => tronUnitsNodeProgram::date( $c['modified'] ?? 0 ) );
                }
                usort( $items, function ( $x, $y ) { return strcmp( $x['identifier'], $y['identifier'] ); } );
                return array( 'total' => count( $items ), 'offset' => (int)$a['offset'],
                              'items' => array_slice( $items, (int)$a['offset'], (int)$a['limit'] ) );
            },
        ) );

        $this->unit( $registry, 'class.describe', array(
            'title' => 'Describe a content class',
            'description' => 'One content class with its settings (name pattern, URL alias pattern, container, sorting) and every attribute in order: identifier, name, datatype, required, searchable, information collector, translatable, category and the datatype settings.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'identifier' ), 'properties' => array(
                'identifier' => $ident,
            ) ),
            'policy' => self::POLICY,
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source ) {
                return self::describe( $source, (string)$a['identifier'] );
            },
        ) );

        $this->unit( $registry, 'class.diff', array(
            'title' => 'Diff two content classes',
            'description' => 'What differs between the class identifier and the class other (both here), or between the class here and other_definition, the class value of class.describe from another installation: class settings that changed, attributes only in a or only in b, attributes whose datatype or settings changed.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'identifier' ), 'properties' => array(
                'identifier' => $ident,
                'other' => $ident,
                'other_definition' => array( 'type' => 'object', 'required' => array( 'identifier', 'attributes' ), 'description' => 'A class value from another installation' ),
            ) ),
            'policy' => self::POLICY,
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source ) {
                $x = self::describe( $source, (string)$a['identifier'] );
                if ( isset( $a['other'] ) === isset( $a['other_definition'] ) )
                    throw new tronToolError( 'Give other (a class here) or other_definition (a class from another installation)' );
                if ( isset( $a['other'] ) )
                {
                    $y = self::describe( $source, (string)$a['other'] );
                    $right = array( 'identifier' => $y['identifier'], 'where' => 'here' );
                }
                else
                {
                    $y = self::normalize( (array)$a['other_definition'] );
                    $right = array( 'identifier' => $y['identifier'], 'where' => 'other_definition' );
                }
                return self::diff( $x, $y, array( 'identifier' => $x['identifier'], 'where' => 'here' ), $right );
            },
        ) );
    }

    private static function describe( tronUnitClassSource $source, string $identifier ): array
    {
        $c = $source->describe( $identifier );
        if ( $c === null )
            throw new tronToolError( "There is no content class '$identifier'" );
        return self::normalize( $c );
    }

    /** A class value with every field typed (also for a definition from another installation). */
    public static function normalize( array $c ): array
    {
        $attrs = array();
        foreach ( (array)( $c['attributes'] ?? array() ) as $at )
        {
            $at = (array)$at;
            $attrs[] = array(
                'id' => (int)( $at['id'] ?? 0 ), 'identifier' => (string)( $at['identifier'] ?? '' ), 'name' => (string)( $at['name'] ?? '' ),
                'datatype' => (string)( $at['datatype'] ?? '' ), 'required' => (bool)( $at['required'] ?? false ),
                'searchable' => (bool)( $at['searchable'] ?? false ), 'information_collector' => (bool)( $at['information_collector'] ?? false ),
                'translatable' => (bool)( $at['translatable'] ?? true ), 'category' => (string)( $at['category'] ?? '' ),
                'placement' => (int)( $at['placement'] ?? 0 ),
                'settings' => tronUnitValue::safe( tronUnitValue::plain( (array)( $at['settings'] ?? array() ) ) ),
            );
        }
        return array(
            'id' => (int)( $c['id'] ?? 0 ), 'identifier' => (string)( $c['identifier'] ?? '' ), 'name' => (string)( $c['name'] ?? '' ),
            'groups' => array_values( array_map( 'strval', (array)( $c['groups'] ?? array() ) ) ),
            'is_container' => (bool)( $c['is_container'] ?? false ), 'always_available' => (bool)( $c['always_available'] ?? false ),
            'object_name_pattern' => (string)( $c['object_name_pattern'] ?? '' ), 'url_alias_pattern' => (string)( $c['url_alias_pattern'] ?? '' ),
            'sort_field' => (string)( $c['sort_field'] ?? '' ), 'sort_order' => (string)( $c['sort_order'] ?? '' ),
            'object_count' => (int)( $c['object_count'] ?? 0 ),
            'modified' => is_string( $c['modified'] ?? null ) ? $c['modified'] : tronUnitsNodeProgram::date( $c['modified'] ?? 0 ),
            'attributes' => $attrs,
        );
    }

    public static function diff( array $x, array $y, array $left, array $right ): array
    {
        $items = array();
        $counts = array( 'changed' => 0, 'only_a' => 0, 'only_b' => 0 );
        foreach ( self::CLASS_FIELDS as $f )
        {
            if ( $x[$f] !== $y[$f] )
            {
                $items[] = array( 'key' => 'class.' . $f, 'change' => 'changed', 'a' => $x[$f], 'b' => $y[$f] );
                $counts['changed']++;
            }
        }
        $ax = array_column( $x['attributes'], null, 'identifier' );
        $ay = array_column( $y['attributes'], null, 'identifier' );
        foreach ( $ax as $id => $at )
        {
            if ( !isset( $ay[$id] ) )
            {
                $items[] = array( 'key' => 'attribute.' . $id, 'change' => 'only_a', 'a' => $at['datatype'], 'b' => null );
                $counts['only_a']++;
                continue;
            }
            foreach ( self::ATTRIBUTE_FIELDS as $f )
            {
                if ( $at[$f] !== $ay[$id][$f] )
                {
                    $items[] = array( 'key' => 'attribute.' . $id . '.' . $f, 'change' => 'changed', 'a' => $at[$f], 'b' => $ay[$id][$f] );
                    $counts['changed']++;
                }
            }
        }
        foreach ( $ay as $id => $at )
        {
            if ( !isset( $ax[$id] ) )
            {
                $items[] = array( 'key' => 'attribute.' . $id, 'change' => 'only_b', 'a' => null, 'b' => $at['datatype'] );
                $counts['only_b']++;
            }
        }
        $orderA = array_values( array_intersect( array_keys( $ax ), array_keys( $ay ) ) );
        $orderB = array_values( array_intersect( array_keys( $ay ), array_keys( $ax ) ) );
        if ( $orderA !== $orderB )
        {
            $items[] = array( 'key' => 'attribute order', 'change' => 'changed', 'a' => $orderA, 'b' => $orderB );
            $counts['changed']++;
        }
        return array( 'a' => $left, 'b' => $right, 'counts' => $counts, 'total' => count( $items ), 'items' => $items );
    }
}
