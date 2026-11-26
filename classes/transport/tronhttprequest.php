<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/** An HTTP request as tron needs it: method, lower-case headers, body and the client address. */
final class tronHttpRequest
{
    /** @var string */ public $method;
    /** @var array<string,string> */ public $headers;
    /** @var string */ public $body;
    /** @var string */ public $remote;

    public function __construct( string $method, array $headers, string $body, string $remote = '' )
    {
        $this->method = strtoupper( $method );
        $this->headers = array();
        foreach ( $headers as $k => $v )
            $this->headers[strtolower( (string)$k )] = is_array( $v ) ? implode( ', ', $v ) : (string)$v;
        $this->body = $body;
        $this->remote = $remote;
    }

    public function header( string $name ): ?string
    {
        return $this->headers[strtolower( $name )] ?? null;
    }

    /**
     * The request PHP is serving. Reads at most $maxBody + 1 bytes of the body, so an oversized body is detected
     * without being held in memory. The Authorization header is also taken from the places Apache with PHP-FPM
     * moves it to (HTTP_AUTHORIZATION, REDIRECT_HTTP_AUTHORIZATION).
     */
    public static function fromGlobals( int $maxBody ): self
    {
        $headers = array();
        foreach ( $_SERVER as $k => $v )
        {
            if ( strpos( $k, 'HTTP_' ) === 0 )
                $headers[str_replace( '_', '-', substr( $k, 5 ) )] = (string)$v;
        }
        if ( isset( $_SERVER['CONTENT_TYPE'] ) )
            $headers['content-type'] = (string)$_SERVER['CONTENT_TYPE'];
        if ( isset( $_SERVER['CONTENT_LENGTH'] ) )
            $headers['content-length'] = (string)$_SERVER['CONTENT_LENGTH'];
        if ( !isset( $headers['AUTHORIZATION'] ) && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) )
            $headers['authorization'] = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        $body = (string)@file_get_contents( 'php://input', false, null, 0, $maxBody + 1 );
        $remote = (string)( $_SERVER['REMOTE_ADDR'] ?? '' );
        return new self( (string)( $_SERVER['REQUEST_METHOD'] ?? 'GET' ), $headers, $body, $remote );
    }
}
