<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * An access checker that can also say why: which roles and policies of the user match a module/function, with their
 * limitations. The policy simulator (tron_policy_check, doc/policy-simulator.md) uses it; the answer word is always
 * the checker's own check(), the explanation only adds the reasons.
 */
interface tronPolicyExplainer
{
    /**
     * @return array( 'access' => yes|limited|no,
     *                'matches' => array of array( 'role' => name, 'policy' => 'module/function' as granted (may hold *),
     *                                             'policy_id' => int|null, 'limitations' => array( identifier => values ) ),
     *                'reason' => one sentence )
     */
    public function explain( tronPrincipal $principal, string $module, string $function ): array;
}
