<?php
/**
 * The units' test world: a small made-up site (a front page, a landing page with three articles, a member page only
 * some users may read), two siteaccesses with INI settings, a design with overrides, two classes, three layouts (one
 * shared), rules, block types, two roles and three accounts. All names and addresses are invented.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

final class TronUnitFixture
{
    /** users of the units tests (ids above the core fixture's) */
    const DESIGNER = 21, READER = 22, PARTIAL = 23, NOBODY = 24;
    const MEMBERS_NODE = 70;

    public static function users(): array
    {
        return array( self::DESIGNER => 'designer', self::READER => 'reader', self::PARTIAL => 'partial', self::NOBODY => 'nobody' );
    }

    public static function access(): tronRoleAccessChecker
    {
        return new tronRoleAccessChecker(
            array( 'designer' => array( 'tron/use', 'setup/setup', 'class/*', 'explayouts/read', 'role/*', 'content/read', 'content/versionread' ),
                   'reader' => array( 'tron/use', 'content/read' ),
                   'partial' => array( 'tron/use', 'content/read:limited', 'setup/setup' ),
                   'nobody' => array( 'tron/use' ) ),
            array( self::DESIGNER => array( 'designer' ), self::READER => array( 'reader' ), self::PARTIAL => array( 'partial' ),
                   self::NOBODY => array( 'nobody' ) ) );
    }

    public static function services(): array
    {
        return array(
            'units.ini' => new TronUnitFixtureIni(), 'units.tpl' => new TronUnitFixtureTpl(), 'units.node' => new TronUnitFixtureNode(),
            'units.class' => new TronUnitFixtureClass(), 'units.layout' => new TronUnitFixtureLayout(), 'units.role' => new TronUnitFixtureRole(),
        );
    }

    /** The whole stack with the units: array( server, ... ) as TronFixture::build() gives it. */
    public static function build( array $o = array() ): array
    {
        return TronFixture::build( $o + array( 'access' => self::access(), 'services' => self::services() ) );
    }

    public static function session( int $userId, string $version = '2025-11-25' ): tronSession
    {
        return new tronSession( new tronPrincipal( $userId, self::users()[$userId] ), 'stdio', false, $version, 'test' );
    }

    public static function nodes(): array
    {
        $t = TronFixture::NOW;
        $n = function ( int $id, int $parent, int $depth, string $name, string $class, string $alias, array $attrs, int $section = 1 ) use ( $t ) {
            return array( 'node_id' => $id, 'object_id' => $id + 776, 'name' => $name, 'class_identifier' => $class,
                          'class_name' => ucfirst( str_replace( '_', ' ', $class ) ), 'url_alias' => $alias, 'parent_node_id' => $parent,
                          'depth' => $depth, 'section_id' => $section, 'section' => $section === 1 ? 'Standard' : 'Members',
                          'language' => 'eng-GB', 'published' => $t - $id * 3600, 'modified' => $t - $id * 60, 'current_version' => 2,
                          'children_count' => 0, 'hidden' => false, 'remote_id' => 'remote-' . $id, 'attributes' => $attrs );
        };
        return array(
            1 => $n( 1, 0, 0, 'Top Level Nodes', 'folder', '', array() ),
            2 => $n( 2, 1, 1, 'Home', 'frontpage', '', array( array( 'identifier' => 'name', 'name' => 'Name', 'datatype' => 'ezstring', 'value' => 'Home' ) ) ),
            60 => $n( 60, 2, 2, 'Fit & Healthy', 'landing_page', 'Fit-Healthy', array(
                array( 'identifier' => 'name', 'name' => 'Name', 'datatype' => 'ezstring', 'value' => 'Fit & Healthy' ),
                array( 'identifier' => 'tags', 'name' => 'Tags', 'datatype' => 'eztags', 'value' => array( 'fitness', 'food' ) ) ) ),
            101 => $n( 101, 60, 3, 'Morning run', 'article', 'Fit-Healthy/Morning-run', array(
                array( 'identifier' => 'title', 'name' => 'Title', 'datatype' => 'ezstring', 'value' => 'Morning run' ),
                array( 'identifier' => 'intro', 'name' => 'Intro', 'datatype' => 'ezrichtext', 'value' => str_repeat( 'Run early. ', 400 ) ),
                array( 'identifier' => 'image', 'name' => 'Image', 'datatype' => 'ezimage', 'value' => array( 'alt' => 'A runner', 'width' => 800, 'url' => 'var/storage/images/run.jpg' ) ),
                array( 'identifier' => 'author_email', 'name' => 'Contact', 'datatype' => 'ezemail', 'value' => 'writer.one@example.org' ),
                array( 'identifier' => 'related', 'name' => 'Related', 'datatype' => 'ezobjectrelationlist', 'value' => array( 102, 103 ) ),
                array( 'identifier' => 'meta', 'name' => 'Meta', 'datatype' => 'ezmatrix', 'value' => array( array( 'k' => 'a', 'v' => array( 'deep' => array( 'deeper' => array( 'deepest' => array( 'end' => array( 1 ) ) ) ) ) ) ) ) ) ),
            102 => $n( 102, 60, 3, 'Green breakfast', 'article', 'Fit-Healthy/Green-breakfast', array(
                array( 'identifier' => 'title', 'name' => 'Title', 'datatype' => 'ezstring', 'value' => 'Green breakfast' ) ) ),
            103 => $n( 103, 60, 3, 'Stretching', 'article', 'Fit-Healthy/Stretching', array(
                array( 'identifier' => 'title', 'name' => 'Title', 'datatype' => 'ezstring', 'value' => 'Stretching' ) ) ),
            self::MEMBERS_NODE => $n( self::MEMBERS_NODE, 2, 2, 'Members only', 'article', 'Members-only', array(
                array( 'identifier' => 'title', 'name' => 'Title', 'datatype' => 'ezstring', 'value' => 'Members only' ) ), 2 ),
        );
    }
}

