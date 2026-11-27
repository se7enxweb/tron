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
    public function __construct( private readonly tronServer $server )
    {
    }

    public function log( $level, string|\Stringable $message, array $context = array() ): void
    {
        if ( in_array( $level, array( LogLevel::DEBUG, LogLevel::INFO ), true ) )
            return;
        $text = (string)$message;
        if ( isset( $context['exception'] ) && $context['exception'] instanceof Throwable )
            $text .= ': ' . get_class( $context['exception'] ) . ': ' . $context['exception']->getMessage();
        $this->server->log( $level === LogLevel::NOTICE ? 'notice' : ( $level === LogLevel::WARNING ? 'warning' : 'error' ), 'mcp sdk: ' . $text );
    }
}
