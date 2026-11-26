<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/** What a handler gets besides its arguments: the caller, the session, the environment and a permission check. */
final class tronCallContext
{
    /** @var tronPrincipal */ public $principal;
    /** @var tronSession */ public $session;
    /** @var tronEnvironment */ public $env;
    /** @var tronAccessChecker */ private $access;

    public function __construct( tronSession $session, tronEnvironment $env, tronAccessChecker $access )
    {
        $this->session = $session;
        $this->principal = $session->principal;
        $this->env = $env;
        $this->access = $access;
    }

    /** Whether the caller holds module/function without limitations (for finer checks inside a tool). */
    public function can( string $module, string $function ): bool
    {
        return $this->access->check( $this->principal, $module, $function ) === tronAccessChecker::YES;
    }
}
