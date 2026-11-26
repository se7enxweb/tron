<?php
/**
 * Setup > tron > Agent rules (doc/agent-rules.md): the site's AGENTS.md (edit, upload, questionnaire, expai draft),
 * the MASTER.md that supersedes it (shipped default or site override), every switch that turns MASTER.md off and
 * which one decided, and the combined text an agent receives. Saving, uploading, removing, the admin toggle and the
 * file marker ask "Verify it's you" ([ReauthGroup_tron]); the questionnaire, the expai draft and one's own
 * preference do not.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

$Module = $Params['Module'];
$http = eZHTTPTool::instance();

$options = array(
    'setPreference' => function ( int $userId, ?string $value ) {
        eZPreferences::setValue( tronRules::PREFERENCE, $value === null ? '' : $value, $userId );
    },
    'expaiUnavailable' => tronRulesExpai::unavailable(),
    'expai' => function ( array $answers ) { return tronRulesExpai::draft( $answers ); },
);
$data = new tronAdminRulesData( tronAdmin::context(), $options );
$result = array( 'notice' => null, 'error' => null, 'warnings' => array(), 'editor' => '' );

$actions = array( 'SaveAgents', 'UploadAgents', 'RemoveAgents', 'SaveMaster', 'UploadMaster', 'RemoveMaster',
                  'AdminToggle', 'Marker', 'Preference', 'Generate', 'ExpaiDraft' );
foreach ( $actions as $action )
{
    if ( !$Module->isCurrentAction( $action ) )
        continue;
    $post = array();
    foreach ( array( 'TronAgentsText', 'TronMasterText', 'TronAdminMaster', 'TronMarker', 'TronPreference', 'TronQ' ) as $field )
        if ( $http->hasPostVariable( $field ) )
            $post[$field] = $http->postVariable( $field );
    $upload = null;
    if ( $action === 'UploadAgents' || $action === 'UploadMaster' )
    {
        $field = $action === 'UploadAgents' ? 'TronAgentsFile' : 'TronMasterFile';
        if ( eZHTTPFile::canFetch( $field ) )
        {
            $file = eZHTTPFile::fetch( $field );
            $path = $file ? (string)$file->attribute( 'filename' ) : '';
            if ( $path !== '' && is_uploaded_file( $path ) )
                $upload = (string)file_get_contents( $path, false, null, 0, $data->rules()->maxBytes() + 1 );
        }
    }
    $result = $data->handle( $action, $post, $upload );
    if ( $action !== 'Generate' && $action !== 'ExpaiDraft' && !$result['error'] )
    {
        // a change of the files or switches: read everything again from a fresh context
        tronAdmin::reset();
        $data = new tronAdminRulesData( tronAdmin::context(), $options );
    }
    break;
}

$tpl = eZTemplate::factory();
$tpl->setVariable( 'data', $data->data( (string)$result['editor'] ) );
$tpl->setVariable( 'notice', $result['notice'] );
$tpl->setVariable( 'error', $result['error'] );
$tpl->setVariable( 'warnings', $result['warnings'] );
$tpl->setVariable( 'view', 'rules' );
$Result = tronAdmin::result( 'rules', $tpl->fetch( 'design:tron/rules.tpl' ), ezpI18n::tr( 'extension/tron', 'Agent rules' ) );
