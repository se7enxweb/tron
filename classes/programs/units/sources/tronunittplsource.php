<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/** Designs and templates: the override settings of a siteaccess, the match keys of a node, the template parser. */
interface tronUnitTplSource extends tronUnitSource
{
    /** @return string[] */
    public function siteAccesses(): array;

    /**
     * The override chain of a template as the siteaccess configures it, or null for a template no design has:
     * designs (the design bases, most preferred first), base (path, design of the plain template, or null), overrides
     * (in the order they are tried: name, match_file (path or null when the file is missing), conditions (key => value)).
     */
    public function overrides( string $template, string $siteAccess ): ?array;

    /**
     * The match keys a node view sets (node, object, class_identifier, parent_node, section, depth, url_alias, viewmode,
     * class_group, state ...), or null when the node does not exist or the user may not read it.
     */
    public function nodeKeys( int $nodeId, string $view, int $userId ): ?array;

    /** The installation root, with a trailing slash. */
    public function root(): string;

    /** The text of a file (a path relative to the root, already checked by the caller), or null. */
    public function read( string $path ): ?string;

    /**
     * The template parser's verdict on a file or a text: errors, warnings (strings), functions and operators used
     * (names), unknown (names neither registered as a function nor as an operator).
     */
    public function lint( string $path, ?string $text ): array;
}
