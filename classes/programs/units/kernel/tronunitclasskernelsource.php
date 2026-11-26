<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/** The class units in an installation: eZContentClass, defined versions only. */
final class tronUnitClassKernelSource implements tronUnitClassSource
{
    const SORT_FIELDS = array( 1 => 'path', 2 => 'published', 3 => 'modified', 4 => 'section', 5 => 'depth', 6 => 'class_identifier',
                               7 => 'class_name', 8 => 'priority', 9 => 'name', 10 => 'modified_subnode', 11 => 'node_id', 12 => 'contentobject_id' );

    public function available(): bool
    {
        return class_exists( 'eZContentClass' );
    }

    public function reason(): string
    {
        return 'no content classes here';
    }

    public function classes(): array
    {
        $out = array();
        foreach ( (array)eZContentClass::fetchList( eZContentClass::VERSION_STATUS_DEFINED, true ) as $c )
            $out[] = array( 'id' => (int)$c->attribute( 'id' ), 'identifier' => (string)$c->attribute( 'identifier' ), 'name' => (string)$c->attribute( 'name' ),
                            'groups' => self::groups( $c ), 'is_container' => (bool)$c->attribute( 'is_container' ),
                            'object_count' => (int)$c->objectCount(), 'modified' => (int)$c->attribute( 'modified' ) );
        return $out;
    }

    public function describe( string $identifier ): ?array
    {
        $c = ctype_digit( $identifier ) ? eZContentClass::fetch( (int)$identifier ) : eZContentClass::fetchByIdentifier( $identifier );
        if ( !$c instanceof eZContentClass )
            return null;
        $attrs = array();
        foreach ( (array)$c->fetchAttributes() as $a )
        {
            $settings = array();
            foreach ( array( 'data_int1', 'data_int2', 'data_int3', 'data_int4', 'data_float1', 'data_float2', 'data_float3', 'data_float4',
                             'data_text1', 'data_text2', 'data_text3', 'data_text4', 'data_text5' ) as $f )
            {
                $v = $a->attribute( $f );
                if ( $v !== null && $v !== '' && $v !== 0 && $v !== '0' && $v !== 0.0 )
                    $settings[$f] = is_string( $v ) && strlen( $v ) > 2000 ? substr( $v, 0, 2000 ) . '…' : $v;
            }
            $attrs[] = array( 'id' => (int)$a->attribute( 'id' ), 'identifier' => (string)$a->attribute( 'identifier' ), 'name' => (string)$a->attribute( 'name' ),
                              'datatype' => (string)$a->attribute( 'data_type_string' ), 'required' => (bool)$a->attribute( 'is_required' ),
                              'searchable' => (bool)$a->attribute( 'is_searchable' ), 'information_collector' => (bool)$a->attribute( 'is_information_collector' ),
                              'translatable' => (bool)$a->attribute( 'can_translate' ), 'category' => (string)$a->attribute( 'category' ),
                              'placement' => (int)$a->attribute( 'placement' ), 'settings' => $settings );
        }
        return array( 'id' => (int)$c->attribute( 'id' ), 'identifier' => (string)$c->attribute( 'identifier' ), 'name' => (string)$c->attribute( 'name' ),
                      'groups' => self::groups( $c ), 'is_container' => (bool)$c->attribute( 'is_container' ),
                      'always_available' => (bool)$c->attribute( 'always_available' ),
                      'object_name_pattern' => (string)$c->attribute( 'contentobject_name' ), 'url_alias_pattern' => (string)$c->attribute( 'url_alias_name' ),
                      'sort_field' => self::SORT_FIELDS[(int)$c->attribute( 'sort_field' )] ?? (string)$c->attribute( 'sort_field' ),
                      'sort_order' => (int)$c->attribute( 'sort_order' ) === 1 ? 'asc' : 'desc',
                      'object_count' => (int)$c->objectCount(), 'modified' => (int)$c->attribute( 'modified' ), 'attributes' => $attrs );
    }

    private static function groups( eZContentClass $c ): array
    {
        $out = array();
        foreach ( (array)$c->fetchGroupList() as $g )
            $out[] = (string)$g->attribute( 'group_name' );
        return $out;
    }
}
