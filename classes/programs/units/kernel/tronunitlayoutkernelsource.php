<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The Exponential Layouts units in an installation: expLayoutsLayout, expLayoutsZone (linked zones through
 * resolveSource(), which follows link chains to the shared layout's published row), expLayoutsBlock, expLayoutsRule and
 * expLayoutsResolver's own matching (ruleMatches(), targetsMatch(), conditionMatches()), tried rule by rule without
 * the resolver cache, so a read never writes a cache entry.
 */
final class tronUnitLayoutKernelSource implements tronUnitLayoutSource
{
    const PUBLISHED = 2, DRAFT = 1;

    public function available(): bool
    {
        return class_exists( 'expLayoutsLayout' ) && class_exists( 'expLayoutsResolver' );
    }

    public function reason(): string
    {
        return 'Exponential Layouts (extension explayouts) is not active here';
    }

    public function layouts(): array
    {
        $out = array();
        foreach ( (array)expLayoutsLayout::fetchList( false ) as $l )
        {
            $status = (int)$l->attribute( 'status' );
            if ( $status !== self::PUBLISHED && $status !== self::DRAFT )
                continue;
            $out[] = array( 'id' => (int)$l->attribute( 'id' ), 'identifier' => (string)$l->attribute( 'identifier' ), 'name' => (string)$l->attribute( 'name' ),
                            'layout_type' => (string)$l->attribute( 'layout_type' ), 'shared' => (int)$l->attribute( 'shared' ) === 1,
                            'status' => $status === self::PUBLISHED ? 'published' : 'draft',
                            'zones' => count( (array)expLayoutsZone::fetchByLayout( (int)$l->attribute( 'id' ), $status ) ),
                            'modified' => (int)$l->attribute( 'modified' ) );
        }
        usort( $out, function ( $a, $b ) { return $a['id'] <=> $b['id']; } );
        return $out;
    }

    public function layout( string $layout, bool $draft ): ?array
    {
        $status = $draft ? self::DRAFT : self::PUBLISHED;
        $l = null;
        if ( ctype_digit( $layout ) )
        {
            $l = expLayoutsLayout::fetch( (int)$layout );
            if ( $l && (int)$l->attribute( 'status' ) !== $status )
                $l = expLayoutsLayout::fetchByIdentifier( (string)$l->attribute( 'identifier' ), $status );
        }
        else
            $l = expLayoutsLayout::fetchByIdentifier( $layout, $status );
        if ( !$l instanceof expLayoutsLayout )
            return null;
        $zones = array();
        foreach ( (array)expLayoutsZone::fetchByLayout( (int)$l->attribute( 'id' ), $status ) as $z )
        {
            $source = expLayoutsZone::resolveSource( $z );
            $sourceLayout = (int)$source->attribute( 'layout_id' ) === (int)$l->attribute( 'id' ) ? $l : expLayoutsLayout::fetch( (int)$source->attribute( 'layout_id' ) );
            $zones[] = array(
                'identifier' => (string)$z->attribute( 'identifier' ),
                'linked' => $z->isLinked() ? array( 'layout_id' => (int)$z->attribute( 'linked_layout_id' ), 'zone' => (string)$z->attribute( 'linked_zone_identifier' ) ) : null,
                'source' => array( 'layout_id' => (int)$source->attribute( 'layout_id' ),
                                   'layout_identifier' => $sourceLayout ? (string)$sourceLayout->attribute( 'identifier' ) : '',
                                   'zone' => (string)$source->attribute( 'identifier' ) ),
                'blocks' => self::blocks( (int)$source->attribute( 'id' ), (int)$source->attribute( 'status' ) ),
            );
        }
        return array( 'id' => (int)$l->attribute( 'id' ), 'identifier' => (string)$l->attribute( 'identifier' ), 'name' => (string)$l->attribute( 'name' ),
                      'layout_type' => (string)$l->attribute( 'layout_type' ), 'shared' => (int)$l->attribute( 'shared' ) === 1,
                      'status' => $status === self::PUBLISHED ? 'published' : 'draft', 'modified' => (int)$l->attribute( 'modified' ), 'zones' => $zones );
    }

    public function rules(): array
    {
        $identifiers = array();
        $out = array();
        foreach ( (array)expLayoutsRule::fetchAll() as $r )
        {
            $lid = (int)$r->attribute( 'layout_id' );
            if ( !isset( $identifiers[$lid] ) )
            {
                $l = expLayoutsLayout::fetch( $lid );
                $identifiers[$lid] = $l ? (string)$l->attribute( 'identifier' ) : '';
            }
            $out[] = array( 'id' => (int)$r->attribute( 'id' ), 'priority' => (int)$r->attribute( 'priority' ), 'enabled' => (int)$r->attribute( 'enabled' ) === 1,
                            'layout_id' => $lid, 'layout_identifier' => $identifiers[$lid],
                            'targets' => self::pairs( $r->targets(), 'target_type', 'target_value' ),
                            'conditions' => self::pairs( $r->conditions(), 'condition_type', 'condition_value' ) );
        }
        return $out;
    }

    public function resolve( string $path ): array
    {
        $path = ltrim( $path, '/' );
        if ( stripos( $path, 'index.php/' ) === 0 )
            $path = substr( $path, 10 );
        if ( $path === '' )
            $path = 'home';
        $tried = array();
        $requestDependent = false;
        $answer = null;
        foreach ( (array)expLayoutsRule::fetchEnabled() as $rule )
        {
            $targetsMatch = (bool)expLayoutsResolver::targetsMatch( $rule, $path );
            $conds = array();
            $condsOk = true;
            foreach ( (array)$rule->conditions() as $c )
            {
                $ok = (bool)expLayoutsResolver::conditionMatches( $c, $path );
                $condsOk = $condsOk && $ok;
                $conds[] = array( 'type' => (string)$c->attribute( 'condition_type' ), 'value' => (string)$c->attribute( 'condition_value' ), 'matched' => $ok );
            }
            if ( $targetsMatch && expLayoutsResolver::isRequestDependent( $rule ) )
                $requestDependent = true;
            $row = array( 'rule_id' => (int)$rule->attribute( 'id' ), 'priority' => (int)$rule->attribute( 'priority' ),
                          'layout_id' => (int)$rule->attribute( 'layout_id' ), 'targets_match' => $targetsMatch, 'conditions' => $conds,
                          'matched' => $targetsMatch && $condsOk );
            if ( $row['matched'] )
            {
                $l = expLayoutsLayout::fetch( $row['layout_id'] );
                if ( !$l || (int)$l->attribute( 'status' ) !== self::PUBLISHED )
                    $row['note'] = 'its layout is not published: passed over';
                else if ( expLayoutsLayoutType::isAdminType( $l->attribute( 'layout_type' ) ) )
                    $row['note'] = 'its layout is an admin layout: passed over on the site';
                else
                    $answer = array( 'source' => 'rule', 'rule_id' => $row['rule_id'], 'layout' => $l );
            }
            $tried[] = $row;
            if ( $answer )
                break;
        }
        if ( !$answer )
        {
            $default = (string)eZINI::instance( 'explayouts.ini' )->variable( 'ResolverSettings', 'DefaultLayout' );
            $l = $default !== '' ? expLayoutsLayout::fetchByIdentifier( $default, self::PUBLISHED ) : false;
            if ( $l && !expLayoutsLayoutType::isAdminType( $l->attribute( 'layout_type' ) ) )
                $answer = array( 'source' => 'default', 'rule_id' => 0, 'layout' => $l );
        }
        $node = expLayoutsResolver::nodeFromPath( $path );
        return array( 'path' => $path, 'node_id' => is_object( $node ) ? (int)$node->attribute( 'node_id' ) : ( is_numeric( $node ) ? (int)$node : null ),
                      'layout_id' => $answer ? (int)$answer['layout']->attribute( 'id' ) : 0,
                      'layout_identifier' => $answer ? (string)$answer['layout']->attribute( 'identifier' ) : '',
                      'source' => $answer ? $answer['source'] : 'none', 'rule_id' => $answer ? $answer['rule_id'] : 0,
                      'request_dependent' => $requestDependent, 'tried' => $tried );
    }

    public function pathOfNode( int $nodeId ): ?string
    {
        $node = eZContentObjectTreeNode::fetch( $nodeId );
        if ( !$node instanceof eZContentObjectTreeNode )
            return null;
        $alias = (string)$node->attribute( 'url_alias' );
        return $alias !== '' ? $alias : 'content/view/full/' . $nodeId;
    }

    public function blockDefinitions(): array
    {
        $ini = eZINI::instance( 'explayouts.ini' );
        $out = array();
        foreach ( (array)$ini->groups() as $name => $settings )
        {
            if ( strpos( (string)$name, 'BlockDefinition_' ) !== 0 )
                continue;
            $id = substr( (string)$name, 16 );
            $row = array( 'identifier' => $id, 'name' => (string)( $settings['Name'] ?? $id ) );
            foreach ( (array)$settings as $k => $v )
                if ( $k !== 'Name' )
                    $row[self::snake( (string)$k )] = $v;
            $out[] = $row;
        }
        return $out;
    }

    private static function blocks( int $zoneId, int $status ): array
    {
        $all = (array)expLayoutsBlock::fetchByZone( $zoneId, $status );
        $byParent = array();
        foreach ( $all as $b )
            $byParent[(int)$b->attribute( 'parent_id' )][] = $b;
        $build = function ( int $parent, int $depth ) use ( &$build, $byParent ) {
            $out = array();
            foreach ( $byParent[$parent] ?? array() as $b )
            {
                $id = (int)$b->attribute( 'id' );
                $out[] = array( 'id' => $id, 'definition' => (string)$b->attribute( 'definition_identifier' ), 'name' => (string)$b->attribute( 'name' ),
                                'view_type' => (string)$b->attribute( 'view_type' ), 'item_view_type' => (string)$b->attribute( 'item_view_type' ),
                                'position' => (int)$b->attribute( 'position' ), 'placeholder' => (string)$b->attribute( 'placeholder' ),
                                'parameters' => self::decode( $b->parameters() ),
                                'children' => $depth < 6 ? $build( $id, $depth + 1 ) : array() );
            }
            return $out;
        };
        return $build( 0, 0 );
    }

    /** Parameter values that are JSON are shown decoded. */
    private static function decode( array $params ): array
    {
        foreach ( $params as $k => $v )
        {
            if ( is_string( $v ) && $v !== '' && ( $v[0] === '{' || $v[0] === '[' ) )
            {
                $d = json_decode( $v, true );
                if ( is_array( $d ) )
                    $params[$k] = $d;
            }
        }
        return $params;
    }

    private static function pairs( $rows, string $typeField, string $valueField ): array
    {
        $out = array();
        foreach ( (array)$rows as $r )
            $out[] = array( 'type' => (string)$r->attribute( $typeField ), 'value' => (string)$r->attribute( $valueField ) );
        return $out;
    }

    private static function snake( string $k ): string
    {
        return strtolower( (string)preg_replace( '/(?<!^)[A-Z]/', '_$0', $k ) );
    }
}
