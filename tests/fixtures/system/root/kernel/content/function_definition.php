<?php
$FunctionList = array();
$FunctionList['node'] = array( 'name' => 'node', 'operation_types' => array( 'read' ),
    'call_method' => array( 'class' => 'eZContentFunctionCollection', 'method' => 'fetchContentNode' ), 'parameter_type' => 'standard',
    'parameters' => array( array( 'name' => 'node_id', 'type' => 'integer', 'required' => true ),
                           array( 'name' => 'language_code', 'type' => 'string', 'required' => false, 'default' => false ) ) );
$FunctionList['list'] = array( 'name' => 'list', 'operation_types' => array( 'read' ),
    'call_method' => array( 'class' => 'eZContentObject', 'method' => 'fetchList' ), 'parameter_type' => 'standard',
    'parameters' => array( array( 'name' => 'parent_node_id', 'type' => 'integer', 'required' => true ),
                           array( 'name' => 'limit', 'type' => 'integer', 'required' => false ) ) );
$FunctionList['store_draft'] = array( 'name' => 'store_draft', 'operation_types' => array( 'write' ),
    'call_method' => array( 'class' => 'eZContentFunctionCollection', 'method' => 'storeDraft' ), 'parameter_type' => 'standard',
    'parameters' => array() );
