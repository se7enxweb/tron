<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * A class of the bridge API (tronMcpBridgeApi) as an engine of the neutral contract. tron builds it on its own for
 * every registered class of that shape; nobody writes an adapter.
 *
 * Bridge tools are read tools that run in process as the signed-in user. A call for anyone else (a token of
 * another user over tron's HTTP transport, a stdio session started for a named user) is refused with a tool error,
 * because the bridge cannot act as that user: tron never switches the signed-in user for an engine.
 */
final class tronMcpBridgeEngine implements tronMcpEngine
{
    /** The public static methods of the bridge API, with their parameter counts (all, required). */
    const API = array(
        'tools'        => array( 0, 0 ),
        'toolNames'    => array( 0, 0 ),
        'isBridgeSafe' => array( 1, 1 ),
        'description'  => array( 1, 1 ),
        'inputSchema'  => array( 1, 1 ),
        'tool'         => array( 3, 1 ),
        'call'         => array( 4, 1 ),
    );

    /** @var string */ private $class;
    /** @var array */ private $settings;
    /** @var callable|null returns the id of the signed-in user */ private $currentUserId;

    /**
     * @param string        $class         a class of the bridge API
     * @param array         $settings      the [McpEngine_<id>] group
     * @param callable|null $currentUserId returns the signed-in user's id; default eZUser::currentUserID() inside an
     *                                     installation, none (no check) without one
     */
    public function __construct( string $class, array $settings = array(), ?callable $currentUserId = null )
    {
        if ( !self::matches( $class ) )
            throw new InvalidArgumentException( "The class $class does not have the bridge API" );
        $this->class = $class;
        $this->settings = $settings;
        if ( $currentUserId === null && class_exists( 'eZUser' ) )
            $currentUserId = function () { return (int)eZUser::currentUserID(); };
        $this->currentUserId = $currentUserId;
    }

    /** Whether a class has the bridge API: it implements tronMcpBridgeApi, or it has the same public static methods. */
    public static function matches( string $class ): bool
    {
        if ( !class_exists( $class ) )
            return false;
        if ( is_subclass_of( $class, 'tronMcpBridgeApi' ) )
            return true;
        foreach ( self::API as $method => $counts )
        {
            if ( !method_exists( $class, $method ) )
                return false;
            $m = new ReflectionMethod( $class, $method );
            if ( !$m->isPublic() || !$m->isStatic() || $m->getNumberOfParameters() !== $counts[0]
                 || $m->getNumberOfRequiredParameters() !== $counts[1] )
                return false;
        }
        return true;
    }

    public function bridgeClass(): string
    {
        return $this->class;
    }

    public function describeServer(): array
    {
        $class = $this->class;
        return array(
            'name' => $class,
            'title' => (string)( $this->settings['Label'] ?? $class ),
            'version' => defined( $class . '::VERSION' ) ? (string)constant( $class . '::VERSION' ) : '',
            'description' => 'In-process read tools of a bridge-API engine, run as the signed-in user.',
        );
    }

    public function listTools(): array
    {
        $class = $this->class;
        $out = array();
        foreach ( (array)$class::toolNames() as $name )
        {
            $name = (string)$name;
            if ( !$class::isBridgeSafe( $name ) )
                continue;
            $out[] = array(
                'name' => $name,
                'title' => $name,
                'description' => (string)$class::description( $name ),
                'inputSchema' => self::objectSchema( (array)$class::inputSchema( $name ) ),
                'write' => false,
                'policy' => null,
            );
        }
        return $out;
    }

    public function callTool( string $name, array $arguments, tronPrincipal $principal )
    {
        $class = $this->class;
        if ( !$class::isBridgeSafe( $name ) )
            throw new tronToolError( "The tool $name is not offered by this engine." );
        if ( $this->currentUserId )
        {
            $current = (int)call_user_func( $this->currentUserId );
            if ( $current !== $principal->userId )
                throw new tronToolError( 'This engine runs in process as the signed-in user only; it cannot act as '
                                         . "the user '{$principal->login}' of this session." );
        }
        try
        {
            $answer = $class::call( $name, $arguments );
        }
        catch ( InvalidArgumentException $e )
        {
            throw new tronToolError( $e->getMessage() );
        }
        catch ( RuntimeException $e )
        {
            throw new tronToolError( $e->getMessage() );
        }
        // A refusal or a miss comes back as an answer with an "error" key; tron reports it as a tool error.
        if ( is_array( $answer ) && isset( $answer['error'] ) && is_string( $answer['error'] ) )
        {
            $details = $answer;
            unset( $details['error'] );
            throw new tronToolError( $answer['error'], $details );
        }
        return is_array( $answer ) || is_string( $answer ) ? $answer : array( 'result' => $answer );
    }

    public function writes( string $name ): bool
    {
        return false;
    }

    public function schema( string $name ): array
    {
        $class = $this->class;
        return self::objectSchema( (array)$class::inputSchema( $name ) );
    }

    private static function objectSchema( array $schema ): array
    {
        if ( ( $schema['type'] ?? null ) !== 'object' )
            $schema['type'] = 'object';
        return $schema;
    }
}
