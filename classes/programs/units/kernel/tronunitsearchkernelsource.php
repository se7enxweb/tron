<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The search units in an installation: eZSearch::search() with the engine the installation configures
 * ([SearchSettings] SearchEngine), called with the parameters fetch( 'content', 'search' ) passes, as the calling
 * user. The user's content/read policies go in as the search's Limitation (as the kernel builds it for the signed-in
 * user), and the user is the current user for the call, so an engine that asks eZUser::currentUser() gets the same
 * answer. A user without content/read gets no hits at all.
 *
 * Facets: engines that count them (eZ Find) are asked for class, section and translation facets; the built-in engine
 * has none, which the answer says. The excerpt is a highlight when the engine gives one, else the text around the
 * first search word in the hit's text attributes.
 */
final class tronUnitSearchKernelSource implements tronUnitSearchSource
{
    const TEXT_TYPES = array( 'ezstring', 'eztext', 'ezxmltext', 'ezrichtext', 'ezkeyword' );
    const EXCERPT = 240;

    public function available(): bool
    {
        return class_exists( 'eZSearch' ) && class_exists( 'eZContentObjectTreeNode' );
    }

    public function reason(): string
    {
        return $this->available() ? '' : 'the kernel search (eZSearch) is not loaded here';
    }

    public function engine(): array
    {
        $ini = eZINI::instance();
        $name = $ini->hasVariable( 'SearchSettings', 'SearchEngine' ) ? (string)$ini->variable( 'SearchSettings', 'SearchEngine' ) : 'ezsearch';
        $engine = eZSearch::getEngine();
        $class = is_object( $engine ) ? get_class( $engine ) : '';
        return array( 'name' => $name, 'class' => $class, 'facets' => self::isSolr( $engine ),
                      'delayed_indexing' => $ini->hasVariable( 'SearchSettings', 'DelayedIndexing' ) ? (string)$ini->variable( 'SearchSettings', 'DelayedIndexing' ) : 'disabled',
                      'available' => is_object( $engine ) );
    }