final class TronUnitFixtureNode implements tronUnitNodeSource
{
    public $calls = 0;

    public function available(): bool
    {
        return true;
    }

    public function reason(): string
    {
        return '';
    }

    private function readable( int $id, int $userId ): bool
    {
        return !( $userId === TronUnitFixture::PARTIAL && $id === TronUnitFixture::MEMBERS_NODE );
    }

    public function node( int $nodeId, int $userId, string $language ): ?array
    {
        $this->calls++;
        $all = TronUnitFixture::nodes();
        return isset( $all[$nodeId] ) && $this->readable( $nodeId, $userId ) ? $all[$nodeId] : null;
    }

    public function nodes( array $c, int $limit, int $offset, int $userId ): array
    {
        $all = TronUnitFixture::nodes();
        $parent = $all[$c['parent']] ?? null;
        $out = array();
        foreach ( $all as $id => $n )
        {
            if ( !$parent || $id === $parent['node_id'] || !$this->readable( $id, $userId ) )
                continue;
            // below the parent: walk up
            $p = $n['parent_node_id'];
            $below = false;
            while ( $p )
            {
                if ( $p === $parent['node_id'] )
                {
                    $below = true;
                    break;
                }
                $p = $all[$p]['parent_node_id'] ?? 0;
            }
            if ( !$below || $n['depth'] - $parent['depth'] > (int)( $c['depth'] ?? 1 ) )
                continue;
            if ( isset( $c['classes'] ) && !in_array( $n['class_identifier'], $c['classes'], true ) )
                continue;
            if ( isset( $c['section'] ) && $n['section_id'] !== $c['section'] )
                continue;
            if ( isset( $c['name'] ) && stripos( $n['name'], $c['name'] ) === false )
                continue;
            if ( isset( $c['attribute'] ) )
            {
                list( $cls, $attr ) = explode( '/', $c['attribute'] );
                $vals = array_column( $n['attributes'], 'value', 'identifier' );
                if ( $n['class_identifier'] !== $cls || !isset( $vals[$attr] ) || (string)$vals[$attr] !== (string)$c['value'] )
                    continue;
            }
            $out[] = $n;
        }
        $key = array( 'published' => 'published', 'modified' => 'modified', 'name' => 'name', 'priority' => 'node_id', 'path' => 'url_alias' )[$c['sort'] ?? 'published'];
        usort( $out, function ( $a, $b ) use ( $key ) { return $a[$key] <=> $b[$key]; } );
        if ( ( $c['order'] ?? 'desc' ) === 'desc' )
            $out = array_reverse( $out );
        return array( 'total' => count( $out ), 'items' => array_slice( $out, $offset, $limit ) );
    }

