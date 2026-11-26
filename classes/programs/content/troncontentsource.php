<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Where the content program reads and writes content: the installation's kernel (tronContentKernelSource), or an
 * array in tests (tronContentArraySource).
 *
 * A node is array( node_id, object_id, name, class, language, current_version, next_version, modified,
 * attributes => array( identifier => array( 'type' => datatype, 'value' => string|null, 'editable' => bool ) ) ).
 * Values are the datatype's string form (toString()); only the types in EDITABLE can be changed.
 */
interface tronContentSource
{
    const EDITABLE = array( 'ezstring', 'eztext', 'ezinteger', 'ezfloat', 'ezboolean' );

    /** Whether the source can answer here; reason() says why not. */
    public function available(): bool;

    public function reason(): string;

    public function node( int $nodeId ): ?array;

    public function canRead( int $nodeId, int $userId ): bool;

    public function canEdit( int $nodeId, int $userId ): bool;

    /**
     * Publishes a new version of the node's object with these attributes changed (identifier => string form), as
     * the user. Returns the node as it is afterwards.
     */
    public function update( int $nodeId, array $values, int $userId ): array;
}
