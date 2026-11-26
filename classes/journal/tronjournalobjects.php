<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Which objects a step touched, read from its arguments and its answer by convention, so no tool has to declare
 * anything: node_id 123 is node:123, object_id is object:, layout_id layout:, an INI file with a group and a key is
 * ini:site.ini[Group]Key, a path is file:, and any other <thing>_id with a number is <thing>:<n>. A handler that knows
 * better attaches its own list (tronJournalHandle::objects()).
 *
 * Also the result summary: one line that says what came back without repeating it (list sizes, ids, the first words of
 * a text), redacted like the arguments.
 */
final class tronJournalObjects
{
    const MAX = 25;
    /** What an answer may name as the call's object */
    const RESULT_KINDS = array( 'node', 'object', 'layout', 'ticket', 'plan', 'apply', 'job' );

    /** Argument and answer keys and the kind they name. */
    const KEYS = array(
        'node_id' => 'node', 'node' => 'node', 'node_ids' => 'node', 'location_id' => 'node', 'parent_node_id' => 'node',
        'main_node_id' => 'node', 'object_id' => 'object', 'contentobject_id' => 'object', 'content_id' => 'object',
        'object_ids' => 'object', 'layout_id' => 'layout', 'layout_ids' => 'layout', 'block_id' => 'block', 'zone' => 'zone',
        'rule_id' => 'rule', 'ticket_id' => 'ticket', 'ticket' => 'ticket', 'class_id' => 'class', 'class_identifier' => 'class',
        'user_id' => 'user', 'plan_key' => 'plan', 'apply_id' => 'apply', 'path' => 'file', 'file' => 'file', 'filename' => 'file',
        'siteaccess' => 'siteaccess', 'url' => 'url', 'job_id' => 'job', 'token_id' => null, 'session_id' => null,
    );

    /**
     * @param string $action read or write
     * @return array[] list of array( kind, ref, action ), unique, at most MAX
     */
    public static function from( array $args, $value, string $action, string $tool = '' ): array
    {
        $out = array();
        // A bare "id" belongs to what the tool is named after: centre_ticket_get id 5 is ticket:5, node_move id 2 is node:2
        if ( preg_match( '/^[a-z0-9]+_([a-z]+)(_[a-z_]+)?$/', $tool, $m ) && !in_array( $m[1], array( 'journal', 'status', 'whoami' ), true ) )
        {
            if ( isset( $args['id'] ) && is_scalar( $args['id'] ) && self::useful( $m[1], $args['id'] ) )
                self::add( $out, $m[1], (string)$args['id'], $action );
            if ( is_array( $value ) && isset( $value['id'] ) && is_scalar( $value['id'] ) && self::useful( $m[1], $value['id'] ) )
                self::add( $out, $m[1], (string)$value['id'], $action );
        }
        self::walk( $args, $out, $action, 0 );
        // From an answer only its own top-level node, object, layout, ticket or plan: what it is about, not every id it
        // lists (a resolver's candidate layouts, a node's class and section). tron's own tools answer about the session.
        if ( is_array( $value ) && !tronSchemaValidator::isList( $value ) && strpos( $tool, 'tron_' ) !== 0 )
        {
            $n = 0;
            foreach ( $value as $k => $v )
            {
                $kind = is_string( $k ) ? ( self::KEYS[strtolower( $k )] ?? null ) : null;
                if ( $kind !== null && in_array( $kind, self::RESULT_KINDS, true ) && is_scalar( $v ) && self::useful( $kind, $v ) && $n++ < 5 )
                    self::add( $out, $kind, (string)$v, $action === 'write' ? 'write' : 'read' );
            }
        }
        self::ini( $args, $out, $action, $tool );
        return array_values( array_slice( $out, 0, self::MAX ) );
    }

