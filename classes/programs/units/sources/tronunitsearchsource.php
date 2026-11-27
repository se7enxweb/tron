<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The installation's search, as a given user: the configured search engine ([SearchSettings] SearchEngine: the
 * built-in engine, eZ Find, or any other plugin), queried the way the kernel's fetch( 'content', 'search' ) queries
 * it, with the user's own content/read limitations. A hit the user may not read never comes back.
 */
interface tronUnitSearchSource extends tronUnitSource
{
    /**
     * The engine: name (the SearchEngine setting), class, facets (whether it can count facets), delayed_indexing
     * (enabled, disabled, classbased).
     */
    public function engine(): array;

    /**
     * A search. Criteria: text, subtree (node ids), classes (identifiers), section (id), language (eng-GB), sort
     * (relevance, published, modified, name, path, class_identifier, section), order (asc, desc), include_hidden,
     * facets (class, section, language). Hits are node_id, object_id, name, class_identifier, url_alias, section_id,
     * language, published, modified, excerpt (plain text, may be empty).
     *
     * @return array total, items, sort (what the engine sorted by, in words), facets (field => list of value, name,
     *               count; empty when none were asked or the engine has none), stop_words
     */
    public function search( array $criteria, int $limit, int $offset, int $userId ): array;

    /**
     * The index state: pending (objects waiting in the indexing queue), and for one object (by node or object id)
     * whether the engine has it (true, false, or null when the engine does not say) and whether it waits in the queue.
     * Null when the node or object does not exist or the user may not read it.
     *
     * @return array|null pending, object (null without one: object_id, node_id, name, indexed, queued)
     */
    public function index( int $nodeId, int $objectId, int $userId ): ?array;
}
