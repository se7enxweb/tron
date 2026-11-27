<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

use Mcp\Schema\JsonRpc\Error;
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
 * Needs PHP 8.2 and the package mcp/sdk; tronProtocolLayers loads it only then.
 */
final class tronSdkHandler implements RequestHandlerInterface
{
    const METHODS = array( 'initialize', 'tools/list', 'tools/call', 'resources/list', 'resources/templates/list',
                           'resources/read', 'prompts/list', 'prompts/get' );

    private ?tronSession $session = null;
    private InitializeHandler $initialize;

    public function __construct( private readonly tronServer $server, Configuration $configuration )
    {
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

    public function handle( Request $request, SessionInterface $session ): Response|Error
    {
        $tron = $this->session;
        if ( $tron === null )
            throw new LogicException( 'tron: no session is bound to the SDK handler' );
        $id = $request->getId();
        $params = self::params( $request );
        try
        {
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
            return new Response( $id, self::clean( $this->server->dispatch( $request::getMethod(), $params, $tron ) ) );
        }
        catch ( tronRpcError $e )
        {
            $error = $this->server->redactOut( $e->toArray() );
            return new Error( $id, (int)$error['code'], (string)$error['message'], $error['data'] ?? null );
        }
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
