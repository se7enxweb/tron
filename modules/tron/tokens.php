<?php
/**
 * Setup > tron > Tokens: make a token (its secret is shown once, on the answer to the form), list, rotate, revoke.
 * Filters are view parameters ((user)/<login>, (state)/active|expired|revoked); the user filter form posts and is
 * redirected to them. Making, rotating and revoking ask "Verify it's you" ([ReauthGroup_tron]).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

$Module = $Params['Module'];
$http = eZHTTPTool::instance();
$params = isset( $Params['UserParameters'] ) && is_array( $Params['UserParameters'] ) ? $Params['UserParameters'] : array();
$filterUser = isset( $params['user'] ) ? tronAdminAuditData::decode( (string)$params['user'] ) : '';
$filterState = isset( $params['state'] ) && in_array( $params['state'], tronAdminTokensData::STATES, true ) ? (string)$params['state'] : '';
$data = new tronAdminTokensData( tronAdmin::context() );

if ( $Module->isCurrentAction( 'Filter' ) )
{
    $login = trim( (string)$http->postVariable( 'TronFilterUser', '' ) );
    $url = '/tron/tokens' . ( $login !== '' ? '/(user)/' . tronAdminAuditData::encode( $login ) : '' ) . ( $filterState !== '' ? '/(state)/' . $filterState : '' );
    tronAdmin::reset();
    return $Module->redirectTo( $url );
}

$created = null;
$error = null;
$notice = null;
$form = array( 'login' => '', 'name' => '', 'days' => $data->defaultDays(), 'scopes' => array() );

if ( $Module->isCurrentAction( 'Create' ) )
{
    $form = array(
        'login' => trim( (string)$http->postVariable( 'TronLogin', '' ) ),
        'name' => trim( (string)$http->postVariable( 'TronName', '' ) ),
        'days' => (int)$http->postVariable( 'TronDays', 0 ),
        'scopes' => array_values( array_filter( (array)$http->postVariable( 'TronScopes', array() ), 'is_string' ) ),
    );
    $r = $data->create( $form['login'], $form['name'], $form['days'], $form['scopes'] );
    if ( isset( $r['error'] ) )
        $error = $r['error'];
    else
        $created = $r;
}
else if ( $Module->isCurrentAction( 'Rotate' ) )
{
    $r = $data->rotate( (int)$http->postVariable( 'TronTokenID', 0 ) );
    if ( isset( $r['error'] ) )
        $error = $r['error'];
    else
        $created = $r;
}
else if ( $Module->isCurrentAction( 'Revoke' ) )
{
    $r = $data->revoke( (int)$http->postVariable( 'TronTokenID', 0 ) );
    $notice = $r['notice'] ?? null;
    $error = $r['error'] ?? null;
}

$tpl = eZTemplate::factory();
$tpl->setVariable( 'tokens', $data->listing( $filterUser, $filterState ) );
$tpl->setVariable( 'filter_user', $filterUser );
$tpl->setVariable( 'filter_state', $filterState );
$tpl->setVariable( 'scope_choices', $data->scopeChoices() );
$tpl->setVariable( 'max_days', $data->maxDays() );
$tpl->setVariable( 'form', $form );
$tpl->setVariable( 'created', $created );
$tpl->setVariable( 'error', $error );
$tpl->setVariable( 'notice', $notice );
$tpl->setVariable( 'view', 'tokens' );
$Result = tronAdmin::result( 'tokens', $tpl->fetch( 'design:tron/tokens.tpl' ), ezpI18n::tr( 'extension/tron', 'Tokens' ) );
