<?php
$eZTemplateOperatorArray = array();
$eZTemplateOperatorArray[] = array( 'class' => 'eZTemplateStringOperator', 'operator_names' => array( 'upcase', 'shorten' ) );
$eZTemplateOperatorArray[] = array( 'class' => 'eZTemplateExecuteOperator', 'operator_names' => array( 'fetch' ) );
$eZTemplateOperatorArray[] = array( 'class' => 'eZURLOperator', 'operator_names' => array( 'ezini', 'has_role' ) );
$eZTemplateOperatorArray[] = array( 'class' => 'eZTemplateImageOperator', 'operator_names' => array( 'texttoimage' ) );
