<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Dry runs and their apply (doc/dry-run.md).
 *
 * A write tool declares one of two ways to show the change it would make, without making it:
 *
 * - transaction: 'dryRun' => array( 'tables' => ..., 'volatile' => ..., 'refs' => ... ). The tool runs for real
 *   inside a transaction; its tables are read before and after, the difference is the change, and the transaction is
 *   rolled back. Its apply runs the tool again inside a transaction and commits only when the change, normalised
 *   (tronRowDiff::normalize()), is exactly the planned one; otherwise it rolls back and refuses.
 * - computed: 'plan' => callable( $args, $ctx ) returning array( 'changes' => [...], 'state' => <what the change
 *   depends on>, 'effects' => [ text, ... ] ). For changes outside tron's transaction (a content publish, a call to
 *   another system). Its apply computes the plan again and runs the tool only when state and changes are unchanged.
 *
 * A dry run returns a plan id. It is bound to the user and token that made it, the tool, the hash of the arguments
 * and a short lifetime, and is used once. tron keeps only hashes of it (table tron_plan), never the arguments or the
 * change, so a plan id holds no data. Applying needs the same arguments again.
 */
final class tronPlanner
{
    const DEFAULT_LIFETIME = 300;

    /** @var tronEnvironment */ private $env;

    public function __construct( tronEnvironment $env )
    {
        $this->env = $env;
    }

    /**
     * Runs the tool as a dry run. Returns the plan: plan_id (null without a database), tool, mode, expires, changes,
     * summary, effects and preview (the tool's answer in the rolled-back run, transaction mode only).
     * A tool that would refuse throws its tronToolError, as a real call would.
     */
    public function dryRun( tronTool $tool, array $args, tronCallContext $ctx ): array
    {
        if ( $tool->dryRun !== null )
        {
            $db = $this->db( true );
            list( $value, $changes ) = $this->inTransaction( $db, $tool, $args, $ctx, function () { return false; } );
            $normal = tronRowDiff::normalize( $changes, (array)$tool->dryRun['volatile'], (array)$tool->dryRun['refs'] );
            $hash = tronRowDiff::hash( $normal );
            $plan = array( 'mode' => 'transaction', 'changes' => $changes, 'summary' => tronRowDiff::summary( $changes ),
                           'effects' => array(), 'preview' => $value );
            return $this->remember( $tool, $args, $ctx, 'transaction', $hash, $hash ) + $plan;
        }
        if ( $tool->plan !== null )
        {
            $p = $this->computed( $tool, $args, $ctx );
            return $this->remember( $tool, $args, $ctx, 'computed', tronRowDiff::hash( $p['state'] ), tronRowDiff::hash( $p['changes'] ) )
                 + array( 'mode' => 'computed', 'changes' => $p['changes'], 'summary' => array( 'changes' => count( $p['changes'] ) ),
                          'effects' => $p['effects'] );
        }
        throw new tronToolError( "The tool {$tool->name} has no dry run" );
    }

    /**
     * Applies a plan made by dryRun(): checks the binding, then makes the change only if it is still exactly the
     * planned one. Returns the tool's answer. Refusals are tronToolErrors with a reason the agent can act on.
     */
    public function apply( tronTool $tool, array $args, tronCallContext $ctx, string $planKey )
    {
        $db = $this->db( true );
        $row = $this->binding( $db, $tool, $args, $ctx, $planKey );
        $now = $this->env->now();
        if ( $tool->dryRun !== null )
        {
            if ( $row['mode'] !== 'transaction' )
                throw new tronToolError( 'The plan was not made by this kind of dry run; make a new one' );
            $volatile = (array)$tool->dryRun['volatile'];
            $refs = (array)$tool->dryRun['refs'];
            list( $value ) = $this->inTransaction( $db, $tool, $args, $ctx, function ( array $changes ) use ( $row, $volatile, $refs, $db, $now ) {
                if ( !hash_equals( (string)$row['changes_hash'], tronRowDiff::hash( tronRowDiff::normalize( $changes, $volatile, $refs ) ) ) )
                    throw new tronToolError( 'The target changed since the dry run: the change this call would make now differs from the plan, so nothing was changed. Make a new dry run.',
                                             array( 'plan_id' => $row['plan_key'], 'reason' => 'changed' ) );
                $db->execute( 'UPDATE tron_plan SET used = :u WHERE id = :id', array( 'u' => $now, 'id' => (int)$row['id'] ) );
                return true;
            } );
            return $value;
        }
        if ( $tool->plan === null )
            throw new tronToolError( "The tool {$tool->name} has no dry run" );
        $p = $this->computed( $tool, $args, $ctx );
        if ( !hash_equals( (string)$row['state_hash'], tronRowDiff::hash( $p['state'] ) )
             || !hash_equals( (string)$row['changes_hash'], tronRowDiff::hash( $p['changes'] ) ) )
            throw new tronToolError( 'The target changed since the dry run, so nothing was changed. Make a new dry run.',
                                     array( 'plan_id' => $row['plan_key'], 'reason' => 'changed' ) );
        $value = call_user_func( $tool->handler, $args, $ctx );
        $db->execute( 'UPDATE tron_plan SET used = :u WHERE id = :id', array( 'u' => $now, 'id' => (int)$row['id'] ) );
        return $value;
    }

