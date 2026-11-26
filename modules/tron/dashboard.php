<?php
/**
 * Setup > tron: the status dashboard (programs, tools, tokens, the last day's calls, the HTTP endpoint).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

$tpl = eZTemplate::factory();
$tpl->setVariable( 'data', ( new tronAdminDashboardData( tronAdmin::context() ) )->data() );
$tpl->setVariable( 'view', 'dashboard' );
$Result = tronAdmin::result( 'dashboard', $tpl->fetch( 'design:tron/dashboard.tpl' ), 'tron' );
