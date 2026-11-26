<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Keeps secrets and personal data out of what tron writes and answers.
 *
 * Two uses:
 *
 * - redact(): arguments as the audit log keeps them. Values under a secret-looking key are replaced, tokens, keys,
 *   connection strings, e-mail addresses and phone numbers are masked wherever they appear, and long text is cut.
 * - output(): every answer tron sends (tool results and errors, resources, prompts, instructions) passes through it
 *   on the way out (doc/redaction.md). It masks the same kinds and counts what it masked per kind, so the audit can
 *   record that redaction happened (counts and kinds, never the values).
 *
 * Kinds: secret (password=..., a value under a key named password, secret, api_key ...), token (tron tokens, bearer
 * headers, JWTs, provider tokens such as ghp_..., glpat-..., sk-...), key (PEM private keys, cloud access keys), dsn
 * (user:password@ in a URL, PDO data source names), email, phone (international numbers, and numbers after a phone
 * label). A masked value reads [redacted:<kind>].
 */
final class tronRedactor
{
    const SECRET_KEY = '/pass|secret|token|api_?key|private|credential|authorization|cookie/i';
    /** Keys whose string values are secrets in an answer (stricter than SECRET_KEY: "token_id" and "passed" stay) */
    const OUTPUT_SECRET_KEY = '/^(password|passwd|pass|pwd|secret|[a-z_]*_secret|secret_[a-z_]+|api_?key|private_?key|access_?key|access_?token|refresh_?token|auth_?token|token|bearer|client_secret|credentials?|authorization|cookie|set_cookie|dsn|connection_?string|password_hash)$/i';
    const OUTPUT_EMAIL_KEY = '/^(e_?mail|email_?address|mail)$/i';
    const OUTPUT_PHONE_KEY = '/^(phone|phone_?number|telephone|tel|mobile|cell|fax)$/i';
    const KINDS = array( 'secret', 'token', 'key', 'dsn', 'email', 'phone' );
    const MAX_STRING = 200;

    /** @var bool set when output() replaced a too deep part with "[nested]" */
    private static $cutNested = false;

    /** @return mixed the value as the audit log keeps it */
    public static function redact( $value, int $depth = 0 )
    {
        if ( $depth > 6 )
            return '[nested]';
        if ( is_array( $value ) )
        {
            $out = array();
            $n = 0;
            foreach ( $value as $k => $v )
            {
                if ( ++$n > 50 )
                {
                    $out['…'] = ( count( $value ) - 50 ) . ' more';
                    break;
                }
                $out[$k] = is_string( $k ) && preg_match( self::SECRET_KEY, $k ) ? '[redacted]' : self::redact( $v, $depth + 1 );
            }
            return $out;
        }
        if ( is_string( $value ) )
        {
            $ignored = array();
            $value = self::maskText( self::maskSecretsInText( self::maskTokens( $value ) ), $ignored );
            $len = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
            if ( $len > self::MAX_STRING )
                $value = ( function_exists( 'mb_substr' ) ? mb_substr( $value, 0, self::MAX_STRING, 'UTF-8' ) : substr( $value, 0, self::MAX_STRING ) ) . '… (' . $len . ' chars)';
            return $value;
        }
        return $value;
    }

    /**
     * An answer on its way out, with every kind masked; $counts gets kind => number masked added. $kinds limits the
     * kinds (null: all). Keys stay, numbers and booleans stay, only strings change.
     *
     * @return mixed
     */
    public static function output( $value, array &$counts, ?array $kinds = null, int $depth = 0 )
    {
        if ( is_array( $value ) )
        {
            // deeper than any answer tron builds: nothing below is let through unread
            if ( $depth > 64 )
            {
                self::$cutNested = true;
                return '[nested]';
            }
            $out = array();
            foreach ( $value as $k => $v )
            {
                if ( is_string( $k ) && is_string( $v ) && $v !== '' && strpos( $v, '[redacted' ) !== 0 )
                {
                    $kind = preg_match( self::OUTPUT_SECRET_KEY, $k ) ? 'secret'
                          : ( preg_match( self::OUTPUT_EMAIL_KEY, $k ) ? 'email' : ( preg_match( self::OUTPUT_PHONE_KEY, $k ) ? 'phone' : '' ) );
                    if ( $kind !== '' && ( $kinds === null || in_array( $kind, $kinds, true ) ) )
                    {
                        $counts[$kind] = ( $counts[$kind] ?? 0 ) + 1;
                        $out[$k] = '[redacted:' . $kind . ']';
                        continue;
                    }
                }
                $out[$k] = self::output( $v, $counts, $kinds, $depth + 1 );
            }
            return $out;
        }
        if ( $value instanceof stdClass )
        {
            $masked = self::output( (array)$value, $counts, $kinds, $depth + 1 );
            return is_array( $masked ) ? (object)$masked : $masked;
        }
        if ( is_string( $value ) )
        {
            // JSON text (a tool answer's content, a resource): masked value by value, so the JSON stays valid
            if ( $value !== '' && ( $value[0] === '{' || $value[0] === '[' ) && $depth < 64 )
            {
                $decoded = json_decode( $value );
                if ( is_array( $decoded ) || is_object( $decoded ) )
                {
                    $c = array();
                    $outerCut = self::$cutNested;
                    self::$cutNested = false;
                    $masked = self::output( $decoded, $c, $kinds, $depth + 1 );
                    $cut = self::$cutNested;
                    self::$cutNested = $outerCut || $cut;
                    if ( !$c && !$cut )
                        return $value;
                    foreach ( $c as $k => $n )
                        $counts[$k] = ( $counts[$k] ?? 0 ) + $n;
                    $json = json_encode( $masked, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION );
                    return $json === false ? '{"error":"unencodable answer"}' : $json;
                }
            }
            return self::maskText( $value, $counts, $kinds );
        }
        return $value;
    }

