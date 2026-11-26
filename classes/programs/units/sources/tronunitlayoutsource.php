<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/** Exponential Layouts: layouts, zones, blocks, rules and the block types. */
interface tronUnitLayoutSource extends tronUnitSource
{
    /** @return array[] id, identifier, name, layout_type, shared, status (published, draft), zones, modified */
    public function layouts(): array;

    /**
     * One published layout (or its draft) by id or identifier, or null: id, identifier, name, layout_type, shared,
     * status, modified, zones (identifier, linked (layout_id, zone) or null, source (layout_id, layout_identifier, zone:
     * where the blocks come from), blocks (id, definition, name, view_type, item_view_type, position, placeholder,
     * parameters, children))
     */
    public function layout( string $layout, bool $draft ): ?array;

    /** @return array[] id, priority, enabled, layout_id, layout_identifier, targets (type, value), conditions (type, value) */
    public function rules(): array;

    /**
     * Which layout the resolver gives a path, tried rule by rule without its cache: path, layout_id, layout_identifier,
     * source (rule, default, none), rule_id, request_dependent, tried (rule_id, priority, layout_id, targets_match,
     * conditions (type, value, matched), matched)
     */
    public function resolve( string $path ): array;

    /** The URL alias of a node (the path the resolver sees), or null. */
    public function pathOfNode( int $nodeId ): ?string;

    /** @return array[] identifier, name, and the rest of the block type's settings */
    public function blockDefinitions(): array;
}
