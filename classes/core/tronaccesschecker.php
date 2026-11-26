<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Answers whether a principal holds an Exponential policy. tron has no permission model of its own: in an
 * installation the answer comes from the user's roles (tronKernelAccessChecker); tests and standalone runs use a
 * role table with the same semantics (tronRoleAccessChecker).
 */
interface tronAccessChecker
{
    const YES = 'yes';
    const LIMITED = 'limited';
    const NO = 'no';

    /** @return string self::YES, self::LIMITED or self::NO */
    public function check( tronPrincipal $principal, string $module, string $function ): string;
}
