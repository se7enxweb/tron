<?php
/**
 * The search units (doc/units.md, program "search"): search.query and search.index against the fixture world, as
 * users with and without access to restricted content, with the engine with and without facets, redaction of
 * excerpts, private engine names, token scopes, the audit and a pipe into the search.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

require_once dirname( __DIR__ ) . '/fixtures/units_sources.php';

final class TronSearchUnitsTest extends TronTestCase
{
    /** @var array */ private $f;
    /** @var TronUnitFixtureSearch */ private $search;

    public function setUp(): void
    {
        tronUnitCatalog::reset();
        $this->search = new TronUnitFixtureSearch();
        $this->f = TronUnitFixture::build( array( 'services' => array( 'units.search' => $this->search ) + TronUnitFixture::services() ) );
    }

    private function session( int $user, array $scopes = array( '*' ) ): tronSession
    {
        return new tronSession( new tronPrincipal( $user, TronUnitFixture::users()[$user], 0, $scopes ), 'stdio', false, '2025-11-25', 'test' );
    }

    private function ok( string $tool, array $args = array(), int $user = TronUnitFixture::READER, ?array $f = null )
    {
        $f = $f ?: $this->f;
        $r = TronFixture::call( $f['server'], $this->session( $user ), $tool, $args );
        if ( isset( $r['error'] ) || !empty( $r['result']['isError'] ) )
            $this->fail( "$tool failed: " . json_encode( $r ) );
        $v = TronFixture::data( $r );
        $u = tronUnitCatalog::byTool( $tool );
        $this->assertSame( array(), tronSchemaValidator::validate( tronUnitTypes::schema( $u['out'] ), $v ), "$tool answers its output type {$u['out']}" );
        return $v;
    }

    private function err( string $tool, array $args = array(), int $user = TronUnitFixture::READER ): string
    {
        $r = TronFixture::call( $this->f['server'], $this->session( $user ), $tool, $args );
        $this->assertTrue( !empty( $r['result']['isError'] ) || isset( $r['error'] ), "$tool fails: " . json_encode( $r ) );
        return (string)( $r['result']['content'][0]['text'] ?? $r['error']['message'] ?? '' );
    }

    public function testBothUnitsAreRegisteredReadOnlyUnderContentRead(): void
    {
        $reg = $this->f['server']->registry();
        foreach ( array( 'search_query' => 'search.query', 'search_index' => 'search.index' ) as $tool => $id )
        {
            $t = $reg->findTool( $tool );
            $this->assertTrue( $t !== null, "$tool registered" );
            $this->assertFalse( $t->write, "$tool only reads" );
            $this->assertSame( 'search', $t->program );
            $this->assertSame( array( 'content', 'read' ), $t->policy );
            $this->assertContains( "Unit $id:", $t->description );
            $this->assertSame( $id, tronUnitCatalog::byTool( $tool )['id'] );
        }
        $this->assertSame( array( 'none', 'node-ref' ), tronUnitCatalog::byId( 'search.query' )['in'] );
        $this->assertSame( 'search-hit[]', tronUnitCatalog::byId( 'search.query' )['out'] );
        $this->assertSame( array( 'node_id' => 101 ), tronUnitTypes::convert( 'search-hit', array( 'node_id' => 101, 'url_alias' => 'x' ), 'node-ref' ) );
    }

    public function testWithoutASourceTheSearchUnitsAreLeftOutWithTheReason(): void
    {
        $f = TronFixture::build();
        $u = $f['server']->registry()->unavailableTools();
        $this->assertTrue( isset( $u['search_query'], $u['search_index'] ) );
        $this->assertContains( 'no search source', $u['search_query'] );
    }

    public function testAHitHasIdsNameClassPathDatesAndAnExcerpt(): void
    {
        $r = $this->ok( 'search_query', array( 'text' => 'breakfast' ) );
        $this->assertSame( 1, $r['total'] );
        $this->assertSame( 0, $r['offset'] );
        $h = $r['items'][0];
        $this->assertSame( array( 'node_id', 'object_id', 'name', 'class_identifier', 'url_alias', 'section_id', 'language', 'published', 'modified', 'excerpt' ), array_keys( $h ) );
        $this->assertSame( 102, $h['node_id'] );
        $this->assertSame( 878, $h['object_id'] );
        $this->assertSame( 'Green breakfast', $h['name'] );
        $this->assertSame( 'article', $h['class_identifier'] );
        $this->assertSame( 'Fit-Healthy/Green-breakfast', $h['url_alias'] );
        $this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', TronFixture::NOW - 102 * 60 ), $h['modified'] );
        $this->assertContains( 'breakfast', $h['excerpt'] );
        $this->assertSame( 'ezsearch', $r['engine'] );
        $this->assertFalse( $r['facets_supported'] );
    }

    public function testExcerptsAndNamesAreRedacted(): void
    {
        $r = $this->ok( 'search_query', array( 'text' => 'bowl' ) );
        $this->assertSame( 1, $r['total'] );
        $json = json_encode( $r );
        $this->assertNotContains( 'chef.two@example.org', $json, 'no e-mail address in an excerpt' );
        $this->assertContains( 'c***@e***.org', $r['items'][0]['excerpt'] );
    }

    public function testRestrictedContentIsNotFoundByAUserWhoMayNotReadIt(): void
    {
        $full = $this->ok( 'search_query', array( 'text' => 'members' ), TronUnitFixture::READER );
        $this->assertSame( array( TronUnitFixture::MEMBERS_NODE ), array_column( $full['items'], 'node_id' ) );
        $this->assertSame( 1, $full['total'] );
        $limited = $this->ok( 'search_query', array( 'text' => 'members' ), TronUnitFixture::PARTIAL );
        $this->assertSame( 0, $limited['total'], 'the total counts only what the user may read' );
        $this->assertSame( array(), $limited['items'] );
        $this->assertNotContains( 'Members only', json_encode( $limited ) );
        $this->assertContains( 'content/read', $this->err( 'search_query', array( 'text' => 'members' ), TronUnitFixture::NOBODY ) );
        $this->assertContains( 'content/read', $this->err( 'search_index', array(), TronUnitFixture::NOBODY ) );
    }

    public function testFiltersSortAndPaging(): void
    {
        $all = $this->ok( 'search_query', array( 'text' => 'e', 'sort' => 'name', 'order' => 'asc', 'limit' => 50 ) );
        $names = array_column( $all['items'], 'name' );
        $sorted = $names;
        sort( $sorted );
        $this->assertSame( $sorted, $names, 'sorted by name' );
        $articles = $this->ok( 'search_query', array( 'text' => 'e', 'class' => 'article', 'limit' => 50 ) );
        $this->assertSame( array( 'article' ), array_values( array_unique( array_column( $articles['items'], 'class_identifier' ) ) ) );
        $below = $this->ok( 'search_query', array( 'text' => 'e', 'node_id' => 60, 'limit' => 50 ) );
        $this->assertSame( array( 60 ), $this->search->last['subtree'] );
        foreach ( $below['items'] as $h )
            $this->assertContains( 'Fit-Healthy', $h['url_alias'] );
        $this->ok( 'search_query', array( 'text' => 'e', 'subtree' => array( 60, 2 ), 'node_id' => 60, 'section' => 2, 'language' => 'eng-GB' ) );
        $section = $this->ok( 'search_query', array( 'text' => 'members', 'section' => 2 ) );
        $this->assertSame( 1, $section['total'] );
        $this->assertSame( 0, $this->ok( 'search_query', array( 'text' => 'members', 'section' => 1 ) )['total'] );
        $this->assertSame( 0, $this->ok( 'search_query', array( 'text' => 'members', 'language' => 'ger-DE' ) )['total'] );
        $page = $this->ok( 'search_query', array( 'text' => 'e', 'sort' => 'name', 'order' => 'asc', 'limit' => 2, 'offset' => 2 ) );
        $this->assertSame( $all['total'], $page['total'] );
        $this->assertSame( 2, $page['offset'] );
        $this->assertSame( array_slice( $names, 2, 2 ), array_column( $page['items'], 'name' ) );
        $this->assertContains( 'does not rank by relevance', $this->ok( 'search_query', array( 'text' => 'run' ) )['sort'] );
    }

    public function testCriteriaFromArguments(): void
    {
        $c = tronUnitsSearchProgram::criteria( array( 'text' => ' run ', 'node_id' => 60, 'subtree' => array( 2, 60 ), 'class' => 'article, folder',
                                                       'section' => 1, 'language' => 'eng-GB', 'facets' => array( 'class', 'class' ) ) );
        $this->assertSame( 'run', $c['text'] );
        $this->assertSame( array( 2, 60 ), $c['subtree'] );
        $this->assertSame( array( 'article', 'folder' ), $c['classes'] );
        $this->assertSame( array( 'class' ), $c['facets'] );
        $this->assertSame( 'relevance', $c['sort'] );
        $this->assertContains( 'at least one word', $this->err( 'search_query', array( 'text' => '   ' ) ) );
        $this->assertContains( 'sort', $this->err( 'search_query', array( 'text' => 'run', 'sort' => 'score' ) ) );
        $this->assertContains( 'additional', strtolower( $this->err( 'search_query', array( 'text' => 'run', 'limitation' => array() ) ) ) );
    }

    public function testFacetsWhereTheEngineCountsThem(): void
    {
        $plain = $this->ok( 'search_query', array( 'text' => 'e', 'facets' => array( 'class' ) ) );
        $this->assertFalse( $plain['facets_supported'] );
        $this->assertSame( array(), $plain['facets'] );
        $raw = TronFixture::call( $this->f['server'], $this->session( TronUnitFixture::READER ), 'search_query', array( 'text' => 'e', 'facets' => array( 'class' ) ) );
        $this->assertContains( '"facets":{}', $raw['result']['content'][0]['text'], 'facets is an object, also when empty' );
        $this->assertContains( 'does not count facets', $plain['note'] );

        $f = TronUnitFixture::build( array( 'services' => array( 'units.search' => new TronUnitFixtureSearch( true, 'ezfind' ) ) + TronUnitFixture::services() ) );
        $r = $this->ok( 'search_query', array( 'text' => 'e', 'facets' => array( 'class', 'section' ), 'limit' => 1 ), TronUnitFixture::READER, $f );
        $this->assertTrue( $r['facets_supported'] );
        $this->assertSame( 'ezfind', $r['engine'] );
        $this->assertSame( array( 'class', 'section' ), array_keys( $r['facets'] ) );
        $this->assertSame( $r['total'], array_sum( array_column( $r['facets']['class'], 'count' ) ), 'facets count every hit, not only the page' );
        $this->assertFalse( isset( $r['note'] ) );
        $limited = $this->ok( 'search_query', array( 'text' => 'e', 'facets' => array( 'section' ) ), TronUnitFixture::PARTIAL, $f );
        $this->assertNotContains( '2', array_column( $limited['facets']['section'], 'value' ), 'a facet never counts what the user may not read' );
    }

    public function testIndexState(): void
    {
        $r = $this->ok( 'search_index' );
        $this->assertSame( array( 'name' => 'ezsearch', 'class' => 'eZSearchEngine', 'facets' => false, 'delayed_indexing' => 'disabled' ), $r['engine'] );
        $this->assertSame( 2, $r['pending'] );
        $this->assertSame( null, $r['object'] );
        $o = $this->ok( 'search_index', array( 'node_id' => 101 ) )['object'];
        $this->assertSame( array( 'object_id' => 877, 'node_id' => 101, 'name' => 'Morning run', 'indexed' => true, 'queued' => false ), $o );
        $q = $this->ok( 'search_index', array( 'object_id' => 879 ) )['object'];
        $this->assertSame( array( 103, false, true ), array( $q['node_id'], $q['indexed'], $q['queued'] ) );
        $this->assertContains( 'not found or you may not read it', $this->err( 'search_index', array( 'node_id' => TronUnitFixture::MEMBERS_NODE ), TronUnitFixture::PARTIAL ) );
        $this->assertContains( 'not found', $this->err( 'search_index', array( 'node_id' => 999 ) ) );
    }

    public function testAPrivateEngineIsNotNamed(): void
    {
        tronSystemInventory::setPrivateNames( array( 'acmesearch*' ) );
        try
        {
            $f = TronUnitFixture::build( array( 'services' => array( 'units.search' => new TronUnitFixtureSearch( false, 'acmesearchengine' ) ) + TronUnitFixture::services() ) );
            $r = $this->ok( 'search_query', array( 'text' => 'run' ), TronUnitFixture::READER, $f );
            $i = $this->ok( 'search_index', array(), TronUnitFixture::READER, $f );
        }
        finally
        {
            tronSystemInventory::setPrivateNames( null );
        }
        $this->assertSame( 'private', $r['engine'] );
        $this->assertSame( 'private', $i['engine']['name'] );
        $this->assertSame( 'private', $i['engine']['class'] );
        $this->assertNotContains( 'acmesearch', json_encode( array( $r, $i ) ) );
    }

    public function testATokenScopedToOtherProgramsDoesNotSeeTheSearch(): void
    {
        $s = $this->session( TronUnitFixture::READER, array( 'node' ) );
        $list = $this->f['server']->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => array() ), $s );
        $names = array_column( $list['result']['tools'], 'name' );
        $this->assertContains( 'node_read', $names );
        $this->assertNotContains( 'search_query', $names );
        $r = TronFixture::call( $this->f['server'], $s, 'search_query', array( 'text' => 'run' ) );
        $this->assertTrue( isset( $r['error'] ) || !empty( $r['result']['isError'] ), 'a call outside the scope is refused' );
        $scoped = TronFixture::call( $this->f['server'], $this->session( TronUnitFixture::READER, array( 'search' ) ), 'search_query', array( 'text' => 'run' ) );
        $this->assertTrue( empty( $scoped['result']['isError'] ) && !isset( $scoped['error'] ), 'a token scoped to search may search' );
    }

    public function testEveryCallIsAudited(): void
    {
        $before = count( $this->f['audit']->entries );
        $this->ok( 'search_query', array( 'text' => 'run' ) );
        $this->ok( 'search_index', array( 'node_id' => 101 ) );
        $targets = array_column( array_slice( $this->f['audit']->entries, $before ), 'target' );
        $this->assertSame( array( 'search_query', 'search_index' ), $targets );
    }

    public function testAPipeSearchesBelowEachNodeItGets(): void
    {
        $r = TronFixture::call( $this->f['server'], $this->session( TronUnitFixture::READER ), 'tron_pipe',
                                array( 'pipe' => 'node.find class=landing_page | search.query text=run' ) );
        $p = TronFixture::data( $r );
        $this->assertSame( 'search-hit[]', $p['out'], json_encode( $p ) );
        $this->assertSame( array( 'none', 'node-ref' ), array_column( $p['steps'], 'in' ) );
        $this->assertSame( array( 60 ), $this->search->last['subtree'] );
        $hits = $this->ok( 'search_query', array( 'text' => 'run', 'node_id' => 60 ) );
        $this->assertSame( array( 101 ), array_column( $hits['items'], 'node_id' ) );
    }

    public function testExcerptCutsAroundTheFirstWord(): void
    {
        $text = str_repeat( 'Lorem ipsum dolor sit amet. ', 30 ) . 'The morning run starts early. ' . str_repeat( 'Consectetur adipiscing elit. ', 30 );
        $e = tronUnitsSearchProgram::excerptOf( $text, array( 'run' ), 120 );
        $this->assertTrue( $e['matched'] );
        $this->assertContains( 'morning run starts', $e['text'] );
        $this->assertSame( '…', mb_substr( $e['text'], 0, 1 ) );
        $this->assertSame( '…', mb_substr( $e['text'], -1 ) );
        $this->assertTrue( mb_strlen( $e['text'] ) <= 122 );
        $none = tronUnitsSearchProgram::excerptOf( 'Short text.', array( 'absent' ), 120 );
        $this->assertSame( array( 'text' => 'Short text.', 'matched' => false ), $none );
    }
}
