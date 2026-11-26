<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The program "node": node.read, node.tree, node.find, node.versions. Everything is read as the calling user: a node
 * that user may not read is "not found", and lists hold only what the user's content/read limitations allow.
 */
final class tronUnitsNodeProgram extends tronUnitProgram
{
    const READ = array( 'content', 'read' );
    const VERSIONREAD = array( 'content', 'versionread' );
    const SORTS = array( 'published', 'modified', 'name', 'priority', 'path' );

    protected function area(): string
    {
        return 'node';
    }

    protected function sourceInterface(): string
    {
        return 'tronUnitNodeSource';
    }

    protected function units( tronRegistry $registry, tronEnvironment $env, $source ): void
    {
        $nodeId = array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The node id' );
        $classes = array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_]+(\s*,\s*[A-Za-z0-9_]+)*$', 'maxLength' => 500,
                          'description' => 'A class identifier or a comma list of them' );
        $sort = array( 'type' => 'string', 'enum' => self::SORTS, 'default' => 'published' );
        $order = array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'desc' );

        $this->unit( $registry, 'node.read', array(
            'title' => 'Read a node',
            'description' => 'One content node: ids, name, class, URL alias, section, dates, and every attribute rendered to a plain value (text, numbers, lists of ids or names; never a PHP structure). E-mail addresses are masked.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'node_id' ), 'properties' => array(
                'node_id' => $nodeId,
                'language' => array( 'type' => 'string', 'pattern' => '^[a-z]{3}-[A-Z]{2}$', 'description' => 'A translation, e.g. eng-GB; default the node\'s main one' ),
                'attributes' => array( 'type' => 'string', 'maxLength' => 1000, 'description' => 'Only these attribute identifiers (comma list)' ),
                'max_text' => array( 'type' => 'integer', 'minimum' => 50, 'maximum' => 50000, 'default' => 2000, 'description' => 'Longer text values are cut' ),
            ) ),
            'policy' => self::READ, 'limitedOk' => true,
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source ) {
                $node = $source->node( (int)$a['node_id'], $ctx->principal->userId, (string)( $a['language'] ?? '' ) );
                if ( $node === null )
                    throw new tronToolError( "Node {$a['node_id']} was not found or you may not read it" );
                return self::readNode( $node, isset( $a['attributes'] ) ? self::commaList( $a['attributes'] ) : array(), (int)$a['max_text'] );
            },
        ) );

        $this->unit( $registry, 'node.tree', array(
            'title' => 'Node children and subtree',
            'description' => 'The children (depth 1) or the subtree (depth up to 5) of a node, optionally only some classes.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'node_id' ), 'properties' => array(
                'node_id' => $nodeId,
                'depth' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 5, 'default' => 1 ),
                'class' => $classes, 'sort' => $sort, 'order' => $order,
            ) + self::paging( 25, 100 ) ),
            'policy' => self::READ, 'limitedOk' => true,
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source ) {
                $c = array( 'parent' => (int)$a['node_id'], 'depth' => (int)$a['depth'], 'sort' => $a['sort'], 'order' => $a['order'] );
                if ( isset( $a['class'] ) )
                    $c['classes'] = self::commaList( $a['class'] );
                return self::list( $source->nodes( $c, (int)$a['limit'], (int)$a['offset'], $ctx->principal->userId ), (int)$a['offset'] );
            },
        ) );

        $this->unit( $registry, 'node.find', array(
            'title' => 'Find nodes',
            'description' => 'Nodes by class, attribute value, section, name or path, below a node (default the root, 1) or below the node of a URL path. No filter is required; without one it lists the newest nodes.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
                'class' => $classes,
                'node_id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'Search below this node (default 1)' ),
                'path' => array( 'type' => 'string', 'maxLength' => 500, 'description' => 'Search below the node of this URL alias (Fit-Healthy)' ),
                'section' => array( 'type' => 'integer', 'minimum' => 1 ),
                'name' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'description' => 'Part of the name' ),
                'attribute' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_]+(/[A-Za-z0-9_]+)?$', 'description' => 'An attribute identifier (class/attribute, or attribute with one class)' ),
                'value' => array( 'type' => array( 'string', 'integer', 'number', 'boolean' ), 'description' => 'The value the attribute must have' ),
                'depth' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 10 ),
                'sort' => $sort, 'order' => $order,
            ) + self::paging( 10, 50 ) ),
            'policy' => self::READ, 'limitedOk' => true,
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source ) {
                $parent = (int)( $a['node_id'] ?? 1 );
                if ( isset( $a['path'] ) )
                {
                    $p = $source->nodeByPath( (string)$a['path'] );
                    if ( $p === null )
                        throw new tronToolError( "No node has the path '{$a['path']}'" );
                    $parent = $p;
                }
                $c = array( 'parent' => $parent, 'depth' => (int)$a['depth'], 'sort' => $a['sort'], 'order' => $a['order'] );
                if ( isset( $a['class'] ) )
                    $c['classes'] = self::commaList( $a['class'] );
                if ( isset( $a['section'] ) )
                    $c['section'] = (int)$a['section'];
                if ( isset( $a['name'] ) )
                    $c['name'] = (string)$a['name'];
                if ( isset( $a['attribute'] ) )
                {
                    if ( !array_key_exists( 'value', $a ) )
                        throw new tronToolError( 'attribute needs a value' );
                    $attr = (string)$a['attribute'];
                    if ( strpos( $attr, '/' ) === false )
                    {
                        $cl = $c['classes'] ?? array();
                        if ( count( $cl ) !== 1 )
                            throw new tronToolError( 'Name the attribute as class/attribute, or give exactly one class' );
                        $attr = $cl[0] . '/' . $attr;
                    }
                    $c['attribute'] = $attr;
                    $c['value'] = $a['value'];
                }
                return self::list( $source->nodes( $c, (int)$a['limit'], (int)$a['offset'], $ctx->principal->userId ), (int)$a['offset'] );
            },
        ) );

        $this->unit( $registry, 'node.versions', array(
            'title' => 'Node versions',
            'description' => 'The versions of the content object of a node, newest first: number, status (draft, published, pending, archived, rejected, internal draft), language, creator, dates.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'node_id' ), 'properties' => array(
                'node_id' => $nodeId,
                'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 20 ),
            ) ),
            'policy' => self::VERSIONREAD, 'limitedOk' => true,
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source ) {
                $v = $source->versions( (int)$a['node_id'], $ctx->principal->userId );
                if ( $v === null )
                    throw new tronToolError( "Node {$a['node_id']} was not found or you may not read its versions" );
                $items = array();
                foreach ( (array)$v['items'] as $row )
                    $items[] = array(
                        'version' => (int)$row['version'], 'status' => (string)$row['status'], 'language' => (string)( $row['language'] ?? '' ),
                        'creator_id' => (int)( $row['creator_id'] ?? 0 ), 'creator' => tronUnitValue::safeText( (string)( $row['creator'] ?? '' ) ),
                        'created' => self::date( $row['created'] ?? 0 ), 'modified' => self::date( $row['modified'] ?? 0 ),
                    );
                usort( $items, function ( $x, $y ) { return $y['version'] <=> $x['version']; } );
                $total = count( $items );
                return array( 'node_id' => (int)$v['node_id'], 'object_id' => (int)$v['object_id'], 'current_version' => (int)$v['current_version'],
                              'total' => $total, 'offset' => 0, 'items' => array_slice( $items, 0, (int)$a['limit'] ) );
            },
        ) );
    }

    /** A node as node.read gives it: fixed fields, attributes as plain, safe values, long text cut. */
    public static function readNode( array $n, array $only, int $maxText ): array
    {
        $attrs = array();
        foreach ( (array)( $n['attributes'] ?? array() ) as $a )
        {
            if ( $only && !in_array( $a['identifier'], $only, true ) )
                continue;
            $v = tronUnitValue::safe( tronUnitValue::plain( $a['value'] ?? null ), (string)$a['identifier'] );
            $v = self::cut( $v, $maxText );
            $attrs[] = array( 'identifier' => (string)$a['identifier'], 'name' => (string)( $a['name'] ?? $a['identifier'] ),
                              'datatype' => (string)( $a['datatype'] ?? '' ), 'value' => $v );
        }
        return array(
            'node_id' => (int)$n['node_id'], 'object_id' => (int)$n['object_id'], 'name' => tronUnitValue::safeText( (string)$n['name'] ),
            'class_identifier' => (string)$n['class_identifier'], 'class_name' => (string)( $n['class_name'] ?? '' ),
            'url_alias' => (string)( $n['url_alias'] ?? '' ), 'parent_node_id' => (int)( $n['parent_node_id'] ?? 0 ),
            'depth' => (int)( $n['depth'] ?? 0 ), 'section_id' => (int)( $n['section_id'] ?? 0 ), 'section' => (string)( $n['section'] ?? '' ),
            'language' => (string)( $n['language'] ?? '' ), 'published' => self::date( $n['published'] ?? 0 ),
            'modified' => self::date( $n['modified'] ?? 0 ), 'current_version' => (int)( $n['current_version'] ?? 0 ),
            'children_count' => (int)( $n['children_count'] ?? 0 ), 'hidden' => (bool)( $n['hidden'] ?? false ),
            'remote_id' => (string)( $n['remote_id'] ?? '' ), 'attributes' => $attrs,
        );
    }

    /** A node list page with summaries. */
    public static function list( array $r, int $offset ): array
    {
        $items = array();
        foreach ( (array)$r['items'] as $n )
            $items[] = array(
                'node_id' => (int)$n['node_id'], 'object_id' => (int)$n['object_id'], 'name' => tronUnitValue::safeText( (string)$n['name'] ),
                'class_identifier' => (string)$n['class_identifier'], 'url_alias' => (string)( $n['url_alias'] ?? '' ),
                'parent_node_id' => (int)( $n['parent_node_id'] ?? 0 ), 'depth' => (int)( $n['depth'] ?? 0 ),
                'published' => self::date( $n['published'] ?? 0 ), 'modified' => self::date( $n['modified'] ?? 0 ),
            );
        return array( 'total' => (int)$r['total'], 'offset' => $offset, 'items' => $items );
    }

    public static function date( $t ): ?string
    {
        return (int)$t > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', (int)$t ) : null;
    }

    private static function cut( $v, int $max )
    {
        if ( is_string( $v ) )
        {
            $len = function_exists( 'mb_strlen' ) ? mb_strlen( $v, 'UTF-8' ) : strlen( $v );
            return $len > $max ? ( function_exists( 'mb_substr' ) ? mb_substr( $v, 0, $max, 'UTF-8' ) : substr( $v, 0, $max ) ) . '… (' . $len . ' characters)' : $v;
        }
        if ( is_array( $v ) )
        {
            foreach ( $v as $k => $item )
                $v[$k] = self::cut( $item, $max );
        }
        return $v;
    }
}
