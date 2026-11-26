<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Runs a skill: checks the parameters against the skill's schema, applies the guards, then runs the steps in order.
 * A unit step goes through tronServer::invoke() as the session's user, exactly as if the caller had called the unit
 * itself: scope, policy, argument check, audit entry and redaction, each step on its own. So a skill can never do
 * more than its caller could do by hand, and the audit log and the activity journal show every step.
 *
 * In this release a skill runs read units only; a step on a write unit is refused (skills that write wait for wave B,
 * which dry-runs the whole chain and applies it after approval).
 */
final class tronSkillRunner
{
    /** @var tronServer */ private $server;
    /** @var tronSession */ private $session;

    public function __construct( tronServer $server, tronSession $session )
    {
        $this->server = $server;
        $this->session = $session;
    }

    /**
     * @return array array( 'output' => the answer, 'trace' => one row per step, 'ms' => total )
     * @throws tronToolError with the step, unit and reason when the run stops
     */
    public function run( tronSkillManifest $m, array $params ): array
    {
        $t0 = microtime( true );
        $schema = $m->inputSchema();
        $errors = tronSchemaValidator::validate( $schema, $params );
        if ( $errors )
            throw new tronToolError( 'Invalid parameters: ' . implode( '; ', $errors ), array( 'inputSchema' => $schema ) );
        $params = tronSchemaValidator::applyDefaults( $schema, $params );
        foreach ( (array)( $schema['properties'] ?? array() ) as $k => $p )
            if ( isset( $params[$k] ) && is_float( $params[$k] ) && ( $p['type'] ?? '' ) === 'integer' )
                $params[$k] = (int)$params[$k];

        $this->guards( $m, $params );

        $p = $this->session->principal;
        $x = new tronSkillExpr( array( 'param' => $params, 'now' => tronSkillProgram::env( $this->server )->now(),
                                       'user' => array( 'login' => $p->login, 'user_id' => $p->userId ) ) );
        $trace = array();
        $calls = 0;
        $call = function ( string $unit, array $args, string $stepId ) use ( &$calls, $m ) {
            if ( ++$calls > 100 )
                throw new tronToolError( "The skill {$m->name} stopped: more than 100 unit calls in one run" );
            return $this->unit( $unit, $args, $stepId );
        };
        foreach ( $m->data['steps'] as $s )
        {
            $ts = microtime( true );
            $row = array( 'id' => $s['id'], 'kind' => isset( $s['op'] ) ? 'op' : 'unit', 'what' => $s['op'] ?? $s['unit'] );
            if ( array_key_exists( 'when', $s ) && !$x->resolve( $s['when'] ) )
            {
                $x->setStep( $s['id'], null );
                $trace[] = $row + array( 'outcome' => 'skipped', 'ms' => 0 );
                continue;
            }
            if ( !isset( $s['op'] ) )
                $value = $call( $s['unit'], (array)$x->resolve( $s['args'] ?? array() ), $s['id'] );
            else
                $value = tronSkillOps::run( $s, $x, function ( $unit, $args ) use ( $call, $s ) { return $call( $unit, $args, $s['id'] ); } );
            $x->setStep( $s['id'], $value );
            $row['outcome'] = 'ok';
            $row['ms'] = round( ( microtime( true ) - $ts ) * 1000, 1 );
            if ( is_array( $value ) )
                $row['items'] = tronSchemaValidator::isList( $value ) ? count( $value ) : ( isset( $value['items'] ) && is_array( $value['items'] ) ? count( $value['items'] ) : null );
            $trace[] = $row;
        }

        $output = $x->resolve( $m->data['output']['value'] );
        $errors = tronSchemaValidator::validate( $m->outputSchema(), $output );
        if ( $errors )
            throw new tronToolError( "The skill {$m->name} made an answer that does not match its output schema: " . implode( '; ', array_slice( $errors, 0, 5 ) ),
                                     array( 'trace' => $trace ) );
        return array( 'output' => $output, 'trace' => $trace, 'ms' => round( ( microtime( true ) - $t0 ) * 1000, 1 ) );
    }

    private function guards( tronSkillManifest $m, array $params ): void
    {
        foreach ( (array)( $m->data['guards'] ?? array() ) as $g )
        {
            if ( isset( $g['param'] ) )
            {
                $v = $params[$g['param']] ?? null;
                if ( $v !== null && !tronSkillOps::compare( $v, $g['op'], $g['value'] ?? null ) )
                    throw new tronToolError( (string)$g['message'] );
            }
            else if ( isset( $g['policy'] ) )
            {
                list( $mod, $fn ) = explode( '/', $g['policy'], 2 );
                if ( !$this->server->permitted( $this->session, array( $mod, $fn ) ) )
                    throw new tronToolError( "Permission denied: the skill {$m->name} needs the policy {$g['policy']}" );
            }
            else if ( isset( $g['program'] ) )
            {
                $has = false;
                foreach ( (array)$this->server->option( 'programs' ) as $row )
                    if ( ( $row['name'] ?? '' ) === $g['program'] && ( $row['state'] ?? '' ) === 'loaded' )
                        $has = true;
                if ( !$has )
                    throw new tronToolError( "The skill {$m->name} needs the program {$g['program']}, which is not loaded here" );
            }
        }
    }

