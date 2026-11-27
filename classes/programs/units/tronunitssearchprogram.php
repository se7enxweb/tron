<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The program "search": search.query and search.index. The installation's own search engine, the one its search page
 * uses ([SearchSettings] SearchEngine), queried as the calling user: the user's content/read limitations go into the
 * search, so a hit that user may not read never comes back, and the count is the count of what the user may read.
 *
 * search.query: full text with subtree, class, section and language filters, sort, paging and, where the engine counts
 * them, facets; each hit with ids, name, class, URL alias, dates and a short excerpt (e-mail addresses and secrets
 * masked). search.index: the engine, its indexing mode, the queue of objects waiting to be indexed, and for one node
 * or object whether the engine holds it. An engine the installation lists in [SystemAdapterSettings] PrivateNames[]
 * is named "private".
 */
final class tronUnitsSearchProgram extends tronUnitProgram
{
    const READ = array( 'content', 'read' );
    const SORTS = array( 'relevance', 'published', 'modified', 'name', 'path', 'class_identifier', 'section' );
    const FACETS = array( 'class', 'section', 'language' );

    protected function area(): string
    {
        return 'search';
    }

    protected function sourceInterface(): string
    {
        return 'tronUnitSearchSource';
    }

    protected function units( tronRegistry $registry, tronEnvironment $env, $source ): void
    {
        $this->unit( $registry, 'search.query', array(
            'title' => 'Search content',
            'description' => 'Full-text search with the installation\'s own search engine, as you: only content you may read is found, and the total counts only that. Filters: below node_id and/or the nodes in subtree, class (identifier or comma list), section (id), language (eng-GB). sort: relevance (the default; an engine without ranking says what it did instead), published, modified, name, path, class_identifier, section; order asc or desc. facets: class, section, language, where the engine counts them (facets_supported says whether it does). Each hit: node_id, object_id, name, class_identifier, url_alias, section_id, language, published, modified and a short excerpt.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'text' ), 'properties' => array(
                'text' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 500, 'description' => 'The words to search for' ),
                'node_id' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 2147483647, 'description' => 'Search below this node (a node piped in)' ),
                'subtree' => array( 'type' => 'array', 'maxItems' => 10, 'items' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 2147483647 ),
                                    'description' => 'Search below any of these nodes' ),
                'class' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_]+(\s*,\s*[A-Za-z0-9_]+)*$', 'maxLength' => 500,
                                  'description' => 'A class identifier or a comma list of them' ),
                'section' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 2147483647, 'description' => 'A section id' ),
                'language' => array( 'type' => 'string', 'pattern' => '^[a-z]{3}-[A-Z]{2}$', 'description' => 'A translation, e.g. eng-GB' ),
                'sort' => array( 'type' => 'string', 'enum' => self::SORTS, 'default' => 'relevance' ),
                'order' => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'desc' ),
                'facets' => array( 'type' => 'array', 'maxItems' => 3, 'items' => array( 'type' => 'string', 'enum' => self::FACETS ),
                                   'description' => 'Count the hits per class, section or language (engines that support facets)' ),
                'include_hidden' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Also hidden nodes (still only what you may read)' ),
            ) + self::paging( 10, 50 ) ),
            'policy' => self::READ, 'limitedOk' => true,
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source ) {
                $c = self::criteria( $a );
                $engine = self::engineInfo( $source->engine() );
                $r = $source->search( $c, (int)$a['limit'], (int)$a['offset'], $ctx->principal->userId );
                return self::answer( $r, $engine, (int)$a['offset'], $c );
            },
        ) );

        $this->unit( $registry, 'search.index', array(
            'title' => 'Search index state',
            'description' => 'The search engine of this installation (its setting name and class, whether it counts facets, delayed or immediate indexing) and how many objects wait in the indexing queue. With node_id or object_id: whether the engine holds that object (null when the engine does not say) and whether it waits in the queue; a node you may not read is "not found".',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
                'node_id' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 2147483647, 'description' => 'A node (its object is checked)' ),
                'object_id' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 2147483647, 'description' => 'A content object' ),
            ) ),
            'policy' => self::READ, 'limitedOk' => true,
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source ) {
                $nodeId = (int)( $a['node_id'] ?? 0 );
                $objectId = (int)( $a['object_id'] ?? 0 );
                $r = $source->index( $nodeId, $objectId, $ctx->principal->userId );
                if ( $r === null )
                    throw new tronToolError( ( $nodeId > 0 ? "Node $nodeId" : "Object $objectId" ) . ' was not found or you may not read it' );
                $object = null;
                if ( is_array( $r['object'] ?? null ) )
                    $object = array( 'object_id' => (int)$r['object']['object_id'], 'node_id' => (int)$r['object']['node_id'],
                                     'name' => tronUnitValue::safeText( (string)$r['object']['name'] ),
                                     'indexed' => isset( $r['object']['indexed'] ) ? (bool)$r['object']['indexed'] : null,
                                     'queued' => (bool)( $r['object']['queued'] ?? false ) );
                return array( 'engine' => self::engineInfo( $source->engine() ), 'pending' => (int)( $r['pending'] ?? 0 ), 'object' => $object );
            },
        ) );
    }

    /** The criteria a source gets from the tool's arguments. */
    public static function criteria( array $a ): array
    {
        $c = array( 'text' => trim( (string)$a['text'] ), 'sort' => (string)( $a['sort'] ?? 'relevance' ), 'order' => (string)( $a['order'] ?? 'desc' ),
                    'include_hidden' => !empty( $a['include_hidden'] ) );
        if ( $c['text'] === '' )
            throw new tronToolError( 'text: give at least one word' );
        $subtree = array_map( 'intval', (array)( $a['subtree'] ?? array() ) );
        if ( isset( $a['node_id'] ) )
            $subtree[] = (int)$a['node_id'];
        $subtree = array_values( array_unique( $subtree ) );
        if ( $subtree )
            $c['subtree'] = $subtree;
        if ( isset( $a['class'] ) )
            $c['classes'] = self::commaList( $a['class'] );
        if ( isset( $a['section'] ) )
            $c['section'] = (int)$a['section'];
        if ( isset( $a['language'] ) )
            $c['language'] = (string)$a['language'];
        if ( !empty( $a['facets'] ) )
            $c['facets'] = array_values( array_unique( array_map( 'strval', (array)$a['facets'] ) ) );
        return $c;
    }

    /** The engine as the answers show it; a private engine name is not shown. */
    public static function engineInfo( array $e ): array
    {
        $name = (string)( $e['name'] ?? '' );
        $class = (string)( $e['class'] ?? '' );
        if ( tronSystemInventory::privateName( $name ) || tronSystemInventory::privateName( $class ) )
            $name = $class = 'private';
        return array( 'name' => $name, 'class' => $class, 'facets' => (bool)( $e['facets'] ?? false ),
                      'delayed_indexing' => (string)( $e['delayed_indexing'] ?? 'disabled' ) );
    }

    /** The answer of search.query: a search-hit[] list with the engine, the sort applied and the facets. */
    public static function answer( array $r, array $engine, int $offset, array $c ): array
    {
        $items = array();
        foreach ( (array)( $r['items'] ?? array() ) as $h )
            $items[] = array(
                'node_id' => (int)$h['node_id'], 'object_id' => (int)$h['object_id'], 'name' => tronUnitValue::safeText( (string)$h['name'] ),
                'class_identifier' => (string)$h['class_identifier'], 'url_alias' => (string)( $h['url_alias'] ?? '' ),
                'section_id' => (int)( $h['section_id'] ?? 0 ), 'language' => (string)( $h['language'] ?? '' ),
                'published' => tronUnitsNodeProgram::date( $h['published'] ?? 0 ), 'modified' => tronUnitsNodeProgram::date( $h['modified'] ?? 0 ),
                'excerpt' => tronUnitValue::safeText( (string)( $h['excerpt'] ?? '' ) ),
            );
        $facets = array();
        if ( !empty( $c['facets'] ) && $engine['facets'] )
            foreach ( (array)( $r['facets'] ?? array() ) as $field => $list )
            {
                if ( !in_array( $field, $c['facets'], true ) )
                    continue;
                $facets[$field] = array();
                foreach ( (array)$list as $v )
                    $facets[$field][] = array( 'value' => (string)$v['value'], 'name' => tronUnitValue::safeText( (string)( $v['name'] ?? $v['value'] ) ),
                                               'count' => (int)$v['count'] );
            }
        $out = array( 'total' => (int)( $r['total'] ?? 0 ), 'offset' => $offset, 'items' => $items,
                      'engine' => $engine['name'], 'sort' => (string)( $r['sort'] ?? '' ),
                      'facets_supported' => $engine['facets'], 'facets' => (object)$facets,
                      'stop_words' => array_values( array_map( 'strval', (array)( $r['stop_words'] ?? array() ) ) ) );
        if ( !empty( $c['facets'] ) && !$engine['facets'] )
            $out['note'] = 'The search engine ' . $engine['name'] . ' does not count facets; facets is empty.';
        return $out;
    }

    /**
     * A short excerpt of a plain text: the text around the first of the words (lower case) it holds, cut at word
     * boundaries to about $max characters, with "…" where it was cut. matched says whether a word was found.
     */
    public static function excerptOf( string $text, array $words, int $max ): array
    {
        $mb = function_exists( 'mb_strlen' );
        $len = $mb ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
        $lower = $mb ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
        $at = -1;
        foreach ( $words as $w )
        {
            if ( $w === '' )
                continue;
            $p = $mb ? mb_strpos( $lower, $w, 0, 'UTF-8' ) : strpos( $lower, $w );
            if ( $p !== false && ( $at < 0 || $p < $at ) )
                $at = (int)$p;
        }
        $start = $at < 0 ? 0 : max( 0, $at - (int)( $max / 3 ) );
        if ( $start > 0 )
        {
            // to the start of a word
            $sp = $mb ? mb_strpos( $text, ' ', $start, 'UTF-8' ) : strpos( $text, ' ', $start );
            if ( $sp !== false && $sp < ( $at < 0 ? $start + 20 : $at ) )
                $start = (int)$sp + 1;
        }
        $cut = $mb ? mb_substr( $text, $start, $max, 'UTF-8' ) : substr( $text, $start, $max );
        if ( $start + $max < $len )
        {
            $sp = $mb ? mb_strrpos( $cut, ' ', 0, 'UTF-8' ) : strrpos( $cut, ' ' );
            if ( $sp !== false && $sp > $max / 2 )
                $cut = $mb ? mb_substr( $cut, 0, $sp, 'UTF-8' ) : substr( $cut, 0, $sp );
            $cut .= '…';
        }
        return array( 'text' => ( $start > 0 ? '…' : '' ) . $cut, 'matched' => $at >= 0 );
    }
}
