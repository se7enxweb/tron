<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

use Mcp\Schema\JsonRpc\Error;
use Mcp\Server\Event\ErrorEvent;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The SDK's event dispatcher for tron: when a request ends in a tronRpcError (thrown by tronSdkHandler), the error the
 * client gets carries tron's code, message and data, redacted like every other answer, instead of the SDK's generic
 * "internal error". Every other event passes unchanged.
 */
final class tronSdkErrors implements EventDispatcherInterface
{
    /** @var tronServer */ private $server;

    public function __construct( tronServer $server )
    {
        $this->server = $server;
    }

    public function dispatch( object $event )
    {
        if ( $event instanceof ErrorEvent && $event->getThrowable() instanceof tronRpcError )
        {
            $error = $this->server->redactOut( $event->getThrowable()->toArray() );
            $event->setError( new Error( $event->getRequest()->getId(), (int)$error['code'], (string)$error['message'], $error['data'] ?? null ) );
        }
        return $event;
    }
}