    /** One unit call as the session's user, through the server's checks. */
    private function unit( string $unit, array $args, string $stepId )
    {
        $args = array_filter( $args, function ( $v ) { return $v !== null; } );   // a parameter left out is an argument left out
        $tool = $this->server->registry()->findTool( $unit );
        if ( !$tool )
            throw new tronToolError( "Step $stepId: the unit $unit is not available here" );
        if ( $tool->write )
            throw new tronToolError( "Step $stepId: the unit $unit writes; skills that write wait for wave B (dry run of the whole chain)" );
        $o = tronSkillProgram::invoke( $this->server, $tool, $args, $this->session );
        if ( !$o['ok'] )
            throw new tronToolError( "Step $stepId ($unit): " . $o['error'] );
        $counts = array();
        return self::redact( $o['value'], $counts );
    }

    /**
     * Masks what must never reach an answer in a value: with tron's output redactor where it has one (every kind it
     * knows), else secret-named keys, password=... pairs, tron tokens, Bearer tokens and e-mail addresses. Each unit's
     * answer passes here before a step sees it, so a skill holds nothing the redactor would mask even where the server
     * does not redact its answers. $counts gets kind => number.
     */
    public static function redact( $value, array &$counts )
    {
        if ( method_exists( 'tronRedactor', 'output' ) )
            return tronRedactor::output( $value, $counts );
        if ( is_array( $value ) )
        {
            $out = array();
            foreach ( $value as $k => $v )
            {
                // a whole key that names a secret (password, api_key, client_secret, token ...), not any key holding "pass"
                if ( is_string( $k ) && preg_match( '/^([a-z0-9]+[_-])*(pass(word|wd|phrase)?|secret|token|api[_-]?key|private[_-]?key|credentials?|authorization|cookie)$/i', $k ) && $v !== '[redacted]' )
                {
                    $counts['secret'] = ( $counts['secret'] ?? 0 ) + 1;
                    $out[$k] = '[redacted]';
                    continue;
                }
                $out[$k] = self::redact( $v, $counts );
            }
            return $out;
        }
        if ( !is_string( $value ) )
            return $value;
        $s = tronRedactor::maskTokens( tronRedactor::maskSecretsInText( $value ) );
        $s = (string)preg_replace( '/(?<![A-Za-z0-9._%+\-])[A-Za-z0-9._%+\-]{1,64}@(?:[A-Za-z0-9](?:[A-Za-z0-9\-]{0,62}[A-Za-z0-9])?\.)+[A-Za-z]{2,24}\b/', '[redacted:email]', $s );
        if ( $s !== $value )
            $counts['text'] = ( $counts['text'] ?? 0 ) + 1;
        return $s;
    }

    /**
     * The policy simulation of a skill for a user: the policy of every unit it runs and of every policy guard, each
     * checked with the real role engine (the server's access checker), without running anything.
     * @return array array( 'allowed' => bool, 'checks' => array( array( what, policy, allowed ) ) )
     */
    public static function simulate( tronServer $server, tronSession $session, tronSkillManifest $m ): array
    {
        $checks = array();
        $ok = true;
        foreach ( $m->units() as $u )
        {
            $t = $server->registry()->findTool( $u );
            if ( !$t )
            {
                $checks[] = array( 'what' => $u, 'policy' => '', 'allowed' => false, 'reason' => 'not available here' );
                $ok = false;
                continue;
            }
            $allowed = $session->principal->inScope( $t->program ) && $server->permitted( $session, $t->policy, $t->limitedOk );
            $checks[] = array( 'what' => $u, 'policy' => $t->policy ? implode( '/', $t->policy ) : '', 'allowed' => $allowed, 'write' => $t->write );
            $ok = $ok && $allowed;
        }
        foreach ( (array)( $m->data['guards'] ?? array() ) as $g )
        {
            if ( !isset( $g['policy'] ) )
                continue;
            list( $mod, $fn ) = explode( '/', $g['policy'], 2 );
            $allowed = $server->permitted( $session, array( $mod, $fn ) );
            $checks[] = array( 'what' => 'guard', 'policy' => $g['policy'], 'allowed' => $allowed );
            $ok = $ok && $allowed;
        }
        return array( 'allowed' => $ok, 'checks' => $checks );
    }
}
