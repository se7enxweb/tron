<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Checks tool arguments against the tool's input schema: the JSON Schema subset every tron schema uses and nothing
 * more. Keywords: type (one or a list), enum, const, minimum, maximum, minLength, maxLength, pattern, properties,
 * required, additionalProperties (true/false), items, minItems, maxItems, default. An unknown keyword is ignored,
 * as JSON Schema says.
 *
 * Values arrive from json_decode( ..., true ), so a JSON object is a PHP array; an empty one ({} or []) is accepted
 * as either.
 */
final class tronSchemaValidator
{
    /** @return string[] one message per violation, empty when the value is valid */
    public static function validate( array $schema, $value, string $path = '' ): array
    {
        $errors = array();
        $where = $path === '' ? 'arguments' : $path;

        if ( isset( $schema['type'] ) )
        {
            $types = (array)$schema['type'];
            $ok = false;
            foreach ( $types as $t )
            {
                if ( self::isType( $value, (string)$t ) )
                {
                    $ok = true;
                    break;
                }
            }
            if ( !$ok )
                return array( $where . ': expected ' . implode( ' or ', $types ) . ', got ' . self::typeOf( $value ) );
        }

        if ( array_key_exists( 'const', $schema ) && $value !== $schema['const'] )
            $errors[] = $where . ': must be ' . json_encode( $schema['const'] );

        if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) && !in_array( $value, $schema['enum'], true ) )
            $errors[] = $where . ': must be one of ' . implode( ', ', array_map( 'json_encode', $schema['enum'] ) );

        if ( is_int( $value ) || is_float( $value ) )
        {
            if ( isset( $schema['minimum'] ) && $value < $schema['minimum'] )
                $errors[] = $where . ': must be at least ' . $schema['minimum'];
            if ( isset( $schema['maximum'] ) && $value > $schema['maximum'] )
                $errors[] = $where . ': must be at most ' . $schema['maximum'];
        }

        if ( is_string( $value ) )
        {
            // JSON text is UTF-8; a string that is not (possible from an in-process caller) is refused here, before a
            // database refuses it (found by the contract fuzzer on PostgreSQL, 0.0.0.3)
            if ( !preg_match( '//u', $value ) )
                return array( $where . ': is not valid UTF-8' );
            $len = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
            if ( isset( $schema['minLength'] ) && $len < $schema['minLength'] )
                $errors[] = $where . ': must have at least ' . $schema['minLength'] . ' characters';
            if ( isset( $schema['maxLength'] ) && $len > $schema['maxLength'] )
                $errors[] = $where . ': must have at most ' . $schema['maxLength'] . ' characters';
            if ( isset( $schema['pattern'] ) )
            {
                $re = '/' . str_replace( '/', '\\/', (string)$schema['pattern'] ) . '/u';
                $m = @preg_match( $re, $value );
                if ( $m !== 1 )
                    $errors[] = $where . ': does not match the pattern ' . $schema['pattern'];
            }
        }

        if ( is_array( $value ) && self::isList( $value ) && ( isset( $schema['items'] ) || isset( $schema['minItems'] ) || isset( $schema['maxItems'] ) ) )
        {
            if ( isset( $schema['minItems'] ) && count( $value ) < $schema['minItems'] )
                $errors[] = $where . ': must have at least ' . $schema['minItems'] . ' items';
            if ( isset( $schema['maxItems'] ) && count( $value ) > $schema['maxItems'] )
                $errors[] = $where . ': must have at most ' . $schema['maxItems'] . ' items';
            if ( isset( $schema['items'] ) && is_array( $schema['items'] ) )
            {
                foreach ( $value as $i => $item )
                    $errors = array_merge( $errors, self::validate( $schema['items'], $item, $where . '[' . $i . ']' ) );
            }
        }

        if ( is_array( $value ) && ( !self::isList( $value ) || $value === array() ) && ( isset( $schema['properties'] ) || isset( $schema['required'] ) || isset( $schema['additionalProperties'] ) ) )
        {
            $props = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
            foreach ( (array)( $schema['required'] ?? array() ) as $req )
            {
                if ( !array_key_exists( $req, $value ) )
                    $errors[] = ( $path === '' ? '' : $path . '.' ) . $req . ': is required';
            }
            foreach ( $value as $k => $v )
            {
                $sub = ( $path === '' ? '' : $path . '.' ) . $k;
                if ( isset( $props[$k] ) && is_array( $props[$k] ) )
                    $errors = array_merge( $errors, self::validate( $props[$k], $v, $sub ) );
                else if ( array_key_exists( 'additionalProperties', $schema ) && $schema['additionalProperties'] === false )
                    $errors[] = $sub . ': is not a known argument';
            }
        }

        return $errors;
    }

    /** Fills in the top-level defaults the schema declares for arguments the caller left out. */
    public static function applyDefaults( array $schema, array $args ): array
    {
        foreach ( (array)( $schema['properties'] ?? array() ) as $name => $prop )
        {
            if ( !array_key_exists( $name, $args ) && is_array( $prop ) && array_key_exists( 'default', $prop ) )
                $args[$name] = $prop['default'];
        }
        return $args;
    }

    public static function isList( array $a ): bool
    {
        if ( $a === array() )
            return true;
        return array_keys( $a ) === range( 0, count( $a ) - 1 );
    }

    private static function isType( $v, string $type ): bool
    {
        switch ( $type )
        {
            case 'string':  return is_string( $v );
            case 'integer': return is_int( $v ) || ( is_float( $v ) && floor( $v ) == $v && abs( $v ) < 9.0E15 );
            case 'number':  return is_int( $v ) || is_float( $v );
            case 'boolean': return is_bool( $v );
            case 'null':    return $v === null;
            case 'array':   return is_array( $v ) && self::isList( $v );
            case 'object':  return is_array( $v ) && ( $v === array() || !self::isList( $v ) );
        }
        return false;
    }

    private static function typeOf( $v ): string
    {
        if ( is_array( $v ) )
            return self::isList( $v ) && $v !== array() ? 'array' : 'object';
        if ( is_int( $v ) )
            return 'integer';
        if ( is_float( $v ) )
            return 'number';
        if ( is_bool( $v ) )
            return 'boolean';
        if ( $v === null )
            return 'null';
        return 'string';
    }
}