    public function search( array $c, int $limit, int $offset, int $userId ): array
    {
        $empty = array( 'total' => 0, 'items' => array(), 'sort' => '', 'facets' => array(), 'stop_words' => array() );
        $limitation = tronUnitNodeKernelSource::limitation( $userId );
        if ( $limitation === null )
            return $empty;
        $engine = eZSearch::getEngine();
        if ( !is_object( $engine ) )
            throw new tronToolError( 'The search engine of this installation could not be loaded' );
        $solr = self::isSolr( $engine );

        $params = array( 'SearchLimit' => $limit, 'SearchOffset' => $offset, 'Limitation' => $limitation,
                         'IgnoreVisibility' => !empty( $c['include_hidden'] ) );
        if ( !empty( $c['subtree'] ) )
            $params['SearchSubTreeArray'] = array_values( array_map( 'intval', $c['subtree'] ) );
        if ( !empty( $c['classes'] ) )
        {
            $ids = array();
            foreach ( $c['classes'] as $identifier )
            {
                $id = eZContentClass::classIDByIdentifier( $identifier );
                if ( !$id )
                    throw new tronToolError( "There is no class '$identifier'" );
                $ids[] = (int)$id;
            }
            $params['SearchContentClassID'] = count( $ids ) === 1 ? $ids[0] : $ids;
        }
        if ( isset( $c['section'] ) )
            $params['SearchSectionID'] = (int)$c['section'];

        $sort = (string)( $c['sort'] ?? 'relevance' );
        $asc = ( $c['order'] ?? 'desc' ) === 'asc';
        if ( $solr )
        {
            $params['SortBy'] = array( ( $sort === 'section' ? 'section_id' : $sort ) => $asc ? 'asc' : 'desc' );
            $params['AsObjects'] = true;
            $sortText = $sort . ' ' . ( $asc ? 'asc' : 'desc' );
            if ( !empty( $c['facets'] ) )
            {
                $facets = array();
                foreach ( $c['facets'] as $f )
                    $facets[] = array( 'field' => $f === 'language' ? 'translation' : $f, 'limit' => 20 );
                $params['Facet'] = $facets;
            }
            if ( isset( $c['language'] ) )
                $params['Filter'] = array( eZSolr::getMetaFieldName( 'language_code', 'filter' ) . ':' . $c['language'] );
        }
        else if ( $sort === 'relevance' )
            $sortText = 'published asc (the engine ' . get_class( $engine ) . ' does not rank by relevance)';
        else
        {
            $params['SortArray'] = array( array( $sort === 'section' ? 'section' : $sort, $asc ) );
            $sortText = $sort . ' ' . ( $asc ? 'asc' : 'desc' );
        }

        $r = $this->asUser( $userId, function () use ( $c, $params ) {
            $languages = null;
            if ( isset( $c['language'] ) )
            {
                $languages = eZContentLanguage::prioritizedLanguageCodes();
                eZContentLanguage::setPrioritizedLanguages( array( (string)$c['language'] ) );
            }
            try
            {
                return eZSearch::search( (string)$c['text'], $params, eZSearch::buildSearchArray() );
            }
            finally
            {
                if ( $languages !== null )
                    eZContentLanguage::clearPrioritizedLanguages();
            }
        } );
        if ( !is_array( $r ) )
            return array( 'sort' => $sortText ) + $empty;

        $words = self::words( (string)$c['text'] );
        $items = array();
        foreach ( (array)( $r['SearchResult'] ?? array() ) as $node )
        {
            if ( !$node instanceof eZContentObjectTreeNode )
                continue;
            // the engine already left out what the limitation forbids; this is the kernel's own check on each hit
            if ( !tronUnitNodeKernelSource::canRead( $node, $userId ) )
                continue;
            $object = $node->object();
            $highlight = '';
            if ( $solr && $node->hasAttribute( 'highlight' ) )
                $highlight = trim( strip_tags( (string)$node->attribute( 'highlight' ) ) );
            $items[] = array(
                'node_id' => (int)$node->attribute( 'node_id' ), 'object_id' => (int)$node->attribute( 'contentobject_id' ),
                'name' => (string)$node->attribute( 'name' ), 'class_identifier' => (string)$node->attribute( 'class_identifier' ),
                'url_alias' => (string)$node->attribute( 'url_alias' ),
                'section_id' => $object ? (int)$object->attribute( 'section_id' ) : 0,
                'language' => $object ? (string)$object->attribute( 'current_language' ) : '',
                'published' => $object ? (int)$object->attribute( 'published' ) : 0,
                'modified' => $object ? (int)$object->attribute( 'modified' ) : 0,
                'excerpt' => $highlight !== '' ? $highlight : self::excerpt( $node, $words ),
            );
        }
        $facets = array();
        if ( $solr && !empty( $c['facets'] ) && isset( $r['SearchExtras'] ) && is_object( $r['SearchExtras'] ) )
            $facets = self::solrFacets( (array)$r['SearchExtras']->attribute( 'facet_fields' ) );
        return array( 'total' => (int)( $r['SearchCount'] ?? 0 ), 'items' => $items, 'sort' => $sortText, 'facets' => $facets,
                      'stop_words' => array_values( array_map( 'strval', (array)( $r['StopWordArray'] ?? array() ) ) ) );
    }

    public function index( int $nodeId, int $objectId, int $userId ): ?array
    {
        $out = array( 'pending' => self::queued( 0 ), 'object' => null );
        if ( $nodeId <= 0 && $objectId <= 0 )
            return $out;
        $node = $nodeId > 0 ? eZContentObjectTreeNode::fetch( $nodeId ) : null;
        $object = $node instanceof eZContentObjectTreeNode ? $node->object() : ( $objectId > 0 ? eZContentObject::fetch( $objectId ) : null );
        if ( !$object instanceof eZContentObject )
            return null;
        if ( !$node instanceof eZContentObjectTreeNode )
            $node = $object->mainNode();
        if ( !$node instanceof eZContentObjectTreeNode || !tronUnitNodeKernelSource::canRead( $node, $userId ) )
            return null;
        $id = (int)$object->attribute( 'id' );
        $out['object'] = array( 'object_id' => $id, 'node_id' => (int)$node->attribute( 'node_id' ), 'name' => (string)$object->attribute( 'name' ),
                                'indexed' => self::indexed( $id ), 'queued' => self::queued( $id ) > 0 );
        return $out;
    }