    public function nodeByPath( string $path ): ?int
    {
        foreach ( TronUnitFixture::nodes() as $id => $n )
            if ( $n['url_alias'] !== '' && strcasecmp( $n['url_alias'], trim( $path, '/' ) ) === 0 )
                return $id;
        return null;
    }

    public function versions( int $nodeId, int $userId ): ?array
    {
        $n = $this->node( $nodeId, $userId, '' );
        if ( !$n )
            return null;
        return array( 'node_id' => $nodeId, 'object_id' => $n['object_id'], 'current_version' => 2, 'items' => array(
            array( 'version' => 1, 'status' => 'archived', 'language' => 'eng-GB', 'creator_id' => 14, 'creator' => 'Editor One', 'created' => TronFixture::NOW - 7200, 'modified' => TronFixture::NOW - 7200 ),
            array( 'version' => 2, 'status' => 'published', 'language' => 'eng-GB', 'creator_id' => 14, 'creator' => 'Editor One', 'created' => TronFixture::NOW - 3600, 'modified' => TronFixture::NOW - 3600 ),
            array( 'version' => 3, 'status' => 'draft', 'language' => 'eng-GB', 'creator_id' => 15, 'creator' => 'editor.two@example.org', 'created' => TronFixture::NOW, 'modified' => TronFixture::NOW ),
        ) );
    }
}

final class TronUnitFixtureIni implements tronUnitIniSource
{
    const SETTINGS = array(
        'site' => array(
            'site' => array(
                'SiteSettings' => array( 'SiteName' => array( 'Fit & Healthy', 'settings/siteaccess/site/site.ini.append.php' ), 'IndexPage' => array( '/content/view/full/2', 'settings/site.ini' ) ),
                'DatabaseSettings' => array( 'Password' => array( 'hunter2', 'settings/override/site.ini.append.php' ), 'Server' => array( 'db', 'settings/override/site.ini.append.php' ) ),
                'DesignSettings' => array( 'SiteDesign' => array( 'media', 'settings/siteaccess/site/site.ini.append.php' ),
                                           'AdditionalSiteDesignList' => array( array( 'standard' ), 'settings/siteaccess/site/site.ini.append.php' ) ),
            ),
            'admin' => array(
                'SiteSettings' => array( 'SiteName' => array( 'Administration', 'settings/siteaccess/admin/site.ini.append.php' ), 'IndexPage' => array( '/content/dashboard', 'settings/siteaccess/admin/site.ini.append.php' ) ),
                'DatabaseSettings' => array( 'Password' => array( 'hunter2', 'settings/override/site.ini.append.php' ), 'Server' => array( 'db', 'settings/override/site.ini.append.php' ) ),
                'DesignSettings' => array( 'SiteDesign' => array( 'admin4', 'settings/siteaccess/admin/site.ini.append.php' ) ),
                'ContentSettings' => array( 'CachedViewPreferences' => array( array( 'full' => 'admin_navigation_content=1' ), 'settings/siteaccess/admin/site.ini.append.php' ) ),
            ),
        ),
        'explayouts' => array(
            'site' => array( 'ResolverSettings' => array( 'DefaultLayout' => array( 'default_page', 'extension/explayouts/settings/explayouts.ini' ) ) ),
            'admin' => array( 'ResolverSettings' => array( 'DefaultLayout' => array( 'default_page', 'extension/explayouts/settings/explayouts.ini' ) ) ),
        ),
    );

