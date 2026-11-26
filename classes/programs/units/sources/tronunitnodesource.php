<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Content nodes, read as a given user: a node the user may not read is reported as not found, and lists hold only
 * what the user's content/read policies allow.
 */
interface tronUnitNodeSource extends tronUnitSource
{
    /**
     * One node, or null: node_id, object_id, name, class_identifier, class_name, url_alias, parent_node_id, depth,
     * section_id, section, language, published, modified, current_version, children_count, hidden, remote_id,
     * attributes (list of identifier, name, datatype, value: a scalar or a list/map of scalars)
     */
    public function node( int $nodeId, int $userId, string $language ): ?array;

    /**
     * Nodes under a parent: criteria parent (node id), depth (1 children .. 10), classes (identifiers), section
     * (id), name (part of it), attribute + value (exact), sort (published, modified, name, priority, path), order (asc,
     * desc). Items are summaries: node_id, object_id, name, class_identifier, url_alias, parent_node_id, depth,
     * published, modified.
     *
     * @return array total, items
     */
    public function nodes( array $criteria, int $limit, int $offset, int $userId ): array;

    /** The node id of a URL alias (Fit-Healthy, content/view/full/2), or null. */
    public function nodeByPath( string $path ): ?int;

    /**
     * The versions of a node's object, or null: node_id, object_id, current_version, items (version, status,
     * language, creator_id, creator, created, modified).
     */
    public function versions( int $nodeId, int $userId ): ?array;
}
