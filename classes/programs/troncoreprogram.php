<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The program "tron": what every session needs to find its way. tron_whoami (the user and token the session acts
 * as), tron_status (version, protocol versions, programs and their state, tools left out with the reason), and the
 * same as resources tron://status and tron://programs.
 *
 * The loader status and the tool counts come from the environment service "tron.status" (a callable the factory
 * sets once the loader has run), so this program needs nothing of the kernel.
 */
final class tronCoreProgram implements tronProgram
{
    public function register( tronRegistry $registry, tronEnvironment $env ): void
    {
        $registry->tool( tronTool::define( array(
            'name' => 'tron_whoami',
            'title' => 'Who am I',
            'description' => 'The Exponential user this session acts as, the token it signed in with (id, scopes; never the token itself) and the transport.',
            'inputSchema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
            'policy' => array( 'tron', 'use' ),
            'outputSchema' => array( 'type' => 'object', 'additionalProperties' => false,
                'required' => array( 'user_id', 'login', 'token_id', 'scopes', 'transport', 'protocol_version' ), 'properties' => array(
                'user_id' => array( 'type' => 'integer' ), 'login' => array( 'type' => 'string' ), 'token_id' => array( 'type' => 'integer' ),
                'scopes' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ), 'transport' => array( 'type' => 'string' ),
                'protocol_version' => array( 'type' => array( 'string', 'null' ) ),
            ) ),
            'handler' => function ( array $args, tronCallContext $ctx ) {
                $p = $ctx->principal;
                return array( 'user_id' => $p->userId, 'login' => $p->login, 'token_id' => $p->tokenId,
                              'scopes' => $p->scopes, 'transport' => $ctx->session->transport,
                              'protocol_version' => $ctx->session->protocolVersion );
            },
        ) ) );