    public function available(): bool
    {
        return true;
    }

    public function reason(): string
    {
        return '';
    }

    public function siteAccesses(): array
    {
        return array( 'site', 'admin' );
    }

    public function files(): array
    {
        return array_keys( self::SETTINGS );
    }

    public function where( string $file, string $block, string $setting, string $siteAccess ): array
    {
        $sa = $siteAccess !== '' ? $siteAccess : 'site';
        $s = self::SETTINGS[$file][$sa][$block][$setting] ?? null;
        if ( $s === null )
            return array( 'siteaccess' => $sa, 'found' => false, 'value' => null, 'files' => array(), 'notLoaded' => array() );
        $files = array();
        if ( $s[1] !== 'settings/site.ini' && $file === 'site' && $block === 'SiteSettings' )
            $files[] = array( 'path' => 'settings/site.ini', 'scope' => 'default', 'value' => 'Exponential' );
        $files[] = array( 'path' => $s[1], 'scope' => strpos( $s[1], 'siteaccess' ) !== false ? 'siteaccess' : 'override', 'value' => $s[0] );
        return array( 'siteaccess' => $sa, 'found' => true, 'value' => $s[0], 'winner' => $s[1], 'files' => $files,
                      'notLoaded' => $block === 'SiteSettings' ? array( array( 'path' => 'extension/inactive/settings/site.ini.append.php', 'scope' => 'extension', 'reason' => 'the extension inactive is not active', 'value' => 'Other' ) ) : array() );
    }

    public function settings( string $file, string $siteAccess ): array
    {
        $sa = $siteAccess !== '' ? $siteAccess : 'site';
        $blocks = array();
        foreach ( self::SETTINGS[$file][$sa] ?? array() as $block => $settings )
            foreach ( $settings as $name => $s )
                $blocks[$block][$name] = array( 'value' => $s[0], 'winner' => $s[1], 'kind' => is_array( $s[0] ) ? ( array_keys( $s[0] ) === range( 0, count( $s[0] ) - 1 ) ? 'list' : 'hash' ) : 'plain' );
        return array( 'siteaccess' => $sa, 'blocks' => $blocks );
    }
}

final class TronUnitFixtureTpl implements tronUnitTplSource
{
    /** @var string a scratch installation root with design files */ private $root;

    public function __construct()
    {
        $this->root = TronFixture::tmp( 'units-root' ) . '/';
        TronFixture::write( $this->root . 'design/standard/templates/node/view/full.tpl', "{\$node.name|wash}\n{* standard full view *}\n" );
        TronFixture::write( $this->root . 'extension/media/design/media/override/templates/full/article.tpl', "<h1>{\$node.name|wash}</h1>\n{\$node.data_map.intro.content.output.output_text}\n{* contact: password=letmein, writer.one@example.org *}\n" );
        TronFixture::write( $this->root . 'extension/media/design/media/override/templates/full/landing_page.tpl', "{layout_zones( \$node )}\n{fetch('explayouts','resolve_layout', hash()).result|attribute(show)}\n" );
        TronFixture::write( $this->root . 'design/standard/templates/broken.tpl', "{if \$x}\nno end\n" );
        TronFixture::write( $this->root . 'settings/override/site.ini.append.php', "<?php /*\n[DatabaseSettings]\nPassword=hunter2\n*/ ?>\n" );
        TronFixture::write( $this->root . 'design/standard/stylesheets/site.css', "body{}\n" );
    }

    public function available(): bool
    {
        return true;
    }

    public function reason(): string
    {
        return '';
    }

    public function siteAccesses(): array
    {
        return array( 'site', 'admin' );
    }

