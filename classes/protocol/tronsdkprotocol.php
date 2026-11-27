<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Implementation;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server\Configuration;
use Mcp\Server\Server;
use Mcp\Server\Transport\Http\Middleware\PassthroughMiddleware;
use Mcp\Server\Transport\StdioTransport;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * tron on the official MCP PHP SDK (package mcp/sdk, https://github.com/modelcontextprotocol/php-sdk): JSON-RPC,
 * the version negotiation, the lifecycle and both transports are the SDK's. tron adds one request handler
 * (tronSdkHandler) for initialize, tools, resources and prompts; ping and the JSON-RPC errors are the SDK's own.
 *
 *   stdio  the SDK's StdioTransport, one SDK session for the process
 *   HTTP   the SDK's StreamableHttpTransport without sessions (no Mcp-Session-Id, no event stream), so every request
 *          stands alone and any node of a cluster can answer it; tronHttpTransport authenticates first
 *
 * The handshake-era versions only (2024-11-05 to 2025-11-25): the stateless 2026-07-28 revision the SDK also speaks
 * is switched off until tron's tools are proven on it.
 */
final class tronSdkProtocol implements tronProtocolLayer
{
    private ?Server $sdk = null;
    private ?tronSdkHandler $handler = null;
    private ?tronSdkLoop $loop = null;
    private ?tronSession $loopSession = null;

    public function __construct( private readonly tronServer $server )
    {
    }

    public function name(): string
    {
        return 'sdk';
    }

    public function versions(): array
    {
        return array_reverse( array_map( fn ( ProtocolVersion $v ): string => $v->value, ProtocolVersion::handshakeVersions() ) );
    }

    /** The SDK server, built once per tronServer. */
    private function sdk(): Server
    {
        if ( $this->sdk !== null )
            return $this->sdk;
        $info = new Implementation( (string)$this->server->option( 'name' ), (string)$this->server->option( 'version' ), title: (string)$this->server->option( 'title' ) );
        $capabilities = new ServerCapabilities( tools: true, toolsListChanged: false, resources: true, resourcesSubscribe: false,
                                                resourcesListChanged: false, prompts: true, promptsListChanged: false,
                                                logging: false, completions: false );
        $this->handler = new tronSdkHandler( $this->server, new Configuration( $info, $capabilities, max( 1, (int)$this->server->option( 'pageSize' ) ) ) );
        return $this->sdk = Server::builder()
            ->setServerInfo( (string)$this->server->option( 'name' ), (string)$this->server->option( 'version' ), title: (string)$this->server->option( 'title' ) )
            ->setCapabilities( $capabilities )
            ->setLogger( new tronSdkLogger( $this->server ) )
            ->addRequestHandler( $this->handler )
            ->withoutModernEra()
            ->build();
    }

    public function serveStdio( tronSession $session, $in, $out, int $maxLineBytes ): int
    {
        $sdk = $this->sdk();
        $this->handler->bind( $session );
        return (int)$sdk->run( new StdioTransport( $in, $out, new tronSdkLogger( $this->server ), new Mcp\Server\Transport\Stdio\RunnerControl(), $maxLineBytes ) );
    }

    public function serveHttp( tronHttpRequest $req, tronSession $session ): tronHttpResponse
    {
        $sdk = $this->sdk();
        $this->handler->bind( $session );
        $f = new Psr17Factory();
        $psr = $f->createServerRequest( $req->method, 'http://' . ( $req->header( 'host' ) ?: 'localhost' ) . '/mcp' )
                 ->withBody( $f->createStream( $req->body ) );
        foreach ( $req->headers as $k => $v )
            $psr = $psr->withHeader( $k, $v );
        // Origin, Host, body size and the bearer token were checked by tronHttpTransport, with tron's settings.
        $transport = new StreamableHttpTransport( $psr, $f, $f, new tronSdkLogger( $this->server ), array( new PassthroughMiddleware() ), max( 1, strlen( $req->body ) + 1 ) );
        $answer = $sdk->run( $transport );
        $headers = array();
        foreach ( $answer->getHeaders() as $k => $v )
            $headers[$k] = implode( ', ', $v );
        return new tronHttpResponse( $answer->getStatusCode(), $headers, (string)$answer->getBody() );
    }

    public function handleRaw( string $raw, tronSession $session ): ?string
    {
        $out = $session->requireInitialize ? $this->viaLoop( $raw, $session ) : $this->viaHttp( $raw, $session );
        return $out;
    }

    public function handle( $message, tronSession $session ): ?array
    {
        $raw = $this->handleRaw( tronServer::encode( self::objects( $message ) ), $session );
        return $raw === null ? null : json_decode( $raw, true );
    }

    /** A session with the stdio lifecycle: the SDK session opened by its initialize lives on between messages. */
    private function viaLoop( string $raw, tronSession $session ): ?string
    {
        $sdk = $this->sdk();
        if ( $this->loop === null || $this->loopSession !== $session )
        {
            $this->loop = new tronSdkLoop( new tronSdkLogger( $this->server ) );
            $this->loopSession = $session;
        }
        $this->handler->bind( $session );
        $this->loop->queue( $raw );
        $sdk->run( $this->loop );
        $sent = $this->loop->sent();
        return $sent ? implode( "\n", $sent ) : null;
    }

    /** A session without a lifecycle (an HTTP request, a program's own call): the same path as the HTTP endpoint. */
    private function viaHttp( string $raw, tronSession $session ): ?string
    {
        $headers = array( 'content-type' => 'application/json', 'accept' => 'application/json, text/event-stream' );
        if ( $session->protocolVersion !== null && $session->protocolVersion !== '' )
            $headers['mcp-protocol-version'] = $session->protocolVersion;
        $r = $this->serveHttp( new tronHttpRequest( 'POST', $headers, $raw, $session->remote ), $session );
        return $r->body === '' ? null : $r->body;
    }

    /**
     * A message built from PHP arrays as JSON would carry it: an empty params, arguments or capabilities is an
     * object ({}), not a list ([]), which the SDK rightly refuses.
     */
    private static function objects( $message )
    {
        if ( !is_array( $message ) )
            return $message;
        if ( $message !== array() && tronSchemaValidator::isList( $message ) )
            return array_map( array( self::class, 'objects' ), $message );
        if ( array_key_exists( 'params', $message ) && is_array( $message['params'] ) )
        {
            if ( $message['params'] === array() )
                $message['params'] = new stdClass();
            else
                foreach ( array( 'arguments', 'capabilities', '_meta' ) as $k )
                {
                    if ( array_key_exists( $k, $message['params'] ) && $message['params'][$k] === array() )
                        $message['params'][$k] = new stdClass();
                }
        }
        return $message;
    }
}
