<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The stdio transport: one JSON-RPC message per line on stdin, one answer per line on stdout, nothing else ever on
 * stdout (logs go to stderr). Runs until stdin closes. A line longer than MaxLineBytes is answered with a parse
 * error and skipped, so a runaway client cannot exhaust the process's memory.
 */
final class tronStdioTransport
{
    /** @var tronServer */ private $server;
    /** @var tronSession */ private $session;
    /** @var resource */ private $in;
    /** @var resource */ private $out;
    /** @var int */ private $maxLine;

    public function __construct( tronServer $server, tronSession $session, $in, $out, int $maxLineBytes = 4194304 )
    {
        $this->server = $server;
        $this->session = $session;
        $this->in = $in;
        $this->out = $out;
        $this->maxLine = $maxLineBytes;
    }

    /** @return int the process exit code */
    public function run(): int
    {
        return $this->server->protocol()->serveStdio( $this->session, $this->in, $this->out, $this->maxLine );
    }
}
