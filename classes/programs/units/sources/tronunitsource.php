<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Where a units program reads from. Each area (ini, tpl, node, class, layout, role) has its own source interface;
 * the kernel implements it with the installation's own APIs (classes/programs/units/kernel), the tests with fixtures.
 * A source that is not available here (Exponential Layouts not active, no kernel) leaves its program's tools out,
 * with the reason in tron_status.
 */
interface tronUnitSource
{
    public function available(): bool;

    public function reason(): string;
}