    /**
     * Masks every kind inside free text, counting per kind. The order matters: whole private keys and connection
     * strings first, so their parts are not counted again as e-mail addresses or secrets.
     */
    public static function maskText( string $s, array &$counts, ?array $kinds = null ): string
    {
        if ( $s === '' || strlen( $s ) < 6 )
            return $s;
        foreach ( self::rules() as $rule )
        {
            list( $kind, $re, $replacement ) = $rule;
            if ( $kinds !== null && !in_array( $kind, $kinds, true ) )
                continue;
            $n = 0;
            if ( is_callable( $replacement ) )
            {
                $r = preg_replace_callback( $re, function ( $m ) use ( $replacement, &$n ) {
                    $out = $replacement( $m );
                    if ( $out !== $m[0] )
                        $n++;
                    return $out;
                }, $s );
            }
            else
                $r = preg_replace( $re, $replacement, $s, -1, $n );
            if ( $r !== null && $n > 0 )
            {
                $s = $r;
                $counts[$kind] = ( $counts[$kind] ?? 0 ) + $n;
            }
        }
        return $s;
    }

    /** @return array[] array( kind, pattern, replacement ) in the order they apply */
    private static function rules(): array
    {
        static $rules = null;
        if ( $rules !== null )
            return $rules;
        $digits = function ( string $m ): int {
            return strlen( (string)preg_replace( '/\D/', '', $m ) );
        };
        $rules = array(
            array( 'key', '/-----BEGIN ([A-Z0-9 ]*)PRIVATE KEY-----.*?(?:-----END \1PRIVATE KEY-----|\z)/s', '[redacted:key]' ),
            array( 'key', '/\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/', '[redacted:key]' ),
            // scheme://user:password@host -> scheme://[redacted:dsn]@host
            array( 'dsn', '~\b([a-z][a-z0-9+.\-]{1,30})://[^\s:/@"\'<>\[]+:[^\s@/"\'<>]+@~i', '$1://[redacted:dsn]@' ),
            // PDO data source names: mysql:host=...;dbname=...;password=...
            array( 'dsn', '/\b(?:mysql|pgsql|sqlsrv|oci|dblib|odbc|ibm|informix|firebird):(?:host|dbname|unix_socket|server|database|dsn)=[^\s"\'<>]+/i', '[redacted:dsn]' ),
            array( 'token', '/\btron_[A-Za-z0-9]{6,16}_[A-Za-z0-9_-]{16,}/', '[redacted:token]' ),
            array( 'token', '/\b(Bearer)\s+(?!\[redacted)[A-Za-z0-9._~+\/=-]{8,}/i', '$1 [redacted:token]' ),
            array( 'token', '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/', '[redacted:token]' ),
            array( 'token', '/\b(?:gh[posur]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|glpat-[A-Za-z0-9_-]{16,}|xox[abprs]-[A-Za-z0-9-]{10,}|sk-[A-Za-z0-9_-]{20,}|(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9]{16,})/', '[redacted:token]' ),
            array( 'secret', '/\b(pass(?:word|wd|phrase)|pwd|secret|client[_-]?secret|api[_-]?key|access[_-]?key|private[_-]?key|token|credentials?)(\s*[=:]\s*)(?!\[redacted)("[^"]*"|\'[^\']*\'|[^\s,;&]+)/i', '$1$2[redacted:secret]' ),
            array( 'email', '/(?<![A-Za-z0-9._%+\-])[A-Za-z0-9._%+\-]{1,64}@(?:[A-Za-z0-9](?:[A-Za-z0-9\-]{0,62}[A-Za-z0-9])?\.)+[A-Za-z]{2,24}\b/', '[redacted:email]' ),
            array( 'phone', '/(?<![\w+])\+\d{1,3}(?:[ .\-]?\(?\d{1,5}\)?){2,6}(?!\w)/', function ( $m ) use ( $digits ) {
                $n = $digits( $m[0] );
                return $n >= 8 && $n <= 15 ? '[redacted:phone]' : $m[0];
            } ),
            array( 'phone', '/\b(tel|phone|telephone|telefon|mobile|mobil|handy|cell|fax)\b(\s*[:.]?\s*)(\(?\d[\d ().\/\-]{5,}\d)/i', function ( $m ) use ( $digits ) {
                $n = $digits( $m[3] );
                return $n >= 6 && $n <= 15 ? $m[1] . $m[2] . '[redacted:phone]' : $m[0];
            } ),
        );
        return $rules;
    }

    /**
     * "password=hunter2", "api_key: abc", "secret = x" inside free text keep their name and lose their value (found by
     * the cookbook's audit example: a ticket body with a password went into the log as written).
     */
    public static function maskSecretsInText( string $s ): string
    {
        return (string)preg_replace( '/\b(pass(?:word|wd)?|secret|api[_-]?key|private[_-]?key|token|credentials?)(\s*[=:]\s*)("[^"]*"|\'[^\']*\'|\S+)/i', '$1$2[redacted]', $s );
    }

    public static function maskTokens( string $s ): string
    {
        $s = (string)preg_replace( '/\btron_([A-Za-z0-9]{6,16})_[A-Za-z0-9_-]{16,}/', 'tron_$1_[redacted]', $s );
        return (string)preg_replace( '/\b(Bearer)\s+[A-Za-z0-9._~+\/=-]{8,}/i', '$1 [redacted]', $s );
    }
}
