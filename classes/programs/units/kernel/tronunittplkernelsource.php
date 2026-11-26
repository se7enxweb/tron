<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The template units in an installation: the override list of a siteaccess as eZTemplateDesignResource builds it
 * (overrideArray(), already in Priority order), the match keys a node view sets (the same keys as
 * eZNodeviewfunctions), and the template parser (eZTemplate::validateTemplateFile()) with the functions and operators
 * of the parsed tree checked against the ones this installation registers.
 */
final class tronUnitTplKernelSource implements tronUnitTplSource
{
    public function available(): bool
    {
        return class_exists( 'eZTemplateDesignResource' ) && class_exists( 'eZTemplate' );
    }

    public function reason(): string
    {
        return 'no template system here';
    }

    public function siteAccesses(): array
    {
        return array_values( array_map( 'strval', (array)eZINI::instance( 'site.ini' )->variable( 'SiteAccessSettings', 'AvailableSiteAccessList' ) ) );
    }

    public function overrides( string $template, string $siteAccess ): ?array
    {
        $current = isset( $GLOBALS['eZCurrentAccess']['name'] ) ? (string)$GLOBALS['eZCurrentAccess']['name'] : '';
        $sa = $siteAccess !== '' && $siteAccess !== $current ? $siteAccess : false;
        $all = eZTemplateDesignResource::overrideArray( $sa );
        $entry = $all['/' . ltrim( $template, '/' )] ?? null;
        if ( !is_array( $entry ) )
            return null;
        $base = null;
        if ( isset( $entry['base_dir'], $entry['template'] ) )
        {
            $dir = (string)$entry['base_dir'];
            $base = array( 'path' => rtrim( $dir, '/' ) . '/' . ltrim( (string)$entry['template'], '/' ),
                           'design' => preg_match( '#design/([^/]+)/#', $dir . '/', $m ) ? $m[1] : '' );
        }
        $overrides = array();
        foreach ( (array)( $entry['custom_match'] ?? array() ) as $cm )
            $overrides[] = array( 'name' => (string)$cm['override_name'], 'match_file' => $cm['match_file'] ? (string)$cm['match_file'] : null,
                                  'conditions' => is_array( $cm['conditions'] ) ? array_map( 'strval', $cm['conditions'] ) : array() );
        $designs = array();
        foreach ( (array)eZTemplateDesignResource::allDesignBases( $sa ) as $b )
            $designs[] = (string)$b;
        return array( 'siteaccess' => $sa === false ? $current : $sa, 'designs' => array_values( array_unique( $designs ) ),
                      'base' => $base, 'overrides' => $overrides );
    }

    public function nodeKeys( int $nodeId, string $view, int $userId ): ?array
    {
        $node = eZContentObjectTreeNode::fetch( $nodeId );
        if ( !$node instanceof eZContentObjectTreeNode || !tronUnitNodeKernelSource::canRead( $node, $userId ) )
            return null;
        $object = $node->object();
        $section = eZSection::fetch( $object->attribute( 'section_id' ) );
        $keys = array(
            'object' => (int)$object->attribute( 'id' ), 'node' => (int)$node->attribute( 'node_id' ),
            'parent_node' => (int)$node->attribute( 'parent_node_id' ), 'class' => (int)$object->attribute( 'contentclass_id' ),
            'class_identifier' => (string)$node->attribute( 'class_identifier' ), 'view_offset' => 0, 'viewmode' => $view,
            'remote_id' => (string)$object->attribute( 'remote_id' ), 'node_remote_id' => (string)$node->attribute( 'remote_id' ),
            'navigation_part_identifier' => $section ? (string)$section->attribute( 'navigation_part_identifier' ) : null,
            'depth' => (int)$node->attribute( 'depth' ), 'url_alias' => (string)$node->attribute( 'url_alias' ),
            'class_group' => array_values( (array)$object->attribute( 'match_ingroup_id_list' ) ),
            'state' => array_values( (array)$object->attribute( 'state_id_array' ) ),
            'state_identifier' => array_values( (array)$object->attribute( 'state_identifier_array' ) ),
            'section' => (int)$object->attribute( 'section_id' ),
            'section_identifier' => $section ? (string)$section->attribute( 'identifier' ) : null,
        );
        $parent = $node->attribute( 'parent' );
        if ( is_object( $parent ) )
        {
            $keys['parent_node_remote_id'] = (string)$parent->attribute( 'remote_id' );
            $po = $parent->attribute( 'object' );
            if ( is_object( $po ) )
            {
                $keys['parent_object_remote_id'] = (string)$po->attribute( 'remote_id' );
                $pc = $po->contentClass();
                if ( is_object( $pc ) )
                {
                    $keys['parent_class'] = (int)$pc->attribute( 'id' );
                    $keys['parent_class_identifier'] = (string)$pc->attribute( 'identifier' );
                }
            }
        }
        return $keys;
    }

