<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The wire between an MCP client and tronServer: JSON-RPC framing, version negotiation, the lifecycle. Two layers
 * implement it: tronSdkProtocol (the official MCP PHP SDK, PHP 8.2 and newer) and tronLegacyProtocol (tron's own,
 * frozen, for PHP 7.4 to 8.1). The transports (stdio, streamable HTTP) ask the server's layer for theirs.
 */
interface tronProtocolLayer
{
    /** "sdk" or "legacy". */
    public function name(): string;

    /** One raw message (a line or an HTTP body). Returns the encoded answer, or null when none is due. */
    public function handleRaw( string $raw, tronSession $session ): ?string;

    /** A decoded message (or batch). Returns the answer as an array, or null when none is due. */
    public function handle( $message, tronSession $session ): ?array;

    /** Serves stdio until the input ends. @return int the process exit code */
    public function serveStdio( tronSession $session, $in, $out, int $maxLineBytes ): int;

    /** Answers one HTTP request that tronHttpTransport has already authenticated, for the session it built. */
    public function serveHttp( tronHttpRequest $req, tronSession $session ): tronHttpResponse;

    /** The protocol versions this layer negotiates, newest first. @return string[] */
    public function versions(): array;
}
