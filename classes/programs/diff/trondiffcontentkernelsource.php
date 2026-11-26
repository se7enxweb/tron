<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The versions of a content object as diff_content_versions compares them, read through the kernel as the calling
 * user: content/read on the object, and content/versionread for any version but the published one
 * (eZContentObject::checkAccess( ..., $userId )). Each version is its metadata and its attributes as text
 * (eZContentObjectAttribute::toString(), the kernel's own serialisation of every datatype).
 */
final class tronDiffContentKernelSource
{
    /** @return array|null object (id, name, class, current_version), version (number, status, language, modified, creator_id), attributes{} */
    public function version( int $objectId, ?int $version, ?string $language, int $userId ): ?array
    {
        $object = eZContentObject::fetch( $objectId );
        if ( !$object instanceof eZContentObject )
            return null;
        if ( (int)$object->checkAccess( 'read', false, false, false, false, $userId ) !== 1 )
            throw new tronToolError( "You may not read the object $objectId" );
        $number = $version ?? (int)$object->attribute( 'current_version' );
        if ( $number !== (int)$object->attribute( 'current_version' ) && (int)$object->checkAccess( 'versionread', false, false, false, false, $userId ) !== 1 )
            throw new tronToolError( "You may not read other versions of the object $objectId (content/versionread)" );
        $v = $object->version( $number );
        if ( !$v instanceof eZContentObjectVersion )
            return null;
        $lang = $language ?: (string)$v->attribute( 'initial_language' )->attribute( 'locale' );
        $attributes = array();
        foreach ( (array)$v->contentObjectAttributes( $lang ) as $a )
        {
            $text = (string)$a->toString();
            $attributes[(string)$a->attribute( 'contentclass_attribute_identifier' )] = mb_strlen( $text ) > 100000 ? mb_substr( $text, 0, 100000 ) . ' [cut]' : $text;
        }
        $status = array( 0 => 'draft', 1 => 'published', 2 => 'pending', 3 => 'archived', 4 => 'rejected', 5 => 'internal draft', 6 => 'repeat', 7 => 'queued' );
        return array(
            'object' => array( 'id' => $objectId, 'name' => (string)$object->attribute( 'name' ),
                               'class' => (string)$object->attribute( 'class_identifier' ), 'current_version' => (int)$object->attribute( 'current_version' ) ),
            'version' => array( 'number' => $number, 'status' => $status[(int)$v->attribute( 'status' )] ?? (string)$v->attribute( 'status' ),
                                'language' => $lang, 'modified' => date( 'c', (int)$v->attribute( 'modified' ) ), 'creator_id' => (int)$v->attribute( 'creator_id' ) ),
            'attributes' => $attributes,
        );
    }
}
