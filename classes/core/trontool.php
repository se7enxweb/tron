<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * One tool, as a program declares it. Built with tronTool::define(), which checks the declaration:
 *
 *   tronTool::define( array(
 *       'name'        => 'centre_ticket_list',          // the program's name, an underscore, then a-z0-9_
 *       'title'       => 'List tickets',
 *       'description' => 'What it does, for the model.',
 *       'inputSchema' => array( 'type' => 'object', 'properties' => array( ... ) ),
 *       'policy'      => array( 'tron', 'centre_read' ), // the Exponential module/function a caller needs
 *       'write'       => false,                          // true: changes something (pre-audited, not read-only)
 *       'destructive' => false, 'idempotent' => true,    // MCP annotations of a write tool
 *       'limitedOk'   => false,                          // true: a policy with limitations counts as allowed
 *       'handler'     => function ( array $args, tronCallContext $ctx ) { return array( ... ); },
 *       'outputSchema' => array( 'type' => 'object', ... ),   // what a successful answer looks like (contract tests)
 *       'dryRun'      => array( 'tables' => array( ... ) ), // a write tool's dry run: tables diffed in a rolled-back transaction
 *       'plan'        => function ( array $args, tronCallContext $ctx ) { ... }, // or: a computed plan (doc/dry-run.md)
 *       'targetCheck' => function ( string $target, tronPrincipal $p, tronEnvironment $env ) { ... }, // policy simulator
 *   ) );
 *
 * A handler returns an array (sent as JSON text and, from 2025-06-18 on, as structuredContent) or a string (sent as
 * text), or throws tronToolError for an answer the agent should see as an error.
 */
final class tronTool
{
    const NAME_PATTERN = '/^[a-z][a-z0-9_]{1,63}$/';

    /** @var string */ public $name;
    /** @var string */ public $title;
    /** @var string */ public $description;
    /** @var array */  public $inputSchema;
    /** @var array|null [module, function] */ public $policy;
    /** @var bool */   public $write;
    /** @var bool */   public $destructive;
    /** @var bool */   public $idempotent;
    /** @var bool */   public $limitedOk;
    /** @var callable */ public $handler;
    /** @var array|null the JSON schema of a successful answer */ public $outputSchema = null;
    /** @var array|null transaction dry run: tables, volatile columns, refs (doc/dry-run.md) */ public $dryRun = null;
    /** @var callable|null computed plan: function ( array $args, tronCallContext $ctx ): array( changes, state, effects ) */ public $plan = null;
    /** @var callable|null object-level check for the policy simulator: function ( string $target, tronPrincipal $p, tronEnvironment $env ): array( allowed, reason ) */ public $targetCheck = null;
    /** @var string set by the registry */ public $program = '';

    private function __construct()
    {
    }

    public static function define( array $d ): self
    {
        $t = new self();
        $t->name = (string)( $d['name'] ?? '' );
        if ( !preg_match( self::NAME_PATTERN, $t->name ) )
            throw new InvalidArgumentException( "Tool name '{$t->name}' must match " . self::NAME_PATTERN );
        $t->title = (string)( $d['title'] ?? $t->name );
        $t->description = trim( (string)( $d['description'] ?? '' ) );
        if ( $t->description === '' )
            throw new InvalidArgumentException( "Tool '{$t->name}' needs a description" );
        $schema = $d['inputSchema'] ?? array( 'type' => 'object' );
        if ( !is_array( $schema ) || ( $schema['type'] ?? null ) !== 'object' )
            throw new InvalidArgumentException( "Tool '{$t->name}': inputSchema must be an object schema" );
        $t->inputSchema = $schema;
        $policy = $d['policy'] ?? null;
        if ( $policy !== null && ( !is_array( $policy ) || count( $policy ) !== 2 || !is_string( $policy[0] ) || !is_string( $policy[1] ) ) )
            throw new InvalidArgumentException( "Tool '{$t->name}': policy must be array( module, function ) or null" );
        $t->policy = $policy === null ? null : array_values( $policy );
        $t->write = !empty( $d['write'] );
        $t->destructive = $t->write && !empty( $d['destructive'] );
        $t->idempotent = !$t->write || !empty( $d['idempotent'] );
        $t->limitedOk = !empty( $d['limitedOk'] );
        if ( !isset( $d['handler'] ) || !is_callable( $d['handler'] ) )
            throw new InvalidArgumentException( "Tool '{$t->name}' needs a callable handler" );
        $t->handler = $d['handler'];
        if ( isset( $d['outputSchema'] ) )
        {
            if ( !is_array( $d['outputSchema'] ) || ( $d['outputSchema']['type'] ?? null ) !== 'object' )
                throw new InvalidArgumentException( "Tool '{$t->name}': outputSchema must be an object schema" );
            $t->outputSchema = $d['outputSchema'];
        }
        if ( isset( $d['dryRun'] ) )
        {
            if ( !is_array( $d['dryRun'] ) || !isset( $d['dryRun']['tables'] ) || !is_array( $d['dryRun']['tables'] ) || !$d['dryRun']['tables'] )
                throw new InvalidArgumentException( "Tool '{$t->name}': dryRun needs array( 'tables' => array( ... ) )" );
            $t->dryRun = $d['dryRun'] + array( 'volatile' => array(), 'refs' => array() );
        }
        if ( isset( $d['plan'] ) )
        {
            if ( !is_callable( $d['plan'] ) )
                throw new InvalidArgumentException( "Tool '{$t->name}': plan must be callable" );
            $t->plan = $d['plan'];
        }
        if ( ( $t->dryRun !== null || $t->plan !== null ) && !$t->write )
            throw new InvalidArgumentException( "Tool '{$t->name}': only a write tool has a dry run" );
        if ( isset( $d['targetCheck'] ) )
        {
            if ( !is_callable( $d['targetCheck'] ) )
                throw new InvalidArgumentException( "Tool '{$t->name}': targetCheck must be callable" );
            $t->targetCheck = $d['targetCheck'];
        }
        return $t;
    }

    /** Whether the tool can run as a dry run (doc/dry-run.md). */
    public function hasDryRun(): bool
    {
        return $this->dryRun !== null || $this->plan !== null;
    }

    /** The tool object of a tools/list answer; with $withOutput (MCP 2025-06-18 and later) its outputSchema too. */
    public function definition( bool $withOutput = false ): array
    {
        $schema = $this->inputSchema;
        if ( !isset( $schema['properties'] ) || $schema['properties'] === array() )
            $schema['properties'] = new stdClass();
        return array(
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'inputSchema' => $schema,
            'annotations' => array(
                'title' => $this->title,
                'readOnlyHint' => !$this->write,
                'destructiveHint' => $this->destructive,
                'idempotentHint' => $this->idempotent,
                'openWorldHint' => false,
            ),
        ) + ( $withOutput && $this->outputSchema !== null ? array( 'outputSchema' => $this->outputSchema ) : array() );
    }
}
