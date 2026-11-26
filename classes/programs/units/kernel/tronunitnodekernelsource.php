<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The node units in an installation: eZContentObjectTreeNode, read as the calling user. A single node is checked with
 * checkAccess( 'read', ..., $userId ); lists pass the user's own content/read policies as the fetch's Limitation, the
 * way the kernel limits a fetch for the signed-in user.
 *
 * Attributes are rendered to plain values per datatype: text as text (rich text without markup), numbers and dates
 * as such, images as alias data, relations as ids and names, a user account as its login only, authors without
 * e-mail addresses. Anything else goes through the datatype's toString().
 */
final class tronUnitNodeKernelSource implements tronUnitNodeSource
{
    const VERSION_STATUS = array( 0 => 'draft', 1 => 'published', 2 => 'pending', 3 => 'archived', 4 => 'rejected',
                                  5 => 'internal draft', 6 => 'repeat', 7 => 'queued' );
    const SORT = array( 'published' => 'published', 'modified' => 'modified', 'name' => 'name', 'priority' => 'priority', 'path' => 'path' );

    public function available(): bool
    {
        return class_exists( 'eZContentObjectTreeNode' );
    }

    public function reason(): string
    {
        return 'no content kernel here';
    }

    public function node( int $nodeId, int $userId, string $language ): ?array
    {
        $node = eZContentObjectTreeNode::fetch( $nodeId, $language !== '' ? $language : false );
        if ( !$node instanceof eZContentObjectTreeNode || !self::canRead( $node, $userId ) )
            return null;
        $object = $node->object();
        $attributes = array();
        foreach ( $node->dataMap() as $identifier => $attr )
        {
            $class = $attr->contentClassAttribute();
            $attributes[] = array( 'identifier' => (string)$identifier, 'name' => $class ? (string)$class->attribute( 'name' ) : (string)$identifier,
                                   'datatype' => (string)$attr->attribute( 'data_type_string' ), 'value' => self::render( $attr ) );
        }
        $section = class_exists( 'eZSection' ) ? eZSection::fetch( $object->attribute( 'section_id' ) ) : null;
        return self::summary( $node ) + array(
            'class_name' => (string)$object->attribute( 'class_name' ),
            'section_id' => (int)$object->attribute( 'section_id' ),
            'section' => $section ? (string)$section->attribute( 'name' ) : '',
            'language' => (string)$object->attribute( 'current_language' ),
            'current_version' => (int)$object->attribute( 'current_version' ),
            'children_count' => (int)$node->attribute( 'children_count' ),
            'hidden' => (bool)$node->attribute( 'is_hidden' ) || (bool)$node->attribute( 'is_invisible' ),
            'remote_id' => (string)$object->attribute( 'remote_id' ),
            'attributes' => $attributes,
        );
    }

    public function nodes( array $c, int $limit, int $offset, int $userId ): array
    {
        $limitation = self::limitation( $userId );
        if ( $limitation === null )
            return array( 'total' => 0, 'items' => array() );
        $params = array( 'Depth' => max( 1, (int)( $c['depth'] ?? 1 ) ), 'DepthOperator' => 'le', 'Limitation' => $limitation );
        if ( !empty( $c['classes'] ) )
        {
            $params['ClassFilterType'] = 'include';
            $params['ClassFilterArray'] = array_values( $c['classes'] );
        }
        $filters = array();
        if ( isset( $c['section'] ) )
            $filters[] = array( 'section', '=', (int)$c['section'] );
        if ( isset( $c['name'] ) )
            $filters[] = array( 'name', 'like', '*' . str_replace( '*', '', (string)$c['name'] ) . '*' );
        if ( isset( $c['attribute'] ) )
            $filters[] = array( (string)$c['attribute'], '=', $c['value'] );
        if ( $filters )
            $params['AttributeFilter'] = array_merge( array( 'and' ), $filters );
        $total = (int)eZContentObjectTreeNode::subTreeCountByNodeID( $params, (int)$c['parent'] );
        $params['SortBy'] = array( self::SORT[$c['sort'] ?? 'published'] ?? 'published', ( $c['order'] ?? 'desc' ) === 'asc' );
        $params['Limit'] = $limit;
        $params['Offset'] = $offset;
        $params['LoadDataMap'] = false;
        $items = array();
        foreach ( (array)eZContentObjectTreeNode::subTreeByNodeID( $params, (int)$c['parent'] ) as $node )
            $items[] = self::summary( $node );
        return array( 'total' => $total, 'items' => $items );
    }