    // ------------------------------------------------------------------ helpers

    private static function isSolr( $engine ): bool
    {
        return is_object( $engine ) && class_exists( 'eZSolr', false ) && $engine instanceof eZSolr;
    }

    /** Objects waiting in the indexing queue (ezpending_actions), all of them or one object's entries. */
    private static function queued( int $objectId ): int
    {
        if ( !class_exists( 'eZPendingActions' ) )
            return 0;
        $conds = array( 'action' => array( array( 'index_object', 'index_moved_node' ) ) );
        if ( $objectId > 0 )
            $conds['param'] = (string)$objectId;
        try
        {
            return (int)eZPersistentObject::count( eZPendingActions::definition(), $conds );
        }
        catch ( Throwable $e )
        {
            return 0;
        }
    }

    /** Whether the engine holds the object: the built-in engine's word links; null for engines that do not say. */
    private static function indexed( int $objectId ): ?bool
    {
        $engine = eZSearch::getEngine();
        if ( !is_object( $engine ) || get_class( $engine ) !== 'eZSearchEngine' )
            return null;
        try
        {
            $rows = eZDB::instance()->arrayQuery( 'SELECT COUNT(*) AS c FROM ezsearch_object_word_link WHERE contentobject_id = ' . (int)$objectId );
            return is_array( $rows ) && isset( $rows[0] ) ? (int)reset( $rows[0] ) > 0 : null;
        }
        catch ( Throwable $e )
        {
            return null;
        }
    }

    /** The search words, lower case, at least two characters. */
    private static function words( string $text ): array
    {
        $out = array();
        foreach ( preg_split( '/[^\p{L}\p{N}]+/u', function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text ) ) ?: array() as $w )
            if ( strlen( $w ) > 1 )
                $out[] = $w;
        return array_values( array_unique( $out ) );
    }

    /** The text around the first search word in the hit's text attributes, else the start of its first text. */
    private static function excerpt( eZContentObjectTreeNode $node, array $words ): string
    {
        $first = '';
        foreach ( (array)$node->dataMap() as $attr )
        {
            if ( !in_array( (string)$attr->attribute( 'data_type_string' ), self::TEXT_TYPES, true ) )
                continue;
            $text = tronUnitNodeKernelSource::render( $attr );
            if ( !is_string( $text ) || trim( $text ) === '' )
                continue;
            $text = trim( (string)preg_replace( '/\s+/u', ' ', $text ) );
            $cut = tronUnitsSearchProgram::excerptOf( $text, $words, self::EXCERPT );
            if ( $cut['matched'] )
                return $cut['text'];
            if ( $first === '' )
                $first = $cut['text'];
        }
        return $first;
    }

    /** eZ Find's facet_fields as field => list of value, name, count. */
    private static function solrFacets( array $fields ): array
    {
        $out = array();
        foreach ( $fields as $f )
        {
            if ( !is_array( $f ) || !isset( $f['field'] ) )
                continue;
            $field = $f['field'] === 'translation' ? 'language' : (string)$f['field'];
            $list = array();
            foreach ( (array)( $f['countList'] ?? array() ) as $value => $count )
                $list[] = array( 'value' => (string)$value, 'name' => (string)( $f['nameList'][$value] ?? $value ), 'count' => (int)$count );
            $out[$field] = $list;
        }
        return $out;
    }

    /** Runs $fn with the user as the current user and puts the previous one back. The session is not touched. */
    private function asUser( int $userId, callable $fn )
    {
        $user = eZUser::fetch( $userId );
        if ( !$user instanceof eZUser || !$user->isEnabled() )
            throw new tronToolError( 'The calling user does not exist or is disabled' );
        $prev = $GLOBALS['eZUserGlobalInstance_'] ?? null;
        $GLOBALS['eZUserGlobalInstance_'] = $user;
        try
        {
            return $fn();
        }
        finally
        {
            if ( $prev === null )
                unset( $GLOBALS['eZUserGlobalInstance_'] );
            else
                $GLOBALS['eZUserGlobalInstance_'] = $prev;
        }
    }
}
