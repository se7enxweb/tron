<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * A tron program: a unit of tools, resources and prompts that tron finds by convention and loads into its
 * registry. A program is any class with a constructor without arguments that implements this interface, named by
 * a manifest (tron/program.json) in its extension. See doc/programs.md.
 *
 * register() is called once per server start (stdio) or request (HTTP). It must be cheap: declare, do not compute.
 * Work belongs in the handlers. Everything it registers is staged and dropped again if it throws.
 */
interface tronProgram
{
    public function register( tronRegistry $registry, tronEnvironment $env ): void;
}
