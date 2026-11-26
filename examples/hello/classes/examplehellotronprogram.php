<?php
/**
 * The cookbook's example program "hello", laid out like an extension: examples/hello/tron/program.json names this
 * class. Copy the folder into extension/<yours>/, rename, regenerate the autoloads, and tron loads it.
 *
 * It shows the four things a program can register and the rules for each:
 * - hello_greet: a read tool with a schema (bounds, default) and a policy;
 * - hello_remember: a write tool (audited before it runs) that refuses bad input with tronToolError;
 * - hello://note/{name}: a resource template;
 * - hello_introduce: a prompt with an argument.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

final class exampleHelloTronProgram implements tronProgram
{
    /** @var array<string,string> notes in memory; a real program keeps them in its own table through $env->get( 'db' ) */
    private $notes = array();

    public function register( tronRegistry $registry, tronEnvironment $env ): void
    {
        $registry->tool( tronTool::define( array(
            'name' => 'hello_greet',
            'title' => 'Greet',
            'description' => 'Greets someone, or the caller when no name is given.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
                'name' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 40 ),
                'times' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 3, 'default' => 1 ),
            ) ),
            'policy' => array( 'tron', 'use' ),
            'handler' => function ( array $args, tronCallContext $ctx ) {
                $name = $args['name'] ?? $ctx->principal->login;
                return array( 'greeting' => trim( str_repeat( "Hello, $name! ", $args['times'] ) ) );
            },
        ) ) );

        $registry->tool( tronTool::define( array(
            'name' => 'hello_remember',
            'title' => 'Remember a note',
            'description' => 'Keeps a short note under a name. Names are lower-case words.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'name', 'text' ), 'properties' => array(
                'name' => array( 'type' => 'string', 'pattern' => '^[a-z]+$' ),
                'text' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ),
            ) ),
            'policy' => array( 'tron', 'centre_write' ),
            'write' => true, 'idempotent' => true,
            'handler' => function ( array $args, tronCallContext $ctx ) {
                if ( $args['name'] === 'secret' )
                    throw new tronToolError( 'The name "secret" is reserved; pick another one.' );
                $this->notes[$args['name']] = $args['text'];
                return array( 'remembered' => $args['name'], 'notes' => count( $this->notes ) );
            },
        ) ) );

        $registry->resource( tronResource::define( array(
            'uri' => 'hello://note/{name}', 'name' => 'hello_note', 'title' => 'A note', 'mimeType' => 'text/plain',
            'policy' => array( 'tron', 'use' ),
            'reader' => function ( array $vars, tronCallContext $ctx ) {
                return $this->notes[$vars['name']] ?? null;        // null: "no such resource"
            },
        ) ) );

        $registry->prompt( tronPrompt::define( array(
            'name' => 'hello_introduce', 'title' => 'Introduce yourself',
            'arguments' => array( array( 'name' => 'audience', 'description' => 'Who you talk to', 'required' => true ) ),
            'policy' => array( 'tron', 'use' ),
            'renderer' => function ( array $args, tronCallContext $ctx ) {
                return "Introduce yourself to {$args['audience']} in one sentence, as an assistant working for {$ctx->principal->login}.";
            },
        ) ) );
    }
}
