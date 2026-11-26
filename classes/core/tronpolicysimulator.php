<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * "Could this user do that?" answered from the real role and policy engine without calling anything
 * (doc/policy-simulator.md). It walks the same gates a call passes, in the same order:
 *
 *   0. the user holds tron/use, which every session needs;
 *   1. the tool exists and is not switched off;
 *   2. the token scope covers the tool's program;
 *   3. the user holds the tool's policy (yes; or limited, where the tool accepts limited);
 *   4. with a target, the tool's own object check (a content node the user may read or edit).
 *
 * Each answer names the gate that decided, the matching roles and policies with their limitations, and a reason.
 * Resources and prompts are checked the same way (gates 1 to 3). The answer word of gate 3 is always the access
 * checker's own check(), the one every call uses.
 */
final class tronPolicySimulator
{
    /** @var tronServer */ private $server;

    public function __construct( tronServer $server )
    {
        $this->server = $server;
    }

    /**
     * @param string $what a tool, resource URI or prompt name, or a program name (then every tool, resource and
     *                     prompt of it), or "*" for everything
     * @param string|null $target an object the tool would act on (its targetCheck decides), e.g. a node id
     * @return array( 'user' => ..., 'scopes' => ..., 'checks' => array of answers )
     */
    public function check( tronPrincipal $subject, string $what, ?string $target = null ): array
    {
        $registry = $this->server->registry();
        $items = array();
        $tool = $registry->findTool( $what );
        $prompt = $registry->findPrompt( $what );
        $resource = $registry->matchResource( $what );
        if ( $tool )
            $items[] = array( 'tool', $tool );
        else if ( $prompt )
            $items[] = array( 'prompt', $prompt );
        else if ( $resource )
            $items[] = array( 'resource', $resource[0] );
        else
        {
            foreach ( $registry->tools() as $t )
                if ( $what === '*' || $t->program === $what )
                    $items[] = array( 'tool', $t );
            foreach ( $registry->resources() as $r )
                if ( $what === '*' || $r->program === $what )
                    $items[] = array( 'resource', $r );
            foreach ( $registry->prompts() as $p )
                if ( $what === '*' || $p->program === $what )
                    $items[] = array( 'prompt', $p );
        }

        $checks = array();
        if ( !$items )
        {
            $unavailable = $registry->unavailableTools();
            if ( isset( $unavailable[$what] ) )
                $checks[] = array( 'kind' => 'tool', 'name' => $what, 'program' => null, 'allowed' => false, 'decided_by' => 'unavailable',
                                   'policy' => null, 'access' => null, 'matches' => array(), 'reason' => 'the tool is not offered here: ' . $unavailable[$what] );
            else
                $checks[] = array( 'kind' => 'unknown', 'name' => $what, 'program' => null, 'allowed' => false, 'decided_by' => 'unknown',
                                   'policy' => null, 'access' => null, 'matches' => array(), 'reason' => 'there is no tool, resource, prompt or program by that name' );
        }
        foreach ( $items as $item )
            $checks[] = $this->one( $item[0], $item[1], $subject, $item[0] === 'tool' ? $target : null );
        return array( 'user' => array( 'id' => $subject->userId, 'login' => $subject->login ), 'scopes' => $subject->scopes,
                      'allowed' => count( array_filter( $checks, function ( $c ) { return $c['allowed']; } ) ),
                      'denied' => count( array_filter( $checks, function ( $c ) { return !$c['allowed']; } ) ),
                      'checks' => $checks );
    }

    /** @param tronTool|tronResource|tronPrompt $item */
    private function one( string $kind, $item, tronPrincipal $subject, ?string $target ): array
    {
        $name = $kind === 'resource' ? $item->uri : $item->name;
        $out = array( 'kind' => $kind, 'name' => $name, 'program' => $item->program, 'allowed' => false, 'decided_by' => '',
                      'policy' => $item->policy === null ? null : implode( '/', $item->policy ), 'access' => null,
                      'limited_ok' => (bool)$item->limitedOk, 'matches' => array(), 'reason' => '' );
        // every session needs tron/use: the HTTP transport answers 403 without it, stdio does not start
        $access = $this->server->access();
        if ( $access->check( $subject, 'tron', 'use' ) !== tronAccessChecker::YES )
        {
            $why = $access instanceof tronPolicyExplainer ? $access->explain( $subject, 'tron', 'use' )['reason'] : 'the user does not hold it';
            return array( 'decided_by' => 'use', 'access' => 'no',
                          'reason' => "every session needs tron/use (HTTP answers 403, stdio does not start), and $why" ) + $out;
        }
        if ( !$subject->inScope( $item->program ) )
            return array( 'decided_by' => 'scope', 'reason' => "the token's scope (" . implode( ', ', $subject->scopes ) . ") does not cover the program {$item->program}" ) + $out;
        if ( $item->policy === null )
            return array( 'allowed' => true, 'decided_by' => 'policy', 'access' => 'yes', 'reason' => 'the tool needs no policy beyond tron/use' ) + $out;

        list( $module, $function ) = $item->policy;
        $word = $access->check( $subject, $module, $function );
        $explained = $access instanceof tronPolicyExplainer ? $access->explain( $subject, $module, $function )
                   : array( 'matches' => array(), 'reason' => 'the access checker of this installation gives no reasons' );
        $out['access'] = $word;
        $out['matches'] = $explained['matches'];
        $granted = $word === tronAccessChecker::YES || ( $item->limitedOk && $word === tronAccessChecker::LIMITED );
        if ( !$granted )
        {
            $why = $word === tronAccessChecker::LIMITED ? "$module/$function is granted only with limitations, and this "
                                                          . $kind . ' needs it without' : $explained['reason'];
            return array( 'decided_by' => 'policy', 'reason' => $why ) + $out;
        }
        if ( $target === null || $target === '' )
        {
            $reason = $explained['reason'];
            if ( $word === tronAccessChecker::LIMITED && $kind === 'tool' && $item->targetCheck !== null )
                $reason .= '; give a target to check the object itself';
            return array( 'allowed' => true, 'decided_by' => 'policy', 'reason' => $reason ) + $out;
        }
        if ( $item->targetCheck === null )
            return array( 'allowed' => true, 'decided_by' => 'policy', 'reason' => $explained['reason'] . '; this tool has no object-level check, the target does not change the answer' ) + $out;
        $t = call_user_func( $item->targetCheck, $target, $subject, $this->server->environment() );
        $out['target'] = $target;
        return array( 'allowed' => (bool)( $t['allowed'] ?? false ), 'decided_by' => 'target',
                      'reason' => (string)( $t['reason'] ?? '' ) ) + $out;
    }
}
