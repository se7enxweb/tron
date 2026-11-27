<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * tron's own JSON-RPC 2.0 and MCP framing, kept for PHP 7.4 to 8.1, where the official MCP PHP SDK cannot run.
 *
 * FROZEN: this layer takes security fixes and nothing else. New protocol behaviour comes from the SDK
 * (tronSdkProtocol), and this file goes once tron's PHP floor reaches the SDK's. The conformance tests run against
 * both layers (TRON_PROTOCOL=legacy|sdk, doc/protocol.md).
 *
 * Versions: 2025-11-25, 2025-06-18, 2025-03-26, 2024-11-05 (tronProtocol). Batches up to 2025-03-26.
 */
final class tronLegacyProtocol implements tronProtocolLayer
{
    /** @var tronServer */ private $server;

    public function __construct( tronServer $server )
    {
        $this->server = $server;
    }

    public function name(): string
    {
        return 'legacy';
    }

    public function versions(): array
    {
        return tronProtocol::VERSIONS;
    }

    public function handleRaw( string $raw, tronSession $session ): ?string
    {
        $decoded = json_decode( $raw, true );
        if ( $decoded === null && json_last_error() !== JSON_ERROR_NONE )
            return tronServer::encode( tronServer::errorResponse( null, tronProtocol::PARSE_ERROR, 'Parse error: ' . json_last_error_msg() ) );
        $answer = $this->handle( $decoded, $session );
        return $answer === null ? null : tronServer::encode( $answer );
    }

    /** Handles a decoded message or batch. Returns the answer (array) or null. */
    public function handle( $message, tronSession $session ): ?array
    {
        if ( is_array( $message ) && $message !== array() && tronSchemaValidator::isList( $message ) )
        {
            $version = $session->protocolVersion;
            if ( !tronProtocol::allowsBatch( $version ) )
                return tronServer::errorResponse( null, tronProtocol::INVALID_REQUEST, "JSON-RPC batches are not part of MCP $version" );
            $out = array();
            foreach ( $message as $one )
            {
                $r = $this->handleOne( $one, $session );
                if ( $r !== null )
                    $out[] = $r;
            }
            return $out ?: null;
        }
        return $this->handleOne( $message, $session );
    }

    private function handleOne( $msg, tronSession $session ): ?array
    {
        if ( !is_array( $msg ) || $msg === array() || tronSchemaValidator::isList( $msg ) )
            return tronServer::errorResponse( null, tronProtocol::INVALID_REQUEST, 'Invalid Request: a message is a JSON object' );
        $hasId = array_key_exists( 'id', $msg );
        $id = $hasId ? $msg['id'] : null;
        if ( $hasId && !is_string( $id ) && !is_int( $id ) )
            return tronServer::errorResponse( null, tronProtocol::INVALID_REQUEST, 'Invalid Request: the id must be a string or an integer' );
        if ( ( $msg['jsonrpc'] ?? null ) !== '2.0' )
            return tronServer::errorResponse( $id, tronProtocol::INVALID_REQUEST, 'Invalid Request: jsonrpc must be "2.0"' );
        if ( !isset( $msg['method'] ) )
        {
            // A response from the client (to a request tron never sends): nothing to answer.
            if ( array_key_exists( 'result', $msg ) || array_key_exists( 'error', $msg ) )
                return null;
            return tronServer::errorResponse( $id, tronProtocol::INVALID_REQUEST, 'Invalid Request: no method' );
        }
        if ( !is_string( $msg['method'] ) )
            return tronServer::errorResponse( $id, tronProtocol::INVALID_REQUEST, 'Invalid Request: the method must be a string' );
        $params = $msg['params'] ?? array();
        if ( !is_array( $params ) )
            return $hasId ? tronServer::errorResponse( $id, tronProtocol::INVALID_PARAMS, 'Invalid params: params must be an object' ) : null;

        $method = $msg['method'];
        if ( !$hasId )
        {
            $this->server->notification( $method, $params, $session );
            return null;
        }

        try
        {
            if ( $session->requireInitialize && !$session->protocolVersion && $method !== 'initialize' && $method !== 'ping' )
                throw new tronRpcError( tronProtocol::INVALID_REQUEST, 'Server not initialized: send initialize first' );
            $result = $method === 'initialize' ? $this->initialize( $params, $session ) : $this->server->dispatch( $method, $params, $session );
            return array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result );
        }
        catch ( tronRpcError $e )
        {
            return array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => $this->server->redactOut( $e->toArray() ) );
        }
        catch ( Throwable $e )
        {
            $this->server->log( 'error', 'internal error in ' . $method . ': ' . get_class( $e ) . ': ' . $e->getMessage() );
            return tronServer::errorResponse( $id, tronProtocol::INTERNAL_ERROR, 'Internal error' );
        }
    }

    private function initialize( array $params, tronSession $session ): array
    {
        if ( !isset( $params['protocolVersion'] ) || !is_string( $params['protocolVersion'] ) )
            throw new tronRpcError( tronProtocol::INVALID_PARAMS, 'Invalid params: initialize needs protocolVersion' );
        return $this->server->initialize( $params, $session, tronProtocol::negotiate( $params['protocolVersion'] ) );
    }

    /**
     * One JSON-RPC message per line on the input, one answer per line on the output, nothing else ever on the output.
     * Runs until the input closes. A line longer than $maxLineBytes is answered with a parse error and skipped.
     */
    public function serveStdio( tronSession $session, $in, $out, int $maxLineBytes ): int
    {
        $write = function ( string $s ) use ( $out ) {
            fwrite( $out, $s . "\n" );
            fflush( $out );
        };
        while ( true )
        {
            $line = fgets( $in, $maxLineBytes + 2 );
            if ( $line === false )
                return 0;
            if ( strlen( $line ) > $maxLineBytes && substr( $line, -1 ) !== "\n" )
            {
                // Skip the rest of the oversized line, then answer once.
                while ( ( $rest = fgets( $in, 65536 ) ) !== false && substr( $rest, -1 ) !== "\n" )
                {
                }
                $write( tronServer::encode( tronServer::errorResponse( null, tronProtocol::PARSE_ERROR, "Parse error: the message is longer than $maxLineBytes bytes" ) ) );
                continue;
            }
            $line = trim( $line );
            if ( $line === '' )
                continue;
            $answer = $this->handleRaw( $line, $session );
            if ( $answer !== null )
                $write( $answer );
        }
    }

    /** Stateless: a POST carries a message (or a batch where the version allows one); notifications get 202. */
    public function serveHttp( tronHttpRequest $req, tronSession $session ): tronHttpResponse
    {
        $version = $req->header( 'mcp-protocol-version' );
        if ( $version === null )
            $session->protocolVersion = self::isInitialize( $req->body ) ? null : tronProtocol::HTTP_DEFAULT;
        $answer = $this->handleRaw( $req->body, $session );
        if ( $answer === null )
            return new tronHttpResponse( 202 );
        return new tronHttpResponse( 200, array( 'Content-Type' => 'application/json; charset=utf-8' ), $answer );
    }

    private static function isInitialize( string $body ): bool
    {
        $d = json_decode( $body, true );
        return is_array( $d ) && ( $d['method'] ?? null ) === 'initialize';
    }
}
