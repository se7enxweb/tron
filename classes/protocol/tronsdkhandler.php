<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\InitializeRequest;
use Mcp\Server\Configuration;
use Mcp\Server\Handler\Request\InitializeHandler;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * The official MCP PHP SDK's request handler for tron: the SDK parses, validates and frames every message, negotiates
 * the version (its own InitializeHandler) and keeps the lifecycle; this handler answers the methods with what
 * tronServer says for the bound tron session (its user, token scope, policies, audit and redaction).
 *
 * A tron protocol error (tronRpcError) is thrown, not returned: the SDK turns it into its error event, where
 * tronSdkErrors puts tron's own code, message and data back. That keeps this file in PHP 7.4 syntax (the interface's
 * return type Response|Error is a union, PHP 8.0; Response alone is a narrower, compatible type), as every tron file
 * must parse on every PHP tron supports, although it runs only with PHP 8.2 and the package mcp/sdk.
 */
final class tronSdkHandler implements RequestHandlerInterface
{
    const METHODS = array( 'initialize', 'tools/list', 'tools/call', 'resources/list', 'resources/templates/list',
                           'resources/read', 'prompts/list', 'prompts/get' );

    /** @var tronServer */ private $server;
    /** @var tronSession|null */ private $session = null;
    /** @var InitializeHandler */ private $initialize;

    public function __construct( tronServer $server, Configuration $configuration )
    {
        $this->server = $server;
        $this->initialize = new InitializeHandler( $configuration );
    }

    /** The tron session the next messages belong to (one per stdio process, HTTP request or in-process call). */
    public function bind( tronSession $session ): void
    {
        $this->session = $session;
    }

    public function supports( Request $request ): bool
    {
        return in_array( $request::getMethod(), self::METHODS, true );
    }

    /** @throws tronRpcError for a protocol error, which tronSdkErrors answers with its code, message and data */
    public function handle( Request $request, SessionInterface $session ): Response
    {
        $tron = $this->session;
        if ( $tron === null )
            throw new LogicException( 'tron: no session is bound to the SDK handler' );
        $id = $request->getId();
        $params = self::params( $request );
        if ( $request instanceof InitializeRequest )
        {
            // The SDK negotiates the version and records the client in its session; tron answers the rest.
            $negotiated = $this->initialize->handle( $request, $session )->result->protocolVersion;
            $version = $negotiated !== null ? $negotiated->value : (string)$session->get( 'protocol_version' );
            $tron->clientInfo = is_array( $params['clientInfo'] ?? null ) ? $params['clientInfo'] : array();
            return new Response( $id, self::clean( $this->server->initialize( $params, $tron, $version ) ) );
        }
        $version = $session->get( 'protocol_version' );
        if ( is_string( $version ) && $version !== '' )
            $tron->protocolVersion = $version;
        // a tronRpcError goes on to the SDK's error event (tronSdkErrors)
        return new Response( $id, self::clean( $this->server->dispatch( $request::getMethod(), $params, $tron ) ) );
    }

    /** The request's params as tron reads them: plain arrays, _meta included. */
    private static function params( Request $request ): array
    {
        $params = json_decode( (string)json_encode( $request->jsonSerialize()['params'] ?? array() ), true );
        $params = is_array( $params ) ? $params : array();
        $meta = $request->getMeta();
        if ( $meta !== null )
            $params['_meta'] = $meta;
        return $params;
    }

    /**
     * The answer as the SDK can encode it: unchanged when it is valid JSON, else with the invalid UTF-8 replaced, so
     * the SDK's strict encoder never fails on tool data (a float such as 100.0 keeps its type either way).
     */
    private static function clean( $result )
    {
        if ( json_encode( $result ) !== false )
            return $result;
        return json_decode( tronServer::encode( $result ) );
    }
}
