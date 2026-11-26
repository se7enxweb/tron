<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/** Content classes (defined versions only). */
interface tronUnitClassSource extends tronUnitSource
{
    /** @return array[] id, identifier, name, groups, is_container, object_count, modified */
    public function classes(): array;

    /**
     * One class by identifier or id, or null: id, identifier, name, groups, is_container, always_available,
     * object_name_pattern, url_alias_pattern, sort_field, sort_order, modified, attributes (id, identifier, name,
     * datatype, required, searchable, information_collector, translatable, category, placement, settings)
     */
    public function describe( string $identifier ): ?array;
}
