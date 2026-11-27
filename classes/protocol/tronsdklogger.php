<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/** The SDK's PSR-3 log lines into tron's logger (stderr over stdio, the kernel's log over HTTP); info and debug are dropped. */
final class tronSdkLogger extends AbstractLogger
{
    /** @var tronServer */ private $server;

    public function __construct( tronServer $server )
    {
        $this->server = $server;
    }

    /** $message is a string or a Stringable (untyped here, a wider type than PSR-3's, so the file parses on PHP 7.4) */
    public function log( $level, $message, array $context = array() ): void
    {
        if ( in_array( $level, array( LogLevel::DEBUG, LogLevel::INFO ), true ) )
            return;
        // a tron protocol error is an answer to the client, not a fault (tronSdkErrors)
        if ( isset( $context['exception'] ) && $context['exception'] instanceof tronRpcError )
            return;
        $text = (string)$message;
        if ( isset( $context['exception'] ) && $context['exception'] instanceof Throwable )
            $text .= ': ' . get_class( $context['exception'] ) . ': ' . $context['exception']->getMessage();
        $this->server->log( $level === LogLevel::NOTICE ? 'notice' : ( $level === LogLevel::WARNING ? 'warning' : 'error' ), 'mcp sdk: ' . $text );
    }
}