    /** Normalises a caller-given list: "node:12", array( 'node', 12 ) or array( 'kind' => .., 'ref' => .. ). */
    public static function normalise( array $list, string $action ): array
    {
        $out = array();
        foreach ( $list as $o )
        {
            if ( is_string( $o ) && strpos( $o, ':' ) !== false )
            {
                list( $k, $r ) = explode( ':', $o, 2 );
                self::add( $out, $k, $r, $action );
            }
            else if ( is_array( $o ) && isset( $o['kind'], $o['ref'] ) )
                self::add( $out, (string)$o['kind'], (string)$o['ref'], (string)( $o['action'] ?? $action ) );
            else if ( is_array( $o ) && isset( $o[0], $o[1] ) )
                self::add( $out, (string)$o[0], (string)$o[1], $action );
        }
        return array_values( $out );
    }

    /** "node:123" (or "node 123") as array( kind, ref ), or null. */
    public static function parse( string $s ): ?array
    {
        if ( !preg_match( '/^\s*([a-z][a-z0-9_]{0,31})\s*[: ]\s*(.{1,255}?)\s*$/i', $s, $m ) )
            return null;
        return array( strtolower( $m[1] ), $m[2] );
    }

    private static function walk( array $a, array &$out, string $action, int $depth, string $resultAction = '' ): void
    {
        if ( $depth > 2 || count( $out ) >= self::MAX )
            return;
        foreach ( $a as $k => $v )
        {
            if ( is_string( $k ) )
            {
                $key = strtolower( $k );
                $kind = array_key_exists( $key, self::KEYS ) ? self::KEYS[$key] : ( preg_match( '/^([a-z][a-z0-9]{1,30})_id$/', $key, $m ) ? $m[1] : null );
                if ( $kind !== null )
                {
                    $act = $resultAction !== '' ? $resultAction : $action;
                    if ( is_scalar( $v ) && self::useful( $kind, $v ) )
                        self::add( $out, $kind, (string)$v, $act );
                    else if ( is_array( $v ) && tronSchemaValidator::isList( $v ) )
                    {
                        foreach ( array_slice( $v, 0, 10 ) as $one )
                            if ( is_scalar( $one ) && self::useful( $kind, $one ) )
                                self::add( $out, $kind, (string)$one, $act );
                    }
                    continue;
                }
                // "id" in a record: the record's kind comes from its key ("ticket" => array( "id" => 5 ))
                if ( is_array( $v ) && !tronSchemaValidator::isList( $v ) && isset( $v['id'] ) && is_scalar( $v['id'] ) && isset( self::KEYS[$key . '_id'] ) )
                    self::add( $out, self::KEYS[$key . '_id'], (string)$v['id'], $resultAction !== '' ? $resultAction : $action );
            }
            if ( is_array( $v ) && $depth < 2 )
                self::walk( tronSchemaValidator::isList( $v ) ? array_slice( $v, 0, 10 ) : $v, $out, $action, $depth + 1, $resultAction );
        }
    }

    /** An INI file named with a group (and a key) becomes one object: ini:site.ini[Group]Key. */
    private static function ini( array $args, array &$out, string $action, string $tool = '' ): void
    {
        $file = null;
        foreach ( array( 'ini', 'ini_file', 'file', 'filename' ) as $k )
        {
            if ( isset( $args[$k] ) && is_string( $args[$k] ) && preg_match( '/\.ini(\.append(\.php)?)?$/', $args[$k] ) )
            {
                $file = $args[$k];
                break;
            }
            // an INI tool names the file without its extension (ini_resolve file "site")
            if ( strpos( $tool, 'ini_' ) === 0 && $k === 'file' && isset( $args[$k] ) && is_string( $args[$k] ) && preg_match( '/^[a-z0-9_.-]{1,80}$/', $args[$k] ) )
            {
                $file = preg_match( '/\.ini$/', $args[$k] ) ? $args[$k] : $args[$k] . '.ini';
                break;
            }
        }
        if ( $file === null )
            return;
        $group = null;
        foreach ( array( 'group', 'block', 'section' ) as $k )
            if ( isset( $args[$k] ) && is_string( $args[$k] ) )
                $group = $args[$k];
        $key = null;
        foreach ( array( 'key', 'setting', 'name' ) as $k )
            if ( isset( $args[$k] ) && is_string( $args[$k] ) )
                $key = $args[$k];
        foreach ( array( 'ini', 'ini_file', 'file', 'filename' ) as $k )
            if ( isset( $args[$k] ) && is_string( $args[$k] ) )
                unset( $out['file:' . $args[$k]] );
        self::add( $out, 'ini', $file . ( $group !== null ? '[' . $group . ']' . ( $key ?? '' ) : '' ), $action );
    }

