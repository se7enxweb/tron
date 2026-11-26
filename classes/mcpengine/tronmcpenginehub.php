<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The engines as one host sees them: every engine active at once, each engine's tools under its own prefix.
 *
 *   tron__<tool>   the tools of tron's programs (tron__centre_ticket_list)
 *   <id>__<tool>   the tools of the engine registered as [McpEngine_<id>] (notes__note_list)
 *
 * A host (expai's assistant) asks for tools() as its signed-in user and calls them with call(); both go through
 * tron's server, so a tool is offered only to users who hold its policy, every call is checked again and every call
 * is written to tron's audit log, whichever engine serves it.
 *
 * Build one per request (kernel()): settings switched in tron.ini take effect on the next request, and nothing is
 * kept between requests of a persistent worker.
 */
final class tronMcpEngineHub
{
    const TRON = 'tron';

    /** @var tronMcpTronEngine */ private $tron;

    public function __construct( tronMcpTronEngine $tron )
    {
        $this->tron = $tron;
    }

    /** The hub of this installation, for a host extension named $host (the audit's "remote"). */
    public static function kernel( string $host ): self
    {
        $settings = tronKernel::settings();
        return new self( new tronMcpTronEngine( tronKernel::server( null, null, true ), tronKernel::audit( $settings ), $host ) );
    }

    public function tronEngine(): tronMcpTronEngine
    {
        return $this->tron;
    }

    /** @return array id => label: tron and every engine registered in the server */
    public function engines(): array
    {
        return array( self::TRON => (string)$this->tron->server()->option( 'title' ) ) + $this->tron->engines();
    }

    /**
     * The tools the principal may use, prefixed: a list of definitions with name (prefixed), engine, tool (the
     * engine's own name), title, description, inputSchema, write.
     */
    public function tools( tronPrincipal $principal ): array
    {
        $engines = $this->tron->engines();
        $out = array();
        foreach ( $this->tron->visibleTools( $principal ) as $def )
        {
            $engine = isset( $engines[$def['program']] ) ? (string)$def['program'] : self::TRON;
            $def['tool'] = $engine === self::TRON ? $def['name'] : substr( $def['name'], strlen( $engine ) + 2 );
            $def['name'] = self::hostName( $engine, $def['tool'] );
            $def['engine'] = $engine;
            unset( $def['program'], $def['policy'] );
            $out[] = $def;
        }
        return $out;
    }

    /** Runs a prefixed tool as the principal. Throws tronToolError for a refusal, a miss or a tool error. */
    public function call( string $name, array $arguments, tronPrincipal $principal )
    {
        return $this->tron->callTool( $this->serverName( $name ), $arguments, $principal );
    }

    /** The name a host sees for an engine's tool. */
    public static function hostName( string $engine, string $tool ): string
    {
        return $engine . tronMcpEngineFactory::SEPARATOR . $tool;
    }

    /** The registry's name of a prefixed tool: tron__x is x, <id>__x stays <id>__x. */
    public function serverName( string $name ): string
    {
        $prefix = self::TRON . tronMcpEngineFactory::SEPARATOR;
        if ( strpos( $name, $prefix ) === 0 )
            return substr( $name, strlen( $prefix ) );
        return $name;
    }

    /** The engine id of a prefixed name ('' when it has no prefix). */
    public static function engineOf( string $name ): string
    {
        $at = strpos( $name, tronMcpEngineFactory::SEPARATOR );
        return $at === false ? '' : substr( $name, 0, $at );
    }
}