    public function nodeByPath( string $path ): ?int
    {
        $path = trim( $path, '/' );
        if ( preg_match( '#^content/view/[a-z0-9_]+/(\d+)#', $path, $m ) )
            return (int)$m[1];
        // translate() resolves multi-level aliases case-insensitively (as expLayoutsResolver::nodeFromPath() does)
        $uri = $path;
        eZURLAliasML::translate( $uri );
        if ( is_string( $uri ) && preg_match( '#content/view/[a-z0-9_]+/(\d+)#', $uri, $m ) )
            return (int)$m[1];
        $id = eZURLAliasML::fetchNodeIDByPath( $path );
        return $id ? (int)$id : null;
    }

    public function versions( int $nodeId, int $userId ): ?array
    {
        $node = eZContentObjectTreeNode::fetch( $nodeId );
        if ( !$node instanceof eZContentObjectTreeNode || !self::canRead( $node, $userId ) )
            return null;
        $object = $node->object();
        if ( (int)$object->checkAccess( 'versionread', false, false, false, false, $userId ) !== 1 )
            return null;
        $items = array();
        $names = array();
        foreach ( (array)$object->versions() as $v )
        {
            $creator = (int)$v->attribute( 'creator_id' );
            if ( !isset( $names[$creator] ) )
            {
                $co = eZContentObject::fetch( $creator );
                $names[$creator] = $co ? (string)$co->attribute( 'name' ) : '';
            }
            $items[] = array( 'version' => (int)$v->attribute( 'version' ), 'status' => self::VERSION_STATUS[(int)$v->attribute( 'status' )] ?? (string)$v->attribute( 'status' ),
                              'language' => (string)$v->attribute( 'initial_language_code' ), 'creator_id' => $creator, 'creator' => $names[$creator],
                              'created' => (int)$v->attribute( 'created' ), 'modified' => (int)$v->attribute( 'modified' ) );
        }
        return array( 'node_id' => $nodeId, 'object_id' => (int)$object->attribute( 'id' ),
                      'current_version' => (int)$object->attribute( 'current_version' ), 'items' => $items );
    }

    // ------------------------------------------------------------------ helpers

    public static function canRead( eZContentObjectTreeNode $node, int $userId ): bool
    {
        return (int)$node->checkAccess( 'read', false, false, false, false, $userId ) === 1;
    }

    /** The user's content/read as a fetch Limitation: array() for all, the policies when limited, null for none. */
    public static function limitation( int $userId ): ?array
    {
        $user = eZUser::fetch( $userId );
        if ( !$user instanceof eZUser || !$user->isEnabled() )
            return null;
        $r = $user->hasAccessTo( 'content', 'read' );
        if ( ( $r['accessWord'] ?? 'no' ) === 'yes' )
            return array();
        if ( ( $r['accessWord'] ?? 'no' ) === 'limited' )
            return (array)$r['policies'];
        return null;
    }

    private static function summary( eZContentObjectTreeNode $node ): array
    {
        $object = $node->object();
        return array( 'node_id' => (int)$node->attribute( 'node_id' ), 'object_id' => (int)$node->attribute( 'contentobject_id' ),
                      'name' => (string)$node->attribute( 'name' ), 'class_identifier' => (string)$node->attribute( 'class_identifier' ),
                      'url_alias' => (string)$node->attribute( 'url_alias' ), 'parent_node_id' => (int)$node->attribute( 'parent_node_id' ),
                      'depth' => (int)$node->attribute( 'depth' ),
                      'published' => $object ? (int)$object->attribute( 'published' ) : 0, 'modified' => $object ? (int)$object->attribute( 'modified' ) : 0 );
    }

