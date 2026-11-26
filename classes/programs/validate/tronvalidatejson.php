<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * JSON syntax with the position of the first error (a small scanner, since json_decode names no position), and
 * JSON Schema (draft 2020-12 and draft-07 keywords): type, enum, const, numbers (minimum, maximum, exclusive*,
 * multipleOf), strings (minLength, maxLength, pattern, format: email, uri, date, date-time, ipv4, ipv6, uuid),
 * arrays (items, prefixItems, minItems, maxItems, uniqueItems, contains), objects (properties, required,
 * additionalProperties, patternProperties, propertyNames, minProperties, maxProperties, dependentRequired), allOf,
 * anyOf, oneOf, not, if/then/else, and local $ref (#, #/$defs/..., #/definitions/...). Remote $ref is refused.
 */
final class tronValidateJson
{
    /** null when valid, else array( line, column, message ) */
    public static function syntax( string $s ): ?array
    {
        $i = 0;
        $n = strlen( $s );
        $err = null;
        $pos = function ( int $at ) use ( $s ) {
            $before = substr( $s, 0, $at );
            $line = substr_count( $before, "\n" ) + 1;
            $col = $at - ( strrpos( $before, "\n" ) === false ? -1 : strrpos( $before, "\n" ) );
            return array( $line, $col );
        };
        $ws = function () use ( $s, $n, &$i ) {
            while ( $i < $n && strpos( " \t\r\n", $s[$i] ) !== false )
                $i++;
        };
        $fail = function ( string $msg ) use ( &$err, &$i, $pos ) {
            if ( $err === null )
            {
                list( $l, $c ) = $pos( $i );
                $err = array( $l, $c, $msg );
            }
            return false;
        };
        $value = null;
        $value = function ( int $depth ) use ( &$value, $s, $n, &$i, $ws, $fail ) {
            if ( $depth > 512 )
                return $fail( 'Nested too deeply' );
            $ws();
            if ( $i >= $n )
                return $fail( 'Unexpected end of the text' );
            $c = $s[$i];
            if ( $c === '{' || $c === '[' )
            {
                $close = $c === '{' ? '}' : ']';
                $i++;
                $ws();
                if ( ( $s[$i] ?? '' ) === $close )
                {
                    $i++;
                    return true;
                }
                while ( true )
                {
                    if ( $c === '{' )
                    {
                        $ws();
                        if ( ( $s[$i] ?? '' ) !== '"' )
                            return $fail( 'Expected a quoted property name' );
                        if ( !$value( $depth + 1 ) )
                            return false;
                        $ws();
                        if ( ( $s[$i] ?? '' ) !== ':' )
                            return $fail( 'Expected ":"' );
                        $i++;
                    }
                    if ( !$value( $depth + 1 ) )
                        return false;
                    $ws();
                    $d = $s[$i] ?? '';
                    if ( $d === ',' )
                    {
                        $i++;
                        $ws();
                        if ( ( $s[$i] ?? '' ) === $close )
                            return $fail( 'A trailing comma' );
                        continue;
                    }
                    if ( $d === $close )
                    {
                        $i++;
                        return true;
                    }
                    return $fail( "Expected ',' or '$close'" );
                }
            }
            if ( $c === '"' )
            {
                $i++;
                while ( $i < $n )
                {
                    $d = $s[$i];
                    if ( $d === '"' )
                    {
                        $i++;
                        return true;
                    }
                    if ( ord( $d ) < 0x20 )
                        return $fail( 'A control character inside a string (escape it)' );
                    if ( $d === '\\' )
                    {
                        $e = $s[$i + 1] ?? '';
                        if ( $e === 'u' )
                        {
                            if ( !preg_match( '/^[0-9a-fA-F]{4}$/', substr( $s, $i + 2, 4 ) ) )
                                return $fail( 'A bad \\u escape' );
                            $i += 6;
                            continue;
                        }
                        if ( strpos( '"\\/bfnrt', $e ) === false || $e === '' )
                            return $fail( 'A bad escape' );
                        $i += 2;
                        continue;
                    }
                    $i++;
                }
                return $fail( 'A string is not closed' );
            }
            if ( preg_match( '/\G-?(0|[1-9][0-9]*)(\.[0-9]+)?([eE][-+]?[0-9]+)?/', $s, $m, 0, $i ) && $m[0] !== '' && $m[0] !== '-' )
            {
                $i += strlen( $m[0] );
                return true;
            }
            foreach ( array( 'true', 'false', 'null' ) as $lit )
                if ( substr( $s, $i, strlen( $lit ) ) === $lit )
                {
                    $i += strlen( $lit );
                    return true;
                }
            if ( $c === "'" )
                return $fail( 'Strings need double quotes' );
            return $fail( 'Unexpected character ' . json_encode( $c ) );
        };
        if ( $value( 0 ) )
        {
            $ws();
            if ( $i < $n )
                $fail( 'Text after the JSON value' );
        }
        return $err;
    }

    /** @return array[] findings of a schema check */
    public static function schema( $data, $schema ): array
    {
        $out = array();
        self::check( $data, $schema, '$', $schema, $out, 0 );
        return $out;
    }

    private static function add( array &$out, string $path, string $rule, string $msg ): void
    {
        $out[] = array( 'severity' => 'error', 'rule' => 'schema.' . $rule, 'message' => $msg, 'location' => array( 'line' => null, 'column' => null, 'path' => $path ) );
    }

    private static function type( $v ): string
    {
        if ( is_array( $v ) )
            return $v === array() || array_keys( $v ) === range( 0, count( $v ) - 1 ) ? 'array' : 'object';
        if ( $v instanceof stdClass )
            return 'object';
        if ( is_int( $v ) )
            return 'integer';
        if ( is_float( $v ) )
            return floor( $v ) == $v && abs( $v ) < 9.0E15 ? 'integer' : 'number';
        return array( 'boolean' => 'boolean', 'string' => 'string', 'NULL' => 'null' )[gettype( $v )] ?? 'unknown';
    }

    private static function is( $v, string $t ): bool
    {
        $have = self::type( $v );
        return $have === $t || $t === 'number' && $have === 'integer' || $t === 'object' && $v === array();
    }

    private static function check( $v, $s, string $path, $root, array &$out, int $depth ): void
    {
        if ( $depth > 64 )
        {
            self::add( $out, $path, 'depth', 'The schema nests too deeply (a $ref loop?)' );
            return;
        }
        if ( $s === true || $s === array() )
            return;
        if ( $s === false )
        {
            self::add( $out, $path, 'false', 'No value is allowed here' );
            return;
        }
        if ( !is_array( $s ) )
            return;
        if ( isset( $s['$ref'] ) )
        {
            $target = self::ref( (string)$s['$ref'], $root );
            if ( $target === null )
                self::add( $out, $path, 'ref', 'The reference ' . $s['$ref'] . ' cannot be resolved (only local references, #/...)' );
            else
                self::check( $v, $target, $path, $root, $out, $depth + 1 );
        }
        if ( isset( $s['type'] ) )
        {
            $ok = false;
            foreach ( (array)$s['type'] as $t )
                if ( self::is( $v, (string)$t ) )
                    $ok = true;
            if ( !$ok )
            {
                self::add( $out, $path, 'type', 'Expected ' . implode( ' or ', (array)$s['type'] ) . ', got ' . self::type( $v ) );
                return;
            }
        }
        if ( isset( $s['enum'] ) && is_array( $s['enum'] ) && !in_array( $v, $s['enum'], true ) && !in_array( json_encode( $v ), array_map( 'json_encode', $s['enum'] ), true ) )
            self::add( $out, $path, 'enum', 'Must be one of ' . mb_substr( implode( ', ', array_map( 'json_encode', $s['enum'] ) ), 0, 200 ) );
        if ( array_key_exists( 'const', $s ) && json_encode( $v ) !== json_encode( $s['const'] ) )
            self::add( $out, $path, 'const', 'Must be ' . mb_substr( (string)json_encode( $s['const'] ), 0, 200 ) );
        if ( is_int( $v ) || is_float( $v ) )
        {
            if ( isset( $s['minimum'] ) && $v < $s['minimum'] )
                self::add( $out, $path, 'minimum', "Must be at least {$s['minimum']}" );
            if ( isset( $s['maximum'] ) && $v > $s['maximum'] )
                self::add( $out, $path, 'maximum', "Must be at most {$s['maximum']}" );
            if ( isset( $s['exclusiveMinimum'] ) && is_numeric( $s['exclusiveMinimum'] ) && $v <= $s['exclusiveMinimum'] )
                self::add( $out, $path, 'exclusiveMinimum', "Must be more than {$s['exclusiveMinimum']}" );
            if ( isset( $s['exclusiveMaximum'] ) && is_numeric( $s['exclusiveMaximum'] ) && $v >= $s['exclusiveMaximum'] )
                self::add( $out, $path, 'exclusiveMaximum', "Must be less than {$s['exclusiveMaximum']}" );
            if ( isset( $s['multipleOf'] ) && $s['multipleOf'] > 0 && abs( fmod( $v, $s['multipleOf'] ) ) > 1e-9 && abs( fmod( $v, $s['multipleOf'] ) - $s['multipleOf'] ) > 1e-9 )
                self::add( $out, $path, 'multipleOf', "Must be a multiple of {$s['multipleOf']}" );
        }
        if ( is_string( $v ) )
        {
            $len = mb_strlen( $v, 'UTF-8' );
            if ( isset( $s['minLength'] ) && $len < $s['minLength'] )
                self::add( $out, $path, 'minLength', "Must have at least {$s['minLength']} characters" );
            if ( isset( $s['maxLength'] ) && $len > $s['maxLength'] )
                self::add( $out, $path, 'maxLength', "Must have at most {$s['maxLength']} characters" );
            if ( isset( $s['pattern'] ) )
            {
                $re = '/' . str_replace( '/', '\\/', (string)$s['pattern'] ) . '/u';
                $ok = @preg_match( $re, $v );
                if ( $ok === false )
                    self::add( $out, $path, 'pattern', 'The schema\'s pattern is not a valid regular expression' );
                else if ( !$ok )
                    self::add( $out, $path, 'pattern', 'Does not match the pattern ' . mb_substr( (string)$s['pattern'], 0, 120 ) );
            }
            if ( isset( $s['format'] ) && !self::format( (string)$s['format'], $v ) )
                self::add( $out, $path, 'format', "Not a valid {$s['format']}" );
        }
        $t = self::type( $v );
        if ( $t === 'array' && is_array( $v ) )
        {
            $n = count( $v );
            if ( isset( $s['minItems'] ) && $n < $s['minItems'] )
                self::add( $out, $path, 'minItems', "Must have at least {$s['minItems']} items" );
            if ( isset( $s['maxItems'] ) && $n > $s['maxItems'] )
                self::add( $out, $path, 'maxItems', "Must have at most {$s['maxItems']} items" );
            if ( !empty( $s['uniqueItems'] ) && count( array_unique( array_map( 'json_encode', $v ) ) ) !== $n )
                self::add( $out, $path, 'uniqueItems', 'The items must be unique' );
            $prefix = isset( $s['prefixItems'] ) && is_array( $s['prefixItems'] ) ? $s['prefixItems'] : ( isset( $s['items'] ) && is_array( $s['items'] ) && array_keys( $s['items'] ) === range( 0, count( $s['items'] ) - 1 ) && $s['items'] !== array() ? $s['items'] : array() );
            foreach ( $v as $i => $item )
            {
                if ( isset( $prefix[$i] ) )
                    self::check( $item, $prefix[$i], $path . '[' . $i . ']', $root, $out, $depth + 1 );
                else if ( isset( $s['items'] ) && ( $prefix === array() || isset( $s['prefixItems'] ) ) )
                    self::check( $item, $s['items'], $path . '[' . $i . ']', $root, $out, $depth + 1 );
            }
            if ( isset( $s['contains'] ) )
            {
                $found = false;
                foreach ( $v as $item )
                {
                    $sub = array();
                    self::check( $item, $s['contains'], $path, $root, $sub, $depth + 1 );
                    if ( !$sub )
                        $found = true;
                }
                if ( !$found )
                    self::add( $out, $path, 'contains', 'No item matches "contains"' );
            }
        }
        if ( $t === 'object' && is_array( $v ) )
        {
            $n = count( $v );
            foreach ( (array)( $s['required'] ?? array() ) as $req )
                if ( !array_key_exists( (string)$req, $v ) )
                    self::add( $out, $path, 'required', "The property '$req' is required" );
            if ( isset( $s['minProperties'] ) && $n < $s['minProperties'] )
                self::add( $out, $path, 'minProperties', "Must have at least {$s['minProperties']} properties" );
            if ( isset( $s['maxProperties'] ) && $n > $s['maxProperties'] )
                self::add( $out, $path, 'maxProperties', "Must have at most {$s['maxProperties']} properties" );
            foreach ( (array)( $s['dependentRequired'] ?? array() ) as $k => $deps )
                if ( array_key_exists( $k, $v ) )
                    foreach ( (array)$deps as $d )
                        if ( !array_key_exists( $d, $v ) )
                            self::add( $out, $path, 'dependentRequired', "'$k' needs '$d' too" );
            $props = is_array( $s['properties'] ?? null ) ? $s['properties'] : array();
            $patterns = is_array( $s['patternProperties'] ?? null ) ? $s['patternProperties'] : array();
            foreach ( $v as $k => $item )
            {
                $k = (string)$k;
                $p = $path . ( preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $k ) ? '.' . $k : '[' . json_encode( $k ) . ']' );
                $matched = false;
                if ( array_key_exists( $k, $props ) )
                {
                    $matched = true;
                    self::check( $item, $props[$k], $p, $root, $out, $depth + 1 );
                }
                foreach ( $patterns as $re => $sub )
                    if ( @preg_match( '/' . str_replace( '/', '\\/', (string)$re ) . '/u', $k ) )
                    {
                        $matched = true;
                        self::check( $item, $sub, $p, $root, $out, $depth + 1 );
                    }
                if ( !$matched && array_key_exists( 'additionalProperties', $s ) )
                {
                    if ( $s['additionalProperties'] === false )
                        self::add( $out, $p, 'additionalProperties', "The property '$k' is not allowed" );
                    else if ( is_array( $s['additionalProperties'] ) )
                        self::check( $item, $s['additionalProperties'], $p, $root, $out, $depth + 1 );
                }
                if ( isset( $s['propertyNames'] ) )
                    self::check( $k, $s['propertyNames'], $p, $root, $out, $depth + 1 );
            }
        }
        foreach ( (array)( $s['allOf'] ?? array() ) as $sub )
            self::check( $v, $sub, $path, $root, $out, $depth + 1 );
        foreach ( array( 'anyOf', 'oneOf' ) as $kw )
            if ( isset( $s[$kw] ) && is_array( $s[$kw] ) )
            {
                $passing = 0;
                foreach ( $s[$kw] as $sub )
                {
                    $tmp = array();
                    self::check( $v, $sub, $path, $root, $tmp, $depth + 1 );
                    if ( !$tmp )
                        $passing++;
                }
                if ( $kw === 'anyOf' && $passing === 0 )
                    self::add( $out, $path, 'anyOf', 'Matches none of the "anyOf" schemas' );
                if ( $kw === 'oneOf' && $passing !== 1 )
                    self::add( $out, $path, 'oneOf', "Must match exactly one \"oneOf\" schema, matches $passing" );
            }
        if ( isset( $s['not'] ) )
        {
            $tmp = array();
            self::check( $v, $s['not'], $path, $root, $tmp, $depth + 1 );
            if ( !$tmp )
                self::add( $out, $path, 'not', 'Must not match the "not" schema' );
        }
        if ( isset( $s['if'] ) )
        {
            $tmp = array();
            self::check( $v, $s['if'], $path, $root, $tmp, $depth + 1 );
            if ( !$tmp && isset( $s['then'] ) )
                self::check( $v, $s['then'], $path, $root, $out, $depth + 1 );
            if ( $tmp && isset( $s['else'] ) )
                self::check( $v, $s['else'], $path, $root, $out, $depth + 1 );
        }
    }

    private static function ref( string $ref, $root )
    {
        if ( $ref === '#' )
            return $root;
        if ( strpos( $ref, '#/' ) !== 0 )
            return null;
        $node = $root;
        foreach ( explode( '/', substr( $ref, 2 ) ) as $part )
        {
            $part = str_replace( array( '~1', '~0' ), array( '/', '~' ), rawurldecode( $part ) );
            if ( !is_array( $node ) || !array_key_exists( $part, $node ) )
                return null;
            $node = $node[$part];
        }
        return $node;
    }

    private static function format( string $f, string $v ): bool
    {
        switch ( $f )
        {
            case 'email': return filter_var( $v, FILTER_VALIDATE_EMAIL ) !== false;
            case 'uri': case 'url': return (bool)preg_match( '~^[a-z][a-z0-9+.-]*:[^\s]*$~i', $v );
            case 'date': return (bool)preg_match( '/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $v ) && checkdate( (int)substr( $v, 5, 2 ), (int)substr( $v, 8, 2 ), (int)substr( $v, 0, 4 ) );
            case 'date-time': return (bool)preg_match( '/^\d{4}-\d{2}-\d{2}[Tt ]\d{2}:\d{2}:\d{2}(\.\d+)?([Zz]|[+-]\d{2}:\d{2})$/', $v );
            case 'ipv4': return filter_var( $v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) !== false;
            case 'ipv6': return filter_var( $v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) !== false;
            case 'uuid': return (bool)preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v );
            case 'hostname': return tronWebGuard::normalizeHost( $v ) !== null;
            default: return true;        // unknown formats are annotations
        }
    }
}
