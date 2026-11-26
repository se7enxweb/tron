<?php
$Module = array( 'name' => 'content' );
$ViewList = array();
$ViewList['view'] = array( 'functions' => array( 'read' ), 'script' => 'view.php', 'params' => array( 'ViewMode', 'NodeID' ),
                           'unordered_params' => array( 'offset' => 'Offset' ) );
$ViewList['edit'] = array( 'functions' => array( 'edit' ), 'script' => 'edit.php', 'params' => array( 'ObjectID' ),
                           'single_post_actions' => array( 'PublishButton' => 'Publish' ) );
$ViewList['removeobject'] = array( 'functions' => array( 'remove' ), 'script' => 'removeobject.php', 'params' => array() );
$ViewList['download'] = array( 'functions' => array( 'read' ), 'script' => 'download.php', 'params' => array( 'ObjectID' ) );
$FunctionList = array();
$FunctionList['read'] = array( 'Class' => array(), 'Section' => array() );
$FunctionList['edit'] = array( 'Class' => array() );
$FunctionList['remove'] = array();
