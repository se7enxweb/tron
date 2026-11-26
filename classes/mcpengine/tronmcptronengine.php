<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * tron itself as an engine of the neutral contract: the tools of its programs (tron_*, centre_*, katalyst_* and
 * any program an extension ships), called in process through tron's server, so the caller's policies, the token
 * scope rules and the audit log apply exactly as over stdio or HTTP.
 *
 * callTool() also runs the tools of the other engines tron registered (<id>__<tool>): every call of a host goes
 * through here and is checked and audited in one place. A call of a tool tron does not have is audited too
 * (outcome "invalid") before it is refused.
 */
final class tronMcpTronEngine implements tronMcpEngine
{
    /** @var tronServer */ private $server;
    /** @var tronAuditSink */ private $audit;
    /** @var string who calls in process (the audit's "remote"), e.g. the host extension */ private $remote;

    public function __construct( tronServer $server, tronAuditSink $audit, string $remote = 'inprocess' )
    {
        $this->server = $server;
        $this->audit = $audit;
        $this->remote = substr( $remote, 0, 64 );
    }

    public function server(): tronServer
    {
        return $this->server;
    }

    /** @return array id => label of the other engines registered in the server */
    public function engines(): array
    {
        return (array)( $this->server->option( 'engines' ) ?? array() );
    }

    public function describeServer(): array
    {
        return array( 'name' => 'tron', 'title' => (string)$this->server->option( 'title' ), 'version' => tronVersion::VERSION,
                      'description' => 'The tools of tron and its programs.' );
    }

    public function listTools(): array
    {
        $engines = $this->engines();
        $out = array();
        foreach ( $this->server->registry()->tools() as $t )
        {
            if ( !isset( $engines[$t->program] ) )
                $out[] = self::definition( $t );
        }
        return $out;
    }

    /** The tools of every program and engine the principal may use: definitions with "program" added. */
    public function visibleTools( tronPrincipal $principal ): array
    {
        $out = array();
        foreach ( $this->server->visibleTools( $this->session( $principal ) ) as $t )
            $out[] = self::definition( $t ) + array( 'program' => $t->program );
        return $out;
    }

    public function callTool( string $name, array $arguments, tronPrincipal $principal )
    {
        $session = $this->session( $principal );
        $r = $this->server->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                                           'params' => array( 'name' => $name, 'arguments' => $arguments ) ), $session );
        if ( isset( $r['error'] ) )
        {
            // refused before the server's own audit entry (an unknown tool, a tool outside the token's scope)
            $this->auditRefusal( $principal, $name, (string)$r['error']['message'] );
            throw new tronToolError( (string)$r['error']['message'] );
        }
        $result = (array)( $r['result'] ?? array() );
        $text = (string)( $result['content'][0]['text'] ?? '' );
        if ( !empty( $result['isError'] ) )
        {
            $body = json_decode( $text, true );
            $message = is_array( $body ) && isset( $body['error'] ) ? (string)$body['error'] : $text;
            if ( is_array( $body ) )
                unset( $body['error'] );
            throw new tronToolError( $message, is_array( $body ) ? $body : array() );
        }
        if ( isset( $result['structuredContent'] ) && is_array( $result['structuredContent'] ) )
            return $result['structuredContent'];
        $decoded = json_decode( $text, true );
        return is_array( $decoded ) ? $decoded : $text;
    }

    public function writes( string $name ): bool
    {
        $t = $this->server->registry()->findTool( $name );
        return $t ? $t->write : false;
    }

    public function schema( string $name ): array
    {
        $t = $this->server->registry()->findTool( $name );
        return $t ? $t->inputSchema : array( 'type' => 'object' );
    }

    private function session( tronPrincipal $principal ): tronSession
    {
        return new tronSession( $principal, 'inprocess', false, '2025-06-18', $this->remote );
    }

    private static function definition( tronTool $t ): array
    {
        return array( 'name' => $t->name, 'title' => $t->title, 'description' => $t->description, 'inputSchema' => $t->inputSchema,
                      'write' => $t->write, 'policy' => $t->policy );
    }

    private function auditRefusal( tronPrincipal $principal, string $name, string $reason ): void
    {
        try
        {
            $this->audit->record( array(
                'created' => time(), 'user_id' => $principal->userId, 'login' => $principal->login, 'token_id' => $principal->tokenId,
                'transport' => 'inprocess', 'remote' => $this->remote, 'method' => 'tools/call', 'target' => $name,
                'program' => (string)strstr( $name . '_', '_', true ), 'outcome' => 'invalid', 'duration_ms' => 0,
                'detail' => tronServer::encode( array( 'args' => array(), 'note' => $reason ) ),
            ) );
        }
        catch ( Throwable $e )
        {
            // the refusal stands without its entry; the server log has the sink's own failure
        }
    }
}