    public function root(): string
    {
        return rtrim( class_exists( 'expIniEditor' ) ? (string)expIniEditor::root() : (string)getcwd(), '/' ) . '/';
    }

    public function read( string $path ): ?string
    {
        $t = @file_get_contents( $this->root() . $path );
        return $t === false ? null : $t;
    }

    public function lint( string $path, ?string $text ): array
    {
        $file = $path;
        $temporary = null;
        if ( $text !== null )
        {
            $dir = $this->root() . 'var/tmp/tron-units';
            if ( !is_dir( $dir ) )
                @mkdir( $dir, 0775, true );
            $temporary = $dir . '/lint-' . md5( $text . microtime() ) . '.tpl';
            file_put_contents( $temporary, $text );
            $file = $temporary;
        }
        try
        {
            $tpl = eZTemplate::factory();
            $data = $tpl->validateTemplateFile( $file, true );
            if ( !is_array( $data ) )
                return array( 'errors' => array( 'the template cannot be read' ), 'warnings' => array(), 'functions' => array(), 'operators' => array(), 'unknown' => array() );
            $out = array( 'errors' => array(), 'warnings' => array() );
            foreach ( array( 'errors', 'warnings' ) as $kind )
            {
                foreach ( (array)( $data[$kind] ?? array() ) as $e )
                {
                    $msg = class_exists( 'expTplTemplateChecker' ) ? expTplTemplateChecker::message( (string)$e['text'] ) : trim( (string)$e['text'] );
                    $line = (int)( $e['placement']['start']['line'] ?? 0 );
                    if ( $temporary !== null )
                        $msg = str_replace( $temporary, '(source)', $msg );
                    $out[$kind][] = ( $line ? "line $line: " : '' ) . $msg;
                }
            }
            $functions = array();
            $operators = array();
            $walk = function ( $node ) use ( &$walk, &$functions, &$operators ) {
                if ( !is_array( $node ) )
                    return;
                if ( isset( $node[0], $node[2] ) && $node[0] === eZTemplate::NODE_FUNCTION && is_string( $node[2] ) )
                    $functions[$node[2]] = true;
                if ( isset( $node[0], $node[1] ) && $node[0] === eZTemplate::TYPE_OPERATOR && is_array( $node[1] ) && isset( $node[1][0] ) && is_string( $node[1][0] ) )
                    $operators[$node[1][0]] = true;
                foreach ( $node as $child )
                    $walk( $child );
            };
            $walk( $data['root-node'] ?? array() );
            $unknown = array();
            foreach ( array_keys( $functions ) as $f )
                if ( !isset( $tpl->Functions[$f] ) && !isset( $tpl->FunctionAttributes[$f] ) )
                    $unknown[] = $f;
            foreach ( array_keys( $operators ) as $o )
                if ( !isset( $tpl->Operators[$o] ) )
                    $unknown[] = $o;
            $tpl->resetErrorLog();
            $functions = array_keys( $functions );
            $operators = array_keys( $operators );
            sort( $functions );
            sort( $operators );
            sort( $unknown );
            return $out + array( 'functions' => $functions, 'operators' => $operators, 'unknown' => $unknown );
        }
        finally
        {
            if ( $temporary !== null )
                @unlink( $temporary );
        }
    }
}