    /** The hash a plan is bound to: the arguments after defaults, in canonical form. */
    public static function inputHash( string $tool, array $args ): string
    {
        return tronRowDiff::hash( array( 'tool' => $tool, 'args' => $args ) );
    }

    /**
     * Runs the handler inside a transaction with snapshots before and after. $decide( $changes ) returns true to
     * commit; false (or an exception) rolls back. @return array( value, changes )
     */
    private function inTransaction( tronDb $db, tronTool $tool, array $args, tronCallContext $ctx, callable $decide ): array
    {
        $tables = (array)$tool->dryRun['tables'];
        $db->begin();
        try
        {
            $before = tronRowDiff::snapshot( $db, $tables );
            $value = call_user_func( $tool->handler, $args, $ctx );
            $changes = tronRowDiff::diff( $before, tronRowDiff::snapshot( $db, $tables ) );
            $commit = $decide( $changes );
        }
        catch ( Throwable $e )
        {
            $db->rollback();
            throw $e;
        }
        if ( $commit === true )
            $db->commit();
        else
            $db->rollback();
        return array( $value, $changes );
    }

    private function computed( tronTool $tool, array $args, tronCallContext $ctx ): array
    {
        $p = call_user_func( $tool->plan, $args, $ctx );
        if ( !is_array( $p ) || !isset( $p['changes'] ) || !is_array( $p['changes'] ) )
            throw new RuntimeException( "The plan of {$tool->name} returned no changes list" );
        return array( 'changes' => array_values( $p['changes'] ), 'state' => $p['state'] ?? null,
                      'effects' => array_values( (array)( $p['effects'] ?? array() ) ) );
    }

    private function remember( tronTool $tool, array $args, tronCallContext $ctx, string $mode, string $stateHash, string $changesHash ): array
    {
        $now = $this->env->now();
        $lifetime = max( 10, min( 3600, (int)$this->env->setting( 'PlanSettings', 'LifetimeSeconds', self::DEFAULT_LIFETIME ) ) );
        $db = $this->db( false );
        if ( !$db )
            return array( 'plan_id' => null, 'tool' => $tool->name, 'expires' => null, 'note' => 'no database here: a dry run only, it cannot be applied' );
        $key = bin2hex( random_bytes( 16 ) );
        $db->insert( 'tron_plan', array(
            'plan_key' => $key, 'user_id' => $ctx->principal->userId, 'token_id' => $ctx->principal->tokenId,
            'tool' => $tool->name, 'mode' => $mode, 'input_hash' => self::inputHash( $tool->name, $args ),
            'state_hash' => $stateHash, 'changes_hash' => $changesHash, 'created' => $now, 'expires' => $now + $lifetime, 'used' => 0,
        ) );
        // expired plans go the next time one is made
        $db->execute( 'DELETE FROM tron_plan WHERE expires < :t', array( 't' => $now - 86400 ) );
        return array( 'plan_id' => $key, 'tool' => $tool->name, 'expires' => gmdate( 'Y-m-d\TH:i:s\Z', $now + $lifetime ) );
    }

    private function binding( tronDb $db, tronTool $tool, array $args, tronCallContext $ctx, string $planKey ): array
    {
        if ( !preg_match( '/^[a-f0-9]{32}$/', $planKey ) )
            throw new tronToolError( 'Unknown plan id', array( 'reason' => 'unknown' ) );
        $rows = $db->select( 'SELECT * FROM tron_plan WHERE plan_key = :k', array( 'k' => $planKey ) );
        $row = $rows[0] ?? null;
        // another user's plan answers exactly like a missing one
        if ( !$row || (int)$row['user_id'] !== $ctx->principal->userId )
            throw new tronToolError( 'Unknown plan id', array( 'reason' => 'unknown' ) );
        if ( (int)$row['token_id'] !== $ctx->principal->tokenId )
            throw new tronToolError( 'The plan was made with another token', array( 'reason' => 'token' ) );
        if ( (string)$row['tool'] !== $tool->name )
            throw new tronToolError( "The plan is for the tool {$row['tool']}, not {$tool->name}", array( 'reason' => 'tool' ) );
        if ( (int)$row['used'] > 0 )
            throw new tronToolError( 'The plan was applied already; a plan is used once', array( 'reason' => 'used' ) );
        if ( (int)$row['expires'] < $this->env->now() )
            throw new tronToolError( 'The plan expired; make a new dry run', array( 'reason' => 'expired' ) );
        if ( !hash_equals( (string)$row['input_hash'], self::inputHash( $tool->name, $args ) ) )
            throw new tronToolError( 'The arguments differ from the dry run; give exactly the same arguments, or make a new dry run', array( 'reason' => 'arguments' ) );
        return $row;
    }

    private function db( bool $required ): ?tronDb
    {
        if ( $this->env->has( 'db' ) )
        {
            $db = $this->env->get( 'db' );
            if ( $db instanceof tronDb )
                return $db;
        }
        if ( $required )
            throw new tronToolError( 'Dry runs need the database of this installation, and there is none here' );
        return null;
    }
}
