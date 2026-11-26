<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/** A protocol-level failure: becomes a JSON-RPC error object (code, message, optional data). */
class tronRpcError extends RuntimeException
{
    /** @var mixed */
    private $data;

    public function __construct( int $code, string $message, $data = null )
    {
        parent::__construct( $message, $code );
        $this->data = $data;
    }

    /** @return mixed */
    public function getData()
    {
        return $this->data;
    }

    public function toArray(): array
    {
        $e = array( 'code' => $this->getCode(), 'message' => $this->getMessage() );
        if ( $this->data !== null )
            $e['data'] = $this->data;
        return $e;
    }
}
