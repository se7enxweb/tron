<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The program "layout": layout.list, layout.read, rule.list, rule.resolve, block.definitions (Exponential Layouts).
 * A linked zone is shown with the blocks of the zone it links to (expLayoutsZone::resolveSource(), which follows link
 * chains and lands on the shared layout's published row); rule.resolve tries the rules the way expLayoutsResolver does,
 * without its cache, and says for every rule why it did or did not match.
 */
final class tronUnitsLayoutProgram extends tronUnitProgram
{
    const POLICY = array( 'explayouts', 'read' );

    protected function area(): string
    {
        return 'layout';
    }

    protected function sourceInterface(): string
    {
        return 'tronUnitLayoutSource';
    }

    protected function units( tronRegistry $registry, tronEnvironment $env, $source ): void
    {
        $layoutId = array( 'type' => 'integer', 'minimum' => 1 );

        $this->unit( $registry, 'layout.list', array(
            'title' => 'List layouts',
            'description' => 'The published layouts (with drafts=true also the drafts): id, identifier, name, layout type, shared (a shared layout\'s zones are linked into others, like a header and footer), number of zones.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
                'shared' => array( 'type' => 'boolean', 'description' => 'Only shared (true) or only not shared (false) layouts' ),
                'drafts' => array( 'type' => 'boolean', 'default' => false ),
            ) + self::paging( 100, 500 ) ),
            'policy' => self::POLICY,
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source ) {
                $items = array();
                foreach ( $source->layouts() as $l )
                {
                    if ( !$a['drafts'] && ( $l['status'] ?? 'published' ) !== 'published' )
                        continue;
                    if ( isset( $a['shared'] ) && (bool)$l['shared'] !== (bool)$a['shared'] )
                        continue;
                    $items[] = array( 'id' => (int)$l['id'], 'identifier' => (string)$l['identifier'], 'name' => (string)$l['name'],
                                      'layout_type' => (string)( $l['layout_type'] ?? '' ), 'shared' => (bool)$l['shared'],
                                      'status' => (string)( $l['status'] ?? 'published' ), 'zones' => (int)( $l['zones'] ?? 0 ),
                                      'modified' => tronUnitsNodeProgram::date( $l['modified'] ?? 0 ) );
                }
                return array( 'total' => count( $items ), 'offset' => (int)$a['offset'],
                              'items' => array_slice( $items, (int)$a['offset'], (int)$a['limit'] ) );
            },
        ) );

        $this->unit( $registry, 'layout.read', array(
            'title' => 'Read a layout',
            'description' => 'One layout (by layout_id or identifier; the published one, or its draft with draft=true) with every zone and its blocks in order: block type, name, view type, item view type, parameters, child blocks. A linked zone names the shared layout and zone it links to, and its blocks are the ones of that zone.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
                'layout_id' => $layoutId,
                'identifier' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_.-]{1,150}$' ),
                'draft' => array( 'type' => 'boolean', 'default' => false ),
            ) ),
            'policy' => self::POLICY,
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source ) {
                $key = isset( $a['layout_id'] ) ? (string)(int)$a['layout_id'] : (string)( $a['identifier'] ?? '' );
                if ( $key === '' )
                    throw new tronToolError( 'Give layout_id or identifier' );
                $l = $source->layout( $key, (bool)$a['draft'] );
                if ( $l === null )
                    throw new tronToolError( ( $a['draft'] ? 'No draft of' : 'No published' ) . " layout '$key'" );
                return tronUnitValue::safe( tronUnitValue::plain( $l, 0, 16 ) );
            },
        ) );

        $this->unit( $registry, 'rule.list', array(
            'title' => 'List layout rules',
            'description' => 'The layout rules in the order the resolver tries them (priority, highest first): targets (path, path prefix, node, subtree, route ...), conditions, the layout, enabled.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
                'layout_id' => $layoutId,
                'enabled' => array( 'type' => 'boolean' ),
            ) + self::paging( 100, 500 ) ),
            'policy' => self::POLICY,
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source ) {
                $items = array();
                foreach ( $source->rules() as $r )
                {
                    if ( isset( $a['layout_id'] ) && (int)$r['layout_id'] !== (int)$a['layout_id'] )
                        continue;
                    if ( isset( $a['enabled'] ) && (bool)$r['enabled'] !== (bool)$a['enabled'] )
                        continue;
                    $items[] = array( 'id' => (int)$r['id'], 'priority' => (int)$r['priority'], 'enabled' => (bool)$r['enabled'],
                                      'layout_id' => (int)$r['layout_id'], 'layout_identifier' => (string)( $r['layout_identifier'] ?? '' ),
                                      'targets' => tronUnitValue::plain( array_values( (array)$r['targets'] ) ),
                                      'conditions' => tronUnitValue::plain( array_values( (array)$r['conditions'] ) ) );
                }
                return array( 'total' => count( $items ), 'offset' => (int)$a['offset'],
                              'items' => array_slice( $items, (int)$a['offset'], (int)$a['limit'] ) );
            },
        ) );

        $this->unit( $registry, 'rule.resolve', array(
            'title' => 'Which layout a page gets',
            'description' => 'The layout the resolver gives a page (path, a URL alias such as Fit-Healthy, or node_id), tried rule by rule without the resolver cache: every rule with whether its targets and conditions matched, the winning rule, or the default layout.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
                'path' => array( 'type' => 'string', 'maxLength' => 500 ),
                'node_id' => array( 'type' => 'integer', 'minimum' => 1 ),
            ) ),
            'policy' => self::POLICY,
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source ) {
                if ( isset( $a['path'] ) )
                    $path = (string)$a['path'];
                else if ( isset( $a['node_id'] ) )
                {
                    $path = $source->pathOfNode( (int)$a['node_id'] );
                    if ( $path === null )
                        throw new tronToolError( "Node {$a['node_id']} has no path" );
                }
                else
                    throw new tronToolError( 'Give path or node_id' );
                $r = $source->resolve( $path );
                $r = tronUnitValue::plain( $r );
                $r['node_id'] = isset( $a['node_id'] ) ? (int)$a['node_id'] : ( $r['node_id'] ?? null );
                $r['layout_id'] = (int)( $r['layout_id'] ?? 0 );
                return $r;
            },
        ) );

        $this->unit( $registry, 'block.definitions', array(
            'title' => 'Block types',
            'description' => 'The block types (explayouts.ini [BlockDefinition_*]): identifier, name, view types, item view types, handler and the other settings of each.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array() ),
            'policy' => self::POLICY,
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source ) {
                $items = array_values( tronUnitValue::safe( tronUnitValue::plain( $source->blockDefinitions() ) ) );
                return array( 'total' => count( $items ), 'offset' => 0, 'items' => $items );
            },
        ) );
    }
}