    public function overrides( string $template, string $siteAccess ): ?array
    {
        if ( $template !== 'node/view/full.tpl' )
            return null;
        return array( 'siteaccess' => $siteAccess !== '' ? $siteAccess : 'site', 'designs' => array( 'extension/media/design/media', 'design/standard' ),
            'base' => array( 'path' => 'design/standard/templates/node/view/full.tpl', 'design' => 'standard' ),
            'overrides' => array(
                array( 'name' => 'full_any', 'match_file' => null, 'conditions' => array() ),
                array( 'name' => 'full_members', 'match_file' => 'extension/media/design/media/override/templates/full/article.tpl', 'conditions' => array( 'class_identifier' => 'article', 'section' => '2', 'url_alias' => 'Members' ) ),
                array( 'name' => 'full_article', 'match_file' => 'extension/media/design/media/override/templates/full/article.tpl', 'conditions' => array( 'class_identifier' => 'article' ) ),
                array( 'name' => 'full_landing', 'match_file' => 'extension/media/design/media/override/templates/full/landing_page.tpl', 'conditions' => array( 'class_identifier' => 'landing_page' ) ),
                array( 'name' => 'full_grouped', 'match_file' => 'extension/media/design/media/override/templates/full/article.tpl', 'conditions' => array( 'class_group' => '7' ) ),
            ) );
    }

    public function nodeKeys( int $nodeId, string $view, int $userId ): ?array
    {
        $n = ( new TronUnitFixtureNode() )->node( $nodeId, $userId, '' );
        if ( !$n )
            return null;
        return array( 'node' => $nodeId, 'object' => $n['object_id'], 'class_identifier' => $n['class_identifier'], 'section' => $n['section_id'],
                      'url_alias' => $n['url_alias'], 'viewmode' => $view, 'parent_node' => $n['parent_node_id'], 'class_group' => array( 1 ) );
    }

    public function root(): string
    {
        return $this->root;
    }

    public function read( string $path ): ?string
    {
        $t = @file_get_contents( $this->root . $path );
        return $t === false ? null : $t;
    }

    public function lint( string $path, ?string $text ): array
    {
        $text = $text ?? (string)$this->read( $path );
        $errors = array();
        // the parser reports an end tag without a start tag (it says nothing about a missing end tag)
        if ( substr_count( $text, '{/if}' ) > substr_count( $text, '{if' ) )
            $errors[] = 'End tag "if" without a start tag, ignoring tag';
        preg_match_all( '/\|([a-z_]+)/', $text, $ops );
        preg_match_all( '/\{([a-z_]+)[\s(]/', $text, $fns );
        $known = array( 'wash', 'attribute', 'if', 'fetch', 'hash', 'def', 'foreach' );
        $used = array_merge( $ops[1], $fns[1] );
        return array( 'errors' => $errors, 'warnings' => array(), 'functions' => $fns[1], 'operators' => $ops[1],
                      'unknown' => array_values( array_diff( $used, $known ) ) );
    }
}

final class TronUnitFixtureClass implements tronUnitClassSource
{
    public static function article( bool $other = false ): array
    {
        $attrs = array(
            array( 'id' => 1, 'identifier' => 'title', 'name' => 'Title', 'datatype' => 'ezstring', 'required' => true, 'searchable' => true, 'placement' => 1, 'settings' => array( 'max_length' => 255 ) ),
            array( 'id' => 2, 'identifier' => 'intro', 'name' => 'Intro', 'datatype' => 'ezrichtext', 'required' => false, 'searchable' => true, 'placement' => 2 ),
            array( 'id' => 3, 'identifier' => 'image', 'name' => 'Image', 'datatype' => 'ezimage', 'required' => false, 'placement' => 3 ),
        );
        if ( $other )
        {
            $attrs[0]['settings'] = array( 'max_length' => 100 );
            unset( $attrs[2] );
            $attrs[] = array( 'id' => 9, 'identifier' => 'teaser', 'name' => 'Teaser', 'datatype' => 'eztext', 'placement' => 4 );
        }
        return array( 'id' => 16, 'identifier' => 'article', 'name' => 'Article', 'groups' => array( 'Content' ), 'is_container' => $other,
                      'always_available' => false, 'object_name_pattern' => '<title>', 'url_alias_pattern' => '', 'sort_field' => 'published',
                      'sort_order' => 'desc', 'object_count' => 4, 'modified' => TronFixture::NOW, 'attributes' => array_values( $attrs ) );
    }

