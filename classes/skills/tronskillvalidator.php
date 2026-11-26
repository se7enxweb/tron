<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The proof of one skill, the same for a draft (skill_validate), the admin's Validate button and the harness:
 *
 *   1. schema      the manifest keeps the standard (tronSkillManifest)
 *   2. units       every unit it runs is registered here and only reads
 *   3. policy      the policy simulator: every unit and guard, for the user who validates, by the real role engine
 *   4. runs        every example runs, read-only, as that user; each answer matches the output schema
 *   5. redaction   no answer still holds anything the redactor would mask (secrets, tokens, personal data)
 *
 * The result is a report; "pass" only when every check passed.
 */
final class tronSkillValidator
{
    /** @return array result (pass|fail|parked), reason, checks */
    public static function prove( tronServer $server, tronSession $session, tronSkillManifest $m ): array
    {
        $checks = array( 'schema' => 'pass' );
        $state = tronSkillCatalog::state( $m, 'loaded', '', $server->registry(), array( 'result' => 'pass' ), array( $m->name => $m->hash() ) );
        if ( $state['state'] === 'parked' )
            return array( 'result' => 'parked', 'reason' => $state['reason'], 'checks' => $checks + array( 'units' => 'missing' ), 'waits' => $state['waits'] ?? array() );
        $checks['units'] = 'pass';

        $sim = tronSkillRunner::simulate( $server, $session, $m );
        $checks['policy'] = $sim['allowed'] ? 'pass' : 'fail';
        $checks['policy_detail'] = $sim['checks'];
        if ( !$sim['allowed'] )
            return array( 'result' => 'fail', 'reason' => "the user '{$session->principal->login}' may not run every unit of the skill", 'checks' => $checks );

        $runner = new tronSkillRunner( $server, $session );
        $runs = array();
        $fail = '';
        $redacted = 0;
        foreach ( $m->data['examples'] as $i => $ex )
        {
            try
            {
                $r = $runner->run( $m, (array)( $ex['params'] ?? array() ) );
                $counts = array();
                tronSkillRunner::redact( $r['output'], $counts );
                $n = array_sum( $counts );
                $redacted += $n;
                $runs[] = array( 'example' => $i, 'result' => $n ? 'fail' : 'pass', 'ms' => $r['ms'], 'steps' => count( $r['trace'] ),
                                 'keys' => array_keys( $r['output'] ) ) + ( $n ? array( 'unredacted' => $counts ) : array() );
                if ( $n && $fail === '' )
                    $fail = "example $i: the answer still holds values the redactor masks (" . implode( ', ', array_keys( $counts ) ) . ')';
            }
            catch ( Throwable $e )
            {
                $runs[] = array( 'example' => $i, 'result' => 'fail', 'error' => $e->getMessage() );
                if ( $fail === '' )
                    $fail = "example $i: " . $e->getMessage();
            }
        }
        $checks['runs'] = $runs;
        $checks['output'] = $fail === '' ? 'pass' : 'fail';
        $checks['redaction'] = $redacted ? 'fail' : 'pass';
        return array( 'result' => $fail === '' ? 'pass' : 'fail', 'reason' => $fail, 'checks' => $checks );
    }
}
