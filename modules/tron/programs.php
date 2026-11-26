<?php
/**
 * Setup > tron > Programs and tools: every program and tool, its state, the policy a caller needs and the roles that
 * grant it, and a switch per program and tool (table tron_switch). Switching asks "Verify it's you"
 * ([ReauthGroup_tron]); it takes effect on the next stdio start and the next HTTP request.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

$Module = $Params['Module'];
$http = eZHTTPTool::instance();
$data = new tronAdminProgramsData( tronAdmin::context() );
$notice = null;
$error = null;

if ( $Module->isCurrentAction( 'Switch' ) )
{
    $r = $data->setSwitch( (string)$http->postVariable( 'TronKind', '' ), (string)$http->postVariable( 'TronName', '' ),
                           (string)$http->postVariable( 'TronEnable', '' ) === '1' );
    $notice = $r['notice'] ?? null;
    $error = $r['error'] ?? null;
    // the switch changes what the server loads: build the lists again from a fresh context
    tronAdmin::reset();
    $data = new tronAdminProgramsData( tronAdmin::context() );
}

$tpl = eZTemplate::factory();
$tpl->setVariable( 'data', $data->data() );
$tpl->setVariable( 'notice', $notice );
$tpl->setVariable( 'error', $error );
$tpl->setVariable( 'view', 'programs' );
$Result = tronAdmin::result( 'programs', $tpl->fetch( 'design:tron/programs.tpl' ), ezpI18n::tr( 'extension/tron', 'Programs and tools' ) );