    public function available(): bool
    {
        return true;
    }

    public function reason(): string
    {
        return '';
    }

    public function classes(): array
    {
        return array( self::article(),
            array( 'id' => 30, 'identifier' => 'landing_page', 'name' => 'Landing page', 'groups' => array( 'Content' ), 'is_container' => true, 'object_count' => 1, 'modified' => 0 ),
            array( 'id' => 4, 'identifier' => 'user', 'name' => 'User', 'groups' => array( 'Users' ), 'is_container' => false, 'object_count' => 3, 'modified' => 0 ) );
    }

    public function describe( string $identifier ): ?array
    {
        if ( $identifier === 'article' || $identifier === '16' )
            return self::article();
        if ( $identifier === 'article_old' )
            return array( 'identifier' => 'article_old' ) + self::article( true );
        return null;
    }
}

final class TronUnitFixtureLayout implements tronUnitLayoutSource
{
    public function available(): bool
    {
        return true;
    }

    public function reason(): string
    {
        return '';
    }

    public function layouts(): array
    {
        return array(
            array( 'id' => 2, 'identifier' => 'header_footer', 'name' => 'Header / Footer', 'layout_type' => 'layout_1', 'shared' => 1, 'status' => 'published', 'zones' => 2 ),
            array( 'id' => 27, 'identifier' => 'fit_healthy', 'name' => 'Fit & Healthy', 'layout_type' => 'layout_2', 'shared' => 0, 'status' => 'published', 'zones' => 3 ),
            array( 'id' => 28, 'identifier' => 'fit_healthy', 'name' => 'Fit & Healthy', 'layout_type' => 'layout_2', 'shared' => 0, 'status' => 'draft', 'zones' => 3 ),
        );
    }

    public function layout( string $layout, bool $draft ): ?array
    {
        if ( ( $layout === '27' || $layout === 'fit_healthy' ) && !$draft )
            return array( 'id' => 27, 'identifier' => 'fit_healthy', 'name' => 'Fit & Healthy', 'layout_type' => 'layout_2', 'shared' => false, 'status' => 'published', 'zones' => array(
                array( 'identifier' => 'header', 'linked' => array( 'layout_id' => 2, 'zone' => 'header' ),
                       'source' => array( 'layout_id' => 2, 'layout_identifier' => 'header_footer', 'zone' => 'header' ),
                       'blocks' => array( array( 'id' => 5, 'definition' => 'menu', 'name' => 'Main menu', 'view_type' => 'menu', 'item_view_type' => '', 'position' => 0, 'placeholder' => '', 'parameters' => array( 'depth' => 1 ), 'children' => array() ) ) ),
                array( 'identifier' => 'main', 'linked' => null, 'source' => array( 'layout_id' => 27, 'layout_identifier' => 'fit_healthy', 'zone' => 'main' ),
                       'blocks' => array( array( 'id' => 80, 'definition' => 'list', 'name' => 'Latest', 'view_type' => 'grid', 'item_view_type' => 'standard_with_intro', 'position' => 0, 'placeholder' => '', 'parameters' => array( 'limit' => 3 ), 'children' => array() ) ) ),
            ) );
        if ( $layout === '2' || $layout === 'header_footer' )
            return array( 'id' => 2, 'identifier' => 'header_footer', 'name' => 'Header / Footer', 'layout_type' => 'layout_1', 'shared' => true, 'status' => 'published', 'zones' => array() );
        return null;
    }

