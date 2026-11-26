<?php
/**
 * The module "tron": the MCP endpoint over HTTP, and the policy functions tron's programs check.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

$Module = array( 'name' => 'tron', 'variable_params' => false );

$ViewList = array();

// The MCP endpoint. It signs callers in with tron tokens, not with a session, so it is listed in
// [RoleSettings] PolicyOmitList (settings/site.ini.append.php); every call then checks the token user's policies.
$ViewList['mcp'] = array(
    'script' => 'mcp.php',
    'params' => array(),
);

// The administration (Setup > tron). Every choice is a view parameter; forms post to the same view.
$ViewList['dashboard'] = array(
    'script' => 'dashboard.php', 'functions' => array( 'admin' ), 'default_navigation_part' => 'ezsetupnavigationpart',
    'params' => array(), 'unordered_params' => array() );
$ViewList['tokens'] = array(
    'script' => 'tokens.php', 'functions' => array( 'tokens' ), 'default_navigation_part' => 'ezsetupnavigationpart',
    'params' => array(), 'unordered_params' => array( 'user' => 'User', 'state' => 'State' ),
    'single_post_actions' => array( 'CreateButton' => 'Create', 'RotateButton' => 'Rotate', 'RevokeButton' => 'Revoke',
                                    'FilterButton' => 'Filter' ) );
$ViewList['programs'] = array(
    'script' => 'programs.php', 'functions' => array( 'admin' ), 'default_navigation_part' => 'ezsetupnavigationpart',
    'params' => array(), 'unordered_params' => array(),
    'single_post_actions' => array( 'SwitchButton' => 'Switch' ) );
$ViewList['audit'] = array(
    'script' => 'audit.php', 'functions' => array( 'audit' ), 'default_navigation_part' => 'ezsetupnavigationpart',
    'params' => array(),
    'unordered_params' => array( 'outcome' => 'Outcome', 'user' => 'User', 'transport' => 'Transport', 'program' => 'Program',
                                 'offset' => 'Offset', 'after' => 'After', 'part' => 'Part' ),
    'single_post_actions' => array( 'FilterButton' => 'Filter' ) );
$ViewList['activity'] = array(
    'script' => 'activity.php', 'functions' => array( 'audit' ), 'default_navigation_part' => 'ezsetupnavigationpart',
    'params' => array(),
    'unordered_params' => array( 'user' => 'User', 'outcome' => 'Outcome', 'transport' => 'Transport', 'kind' => 'Kind', 'tool' => 'Tool',
                                 'from' => 'From', 'to' => 'To', 'offset' => 'Offset', 'session' => 'Session', 'object' => 'Object',
                                 'format' => 'Format' ),
    'single_post_actions' => array( 'FilterButton' => 'Filter' ) );
$ViewList['settings'] = array(
    'script' => 'settings.php', 'functions' => array( 'admin' ), 'default_navigation_part' => 'ezsetupnavigationpart',
    'params' => array(), 'unordered_params' => array() );
// Setup > tron > Agent rules: the site's AGENTS.md, the MASTER.md that supersedes it, the switches (doc/agent-rules.md)
$ViewList['rules'] = array(
    'script' => 'rules.php', 'functions' => array( 'admin' ), 'default_navigation_part' => 'ezsetupnavigationpart',
    'params' => array(), 'unordered_params' => array(),
    'single_post_actions' => array( 'SaveAgentsButton' => 'SaveAgents', 'UploadAgentsButton' => 'UploadAgents', 'RemoveAgentsButton' => 'RemoveAgents',
                                    'SaveMasterButton' => 'SaveMaster', 'UploadMasterButton' => 'UploadMaster', 'RemoveMasterButton' => 'RemoveMaster',
                                    'AdminToggleButton' => 'AdminToggle', 'MarkerButton' => 'Marker', 'PreferenceButton' => 'Preference',
                                    'GenerateButton' => 'Generate', 'ExpaiDraftButton' => 'ExpaiDraft' ) );

// Setup > tron > Skills: packs, skills, approve, revoke, fork (doc/skills.md). Filters are view parameters.
$ViewList['skills'] = array(
    'script' => 'skills.php', 'functions' => array( 'admin' ), 'default_navigation_part' => 'ezsetupnavigationpart',
    'params' => array(), 'unordered_params' => array( 'pack' => 'Pack', 'state' => 'State', 'tag' => 'Tag', 'q' => 'Q', 'offset' => 'Offset', 'skill' => 'Skill' ),
    'single_post_actions' => array( 'ApproveButton' => 'Approve', 'ApprovePackButton' => 'ApprovePack', 'RevokeButton' => 'Revoke',
                                    'PackSwitchButton' => 'PackSwitch', 'ForkButton' => 'Fork', 'SearchButton' => 'Search' ) );

// Setup > tron > Diff: the diff program's engines and HTML renderer on two texts (doc/diff.md). The texts are posted.
$ViewList['diff'] = array(
    'script' => 'diff.php', 'functions' => array( 'diff' ), 'default_navigation_part' => 'ezsetupnavigationpart',
    'params' => array(), 'unordered_params' => array(),
    'single_post_actions' => array( 'CompareButton' => 'Compare' ) );

// Policy functions. A role grants them like any other module's (Roles and policies in the admin interface).
$FunctionList = array();
$FunctionList['use'] = array();             // connect at all, over either transport; tron_whoami, tron_status
$FunctionList['admin'] = array();           // Setup > tron: dashboard, programs and tools, settings; program origins in tron_status
$FunctionList['tokens'] = array();          // Setup > tron > Tokens: make, list and revoke every user's tokens
$FunctionList['audit'] = array();           // Setup > tron > Audit: read the audit log
$FunctionList['centre_read'] = array();     // the centre's read tools, resources and prompts
$FunctionList['centre_write'] = array();    // create, update, comment, move, close and link tickets
$FunctionList['centre_decide'] = array();   // record a decision
$FunctionList['katalyst_read'] = array();   // Katalyst services, plans and orders (domains masked)
$FunctionList['katalyst_detail'] = array(); // ... with full domains, and search by domain
// the root programs (doc/root-programs.md)
$FunctionList['web'] = array();             // web_*: fetch public addresses, check them, robots.txt, search
$FunctionList['browser'] = array();         // browser_*: headless browser jobs on the runner node
$FunctionList['browser_install'] = array(); // browser_install: install a browser engine on the runner node
$FunctionList['validate'] = array();        // validate_*: every validator
$FunctionList['diff'] = array();            // diff_*: diff engines and renderers; Setup > tron > Diff
$FunctionList['api_read'] = array();        // api_endpoints, api_rest_v1, api_rest_v2, api_graphql, api_http_inspect
$FunctionList['api_write'] = array();       // api_rest_v2_write: content writes through REST v2
$FunctionList['api_http'] = array();        // api_http_request: requests to allowlisted hosts with named secrets
