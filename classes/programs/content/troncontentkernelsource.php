<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Content through the installation's kernel. Reads come from the node's current version; permissions are the
 * kernel's own object checks for the given user (checkAccess( 'read'|'edit', ..., $userId )); a write is
 * eZContentFunctions::updateAndPublishObject() while the user is the current user of the request (set only in the
 * user instance cache, never in the session, and put back afterwards).
 */
final class tronContentKernelSource implements tronContentSource
{
    public function available(): bool
    {
        return class_exists( 'eZContentObjectTreeNode' ) && class_exists( 'eZContentFunctions' );
    }

    public function reason(): string
    {
        return $this->available() ? '' : 'needs the Exponential kernel (eZContentObjectTreeNode)';
    }

    public function node( int $nodeId ): ?array
    {
        $node = $nodeId > 0 ? eZContentObjectTreeNode::fetch( $nodeId ) : null;
        if ( !$node instanceof eZContentObjectTreeNode )
            return null;
        $object = $node->object();
        if ( !$object instanceof eZContentObject )
            return null;
        $attributes = array();
        foreach ( (array)$object->dataMap() as $identifier => $attribute )
        {
            $type = (string)$attribute->attribute( 'data_type_string' );
            $editable = in_array( $type, self::EDITABLE, true );
            $attributes[(string)$identifier] = array( 'type' => $type, 'value' => $editable ? (string)$attribute->toString() : null, 'editable' => $editable );
        }
        ksort( $attributes );
        $language = eZContentLanguage::fetch( (int)$object->attribute( 'initial_language_id' ) );
        return array(
            'node_id' => (int)$node->attribute( 'node_id' ), 'object_id' => (int)$object->attribute( 'id' ),
            'name' => (string)$object->attribute( 'name' ), 'class' => (string)$object->attribute( 'class_identifier' ),
            'language' => $language ? (string)$language->attribute( 'locale' ) : '',
            'current_version' => (int)$object->attribute( 'current_version' ), 'next_version' => (int)$object->nextVersion(),
            'modified' => (int)$object->attribute( 'modified' ), 'attributes' => $attributes,
        );
    }

    public function canRead( int $nodeId, int $userId ): bool
    {
        return $this->check( $nodeId, 'read', $userId );
    }

    public function canEdit( int $nodeId, int $userId ): bool
    {
        return $this->check( $nodeId, 'edit', $userId );
    }

    public function update( int $nodeId, array $values, int $userId ): array
    {
        $node = eZContentObjectTreeNode::fetch( $nodeId );
        $user = eZUser::fetch( $userId );
        if ( !$node instanceof eZContentObjectTreeNode || !$user instanceof eZUser )
            throw new tronToolError( 'The node or the user is gone' );
        $object = $node->object();
        $had = array_key_exists( 'eZUserGlobalInstance_', $GLOBALS );
        $before = $had ? $GLOBALS['eZUserGlobalInstance_'] : null;
        $GLOBALS['eZUserGlobalInstance_'] = $user;
        try
        {
            $ok = eZContentFunctions::updateAndPublishObject( $object, array( 'attributes' => $values ) );
        }
        finally
        {
            if ( $had )
                $GLOBALS['eZUserGlobalInstance_'] = $before;
            else
                unset( $GLOBALS['eZUserGlobalInstance_'] );
        }
        if ( !$ok )
            throw new RuntimeException( "Publishing a new version of node $nodeId failed" );
        eZContentObject::clearCache( array( (int)$object->attribute( 'id' ) ) );
        $after = $this->node( $nodeId );
        if ( !$after )
            throw new RuntimeException( "Node $nodeId is gone after the publish" );
        return $after;
    }

    private function check( int $nodeId, string $function, int $userId ): bool
    {
        $node = $nodeId > 0 ? eZContentObjectTreeNode::fetch( $nodeId ) : null;
        if ( !$node instanceof eZContentObjectTreeNode )
            return false;
        $r = $node->checkAccess( $function, false, false, false, false, $userId );
        return !is_array( $r ) && (int)$r === 1;
    }
}
