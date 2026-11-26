<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The streamable HTTP transport, stateless: one endpoint, a POST carries a JSON-RPC message (or a batch where the
 * protocol version allows one) and gets the answer as application/json; a POST of only notifications or responses
 * gets 202 without a body. tron opens no server-sent event stream (GET answers 405) and issues no Mcp-Session-Id,
 * so every request stands alone and any node of the cluster can serve it.
 *
 * Checks, in this order, each with its own status:
 *   405 a method other than POST            403 an Origin that is not allowed (DNS rebinding)
 *   421 a Host that is not allowed           415 a body that is not application/json
 *   413 a body over MaxBodyBytes             400 an MCP-Protocol-Version tron does not speak
 *   401 no or a wrong bearer token           403 a user without the policy tron/use
 * Failed sign-ins are audited (method "auth", the token's prefix only, never the token).
 */
final class tronHttpTransport
{
    /** @var tronServer */ private $server;
    /** @var callable (string token, ?string &reason): ?tronPrincipal */ private $authenticate;
    /** @var tronAccessChecker */ private $access;
    /** @var tronAuditSink */ private $audit;
    /** @var array */ private $options;
    /** @var callable */ private $clock;

    /**
     * Options: allowedOrigins (list; empty: a request with an Origin header is refused), allowedHosts (list; empty:
     * any Host), maxBodyBytes.
     */
    public function __construct( tronServer $server, callable $authenticate, tronAccessChecker $access, tronAuditSink $audit, array $options = array(), ?callable $clock = null )
    {
        $this->server = $server;
        $this->authenticate = $authenticate;
        $this->access = $access;
        $this->audit = $audit;
        $this->options = $options + array( 'allowedOrigins' => array(), 'allowedHosts' => array(), 'maxBodyBytes' => 1048576 );
        $this->clock = $clock ?: 'time';
    }

    public function handle( tronHttpRequest $req ): tronHttpResponse
    {
        if ( $req->method !== 'POST' )
        {
            $status = $req->method === 'OPTIONS' ? 204 : 405;
            return new tronHttpResponse( $status, array( 'Allow' => 'POST' ),
                $status === 405 ? 'tron serves MCP over POST only; it offers no event stream.' : '' );
        }

        $origin = $req->header( 'origin' );
        if ( $origin !== null && $origin !== '' && !in_array( rtrim( $origin, '/' ), (array)$this->options['allowedOrigins'], true ) )
            return self::rpcError( 403, tronProtocol::INVALID_REQUEST, 'Forbidden: this Origin is not allowed' );

        $hosts = (array)$this->options['allowedHosts'];
        if ( $hosts )
        {
            $host = strtolower( (string)preg_replace( '/:\d+$/', '', (string)$req->header( 'host' ) ) );
            if ( !in_array( $host, array_map( 'strtolower', $hosts ), true ) )
                return self::rpcError( 421, tronProtocol::INVALID_REQUEST, 'Misdirected request: this Host is not served here' );
        }

        $type = strtolower( trim( explode( ';', (string)$req->header( 'content-type' ) )[0] ) );
        if ( $type !== 'application/json' )
            return self::rpcError( 415, tronProtocol::INVALID_REQUEST, 'Unsupported media type: send application/json' );

        if ( strlen( $req->body ) > (int)$this->options['maxBodyBytes'] )
            return self::rpcError( 413, tronProtocol::INVALID_REQUEST, 'Payload too large: the limit is ' . (int)$this->options['maxBodyBytes'] . ' bytes' );

        $version = $req->header( 'mcp-protocol-version' );
        if ( $version !== null && !tronProtocol::isSupported( $version ) )
            return self::rpcError( 400, tronProtocol::INVALID_REQUEST, "Bad request: unsupported MCP-Protocol-Version $version (supported: " . implode( ', ', tronProtocol::VERSIONS ) . ')' );

        $auth = (string)$req->header( 'authorization' );
        $token = preg_match( '/^Bearer\s+(\S+)$/i', trim( $auth ), $m ) ? $m[1] : '';
        $reason = null;
        $principal = $token === '' ? null : call_user_func_array( $this->authenticate, array( $token, &$reason ) );
        if ( !$principal )
        {
            $this->auditAuth( $req, $token === '' ? 'no bearer token' : (string)$reason );
            return self::rpcError( 401, tronProtocol::INVALID_REQUEST, 'Unauthorized: send a tron token as a bearer token',
                                   array( 'WWW-Authenticate' => 'Bearer realm="tron"' ) );
        }
        if ( $this->access->check( $principal, 'tron', 'use' ) !== tronAccessChecker::YES )
        {
            $this->auditAuth( $req, "user {$principal->login} lacks tron/use", $principal );
            return self::rpcError( 403, tronProtocol::INVALID_REQUEST, 'Forbidden: this user does not hold the policy tron/use' );
        }

        // Messages before initialize are fine over HTTP: there is no session to initialize.
        $session = new tronSession( $principal, 'http', false, $version ?? null, $req->remote );
        if ( $version === null )
            $session->protocolVersion = self::isInitialize( $req->body ) ? null : tronProtocol::HTTP_DEFAULT;
        $answer = $this->server->handleRaw( $req->body, $session );
        if ( $answer === null )
            return new tronHttpResponse( 202 );
        return new tronHttpResponse( 200, array( 'Content-Type' => 'application/json; charset=utf-8' ), $answer );
    }

    private static function isInitialize( string $body ): bool
    {
        $d = json_decode( $body, true );
        return is_array( $d ) && ( $d['method'] ?? null ) === 'initialize';
    }

    private function auditAuth( tronHttpRequest $req, string $reason, ?tronPrincipal $p = null ): void
    {
        try
        {
            $this->audit->record( array(
                'created' => (int)call_user_func( $this->clock ), 'user_id' => $p ? $p->userId : 0, 'login' => $p ? $p->login : '',
                'token_id' => $p ? $p->tokenId : 0, 'transport' => 'http', 'remote' => $req->remote, 'method' => 'auth',
                'target' => '', 'program' => 'tron', 'outcome' => $p ? 'denied' : 'unauthenticated', 'duration_ms' => 0,
                'detail' => tronRedactor::maskTokens( $reason ),
            ) );
        }
        catch ( Throwable $e )
        {
            // A lost audit line must not turn a refusal into an error page.
        }
    }

    private static function rpcError( int $status, int $code, string $message, array $headers = array() ): tronHttpResponse
    {
        return tronHttpResponse::json( $status, tronServer::errorResponse( null, $code, $message ), $headers );
    }
}
