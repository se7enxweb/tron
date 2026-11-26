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
        while ( true )
        {
            $line = fgets( $this->in, $this->maxLine + 2 );
            if ( $line === false )
                return 0;
            if ( strlen( $line ) > $this->maxLine && substr( $line, -1 ) !== "\n" )
            {
                // Skip the rest of the oversized line, then answer once.
                while ( ( $rest = fgets( $this->in, 65536 ) ) !== false && substr( $rest, -1 ) !== "\n" )
                {
                }
                $this->write( tronServer::encode( tronServer::errorResponse( null, tronProtocol::PARSE_ERROR, "Parse error: the message is longer than {$this->maxLine} bytes" ) ) );
                continue;
            }
            $line = trim( $line );
            if ( $line === '' )
                continue;
            $answer = $this->server->handleRaw( $line, $this->session );
            if ( $answer !== null )
                $this->write( $answer );
        }
    }

    private function write( string $s ): void
    {
        fwrite( $this->out, $s . "\n" );
        fflush( $this->out );
    }
}
