<?php
/**
 * The units (doc/units.md): the catalog and types, every unit against the fixture world (output checked against its
 * output schema), the policies and limitations, redaction, the template path guard, and tron_pipe.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

require_once dirname( __DIR__ ) . '/fixtures/units_sources.php';

final class TronUnitsTest extends TronTestCase
{
    const AREAS = array( 'ini' => 3, 'tpl' => 3, 'node' => 4, 'class' => 3, 'layout' => 5, 'role' => 2, 'search' => 2 );

    /** @var array */ private $f;

    public function setUp(): void
    {
        tronUnitCatalog::reset();
        $this->f = TronUnitFixture::build();
    }

    /** A successful call's value, checked against the unit's output schema. */
    private function ok( string $tool, array $args = array(), int $user = TronUnitFixture::DESIGNER )
    {
        $r = TronFixture::call( $this->f['server'], TronUnitFixture::session( $user ), $tool, $args );
        if ( isset( $r['error'] ) || !empty( $r['result']['isError'] ) )
            $this->fail( "$tool failed: " . json_encode( $r ) );
        $v = TronFixture::data( $r );
        $u = tronUnitCatalog::byTool( $tool );
        if ( $u )
        {
            $errors = tronSchemaValidator::validate( tronUnitTypes::schema( $u['out'] ), $v );
            $this->assertSame( array(), $errors, "$tool answers its output type {$u['out']}" );
            $this->assertNotContains( '"Array"', json_encode( $v ), "$tool never says Array" );
        }
        return $v;
    }

    /** The error text of a failed call. */
    private function err( string $tool, array $args = array(), int $user = TronUnitFixture::DESIGNER ): string
    {
        $r = TronFixture::call( $this->f['server'], TronUnitFixture::session( $user ), $tool, $args );
        $this->assertTrue( !empty( $r['result']['isError'] ), "$tool fails: " . json_encode( $r ) );
        return (string)$r['result']['content'][0]['text'];
    }

    // ------------------------------------------------------------------ catalog and types

    public function testCatalogDeclaresTwentyTwoUnitsWithKnownTypes(): void
    {
        $units = tronUnitCatalog::units();
        $this->assertCount( 22, $units );
        $per = array();
        foreach ( $units as $u )
            $per[$u['program']] = ( $per[$u['program']] ?? 0 ) + 1;
        ksort( $per );
        $want = self::AREAS;
        ksort( $want );
        $this->assertSame( $want, $per );
        $this->assertSame( 'node_read', tronUnitCatalog::find( 'node.read' )['tool'] );
        $this->assertSame( 'rule.list', tronUnitCatalog::find( 'layout_rule_list' )['id'] );
        $this->assertCount( 22, tronUnitCatalog::contracts() );
        foreach ( tronUnitCatalog::contracts() as $tool => $c )
            $this->assertSame( 'object', $c['outputSchema']['type'], $tool );
    }

    public function testEveryUnitIsRegisteredWithItsOutputSchemaAndEveryToolIsAUnit(): void
    {
        $reg = $this->f['server']->registry();
        foreach ( tronUnitCatalog::units() as $u )
        {
            $t = $reg->findTool( $u['tool'] );
            $this->assertTrue( $t !== null, $u['tool'] . ' registered' );
            $this->assertFalse( $t->write, $u['tool'] . ' only reads' );
            $this->assertContains( 'Unit ' . $u['id'] . ':', $t->description );
            if ( property_exists( $t, 'outputSchema' ) )
                $this->assertSame( tronUnitTypes::schema( $u['out'] ), $t->outputSchema );
        }
        foreach ( $reg->tools() as $t )
            if ( isset( self::AREAS[$t->program] ) )
                $this->assertTrue( tronUnitCatalog::byTool( $t->name ) !== null, $t->name . ' is a unit' );
        $this->assertTrue( $reg->findTool( 'tron_pipe' ) !== null, 'tron_pipe where the units are' );
    }

    public function testWithoutSourcesTheUnitsAreLeftOutWithTheReason(): void
    {
        $f = TronFixture::build();
        $reg = $f['server']->registry();
        $this->assertSame( null, $reg->findTool( 'node_read' ) );
        $this->assertSame( null, $reg->findTool( 'tron_pipe' ) );
        $u = $reg->unavailableTools();
        $this->assertContains( 'Exponential installation', $u['node_read'] );
        $this->assertTrue( isset( $u['tron_pipe'], $u['layout_rule_resolve'], $u['role_user_find'] ) );
    }

    public function testManifestChecksRefuseBadDeclarations(): void
    {
        $dir = TronFixture::tmp( 'units-manifests' );
        $bad = array(
            array( 'id' => 'Node.read', 'tool' => 'x_read', 'in' => array( 'none' ), 'out' => 'node' ),
            array( 'id' => 'x.read', 'tool' => 'other_read', 'in' => array( 'none' ), 'out' => 'node' ),
            array( 'id' => 'x.read', 'tool' => 'x_read', 'in' => array( 'node' ), 'out' => 'node' ),
            array( 'id' => 'x.read', 'tool' => 'x_read', 'in' => array( 'none' ), 'out' => 'node-ref' ),
            array( 'id' => 'x.read', 'tool' => 'x_read', 'in' => array(), 'out' => 'node' ),
        );
        foreach ( $bad as $i => $u )
        {
            TronFixture::write( "$dir/m$i/program.json", json_encode( array( 'name' => 'x', 'units' => array( $u ) ) ) );
            $this->assertThrows( function () use ( $dir, $i ) { tronUnitCatalog::units( array( "$dir/m$i/program.json" ) ); }, 'InvalidArgumentException' );
        }
        TronFixture::write( "$dir/ok/program.json", json_encode( array( 'name' => 'x', 'units' => array( array( 'id' => 'x.read', 'tool' => 'x_read', 'in' => array( 'none' ), 'out' => 'node' ) ) ) ) );
        $this->assertCount( 1, tronUnitCatalog::units( array( "$dir/ok/program.json" ) ) );
        $this->assertCount( 22, tronUnitCatalog::units(), 'an explicit manifest list does not replace the cached catalog' );
    }

    public function testTypesConvertByProvides(): void
    {
        $this->assertSame( array( 'node_id' => 7 ), tronUnitTypes::convert( 'node-summary', array( 'node_id' => '7', 'class_identifier' => 'a' ), 'node-ref' ) );
        $this->assertSame( array( 'identifier' => 'a' ), tronUnitTypes::convert( 'node', array( 'node_id' => 7, 'class_identifier' => 'a' ), 'class-ref' ) );
        $this->assertSame( array( 'path' => 'x/y.tpl' ), tronUnitTypes::convert( 'template-chain', array( 'winner' => array( 'path' => 'x/y.tpl' ) ), 'template-file' ) );
        $this->assertSame( null, tronUnitTypes::convert( 'template-chain', array( 'winner' => array( 'path' => null ) ), 'template-file' ), 'a missing winner file' );
        $this->assertSame( null, tronUnitTypes::convert( 'rule-trace', array( 'layout_id' => 0 ), 'layout-ref' ), 'no layout' );
        $this->assertSame( array( 'node_id' => 3 ), tronUnitTypes::convert( 'node-ref', array( 'node_id' => 3 ), 'node-ref' ) );
        $this->assertSame( null, tronUnitTypes::convert( 'user', array( 'user_id' => 3 ), 'node-ref' ) );
        $this->assertTrue( tronUnitTypes::feeds( 'node-summary', 'class-ref' ) );
        $this->assertFalse( tronUnitTypes::feeds( 'class-summary', 'node-ref' ) );
        $this->assertSame( 'node-summary', tronUnitTypes::itemType( 'node[]' ) );
        foreach ( tronUnitTypes::catalog() as $t )
            $this->assertTrue( tronUnitTypes::exists( $t['type'] ), $t['type'] );
    }

    // ------------------------------------------------------------------ ini

    public function testIniResolveNamesTheWinningFileAndRedactsSecrets(): void
    {
        $v = $this->ok( 'ini_resolve', array( 'file' => 'site.ini', 'block' => 'SiteSettings', 'setting' => 'SiteName', 'siteaccess' => 'site' ) );
        $this->assertSame( 'Fit & Healthy', $v['value'] );
        $this->assertSame( 'settings/siteaccess/site/site.ini.append.php', $v['winner'] );
        $this->assertSame( array( 'settings/site.ini', 'settings/siteaccess/site/site.ini.append.php' ), array_column( $v['files'], 'path' ) );
        $this->assertCount( 1, $v['not_loaded'] );
        $p = $this->ok( 'ini_resolve', array( 'file' => 'site', 'block' => 'DatabaseSettings', 'setting' => 'Password' ) );
        $this->assertSame( '[redacted]', $p['value'] );
        $this->assertSame( '[redacted]', $p['files'][0]['value'] );
        $this->assertNotContains( 'hunter2', json_encode( $p ) );
        $this->assertFalse( $this->ok( 'ini_resolve', array( 'file' => 'site', 'block' => 'SiteSettings', 'setting' => 'Nope' ) )['found'] );
        $this->assertContains( 'not a siteaccess', $this->err( 'ini_resolve', array( 'file' => 'site', 'block' => 'A', 'setting' => 'B', 'siteaccess' => 'shop' ) ) );
        $this->assertContains( 'no INI file', $this->err( 'ini_resolve', array( 'file' => 'nothere', 'block' => 'A', 'setting' => 'B' ) ) );
    }

    public function testIniDiffBetweenSiteaccessesAndFiles(): void
    {
        $d = $this->ok( 'ini_diff', array( 'file' => 'site', 'a' => 'site', 'b' => 'admin' ) );
        $this->assertSame( array( 'changed' => 3, 'only_a' => 1, 'only_b' => 1 ), $d['counts'] );
        $keys = array_column( $d['items'], 'change', 'key' );
        $this->assertSame( 'changed', $keys['SiteSettings/SiteName'] );
        $this->assertSame( 'only_a', $keys['DesignSettings/AdditionalSiteDesignList'] );
        $this->assertSame( 'only_b', $keys['ContentSettings/CachedViewPreferences'] );
        $this->assertFalse( isset( $keys['DatabaseSettings/Password'] ), 'equal values are no difference' );
        $one = $this->ok( 'ini_diff', array( 'file' => 'site', 'a' => 'site', 'b' => 'admin', 'block' => 'SiteSettings', 'limit' => 1 ) );
        $this->assertSame( 2, $one['total'] );
        $this->assertTrue( $one['truncated'] );
        $files = $this->ok( 'ini_diff', array( 'file' => 'site', 'other_file' => 'explayouts', 'siteaccess' => 'site' ) );
        $this->assertSame( 'explayouts', $files['b']['file'] );
        $this->assertSame( 1, $files['counts']['only_b'] );
        $this->assertNotContains( 'hunter2', json_encode( $files ) );
        $this->assertContains( 'two siteaccesses', $this->err( 'ini_diff', array( 'file' => 'site', 'a' => 'site' ) ) );
    }

    public function testIniListBlocksAndValues(): void
    {
        $l = $this->ok( 'ini_list', array( 'file' => 'site', 'siteaccess' => 'admin' ) );
        $this->assertSame( array( 'SiteSettings', 'DatabaseSettings', 'DesignSettings', 'ContentSettings' ), array_column( $l['items'], 'block' ) );
        $this->assertFalse( isset( $l['items'][0]['settings'][0]['value'] ), 'names only by default' );
        $b = $this->ok( 'ini_list', array( 'file' => 'site', 'siteaccess' => 'admin', 'block' => 'ContentSettings' ) );
        $this->assertSame( 'hash', $b['items'][0]['settings'][0]['kind'] );
        $this->assertSame( array( 'full' => 'admin_navigation_content=1' ), $b['items'][0]['settings'][0]['value'] );
        $all = $this->ok( 'ini_list', array( 'file' => 'site', 'values' => true ) );
        $this->assertNotContains( 'hunter2', json_encode( $all ) );
        $this->assertContains( 'is not in site.ini', $this->err( 'ini_list', array( 'file' => 'site', 'block' => 'Nope' ) ) );
    }

    // ------------------------------------------------------------------ tpl

    public function testTplResolveReplaysTheOverrideMatch(): void
    {
        $c = $this->ok( 'tpl_resolve', array( 'node_id' => 101, 'siteaccess' => 'site' ) );
        $this->assertSame( 'node/view/full.tpl', $c['template'] );
        $this->assertSame( 'full_article', $c['winner']['by'] );
        $this->assertSame( 'extension/media/design/media/override/templates/full/article.tpl', $c['winner']['path'] );
        $by = array_column( $c['overrides'], null, 'name' );
        $this->assertContains( 'taken (no conditions', $by['full_any']['result'] );
        $this->assertContains( 'missing', $by['full_any']['result'] );
        $this->assertSame( 'failed', $by['full_members']['result'] );
        $this->assertSame( array( 'section', 'url_alias' ), array_column( $by['full_members']['failed'], 'key' ) );
        $this->assertSame( 'not tried', $by['full_landing']['result'], 'the search stops at the first match' );
        $this->assertSame( 'full_landing', $this->ok( 'tpl_resolve', array( 'node_id' => 60 ) )['winner']['by'] );
        $home = $this->ok( 'tpl_resolve', array( 'node_id' => 2 ) );
        $this->assertSame( 'full_any', $home['winner']['by'], 'an override without conditions is taken when nothing later matches' );
        $this->assertSame( null, $home['winner']['path'] );
        $what = $this->ok( 'tpl_resolve', array( 'node_id' => 2, 'keys' => array( 'class_group' => array( '1', '7' ) ) ) );
        $this->assertSame( 'full_grouped', $what['winner']['by'], 'a list key matches by membership' );
        $members = $this->ok( 'tpl_resolve', array( 'node_id' => TronUnitFixture::MEMBERS_NODE ) );
        $this->assertSame( 'full_members', $members['winner']['by'], 'url_alias matches as a prefix' );
        $this->assertContains( 'not found', $this->err( 'tpl_resolve', array( 'node_id' => TronUnitFixture::MEMBERS_NODE ), TronUnitFixture::PARTIAL ) );
        $base = $this->ok( 'tpl_resolve', array( 'template' => 'node/view/full.tpl', 'keys' => array( 'class_identifier' => 'folder' ) ) );
        $this->assertSame( 'full_any', $base['winner']['by'] );
        $this->assertContains( 'No design', $this->err( 'tpl_resolve', array( 'template' => 'nothing.tpl' ) ) );
        $this->assertContains( 'node_id', $this->err( 'tpl_resolve', array() ) );
    }

    public function testTplSourceIsGuardedToDesignDirectoriesAndMasked(): void
    {
        $s = $this->ok( 'tpl_source', array( 'path' => 'extension/media/design/media/override/templates/full/article.tpl' ) );
        $this->assertSame( 3, $s['lines'] );
        $this->assertContains( '{$node.name|wash}', $s['text'] );
        $this->assertNotContains( 'letmein', $s['text'] );
        $this->assertNotContains( 'writer.one@example.org', $s['text'] );
        $part = $this->ok( 'tpl_source', array( 'path' => 'design/standard/templates/node/view/full.tpl', 'from_line' => 2, 'to_line' => 2 ) );
        $this->assertSame( '{* standard full view *}', $part['text'] );
        foreach ( array( 'settings/override/site.ini.append.php', 'design/standard/stylesheets/site.css', 'design/standard/templates/../../../settings/override/site.ini.append.php',
                         '/etc/passwd', 'design/standard/templates/missing.tpl', 'extension/media/../../settings/x.tpl' ) as $bad )
            $this->assertTrue( strpos( $this->err( 'tpl_source', array( 'path' => $bad ) ), 'hunter2' ) === false, $bad );
        $this->assertContains( 'not a .tpl file inside a design', $this->err( 'tpl_source', array( 'path' => 'settings/override/site.ini.append.php' ) ) );
        $this->assertContains( 'no template file', $this->err( 'tpl_source', array( 'path' => 'design/standard/templates/missing.tpl' ) ) );
        $this->assertContains( 'before from_line', $this->err( 'tpl_source', array( 'path' => 'design/standard/templates/node/view/full.tpl', 'from_line' => 2, 'to_line' => 1 ) ) );
    }

    public function testTplLintReportsErrorsAndUnknownNames(): void
    {
        $ok = $this->ok( 'tpl_lint', array( 'path' => 'design/standard/templates/node/view/full.tpl' ) );
        $this->assertTrue( $ok['ok'] );
        $broken = $this->ok( 'tpl_lint', array( 'path' => 'design/standard/templates/broken.tpl' ) );
        $this->assertFalse( $broken['ok'] );
        $this->assertSame( array( 'line 1: {if} is opened 1 times and closed 0 times' ), $broken['errors'], 'a missing end tag is found (the parser says nothing)' );
        $this->assertSame( array(), tronUnitsTplProgram::unclosed( "{* {if} *}{literal}{foreach}{/literal}{if \$a}{section-else}{/if}{section show=1}{/section}" ) );
        $this->assertSame( array( 'line 2: {foreach} is opened 2 times and closed 1 times' ), tronUnitsTplProgram::unclosed( "{foreach \$a as \$b}{/foreach}\n{foreach \$c as \$d}" ) );
        $landing = $this->ok( 'tpl_lint', array( 'path' => 'extension/media/design/media/override/templates/full/landing_page.tpl' ) );
        $this->assertSame( array( 'layout_zones' ), $landing['unknown'] );
        $text = $this->ok( 'tpl_lint', array( 'source' => '{$x|nosuchop}' ) );
        $this->assertSame( '(source)', $text['path'] );
        $this->assertSame( array( 'nosuchop' ), $text['unknown'] );
        $this->assertContains( 'one of them', $this->err( 'tpl_lint', array() ) );
    }

    // ------------------------------------------------------------------ node

    public function testNodeReadGivesPlainSafeValues(): void
    {
        $n = $this->ok( 'node_read', array( 'node_id' => 101, 'max_text' => 100 ) );
        $this->assertSame( 'Morning run', $n['name'] );
        $this->assertSame( 'Fit-Healthy/Morning-run', $n['url_alias'] );
        $a = array_column( $n['attributes'], 'value', 'identifier' );
        $this->assertSame( array( 102, 103 ), $a['related'] );
        $this->assertSame( 'A runner', $a['image']['alt'] );
        $this->assertSame( 'w***@e***.org', $a['author_email'] );
        $this->assertContains( '(4400 characters)', $a['intro'] );
        $this->assertSame( '[1]', $a['meta'][0]['v']['deep']['deeper']['deepest']['end'], 'deep structures end as JSON text' );
        $this->assertSame( '2026-11-24T12:00:00Z', tronUnitsNodeProgram::date( TronFixture::NOW ) );
        $only = $this->ok( 'node_read', array( 'node_id' => 101, 'attributes' => 'title, image' ) );
        $this->assertSame( array( 'title', 'image' ), array_column( $only['attributes'], 'identifier' ) );
        $this->assertContains( 'not found', $this->err( 'node_read', array( 'node_id' => 999 ) ) );
    }

    public function testNodeUnitsReadAsTheCallingUser(): void
    {
        $this->assertContains( 'Permission denied', $this->err( 'node_read', array( 'node_id' => 101 ), TronUnitFixture::NOBODY ) );
        $this->assertSame( 101, $this->ok( 'node_read', array( 'node_id' => 101 ), TronUnitFixture::PARTIAL )['node_id'], 'limited content/read is enough to call' );
        $this->assertContains( 'not found', $this->err( 'node_read', array( 'node_id' => TronUnitFixture::MEMBERS_NODE ), TronUnitFixture::PARTIAL ) );
        $this->assertSame( 4, $this->ok( 'node_find', array( 'class' => 'article' ), TronUnitFixture::READER )['total'] );
        $this->assertSame( 3, $this->ok( 'node_find', array( 'class' => 'article' ), TronUnitFixture::PARTIAL )['total'], 'lists hold only what the user may read' );
        $this->assertContains( 'Permission denied', $this->err( 'node_versions', array( 'node_id' => 101 ), TronUnitFixture::READER ) );
        $this->assertContains( 'Permission denied', $this->err( 'ini_resolve', array( 'file' => 'site', 'block' => 'A', 'setting' => 'B' ), TronUnitFixture::READER ) );
        $this->assertContains( 'Permission denied', $this->err( 'class_list', array(), TronUnitFixture::PARTIAL ) );
        $list = $this->f['server']->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' ), TronUnitFixture::session( TronUnitFixture::READER ) );
        $visible = array_column( $list['result']['tools'], 'name' );
        $this->assertContains( 'node_read', $visible );
        $this->assertNotContains( 'ini_resolve', $visible );
        $this->assertNotContains( 'node_versions', $visible );
    }

    public function testNodeTreeFindAndVersions(): void
    {
        $t = $this->ok( 'node_tree', array( 'node_id' => 60 ) );
        $this->assertSame( 3, $t['total'] );
        $this->assertSame( array( 101, 102, 103 ), array_column( $t['items'], 'node_id' ), 'newest first' );
        $this->assertSame( 0, $this->ok( 'node_tree', array( 'node_id' => 2, 'class' => 'article,folder', 'depth' => 1 ) )['total'] - 1, 'depth 1: only the members page' );
        $this->assertSame( 4, $this->ok( 'node_tree', array( 'node_id' => 2, 'depth' => 2, 'class' => 'article' ) )['total'] );
        $byPath = $this->ok( 'node_find', array( 'path' => 'Fit-Healthy', 'sort' => 'name', 'order' => 'asc', 'limit' => 2 ) );
        $this->assertSame( 3, $byPath['total'] );
        $this->assertSame( array( 'Green breakfast', 'Morning run' ), array_column( $byPath['items'], 'name' ) );
        $this->assertSame( array( 103 ), array_column( $this->ok( 'node_find', array( 'class' => 'article', 'attribute' => 'title', 'value' => 'Stretching' ) )['items'], 'node_id' ) );
        $this->assertSame( array( 102 ), array_column( $this->ok( 'node_find', array( 'name' => 'breakfast' ) )['items'], 'node_id' ) );
        $this->assertSame( array( TronUnitFixture::MEMBERS_NODE ), array_column( $this->ok( 'node_find', array( 'section' => 2 ) )['items'], 'node_id' ) );
        $this->assertContains( 'exactly one class', $this->err( 'node_find', array( 'attribute' => 'title', 'value' => 'x' ) ) );
        $this->assertContains( 'No node has the path', $this->err( 'node_find', array( 'path' => 'Nowhere' ) ) );
        $v = $this->ok( 'node_versions', array( 'node_id' => 101 ) );
        $this->assertSame( array( 3, 2, 1 ), array_column( $v['items'], 'version' ) );
        $this->assertSame( 'e***@e***.org', $v['items'][0]['creator'] );
        $this->assertCount( 1, $this->ok( 'node_versions', array( 'node_id' => 101, 'limit' => 1 ) )['items'] );
    }

    // ------------------------------------------------------------------ class

    public function testClassListDescribeAndDiff(): void
    {
        $l = $this->ok( 'class_list' );
        $this->assertSame( array( 'article', 'landing_page', 'user' ), array_column( $l['items'], 'identifier' ) );
        $this->assertSame( array( 'user' ), array_column( $this->ok( 'class_list', array( 'group' => 'Users' ) )['items'], 'identifier' ) );
        $c = $this->ok( 'class_describe', array( 'identifier' => 'article' ) );
        $this->assertSame( array( 'title', 'intro', 'image' ), array_column( $c['attributes'], 'identifier' ) );
        $this->assertSame( true, $c['attributes'][0]['required'] );
        $this->assertContains( "no content class 'nope'", $this->err( 'class_describe', array( 'identifier' => 'nope' ) ) );
        $d = $this->ok( 'class_diff', array( 'identifier' => 'article', 'other' => 'article_old' ) );
        $keys = array_column( $d['items'], 'change', 'key' );
        $this->assertSame( 'changed', $keys['class.is_container'] );
        $this->assertSame( 'changed', $keys['attribute.title.settings'] );
        $this->assertSame( 'only_a', $keys['attribute.image'] );
        $this->assertSame( 'only_b', $keys['attribute.teaser'] );
        $same = $this->ok( 'class_diff', array( 'identifier' => 'article', 'other_definition' => $c ) );
        $this->assertSame( 0, $same['total'], 'a class.describe value from elsewhere compares with the class here' );
        $this->assertContains( 'other_definition', $this->err( 'class_diff', array( 'identifier' => 'article' ) ) );
    }

    // ------------------------------------------------------------------ layout

    public function testLayoutUnits(): void
    {
        $l = $this->ok( 'layout_list' );
        $this->assertSame( array( 2, 27 ), array_column( $l['items'], 'id' ), 'drafts left out' );
        $this->assertSame( 3, $this->ok( 'layout_list', array( 'drafts' => true ) )['total'] );
        $this->assertSame( array( 2 ), array_column( $this->ok( 'layout_list', array( 'shared' => true ) )['items'], 'id' ) );
        $r = $this->ok( 'layout_read', array( 'identifier' => 'fit_healthy' ) );
        $this->assertSame( array( 'layout_id' => 2, 'zone' => 'header' ), $r['zones'][0]['linked'] );
        $this->assertSame( 'header_footer', $r['zones'][0]['source']['layout_identifier'] );
        $this->assertSame( 'list', $r['zones'][1]['blocks'][0]['definition'] );
        $this->assertContains( 'No draft of', $this->err( 'layout_read', array( 'layout_id' => 27, 'draft' => true ) ) );
        $rules = $this->ok( 'layout_rule_list', array( 'enabled' => true ) );
        $this->assertSame( array( 9 ), array_column( $rules['items'], 'id' ) );
        $this->assertSame( array( 3 ), array_column( $this->ok( 'layout_rule_list', array( 'layout_id' => 2 ) )['items'], 'id' ) );
        $t = $this->ok( 'layout_rule_resolve', array( 'node_id' => 101 ) );
        $this->assertSame( 27, $t['layout_id'] );
        $this->assertSame( 'Fit-Healthy/Morning-run', $t['path'] );
        $this->assertSame( 0, $this->ok( 'layout_rule_resolve', array( 'path' => 'elsewhere' ) )['layout_id'] );
        $b = $this->ok( 'layout_block_definitions' );
        $this->assertSame( 2, $b['total'] );
        $this->assertSame( '[redacted]', $b['items'][1]['api_token'] );
    }

    // ------------------------------------------------------------------ role

    public function testRoleUnitsShowNoPersonalData(): void
    {
        $r = $this->ok( 'role_describe', array( 'role' => 'Editor' ) );
        $this->assertSame( array( 'Section' ), array_column( $r['policies'][0]['limitations'], 'identifier' ) );
        $this->assertSame( array( '1', '2' ), $r['policies'][0]['limitations'][0]['values'] );
        $this->assertSame( 'w***@e***.org', $r['assignments'][1]['name'] );
        $this->assertContains( 'Anonymous, Administrator, Editor', $this->err( 'role_describe', array( 'role' => 'Ghost' ) ) );
        $u = $this->ok( 'role_user_find', array( 'text' => 'writer' ) );
        $this->assertSame( 2, $u['total'] );
        $this->assertSame( array( 'user_id', 'login', 'name', 'enabled', 'roles' ), array_keys( $u['items'][0] ) );
        $this->assertNotContains( 'example.org', json_encode( $u ) );
        $this->assertSame( false, $u['items'][1]['enabled'] );
    }

    // ------------------------------------------------------------------ pipe

    private function pipe( array $args, int $user = TronUnitFixture::DESIGNER )
    {
        $r = TronFixture::call( $this->f['server'], TronUnitFixture::session( $user ), 'tron_pipe', $args );
        if ( !empty( $r['result']['isError'] ) )
            $this->fail( 'tron_pipe failed: ' . $r['result']['content'][0]['text'] );
        return TronFixture::data( $r );
    }

    public function testPipeNodeFindReadResolve(): void
    {
        $before = count( $this->f['audit']->entries );
        $p = $this->pipe( array( 'pipe' => 'node.find class=article limit 3 path=Fit-Healthy | node.read | tpl.resolve siteaccess=site' ) );
        $this->assertSame( 'node.find class=article limit=3 path=Fit-Healthy | node.read | tpl.resolve siteaccess=site', $p['pipe'] );
        $this->assertSame( 'template-chain', $p['out'] );
        $this->assertSame( 3, $p['count'] );
        $this->assertSame( array( 'full_article', 'full_article', 'full_article' ), array_column( array_column( $p['results'], 'winner' ), 'by' ) );
        $this->assertSame( array( 101, 102, 103 ), array_column( $p['results'], 'node_id' ) );
        $this->assertSame( array( 1, 3, 3 ), array_column( $p['steps'], 'calls' ) );
        $this->assertSame( array( 'none', 'node-ref', 'node-ref' ), array_column( $p['steps'], 'in' ) );
        $this->assertSame( 7, $p['calls'] );
        $targets = array_column( array_slice( $this->f['audit']->entries, $before ), 'target' );
        $this->assertSame( array( 'node_find', 'node_read', 'node_read', 'node_read', 'tpl_resolve', 'tpl_resolve', 'tpl_resolve', 'tron_pipe' ), $targets, 'every step audited, then the pipe' );
    }

    public function testPipeStepsFormFanOutReuseAndLimits(): void
    {
        $p = $this->pipe( array( 'steps' => array( array( 'unit' => 'node_find', 'args' => array( 'class' => 'article' ) ), array( 'unit' => 'class.describe' ) ) ) );
        $this->assertSame( 1, $p['count'] );
        $this->assertSame( 4, $p['steps'][1]['inputs'] );
        $this->assertSame( 3, $p['steps'][1]['reused'], 'the same class is described once' );
        $cut = $this->pipe( array( 'pipe' => 'node.find class=article | node.read', 'max_items' => 2 ) );
        $this->assertSame( 2, $cut['count'] );
        $this->assertSame( 2, $cut['steps'][1]['dropped'] );
        $layouts = $this->pipe( array( 'pipe' => 'node.tree node_id=60 | rule.resolve | layout.read' ) );
        $this->assertSame( 'layout', $layouts['out'] );
        $this->assertSame( array( 27 ), array_column( $layouts['results'], 'id' ) );
        $src = $this->pipe( array( 'pipe' => 'tpl.resolve node_id=60 | tpl.source | tpl.lint' ) );
        $this->assertSame( array( 'layout_zones' ), $src['results'][0]['unknown'] );
        $x = $this->pipe( array( 'pipe' => 'node.find class=article | node.read | tpl.resolve', 'explain' => true ) );
        $this->assertTrue( $x['explained'] );
        $this->assertSame( array( false, true, false ), array_column( $x['steps'], 'fan_out' ) );
        $this->assertSame( array(), $x['results'] );
    }

    public function testPipeRefusesWhatCannotRun(): void
    {
        $e = function ( array $a, int $user = TronUnitFixture::DESIGNER ) {
            return $this->err( 'tron_pipe', $a, $user );
        };
        $this->assertContains( 'takes node-ref, but step 1 (class.list) gives class-summary', $e( array( 'pipe' => 'class.list | node.read' ) ) );
        $this->assertContains( "'centre_ticket_list' is not a unit", $e( array( 'pipe' => 'centre_ticket_list' ) ) );
        $this->assertContains( 'needs its input', $e( array( 'pipe' => 'node.read | tpl.resolve' ) ) );
        $this->assertContains( 'needs the policy setup/setup', $e( array( 'pipe' => 'node.find | tpl.resolve' ), TronUnitFixture::READER ) );
        $this->assertContains( 'at most 8 steps', $e( array( 'pipe' => implode( ' | ', array_fill( 0, 9, 'class.list' ) ) ) ) );
        $this->assertContains( 'one of them', $e( array() ) );
        $this->assertContains( 'not a unit', $e( array( 'steps' => array( array( 'unit' => 'x' ) ) ) ) );
        // a step that fails for every item ends the pipe early, with the reason
        $p = $this->pipe( array( 'pipe' => 'node.find class=article | tpl.resolve' ), TronUnitFixture::DESIGNER );
        $this->assertSame( 4, $p['count'] );
        $partial = $this->pipe( array( 'pipe' => 'node.find section=2 | node.read' ), TronUnitFixture::DESIGNER );
        $this->assertTrue( $partial['complete'] );
        $this->assertContains( 'needs the policy content/read', $this->err( 'tron_pipe', array( 'pipe' => 'node.find' ), TronUnitFixture::NOBODY ) );
    }

    public function testPipeTextParsing(): void
    {
        $steps = tronUnitPipe::parse( 'node.find class=article limit 3 name="green breakfast" | node.read attributes=title,intro | tpl.resolve keys=\'{"a":"b"}\' flag', $this->f['server']->registry() );
        $this->assertSame( array( 'class' => 'article', 'limit' => 3, 'name' => 'green breakfast' ), $steps[0]['args'] );
        $this->assertSame( array( 'attributes' => 'title,intro' ), $steps[1]['args'] );
        $this->assertSame( array( 'keys' => array( 'a' => 'b' ), 'flag' => true ), $steps[2]['args'] );
        $this->assertSame( array( 'unit' => 'a', 'args' => array( 'x' => 'a|b' ) ), tronUnitPipe::parse( 'a x="a|b"' )[0] );
    }

    public function testUnitsResource(): void
    {
        $r = $this->f['server']->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/read', 'params' => array( 'uri' => 'tron://units' ) ), TronUnitFixture::session( TronUnitFixture::NOBODY ) );
        $d = json_decode( $r['result']['contents'][0]['text'], true );
        $this->assertCount( 22, $d['units'] );
        $this->assertSame( 8, $d['limits']['steps'] );
    }
}
