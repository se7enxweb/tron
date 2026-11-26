<?php
/**
 * Setup > tron > Audit: the audit log, newest first, with filters and paging as view parameters only
 * ((outcome), (user), (transport), (program), (offset)). The user filter form posts and is redirected to the view
 * parameter. The live tail asks the same view for (part)/rows with (after)/<newest id> and gets only the newer rows,
 * without the page around them.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

$Module = $Params['Module'];
$params = isset( $Params['UserParameters'] ) && is_array( $Params['UserParameters'] ) ? $Params['UserParameters'] : array();
$data = new tronAdminAuditData( tronAdmin::context() );

if ( $Module->isCurrentAction( 'Filter' ) )
{
    $filters = tronAdminAuditData::filtersFrom( $params );
    $filters['user'] = trim( (string)eZHTTPTool::instance()->postVariable( 'TronFilterUser', '' ) );
    tronAdmin::reset();
    return $Module->redirectTo( '/' . tronAdminAuditData::base( $filters ) );
}

$tpl = eZTemplate::factory();
if ( isset( $params['part'] ) && $params['part'] === 'rows' )
{
    // The live tail: only the newer rows, no page around them
    $tail = $data->tail( $params );
    $tpl->setVariable( 'rows', $tail['rows'] );
    tronAdmin::reset();
    $Result = array( 'content' => $tpl->fetch( 'design:tron/parts/audit_rows.tpl' ), 'pagelayout' => false );
    return;
}

foreach ( $data->page( $params ) as $name => $value )
    $tpl->setVariable( $name, $value );
$tpl->setVariable( 'view', 'audit' );
$Result = tronAdmin::result( 'audit', $tpl->fetch( 'design:tron/audit.tpl' ), ezpI18n::tr( 'extension/tron', 'Audit' ) );