    public function rules(): array
    {
        return array(
            array( 'id' => 9, 'priority' => 20, 'enabled' => 1, 'layout_id' => 27, 'layout_identifier' => 'fit_healthy',
                   'targets' => array( array( 'type' => 'subtree', 'value' => '60' ) ), 'conditions' => array() ),
            array( 'id' => 3, 'priority' => 10, 'enabled' => 0, 'layout_id' => 2, 'layout_identifier' => 'header_footer',
                   'targets' => array( array( 'type' => 'path_info_prefix', 'value' => '/' ) ), 'conditions' => array() ),
        );
    }

    public function resolve( string $path ): array
    {
        $hit = stripos( $path, 'Fit-Healthy' ) === 0;
        return array( 'path' => $path, 'layout_id' => $hit ? 27 : 0, 'layout_identifier' => $hit ? 'fit_healthy' : '', 'source' => $hit ? 'rule' : 'none',
                      'rule_id' => $hit ? 9 : 0, 'request_dependent' => false,
                      'tried' => array( array( 'rule_id' => 9, 'priority' => 20, 'layout_id' => 27, 'targets_match' => $hit, 'conditions' => array(), 'matched' => $hit ) ) );
    }

    public function pathOfNode( int $nodeId ): ?string
    {
        $n = TronUnitFixture::nodes()[$nodeId] ?? null;
        return $n ? $n['url_alias'] : null;
    }

    public function blockDefinitions(): array
    {
        return array( array( 'identifier' => 'list', 'name' => 'List', 'view_types' => array( 'grid', 'list' ), 'handler' => 'list' ),
                      array( 'identifier' => 'html', 'name' => 'HTML', 'view_types' => array( 'html' ), 'api_token' => 'abc123' ) );
    }
}

final class TronUnitFixtureRole implements tronUnitRoleSource
{
    public function available(): bool
    {
        return true;
    }

    public function reason(): string
    {
        return '';
    }

    public function roles(): array
    {
        return array( array( 'id' => 1, 'name' => 'Anonymous' ), array( 'id' => 2, 'name' => 'Administrator' ), array( 'id' => 5, 'name' => 'Editor' ) );
    }

    public function role( string $role ): ?array
    {
        if ( $role !== 'Editor' && $role !== '5' )
            return null;
        return array( 'id' => 5, 'name' => 'Editor',
            'policies' => array( array( 'module' => 'content', 'function' => 'read', 'limitations' => array( array( 'identifier' => 'Section', 'values' => array( 1, 2 ) ) ) ),
                                 array( 'module' => 'content', 'function' => 'edit', 'limitations' => array() ) ),
            'assignments' => array( array( 'kind' => 'group', 'object_id' => 13, 'name' => 'Editors', 'limit_identifier' => 'Subtree', 'limit_value' => '/1/2/60/' ),
                                    array( 'kind' => 'user', 'object_id' => 40, 'name' => 'writer.one@example.org', 'limit_identifier' => '', 'limit_value' => '' ) ) );
    }

    public function findUsers( string $text, int $limit, int $offset ): array
    {
        $all = array(
            array( 'user_id' => 14, 'login' => 'admin', 'name' => 'Administrator User', 'enabled' => true, 'roles' => array( 'Administrator' ), 'email' => 'admin@example.org' ),
            array( 'user_id' => 40, 'login' => 'writer.one@example.org', 'name' => 'Writer One', 'enabled' => true, 'roles' => array( 'Editor' ), 'email' => 'writer.one@example.org' ),
            array( 'user_id' => 41, 'login' => 'writer2', 'name' => 'Writer Two', 'enabled' => false, 'roles' => array(), 'email' => 'w2@example.org' ),
        );
        $hits = array_values( array_filter( $all, function ( $u ) use ( $text ) { return stripos( $u['login'], $text ) !== false || stripos( $u['name'], $text ) !== false; } ) );
        return array( 'total' => count( $hits ), 'items' => array_slice( $hits, $offset, $limit ) );
    }
}