    private static function useful( string $kind, $v ): bool
    {
        if ( is_bool( $v ) || $v === null || $v === '' )
            return false;
        if ( in_array( $kind, array( 'file', 'url', 'siteaccess', 'class', 'zone', 'plan', 'apply' ), true ) )
            return is_string( $v ) && strlen( $v ) <= 255;
        return ( is_int( $v ) && $v > 0 ) || ( is_string( $v ) && preg_match( '/^[A-Za-z0-9_.:-]{1,64}$/', $v ) );
    }

    private static function add( array &$out, string $kind, string $ref, string $action ): void
    {
        $kind = strtolower( $kind );
        $ref = (string)tronRedactor::redact( $ref );
        if ( !preg_match( '/^[a-z][a-z0-9_]{0,31}$/', $kind ) || $ref === '' || count( $out ) >= self::MAX )
            return;
        $id = $kind . ':' . $ref;
        if ( isset( $out[$id] ) )
        {
            if ( $action === 'write' )
                $out[$id]['action'] = 'write';
            return;
        }
        $out[$id] = array( 'kind' => $kind, 'ref' => $ref, 'action' => $action === 'write' ? 'write' : 'read' );
    }

    /** One line about an answer: "3 items", "ticket 5", "text, 120 chars: ...". Redacted. */
    public static function summary( $value, int $max = 300 ): string
    {
        if ( is_object( $value ) )
            $value = tronJournalHandle::plain( $value );
        if ( $value === null )
            return '';
        if ( is_string( $value ) )
            $s = 'text, ' . strlen( $value ) . ' chars: ' . preg_replace( '/\s+/', ' ', $value );
        else if ( is_scalar( $value ) )
            $s = var_export( $value, true );
        else if ( is_array( $value ) && tronSchemaValidator::isList( $value ) )
            $s = count( $value ) . ' item' . ( count( $value ) === 1 ? '' : 's' );
        else if ( is_array( $value ) )
        {
            $value = tronRedactor::redact( $value );       // values under secret-looking keys go before anything is shown
            $parts = array();
            foreach ( $value as $k => $v )
            {
                if ( count( $parts ) >= 8 )
                {
                    $parts[] = '…';
                    break;
                }
                if ( is_array( $v ) )
                    $parts[] = $k . ': ' . ( tronSchemaValidator::isList( $v ) ? count( $v ) . ' item' . ( count( $v ) === 1 ? '' : 's' ) : '{' . implode( ', ', array_slice( array_map( 'strval', array_keys( $v ) ), 0, 4 ) ) . ( count( $v ) > 4 ? ', …' : '' ) . '}' );
                else if ( is_string( $v ) && strlen( $v ) > 60 )
                    $parts[] = $k . ': ' . strlen( $v ) . ' chars';
                else if ( is_object( $v ) )
                    $parts[] = $k . ': {…}';
                else
                    $parts[] = $k . ': ' . ( is_bool( $v ) ? ( $v ? 'true' : 'false' ) : ( $v === null ? 'null' : (string)$v ) );
            }
            $s = implode( ', ', $parts );
        }
        else
            $s = gettype( $value );
        $s = (string)tronRedactor::redact( $s );
        if ( strlen( $s ) > $max )
            $s = ( function_exists( 'mb_strcut' ) ? mb_strcut( $s, 0, $max - 1, 'UTF-8' ) : substr( $s, 0, $max - 1 ) ) . '…';
        return $s;
    }
}