        $registry->tool( tronTool::define( array(
            'name' => 'tron_status',
            'title' => 'tron status',
            'description' => 'The tron version, the MCP protocol versions it speaks, every program with its state (loaded, disabled, failed, conflict, incompatible) and the tools a program left out with the reason. With tron/admin also where each program came from and how long it took to load.',
            'inputSchema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
            'policy' => array( 'tron', 'use' ),
            'outputSchema' => array( 'type' => 'object', 'required' => array( 'tron', 'protocol_versions', 'programs', 'tools', 'not_registered' ), 'properties' => array(
                'tron' => array( 'type' => 'string' ), 'protocol_versions' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                'programs' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'required' => array( 'name', 'state' ), 'properties' => array(
                    'name' => array( 'type' => 'string' ), 'state' => array( 'type' => 'string' ) ) ) ),
                'tools' => array( 'type' => 'integer', 'minimum' => 0 ), 'not_registered' => array( 'type' => 'object' ),
            ) ),
            'handler' => function ( array $args, tronCallContext $ctx ) use ( $env ) {
                return self::status( $env, $ctx->can( 'tron', 'admin' ) );
            },
        ) ) );

        $this->registerAdvanced( $registry, $env );
        tronJournalTools::register( $registry, $env );      // tron_journal_note, _list, _show, _search (doc/activity-journal.md)
        tronUnitPipe::register( $registry, $env );          // tron_pipe and tron://units, where the units are (doc/units.md)
        tronRulesProgram::register( $registry, $env );      // tron_rules, tron://rules/*, tron_project_rules, tron_rules_wizard (doc/agent-rules.md)

        $registry->resource( tronResource::define( array(
            'uri' => 'tron://status', 'name' => 'tron_status', 'title' => 'tron status',
            'description' => 'What tron_status returns.', 'policy' => array( 'tron', 'use' ),
            'reader' => function ( array $vars, tronCallContext $ctx ) use ( $env ) {
                return self::status( $env, $ctx->can( 'tron', 'admin' ) );
            },
        ) ) );

        $registry->resource( tronResource::define( array(
            'uri' => 'tron://programs', 'name' => 'tron_programs', 'title' => 'tron programs',
            'description' => 'The programs tron found and their state.', 'policy' => array( 'tron', 'use' ),
            'reader' => function ( array $vars, tronCallContext $ctx ) use ( $env ) {
                $s = self::status( $env, $ctx->can( 'tron', 'admin' ) );
                return array( 'programs' => $s['programs'] );
            },
        ) ) );
    }

    /**
     * tron_plan and tron_apply (dry runs, doc/dry-run.md) and tron_policy_check (the policy simulator,
     * doc/policy-simulator.md). They reach the server through the environment service "tron.server".
     */
    private function registerAdvanced( tronRegistry $registry, tronEnvironment $env ): void
    {
        $toolName = array( 'type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{1,63}$', 'description' => 'The name of a write tool, e.g. centre_ticket_update.' );
        $arguments = array( 'type' => 'object', 'description' => 'The arguments for that tool, exactly as for a call.', 'default' => array() );
        $planId = array( 'type' => 'string', 'pattern' => '^[a-f0-9]{32}$', 'description' => 'The plan_id of a dry run.' );
        $change = array( 'type' => 'object' );
        $planOut = array( 'type' => 'object', 'required' => array( 'plan_id', 'tool', 'mode', 'changes', 'summary', 'effects' ), 'properties' => array(
            'plan_id' => array( 'type' => array( 'string', 'null' ) ), 'tool' => array( 'type' => 'string' ),
            'mode' => array( 'type' => 'string', 'enum' => array( 'transaction', 'computed' ) ),
            'expires' => array( 'type' => array( 'string', 'null' ) ), 'changes' => array( 'type' => 'array', 'items' => $change ),
            'summary' => array( 'type' => 'object' ), 'effects' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
            'note' => array( 'type' => 'string' ),
        ) );
        $server = function () use ( $env ): tronServer {
            return $env->get( 'tron.server' );
        };
        $target = function ( string $name, tronCallContext $ctx, tronServer $srv ): tronTool {
            $tool = $srv->registry()->findTool( $name );
            if ( !$tool || !$ctx->principal->inScope( $tool->program ) )
                throw new tronToolError( "Unknown tool: $name" );
            return $tool;
        };

        $registry->tool( tronTool::define( array(
            'name' => 'tron_plan', 'title' => 'Dry run of a write tool',
            'description' => 'Runs a write tool as a dry run: nothing changes, and the answer is the exact change the call would make (rows, objects, versions, attributes, with from and to), a summary and the effects outside tron. Its plan_id applies exactly that change with tron_apply, for a few minutes, once, as the same user, with the same arguments; if the target changed in between, tron_apply refuses.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'tool' ),
                                    'properties' => array( 'tool' => $toolName, 'arguments' => $arguments ) ),
            'policy' => array( 'tron', 'use' ),
            'outputSchema' => $planOut,
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $server, $target ) {
                $srv = $server();
                $o = $srv->invoke( $target( $a['tool'], $ctx, $srv ), (array)$a['arguments'], $ctx->session, 'dry-run' );
                if ( !$o['ok'] )
                    throw new tronToolError( $o['error'], $o['details'] );
                return $o['value'];
            },
        ) ) );

        $registry->tool( tronTool::define( array(
            'name' => 'tron_apply', 'title' => 'Apply a dry run',
            'description' => 'Applies the plan of a tron_plan dry run: give its plan_id, the tool and exactly the same arguments. The change is made only if it is still exactly the planned one; a changed target, other arguments, another user or token, an expired or used plan are refused and nothing changes.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'plan_id', 'tool' ),
                                    'properties' => array( 'plan_id' => $planId, 'tool' => $toolName, 'arguments' => $arguments ) ),
            'policy' => array( 'tron', 'use' ), 'write' => true,
            'outputSchema' => array( 'type' => 'object', 'required' => array( 'plan_id', 'tool', 'applied', 'result' ), 'properties' => array(
                'plan_id' => array( 'type' => 'string' ), 'tool' => array( 'type' => 'string' ), 'applied' => array( 'type' => 'boolean' ),
            ) ),
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $server, $target ) {
                $srv = $server();
                $o = $srv->invoke( $target( $a['tool'], $ctx, $srv ), (array)$a['arguments'], $ctx->session, 'apply', $a['plan_id'] );
                if ( !$o['ok'] )
                    throw new tronToolError( $o['error'], $o['details'] );
                return array( 'plan_id' => $a['plan_id'], 'tool' => $a['tool'], 'applied' => true, 'result' => $o['value'] );
            },
        ) ) );

        $registry->tool( tronTool::define( array(
            'name' => 'tron_policy_check', 'title' => 'Could this user do that?',
            'description' => 'The policy simulator: whether a user could call a tool (or read a resource, get a prompt; a program name or "*" checks all of them), answered from the installation\'s real roles and policies without calling anything. Each answer names the gate that decided (scope, policy, target), the matching roles and policies with their limitations, and the reason. With target (e.g. a node id) the tool\'s object-level check runs too. Without user it checks yourself; another user needs tron/admin.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'tool' ), 'properties' => array(
                'user' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 150, 'description' => 'The login to check (default: yourself).' ),
                'tool' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'description' => 'A tool, resource URI, prompt or program name, or "*".' ),
                'target' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'description' => 'The object the tool would act on, e.g. a node id.' ),
                'scopes' => array( 'type' => 'array', 'maxItems' => 32, 'items' => array( 'type' => 'string', 'pattern' => '^(\*|[a-z][a-z0-9_]{0,31})$' ),
                                   'description' => 'Simulate a token limited to these programs (default: the session\'s own scopes when checking yourself, all otherwise).' ),
            ) ),
            'policy' => array( 'tron', 'use' ),
            'outputSchema' => array( 'type' => 'object', 'required' => array( 'user', 'scopes', 'allowed', 'denied', 'checks' ), 'properties' => array(
                'user' => array( 'type' => 'object', 'required' => array( 'id', 'login' ) ),
                'scopes' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                'allowed' => array( 'type' => 'integer', 'minimum' => 0 ), 'denied' => array( 'type' => 'integer', 'minimum' => 0 ),
                'checks' => array( 'type' => 'array', 'minItems' => 1, 'items' => array( 'type' => 'object',
                    'required' => array( 'kind', 'name', 'allowed', 'decided_by', 'reason', 'matches' ), 'properties' => array(
                    'kind' => array( 'type' => 'string', 'enum' => array( 'tool', 'resource', 'prompt', 'unknown' ) ),
                    'name' => array( 'type' => 'string' ), 'allowed' => array( 'type' => 'boolean' ),
                    'decided_by' => array( 'type' => 'string', 'enum' => array( 'use', 'scope', 'policy', 'target', 'unavailable', 'unknown' ) ),
                    'access' => array( 'type' => array( 'string', 'null' ), 'enum' => array( 'yes', 'limited', 'no', null ) ),
                    'reason' => array( 'type' => 'string' ), 'matches' => array( 'type' => 'array' ),
                ) ) ),
            ) ),
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $server, $env ) {
                $self = $ctx->principal;
                $subject = $self;
                if ( isset( $a['user'] ) && $a['user'] !== $self->login )
                {
                    if ( !$ctx->can( 'tron', 'admin' ) )
                        throw new tronToolError( 'Checking another user needs the policy tron/admin; without user you check yourself' );
                    $u = $env->has( 'users' ) ? $env->get( 'users' )->byLogin( $a['user'] ) : null;
                    if ( !$u )
                        throw new tronToolError( "There is no user with the login '{$a['user']}'" );
                    $subject = new tronPrincipal( (int)$u['id'], (string)$u['login'], 0, array( '*' ) );
                }
                if ( isset( $a['scopes'] ) && $a['scopes'] )
                {
                    // a simulated token can only be narrower than the caller's own when checking yourself
                    $scopes = array_values( array_unique( $a['scopes'] ) );
                    if ( $subject === $self && !in_array( '*', $self->scopes, true ) )
                        $scopes = in_array( '*', $scopes, true ) ? $self->scopes : array_values( array_intersect( $scopes, $self->scopes ) );
                    $subject = new tronPrincipal( $subject->userId, $subject->login, $subject->tokenId, $scopes ?: array( 'tron' ) );
                }
                return ( new tronPolicySimulator( $server() ) )->check( $subject, $a['tool'], $a['target'] ?? null );
            },
        ) ) );
    }

    public static function status( tronEnvironment $env, bool $admin ): array
    {
        $info = $env->has( 'tron.status' ) ? call_user_func( $env->get( 'tron.status' ) ) : array();
        $programs = array();
        foreach ( (array)( $info['programs'] ?? array() ) as $row )
        {
            if ( !$admin )
                unset( $row['origin'], $row['ms'] );
            $programs[] = $row;
        }
        return array(
            'tron' => tronVersion::VERSION,
            'protocol_versions' => tronProtocol::VERSIONS,
            'programs' => $programs,
            'tools' => (int)( $info['tools'] ?? 0 ),
            'not_registered' => (object)( $info['unavailable'] ?? array() ),
            'rules' => tronRulesProgram::summary( $env ),          // which MASTER.md and AGENTS.md are active, and from where
        );
    }
}
