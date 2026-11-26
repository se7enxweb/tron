<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The agent rules layer as MCP (doc/agent-rules.md), registered by the core program "tron":
 *
 *   resources  tron://rules/agents     the site's AGENTS.md: source, active, strength, text
 *              tron://rules/master     MASTER.md: source (override, default, off), decided_by, text
 *              tron://rules/combined   what the session receives: both, MASTER.md first, with the precedence
 *              tron://rules/status     the switches and which one decided, without the texts
 *   prompts    tron_project_rules      the combined rules as a user message (argument master=disabled for this once)
 *              tron_rules_wizard       an interview that writes a starter AGENTS.md with the user
 *   tool       tron_rules              status and combined rules; master=disabled|enabled sets this session's flag
 *
 * Everything here only reads: AGENTS.md and the MASTER.md override are written in Setup > tron > Agent rules, by a
 * person, after "Verify it's you". The policy is tron/use; paths on disk are shown to tron/admin only.
 */
final class tronRulesProgram
{
    public static function register( tronRegistry $registry, tronEnvironment $env ): void
    {
        $state = function ( tronCallContext $ctx, array $extra = array() ) use ( $env ): array {
            $rules = tronRules::fromEnvironment( $env );
            $choices = $extra + tronRules::sessionChoices( $ctx->session );
            return array( $rules, $choices, $rules->state( $ctx->principal->userId, $choices, $ctx->can( 'tron', 'admin' ) ) );
        };

        $registry->resource( tronResource::define( array(
            'uri' => 'tron://rules/agents', 'name' => 'tron_rules_agents', 'title' => 'Project rules: AGENTS.md',
            'description' => 'The site\'s own rules for agents (AGENTS.md), with where they come from (source: override or none) and how much they say (strength).',
            'policy' => array( 'tron', 'use' ),
            'reader' => function ( array $vars, tronCallContext $ctx ) use ( $state ) {
                list( $rules, , $st ) = $state( $ctx );
                return $st['agents'] + array( 'superseded_by_master' => $st['master']['active'],
                                              'text' => $st['agents']['active'] ? $rules->agentsText() : '' );
            },
        ) ) );

        $registry->resource( tronResource::define( array(
            'uri' => 'tron://rules/master', 'name' => 'tron_rules_master', 'title' => 'Project rules: MASTER.md',
            'description' => 'The Exponential rules that supersede AGENTS.md, with where they come from (source: override, default or off) and which switch decided.',
            'policy' => array( 'tron', 'use' ),
            'reader' => function ( array $vars, tronCallContext $ctx ) use ( $state ) {
                list( $rules, , $st ) = $state( $ctx );
                return $st['master'] + array( 'text' => $st['master']['active'] ? $rules->masterText() : '' );
            },
        ) ) );

        $registry->resource( tronResource::define( array(
            'uri' => 'tron://rules/combined', 'name' => 'tron_rules_combined', 'title' => 'Project rules: combined',
            'description' => 'The rules this session receives: MASTER.md first, then AGENTS.md; MASTER.md wins where they conflict.',
            'policy' => array( 'tron', 'use' ),
            'reader' => function ( array $vars, tronCallContext $ctx ) use ( $state ) {
                list( $rules, $choices, $st ) = $state( $ctx );
                return array( 'master' => array( 'source' => $st['master']['source'], 'decided_by' => $st['master']['decided_by'] ),
                              'agents' => array( 'source' => $st['agents']['source'], 'strength' => $st['agents']['strength'] ),
                              'precedence' => $st['precedence'],
                              'text' => $rules->combined( $ctx->principal->userId, $choices ) );
            },
        ) ) );

        $registry->resource( tronResource::define( array(
            'uri' => 'tron://rules/status', 'name' => 'tron_rules_status', 'title' => 'Project rules: status',
            'description' => 'Which rules are active, their sources, every MASTER.md switch in precedence order and the one that decided.',
            'policy' => array( 'tron', 'use' ),
            'reader' => function ( array $vars, tronCallContext $ctx ) use ( $state ) {
                return $state( $ctx )[2];
            },
        ) ) );

        $registry->prompt( tronPrompt::define( array(
            'name' => 'tron_project_rules', 'title' => 'Project rules',
            'description' => 'The rules of this project for agents: MASTER.md (Exponential) and the site\'s AGENTS.md, MASTER.md winning where they conflict.',
            'arguments' => array( array( 'name' => 'master', 'description' => 'disabled: leave MASTER.md out this once', 'required' => false ) ),
            'policy' => array( 'tron', 'use' ),
            'renderer' => function ( array $args, tronCallContext $ctx ) use ( $state ) {
                $extra = isset( $args['master'] ) && $args['master'] !== '' ? array( 'master' => $args['master'] ) : array();
                list( $rules, $choices ) = $state( $ctx, $extra );
                $text = $rules->combined( $ctx->principal->userId, $choices );
                if ( $text === '' )
                    return 'This project has no rules for agents yet (no AGENTS.md, and MASTER.md is off). Ask the site\'s administrator, or use the prompt tron_rules_wizard to write an AGENTS.md together.';
                return "Follow these project rules for all work in this session.\n\n" . $text;
            },
        ) ) );

        $registry->prompt( tronPrompt::define( array(
            'name' => 'tron_rules_wizard', 'title' => 'Write an AGENTS.md',
            'description' => 'A guided interview that writes a starter AGENTS.md for this project with the user. The result is a draft for a person to save in Setup > tron > Agent rules.',
            'arguments' => array( array( 'name' => 'project_type', 'description' => 'site, extension, design, integration, migration or other', 'required' => false ) ),
            'policy' => array( 'tron', 'use' ),
            'renderer' => function ( array $args, tronCallContext $ctx ) use ( $state ) {
                list( $rules, , $st ) = $state( $ctx );
                $type = (string)( $args['project_type'] ?? '' );
                $s = "Help me write the AGENTS.md of this Exponential project: the rules AI agents follow here.\n\n"
                   . "Ask me these questions one or two at a time, in this order, and wait for my answers. Skip a question I already answered"
                   . ( $type !== '' ? " (the project type is: $type)" : '' ) . ".\n\n"
                   . tronRulesGenerator::interview()
                   . "\nThen write the AGENTS.md in Markdown, with the sections Project, Stack, Conventions, Always, Never, Verification, and "
                   . "Commits and releases, as short imperative rules. Never put a password, token, host name of a real server or a person's "
                   . "contact details into it: use placeholders such as <ADMIN_PASSWORD> and www.example.org. Write the product name as Exponential.\n"
                   . "Show me the draft; I save it myself in Setup > tron > Agent rules.";
                if ( $st['master']['active'] )
                    $s .= "\n\nMASTER.md (the Exponential rules) is on for this site and supersedes the AGENTS.md: the AGENTS.md need not repeat it, "
                        . 'but it must not contradict it. Read it at tron://rules/master if needed.';
                if ( $st['agents']['active'] )
                    $s .= "\n\nThis site already has an AGENTS.md (strength: {$st['agents']['strength']}); read it at tron://rules/agents and improve it rather than start over.";
                return $s;
            },
        ) ) );

        $registry->tool( tronTool::define( array(
            'name' => 'tron_rules',
            'title' => 'Project rules',
            'description' => 'The project rules for agents: which MASTER.md and AGENTS.md are active, their sources, the switches, and the combined text (MASTER.md first; it wins where they conflict). master=disabled switches MASTER.md off for the rest of this session (master=enabled undoes that; it cannot switch on what the site switched off).',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
                'master' => array( 'type' => 'string', 'enum' => array( 'enabled', 'disabled' ), 'description' => 'This session\'s MASTER.md flag.' ),
                'text' => array( 'type' => 'boolean', 'default' => true, 'description' => 'false: only the status, without the combined text.' ),
            ) ),
            'policy' => array( 'tron', 'use' ),
            'outputSchema' => array( 'type' => 'object', 'required' => array( 'master', 'agents', 'precedence', 'switches' ), 'properties' => array(
                'master' => array( 'type' => 'object' ), 'agents' => array( 'type' => 'object' ), 'precedence' => array( 'type' => 'string' ),
                'switches' => array( 'type' => 'array' ), 'text' => array( 'type' => 'string' ),
            ) ),
            'handler' => function ( array $args, tronCallContext $ctx ) use ( $state ) {
                if ( isset( $args['master'] ) && property_exists( $ctx->session, 'rules' ) )
                    $ctx->session->rules['master'] = (string)$args['master'];
                list( $rules, $choices, $st ) = $state( $ctx );
                if ( $args['text'] ?? true )
                    $st['text'] = $rules->combined( $ctx->principal->userId, $choices );
                return $st;
            },
        ) ) );
    }

    /** The short form for tron_status and the dashboard. */
    public static function summary( tronEnvironment $env ): array
    {
        try
        {
            $st = tronRules::fromEnvironment( $env )->state();
            return array( 'master' => $st['master']['source'], 'master_decided_by' => $st['master']['decided_by'],
                          'agents' => $st['agents']['source'], 'agents_strength' => $st['agents']['strength'] );
        }
        catch ( Throwable $e )
        {
            return array( 'error' => 'the rules layer could not be read' );
        }
    }
}
