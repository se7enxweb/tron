<?php
/**
 * Setup > tron > Skills: the packs and skills, filters as view parameters ((pack), (state), (tag), (q), (offset)), one
 * skill in full with (skill)/<name>. Approve, Approve pack, Revoke, switching a pack and Fork ask "Verify it's you"
 * ([ReauthGroup_tron]); approving proves the skill again as the signed-in administrator, with their real policies.
 * The search form posts and is redirected to the view with the words as a view parameter.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

$Module = $Params['Module'];
$http = eZHTTPTool::instance();
$params = isset( $Params['UserParameters'] ) && is_array( $Params['UserParameters'] ) ? $Params['UserParameters'] : array();
$filters = tronAdminSkillsData::filtersFrom( $params );

if ( $Module->isCurrentAction( 'Search' ) )
{
    $filters['q'] = trim( (string)$http->postVariable( 'TronSkillQuery', '' ) );
    unset( $filters['offset'] );
    return $Module->redirectTo( '/' . tronAdminSkillsData::base( tronAdminSkillsData::filtersFrom( $filters ) ) );
}

// proving runs as the signed-in administrator, with the kernel's role engine
$user = eZUser::currentUser();
$principal = new tronPrincipal( (int)$user->attribute( 'contentobject_id' ), (string)$user->attribute( 'login' ) );
$prover = tronKernel::server( null, new tronKernelAccessChecker() );
$session = new tronSession( $principal, 'admin', false, '2025-11-25', 'admin' );
$data = new tronAdminSkillsData( tronAdmin::context(), $prover, $session );
$notice = null;
$error = null;
$r = null;
$name = (string)$http->postVariable( 'TronSkill', '' );
if ( $Module->isCurrentAction( 'Approve' ) )
    $r = $data->approve( $name, (int)$http->postVariable( 'TronDraft', 0 ) );
else if ( $Module->isCurrentAction( 'ApprovePack' ) )
    $r = $data->approvePack( (string)$http->postVariable( 'TronPack', '' ) );
else if ( $Module->isCurrentAction( 'Revoke' ) )
    $r = $data->revoke( $name );
else if ( $Module->isCurrentAction( 'PackSwitch' ) )
    $r = $data->setPack( (string)$http->postVariable( 'TronPack', '' ), (string)$http->postVariable( 'TronEnable', '' ) === '1' );
else if ( $Module->isCurrentAction( 'Fork' ) )
    $r = $data->fork( $name, trim( (string)$http->postVariable( 'TronNewName', '' ) ), (string)$http->postVariable( 'TronLocalPack', 'local' ) );
if ( $r )
{
    $notice = $r['notice'] ?? null;
    $error = $r['error'] ?? null;
    tronAdmin::reset();
    $data = new tronAdminSkillsData( tronAdmin::context(), $prover, $session );
}

$tpl = eZTemplate::factory();
$tpl->setVariable( 'notice', $notice );
$tpl->setVariable( 'error', $error );
$tpl->setVariable( 'view', 'skills' );
$skillName = isset( $params['skill'] ) && preg_match( tronSkillManifest::NAME_PATTERN, (string)$params['skill'] ) ? (string)$params['skill'] : '';
if ( $skillName !== '' )
{
    $skill = $data->skill( $skillName );
    $tpl->setVariable( 'skill', $skill );
    $tpl->setVariable( 'local_packs', $data->data( array() )['local_packs'] );
    $Result = tronAdmin::result( 'skills', $tpl->fetch( 'design:tron/skill.tpl' ), $skill ? $skillName : ezpI18n::tr( 'extension/tron', 'Skills' ) );
    return;
}
$tpl->setVariable( 'data', $data->data( $filters ) );
$tpl->setVariable( 'base', tronAdminSkillsData::base( $filters ) );
$tpl->setVariable( 'bases', array(
    'pack' => tronAdminSkillsData::base( $filters, array( 'pack', 'offset' ) ), 'state' => tronAdminSkillsData::base( $filters, array( 'state', 'offset' ) ),
    'tag' => tronAdminSkillsData::base( $filters, array( 'tag', 'offset' ) ), 'q' => tronAdminSkillsData::base( $filters, array( 'q', 'offset' ) ),
    'offset' => tronAdminSkillsData::base( $filters, array( 'offset' ) ),
) );
$Result = tronAdmin::result( 'skills', $tpl->fetch( 'design:tron/skills.tpl' ), ezpI18n::tr( 'extension/tron', 'Skills' ) );
