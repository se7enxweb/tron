<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

use Mcp\Server\Transport\BaseTransport;

/**
 * An in-process transport for the SDK: hands it one message and collects what it sends back. Used by
 * tronSdkProtocol::handle() for a session that keeps the stdio lifecycle (initialize first) without a process,
 * e.g. the conformance tests. The SDK session it opens on initialize stays open between messages.
 */
final class tronSdkLoop extends BaseTransport
{
    /** @var string[] */ private array $sent = array();
    private ?string $pending = null;

    /** Queues one message for the next listen(), which Server::run() calls. */
    public function queue( string $payload ): void
    {
        $this->pending = $payload;
        $this->sent = array();
    }

    public function onMessage( callable $listener ): void
    {
        $this->messageListener = $listener;
    }

    public function listen(): mixed
    {
        if ( $this->pending !== null )
        {
            $payload = $this->pending;
            $this->pending = null;
            $this->handleMessage( $payload, $this->sessionId );
        }
        return null;
    }

    public function send( string $data, array $context ): void
    {
        if ( isset( $context['session_id'] ) )
            $this->sessionId = $context['session_id'];
        $this->sent[] = $data;
    }

    /** @return string[] */
    public function sent(): array
    {
        return $this->sent;
    }
}
