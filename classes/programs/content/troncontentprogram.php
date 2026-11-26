<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The program "content": content_node_update, a write that publishes a new version of a node's object with some
 * simple attributes changed (text line, text block, integer, float, checkbox), as the session's user.
 *
 * Gates: the policy content/edit (limited is fine), then the kernel's own object check for that node and user. Its
 * dry run (tron_plan) is a computed plan (doc/dry-run.md): the exact attribute changes from the current version, the
 * version number the publish will make, and the effects a publish has outside the tables (name, URL alias, caches,
 * search index); the apply refuses when the object changed in between.
 *
 * The source is the environment service "content.source" (a tronContentSource), else the kernel's when the kernel
 * is there.
 */
final class tronContentProgram implements tronProgram
{
    const EDIT = array( 'content', 'edit' );

    public function register( tronRegistry $registry, tronEnvironment $env ): void
    {
        $source = $env->has( 'content.source' ) ? $env->get( 'content.source' ) : null;
        if ( $source === null && class_exists( 'eZContentObjectTreeNode' ) )
            $source = new tronContentKernelSource();
        if ( !$source instanceof tronContentSource || !$source->available() )
        {
            $registry->unavailable( 'content_node_update', $source instanceof tronContentSource ? $source->reason() : 'needs the Exponential kernel or a content source' );
            return;
        }

        $nodeSchema = array( 'type' => 'object', 'required' => array( 'node_id', 'object_id', 'name', 'class', 'current_version', 'attributes' ), 'properties' => array(
            'node_id' => array( 'type' => 'integer' ), 'object_id' => array( 'type' => 'integer' ), 'name' => array( 'type' => 'string' ),
            'class' => array( 'type' => 'string' ), 'language' => array( 'type' => 'string' ), 'current_version' => array( 'type' => 'integer' ),
            'modified' => array( 'type' => array( 'string', 'null' ) ),
            'attributes' => array( 'type' => 'object' ),
        ) );

        $registry->tool( tronTool::define( array(
            'name' => 'content_node_update', 'title' => 'Update a content node',
            'description' => 'Publishes a new version of a node\'s content object with the given attributes changed: text lines, text blocks, integers, floats and checkboxes (1/0), by attribute identifier. Other attribute types are refused. Make a dry run first with tron_plan to see the exact change (from/to per attribute, the new version number) without changing anything, then apply it with tron_apply.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'node_id', 'attributes' ), 'properties' => array(
                'node_id' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 2147483647, 'description' => 'The node (location) id.' ),
                'attributes' => array( 'type' => 'object', 'minProperties' => 1, 'description' => 'identifier => new value, e.g. {"short_title": "News"}.' ),
            ) ),
            'policy' => self::EDIT, 'limitedOk' => true, 'write' => true, 'idempotent' => false,
            'outputSchema' => array( 'type' => 'object', 'required' => array( 'node', 'version', 'changed' ), 'properties' => array(
                'node' => $nodeSchema, 'version' => array( 'type' => 'integer' ),
                'changed' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'required' => array( 'attribute', 'from', 'to' ) ) ),
            ) ),
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source ) {
                $p = self::plan( $source, $a, $ctx->principal->userId );
                $after = $source->update( (int)$a['node_id'], $p['values'], $ctx->principal->userId );
                $changed = array();
                foreach ( $p['changes'] as $c )
                    if ( $c['kind'] === 'attribute' )
                        $changed[] = array( 'attribute' => $c['field'], 'from' => $c['from'], 'to' => $after['attributes'][$c['field']]['value'] ?? null );
                return array( 'node' => self::shape( $after ), 'version' => (int)$after['current_version'], 'changed' => $changed );
            },
            'plan' => function ( array $a, tronCallContext $ctx ) use ( $source ) {
                $p = self::plan( $source, $a, $ctx->principal->userId );
                unset( $p['values'] );
                return $p;
            },
            'targetCheck' => function ( string $target, tronPrincipal $p, tronEnvironment $env ) use ( $source ) {
                if ( !ctype_digit( $target ) || !$source->node( (int)$target ) )
                    return array( 'allowed' => false, 'reason' => "there is no node $target" );
                if ( !$source->canEdit( (int)$target, $p->userId ) )
                    return array( 'allowed' => false, 'reason' => "the object check of node $target refuses edit for this user (its class, section, subtree or owner limitations)" );
                return array( 'allowed' => true, 'reason' => "the object check of node $target allows edit for this user" );
            },
        ) ) );
    }

    /**
     * What an update of these attributes would change, from the node as it is now: one change per attribute whose
     * value differs (from/to in the datatype's string form), the version the publish makes, its effects, and the state
     * the plan depends on (object, versions, modified and every editable value). Refusals are tool errors.
     */
    public static function plan( tronContentSource $source, array $a, int $userId ): array
    {
        $nodeId = (int)$a['node_id'];
        $node = $source->node( $nodeId );
        // a node the user may not read answers like a missing one
        if ( !$node || !$source->canRead( $nodeId, $userId ) )
            throw new tronToolError( "There is no node $nodeId" );
        if ( !$source->canEdit( $nodeId, $userId ) )
            throw new tronToolError( "Permission denied: you may not edit node $nodeId (the kernel's object check refuses it)" );
        $values = array();
        $changes = array();
        foreach ( (array)$a['attributes'] as $identifier => $value )
        {
            $identifier = (string)$identifier;
            if ( !isset( $node['attributes'][$identifier] ) )
                throw new tronToolError( "The class {$node['class']} has no attribute '$identifier'",
                                         array( 'attributes' => array_keys( array_filter( $node['attributes'], function ( $x ) { return $x['editable']; } ) ) ) );
            $attr = $node['attributes'][$identifier];
            if ( !$attr['editable'] )
                throw new tronToolError( "The attribute '$identifier' is of the type {$attr['type']}, which this tool does not change" );
            $new = self::normalise( $attr['type'], $value, $identifier );
            $values[$identifier] = $new;
            if ( $new !== (string)$attr['value'] )
                $changes[] = array( 'kind' => 'attribute', 'target' => 'object ' . $node['object_id'], 'field' => $identifier,
                                    'type' => $attr['type'], 'from' => (string)$attr['value'], 'to' => $new );
        }
        if ( !$changes )
            throw new tronToolError( 'Nothing to change: every given value equals the current one' );
        ksort( $values );
        $changes[] = array( 'kind' => 'version', 'target' => 'object ' . $node['object_id'], 'field' => 'current_version',
                            'from' => (int)$node['current_version'], 'to' => (int)$node['next_version'] );
        $state = array( 'object_id' => $node['object_id'], 'current_version' => $node['current_version'],
                        'next_version' => $node['next_version'], 'modified' => $node['modified'], 'values' => array() );
        foreach ( $node['attributes'] as $k => $x )
            if ( $x['editable'] )
                $state['values'][$k] = $x['value'];
        return array( 'changes' => $changes, 'values' => $values, 'state' => $state, 'effects' => array(
            'a new version ' . $node['next_version'] . ' of object ' . $node['object_id'] . ' is created and published in ' . ( $node['language'] ?: 'its main language' ),
            'the object name is computed again from the class name pattern, and the URL alias may follow it',
            'the view caches of the node and its relations are cleared, and the search index is updated',
        ) );
    }

    private static function normalise( string $type, $value, string $identifier ): string
    {
        if ( is_array( $value ) || is_object( $value ) || $value === null )
            throw new tronToolError( "The value of '$identifier' must be a string, a number or a boolean" );
        switch ( $type )
        {
            case 'ezboolean':
                if ( is_bool( $value ) )
                    return $value ? '1' : '0';
                if ( in_array( (string)$value, array( '0', '1' ), true ) )
                    return (string)$value;
                throw new tronToolError( "'$identifier' is a checkbox: give true, false, 1 or 0" );
            case 'ezinteger':
                if ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^-?\d{1,18}$/', $value ) ) )
                    return (string)(int)$value;
                throw new tronToolError( "'$identifier' is an integer" );
            case 'ezfloat':
                if ( is_int( $value ) || is_float( $value ) || ( is_string( $value ) && is_numeric( $value ) ) )
                {
                    $f = (float)$value;
                    if ( !is_finite( $f ) )
                        throw new tronToolError( "'$identifier' is a finite number" );
                    return (string)$f;
                }
                throw new tronToolError( "'$identifier' is a number" );
        }
        $s = is_bool( $value ) ? ( $value ? '1' : '0' ) : (string)$value;
        if ( $type === 'ezstring' && ( strpos( $s, "\n" ) !== false || ( function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s ) ) > 255 ) )
            throw new tronToolError( "'$identifier' is a text line: one line, at most 255 characters" );
        if ( strlen( $s ) > 65000 )
            throw new tronToolError( "'$identifier' is longer than 65000 bytes" );
        if ( function_exists( 'mb_check_encoding' ) && !mb_check_encoding( $s, 'UTF-8' ) )
            throw new tronToolError( "'$identifier' is not valid UTF-8" );
        return $s;
    }

    private static function shape( array $n ): array
    {
        $attrs = array();
        foreach ( $n['attributes'] as $k => $x )
            if ( $x['editable'] )
                $attrs[$k] = $x['value'];
        return array( 'node_id' => (int)$n['node_id'], 'object_id' => (int)$n['object_id'], 'name' => (string)$n['name'],
                      'class' => (string)$n['class'], 'language' => (string)$n['language'], 'current_version' => (int)$n['current_version'],
                      'modified' => $n['modified'] > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', (int)$n['modified'] ) : null,
                      'attributes' => (object)$attrs );
    }
}