    /** An attribute as a plain value. */
    public static function render( eZContentObjectAttribute $attr )
    {
        $type = (string)$attr->attribute( 'data_type_string' );
        try
        {
            if ( !$attr->hasContent() && !in_array( $type, array( 'ezboolean', 'ezinteger', 'ezfloat' ), true ) )
                return null;
            switch ( $type )
            {
                case 'ezboolean':
                    return (bool)$attr->attribute( 'data_int' );
                case 'ezinteger':
                    return (int)$attr->attribute( 'data_int' );
                case 'ezfloat':
                    return (float)$attr->attribute( 'data_float' );
                case 'ezdate':
                case 'ezdatetime':
                    return (int)$attr->attribute( 'data_int' ) > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', (int)$attr->attribute( 'data_int' ) ) : null;
                case 'ezxmltext':
                case 'ezrichtext':
                    return self::text( (string)$attr->toString() );
                case 'ezimage':
                    $c = $attr->content();
                    $o = $c ? $c->attribute( 'original' ) : null;
                    return is_array( $o ) ? array( 'url' => (string)( $o['url'] ?? '' ), 'alt' => (string)( $c->attribute( 'alternative_text' ) ?? '' ),
                                                   'width' => (int)( $o['width'] ?? 0 ), 'height' => (int)( $o['height'] ?? 0 ),
                                                   'mime_type' => (string)( $o['mime_type'] ?? '' ) ) : null;
                case 'ezbinaryfile':
                case 'ezmedia':
                    $c = $attr->content();
                    return $c ? array( 'filename' => (string)$c->attribute( 'original_filename' ), 'size' => (int)$c->attribute( 'filesize' ),
                                       'mime_type' => (string)$c->attribute( 'mime_type' ) ) : null;
                case 'ezobjectrelation':
                    $o = $attr->content();
                    return $o instanceof eZContentObject ? array( 'object_id' => (int)$o->attribute( 'id' ), 'name' => (string)$o->attribute( 'name' ),
                                                                   'node_id' => (int)$o->attribute( 'main_node_id' ) ) : null;
                case 'ezobjectrelationlist':
                    $out = array();
                    foreach ( (array)( $attr->content()['relation_list'] ?? array() ) as $rel )
                    {
                        $o = eZContentObject::fetch( (int)$rel['contentobject_id'] );
                        $out[] = array( 'object_id' => (int)$rel['contentobject_id'], 'node_id' => (int)( $rel['node_id'] ?? 0 ),
                                        'name' => $o ? (string)$o->attribute( 'name' ) : '' );
                    }
                    return $out;
                case 'ezuser':
                    $u = $attr->content();
                    return $u instanceof eZUser ? array( 'login' => (string)$u->attribute( 'login' ) ) : null;
                case 'ezauthor':
                    $out = array();
                    $c = $attr->content();
                    foreach ( $c ? (array)$c->attribute( 'author_list' ) : array() as $a )
                        $out[] = (string)( $a['name'] ?? '' );
                    return $out;
                case 'ezemail':
                    return (string)$attr->attribute( 'data_text' );
            }
            $s = $attr->toString();
            if ( is_string( $s ) && strpos( $s, '<' ) !== false && preg_match( '/<\/?[a-z][^>]*>/i', $s ) )
                return self::text( $s );
            return is_scalar( $s ) || $s === null ? $s : tronUnitValue::plain( $s );
        }
        catch ( Throwable $e )
        {
            return '[' . $type . ': ' . $e->getMessage() . ']';
        }
    }

    /** Markup as plain text, whitespace folded. */
    private static function text( string $s ): string
    {
        $s = preg_replace( '/<(br|\/p|\/paragraph|\/li|\/h[1-6]|\/header|\/section)[^>]*>/i', "\n", $s );
        $s = html_entity_decode( strip_tags( (string)$s ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $s = preg_replace( "/[ \t]+/", ' ', $s );
        return trim( (string)preg_replace( "/\n\s*\n+/", "\n", $s ) );
    }
}
