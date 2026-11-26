<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Whatever a fetch function, an operator or a view hands back, as plain JSON values an agent can read: scalars stay,
 * lists and maps are walked, kernel objects (eZPersistentObject and the like) become their stored fields plus a few
 * safe derived ones (name, url_alias, class_identifier), capped in depth and length. Fields that hold secrets
 * (password hashes, tokens, keys) never leave: they are dropped by name before the server's redactor sees the rest.
 */
final class tronSystemValue
{
    const MAX_ITEMS = 100;
    const MAX_DEPTH = 4;
    const MAX_STRING = 4000;
    const DERIVED = array( 'name', 'url_alias', 'class_identifier', 'class_name', 'path_identification_string', 'children_count' );
    const SECRET_FIELD = '/pass(word)?|hash|secret|token|api_?key|private|salt|session|cookie|credential/i';

    /** @var bool set when anything was cut */ public static $truncated = false;

    public static function plain( $v, int $depth = 0 )
    {
        if ( $v === null || is_bool( $v ) || is_int( $v ) )
            return $v;
        if ( is_float( $v ) )
            return is_finite( $v ) ? $v : (string)$v;
        if ( is_string( $v ) )
        {
            if ( strlen( $v ) > self::MAX_STRING )
            {
                self::$truncated = true;
                $v = mb_substr( $v, 0, self::MAX_STRING ) . '...';
            }
            return self::safe( $v );
        }
        if ( $depth >= self::MAX_DEPTH )
        {
            self::$truncated = true;
            return is_object( $v ) ? array( '_class' => get_class( $v ) ) : ( is_array( $v ) ? array( '_items' => count( $v ) ) : null );
        }
        if ( is_array( $v ) )
        {
            $out = array();
            $n = 0;
            foreach ( $v as $k => $item )
            {
                if ( ++$n > self::MAX_ITEMS )
                {
                    self::$truncated = true;
                    break;
                }
                if ( is_string( $k ) && preg_match( self::SECRET_FIELD, $k ) )
                    continue;
                $out[$k] = self::plain( $item, $depth + 1 );
            }
            return $out;
        }
        if ( is_object( $v ) )
            return self::object( $v, $depth );
        return null;
    }

    /**
     * Text as it may leave: tokens, secret pairs and e-mail addresses masked here already, whatever the server's own
     * output redaction is set to, because a fetch or a page can carry anybody's address.
     */
    public static function safe( string $s ): string
    {
        if ( class_exists( 'tronUnitValue' ) )
            return tronUnitValue::safeText( $s );
        return (string)preg_replace( '/([A-Za-z0-9._%+-])[A-Za-z0-9._%+-]*@([A-Za-z0-9-])[A-Za-z0-9.-]*(\.[A-Za-z]{2,24})\b/', '$1***@$2***$3', $s );
    }

    private static function object( $o, int $depth ): array
    {
        $out = array( '_class' => get_class( $o ) );
        $fields = array();
        if ( method_exists( $o, 'definition' ) )
        {
            try
            {
                $def = call_user_func( array( get_class( $o ), 'definition' ) );
                $fields = array_keys( (array)( $def['fields'] ?? array() ) );
            }
            catch ( Throwable $e )
            {
                $fields = array();
            }
        }
        if ( !$fields && $o instanceof JsonSerializable )
            return $out + (array)self::plain( $o->jsonSerialize(), $depth + 1 );
        if ( !$fields && !method_exists( $o, 'attribute' ) )
            return $out + (array)self::plain( get_object_vars( $o ), $depth + 1 );
        if ( method_exists( $o, 'hasAttribute' ) )
            foreach ( self::DERIVED as $d )
                if ( !in_array( $d, $fields, true ) && $o->hasAttribute( $d ) )
                    $fields[] = $d;
        foreach ( $fields as $f )
        {
            if ( preg_match( self::SECRET_FIELD, $f ) )
                continue;
            try
            {
                $val = $o->attribute( $f );
            }
            catch ( Throwable $e )
            {
                continue;
            }
            $out[$f] = is_object( $val ) ? array( '_class' => get_class( $val ) ) : self::plain( $val, $depth + 1 );
        }
        return $out;
    }

    /** The text of a rendered page: tags and scripts out, whitespace collapsed, at most $max characters. */
    public static function text( string $html, int $max = 20000 ): string
    {
        $html = preg_replace( '#<(script|style|noscript|template)\b[^>]*>.*?</\1>#is', ' ', $html );
        $html = preg_replace( '#<(br|p|div|li|h[1-6]|tr|section|article|header|footer)\b[^>]*>#i', "\n", (string)$html );
        $text = html_entity_decode( strip_tags( (string)$html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = trim( preg_replace( "/[ \t\x{00A0}]+/u", ' ', $text ) );
        $text = preg_replace( "/\s*\n\s*/", "\n", $text );
        if ( mb_strlen( (string)$text ) > $max )
        {
            self::$truncated = true;
            return self::safe( mb_substr( (string)$text, 0, $max ) ) . '...';
        }
        return self::safe( (string)$text );
    }

    /** The links of a rendered page: href and text, at most $max, no javascript: or mailto: links. */
    public static function links( string $html, int $max = 100 ): array
    {
        preg_match_all( '#<a\b[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is', $html, $m, PREG_SET_ORDER );
        $out = array();
        $seen = array();
        foreach ( $m as $a )
        {
            $href = html_entity_decode( $a[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            if ( $href === '' || $href[0] === '#' || stripos( $href, 'javascript:' ) === 0 || stripos( $href, 'mailto:' ) === 0 || isset( $seen[$href] ) )
                continue;
            $seen[$href] = true;
            $out[] = array( 'href' => $href, 'text' => self::safe( mb_substr( trim( preg_replace( '/\s+/', ' ', strip_tags( $a[3] ) ) ), 0, 120 ) ) );
            if ( count( $out ) >= $max )
                break;
        }
        return $out;
    }
}
