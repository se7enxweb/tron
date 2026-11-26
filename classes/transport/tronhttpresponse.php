<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/** An HTTP answer: status, headers and body; emit() sends it through PHP. */
final class tronHttpResponse
{
    /** @var int */ public $status;
    /** @var array<string,string> */ public $headers;
    /** @var string */ public $body;

    public function __construct( int $status, array $headers = array(), string $body = '' )
    {
        $this->status = $status;
        $this->headers = $headers + array( 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff' );
        $this->body = $body;
    }

    public static function json( int $status, $value, array $headers = array() ): self
    {
        return new self( $status, $headers + array( 'Content-Type' => 'application/json; charset=utf-8' ), tronServer::encode( $value ) );
    }

    public function emit(): void
    {
        if ( !headers_sent() )
        {
            http_response_code( $this->status );
            foreach ( $this->headers as $k => $v )
                header( $k . ': ' . $v );
        }
        echo $this->body;
    }
}
