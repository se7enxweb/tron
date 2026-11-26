<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Plain values and redaction for the units.
 *
 * plain() turns whatever a source hands over into JSON-safe plain values: scalars stay, lists and maps are walked
 * (six levels by default, then JSON text), objects become their string form or their public fields. Nothing ever comes out as
 * the word "Array" or "Object".
 *
 * Redaction: a value under a secret-looking name (tronRedactor::SECRET_KEY: Password, SecretKey, ApiKey, Token ...)
 * is replaced by "[redacted]"; text has tokens, "password=..." pairs and e-mail addresses masked.
 */
final class tronUnitValue
{
    const REDACTED = '[redacted]';
    const EMAIL = '/([A-Za-z0-9._%+-])[A-Za-z0-9._%+-]*@([A-Za-z0-9-])[A-Za-z0-9.-]*(\.[A-Za-z]{2,24})\b/';

    /** @return mixed scalar, null, or array of plain values */
    public static function plain( $v, int $depth = 0, int $maxDepth = 6 )
    {
        if ( $v === null || is_bool( $v ) || is_int( $v ) )
            return $v;
        if ( is_float( $v ) )
            return is_finite( $v ) ? $v : (string)$v;
        if ( is_string( $v ) )
            return $v;
        if ( is_object( $v ) )
        {
            if ( $v instanceof DateTimeInterface )
                return $v->format( 'c' );
            if ( method_exists( $v, '__toString' ) )
                return (string)$v;
            $v = get_object_vars( $v );
        }
        if ( is_array( $v ) )
        {
            if ( $depth >= $maxDepth )
                return (string)json_encode( $v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
            $out = array();
            foreach ( $v as $k => $item )
                $out[$k] = self::plain( $item, $depth + 1, $maxDepth );
            return $out;
        }
        return is_resource( $v ) ? '[resource]' : (string)gettype( $v );
    }

    /** One line of text: a plain value with lists joined by ", " (for a value shown in a table cell). */
    public static function text( $v ): string
    {
        $p = self::plain( $v );
        if ( is_array( $p ) )
        {
            $parts = array();
            foreach ( $p as $k => $item )
                $parts[] = ( is_string( $k ) ? $k . ': ' : '' ) . self::text( $item );
            return implode( ', ', $parts );
        }
        if ( is_bool( $p ) )
            return $p ? 'true' : 'false';
        return (string)$p;
    }

    public static function isSecretName( string $name ): bool
    {
        return (bool)preg_match( tronRedactor::SECRET_KEY, $name );
    }

    /** Masks e-mail addresses: jane.doe@example.org -> j***@e***.org. */
    public static function maskEmails( string $s ): string
    {
        return (string)preg_replace( self::EMAIL, '$1***@$2***$3', $s );
    }

    /** Text as it may leave the server: tokens, secret pairs and e-mail addresses masked. */
    public static function safeText( string $s ): string
    {
        return self::maskEmails( tronRedactor::maskSecretsInText( tronRedactor::maskTokens( $s ) ) );
    }

    /** A plain value with every string made safe and every secret-named key's value replaced. */
    public static function safe( $v, string $name = '' )
    {
        if ( $name !== '' && self::isSecretName( $name ) && $v !== null && $v !== '' && $v !== array() )
            return self::REDACTED;
        if ( is_string( $v ) )
            return self::safeText( $v );
        if ( is_array( $v ) )
        {
            $out = array();
            foreach ( $v as $k => $item )
                $out[$k] = self::safe( $item, is_string( $k ) ? $k : '' );
            return $out;
        }
        return $v;
    }

    /** A page of a list: array( total, offset, items ) after limit/offset. */
    public static function page( array $items, int $limit, int $offset, ?int $total = null ): array
    {
        $total = $total ?? count( $items );
        return array( 'total' => $total, 'offset' => $offset, 'items' => array_values( $items ) );
    }
}
