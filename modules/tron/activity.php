<?php
/**
 * Setup > tron > Activity: the activity journal. Sessions with filters ((user), (outcome), (transport), (kind),
 * (tool), (from), (to), (offset)), one session ((session)/<id>) with its story, timeline and diffs, everything done to
 * one object ((object)/<kind:ref>), and a session's export ((session)/<id>/(format)/text|markdown|json). The user,
 * tool and object filter form posts and is redirected to the view parameters.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

$Module = $Params['Module'];
$params = isset( $Params['UserParameters'] ) && is_array( $Params['UserParameters'] ) ? $Params['UserParameters'] : array();
$data = new tronAdminActivityData( tronAdmin::context() );

if ( $Module->isCurrentAction( 'Filter' ) )
{
    $http = eZHTTPTool::instance();
    $filters = tronAdminActivityData::filtersFrom( $params );
    foreach ( array( 'user' => 'TronFilterUser', 'tool' => 'TronFilterTool', 'from' => 'TronFilterFrom', 'to' => 'TronFilterTo' ) as $k => $post )
        $filters[$k] = trim( (string)$http->postVariable( $post, '' ) );
    $object = trim( (string)$http->postVariable( 'TronFilterObject', '' ) );
    tronAdmin::reset();
    if ( $object !== '' && tronJournalObjects::parse( $object ) )
        return $Module->redirectTo( '/tron/activity/(object)/' . tronAdminActivityData::encode( $object ) );
    return $Module->redirectTo( '/' . tronAdminActivityData::base( tronAdminActivityData::filtersFrom( array_map( 'tronAdminActivityData::encode', $filters ) ) ) );
}

if ( isset( $params['session'], $params['format'] ) )
{
    $export = $data->export( (int)$params['session'], (string)$params['format'] );
    tronAdmin::reset();
    if ( !$export )
        return $Module->handleError( eZError::KERNEL_NOT_AVAILABLE, 'kernel' );
    // sent after everything that may fail: on Velocity cleanExit() ends the request by throwing, nothing catches it here
    header( 'Content-Type: ' . $export['type'] );
    header( 'Content-Disposition: attachment; filename="' . $export['filename'] . '"' );
    header( 'X-Content-Type-Options: nosniff' );
    echo $export['body'];
    eZDB::checkTransactionCounter();
    eZExecution::cleanExit();
}

$tpl = eZTemplate::factory();
foreach ( $data->page( $params ) as $name => $value )
    $tpl->setVariable( $name, $value );
$tpl->setVariable( 'view', 'activity' );
$Result = tronAdmin::result( 'activity', $tpl->fetch( 'design:tron/activity.tpl' ), ezpI18n::tr( 'extension/tron', 'Activity' ) );
