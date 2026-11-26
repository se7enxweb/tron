<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The inventory of an installation's API points: everything code, templates, scripts and agents can call, read from
 * the files of the installation (never written by hand), each with its risk class and whether tron covers it.
 *
 * Areas (doc/api-coverage.md):
 *   module            a module (module.php), with its views and policy functions
 *   module_view       a view of a module: params, the policy functions it needs
 *   policy_function   a policy function of a module (FunctionList), with its limitations
 *   fetch_function    a template fetch function (function_definition.php): parameters, operation types
 *   operation         an operation (operation_definition.php): content/publish and the like
 *   template_operator a template operator (eztemplateautoload.php)
 *   datatype          a content datatype (content.ini [DataTypeSettings] AvailableDataTypes[])
 *   workflow_event    a workflow event type (workflow.ini [EventSettings] AvailableEventTypes[])
 *   service_method    a public method of a content, class, user or role service, or of an extension service
 *   ini_file          an INI file; ini_block a block of one
 *   console_command   a command of bin/php/console
 *   bin_script        a script in bin/php or extension/<ext>/bin/php
 *   cronjob_part      a cronjob part (cronjob.ini [CronjobPart-*]) and its scripts
 *   queue_job_type    an expqueue job type (expqueue.ini [JobType_*])
 *
 * Inputs (all optional except root), so that the tests run on fixtures and the kernel on the installation:
 *   root        the installation root
 *   extensions  active extension names, in load order (default: every directory in extension/)
 *   ini         callable( string $file ): array group => settings, the merged settings (default: the files under root)
 *   console     the text of "bin/php/console list" (default: no console commands)
 *   units       tronUnitCatalog::units() (default: tron's own manifests)
 *   coreTools   tool names of tron's core program (default: read from troncoreprogram.php)
 *   allow       tronSystemAllow
 *   exclude     callable( string $extension ): bool, extensions to leave out of the catalog
 *   now         unix time of the catalog
 */
final class tronSystemInventory
{
    const AREAS = array( 'module', 'module_view', 'policy_function', 'fetch_function', 'operation', 'template_operator',
        'datatype', 'workflow_event', 'service_method', 'ini_file', 'ini_block', 'console_command', 'bin_script',
        'cronjob_part', 'queue_job_type' );

    /** Kernel service classes: content, class, user, role and their neighbours. */
    const KERNEL_SERVICES = array(
        'eZContentObject' => 'node', 'eZContentObjectTreeNode' => 'node', 'eZContentObjectVersion' => 'node',
        'eZContentObjectAttribute' => 'node', 'eZContentFunctions' => 'node', 'eZContentOperationCollection' => 'node',
        'eZContentObjectState' => 'node', 'eZContentObjectStateGroup' => 'node', 'eZContentLanguage' => 'node',
        'eZURLAliasML' => 'node', 'eZSearch' => 'node', 'eZContentClass' => 'class', 'eZContentClassAttribute' => 'class',
        'eZContentClassGroup' => 'class', 'eZUser' => 'user', 'eZUserSetting' => 'user', 'eZRole' => 'role',
        'eZPolicy' => 'role', 'eZPolicyLimitation' => 'role', 'eZSection' => 'section', 'eZWorkflow' => 'workflow',
        'eZTrigger' => 'workflow',
    );

    /** Unit areas a service area reads through, and the unit that does it. */
    const SERVICE_UNITS = array( 'node' => 'node.read', 'class' => 'class.read', 'role' => 'role.read', 'layout' => 'layout.read' );

    /** @var array */ private $in;
    /** @var array[] */ private $points = array();
    /** @var array[] */ private $problems = array();
    /** @var array fetch call_method class::method => fetch id */ private $fetchMethods = array();

    public function __construct( array $inputs )
    {
        if ( !isset( $inputs['root'] ) || !is_dir( $inputs['root'] ) )
            throw new InvalidArgumentException( 'The inventory needs the installation root' );
        $inputs['root'] = rtrim( (string)$inputs['root'], '/' );
        $this->in = $inputs + array( 'extensions' => null, 'ini' => null, 'console' => '', 'units' => null,
                                     'coreTools' => null, 'allow' => new tronSystemAllow(), 'exclude' => null, 'now' => time() );
        if ( $this->in['extensions'] === null )
        {
            $dirs = glob( $this->in['root'] . '/extension/*', GLOB_ONLYDIR ) ?: array();
            $this->in['extensions'] = array_map( 'basename', $dirs );
        }
        $this->in['extensions'] = array_values( array_filter( (array)$this->in['extensions'], function ( $e ) {
            return !$this->excluded( (string)$e );
        } ) );
        if ( $this->in['units'] === null )
            $this->in['units'] = class_exists( 'tronUnitCatalog' ) ? tronUnitCatalog::units() : array();
        if ( $this->in['coreTools'] === null )
        {
            $src = (string)@file_get_contents( dirname( __DIR__ ) . '/troncoreprogram.php' );
            preg_match_all( "/'name'\s*=>\s*'(tron_[a-z0-9_]+)'/", $src, $m );
            $this->in['coreTools'] = array_values( array_unique( $m[1] ) );
        }
    }

    /** The catalog: meta, the summary per area and every point. */
    public function build(): array
    {
        $this->points = array();
        $this->problems = array();
        $this->fetchFunctions();          // first: service methods look up which fetch function calls them
        $this->modules();
        $this->operations();
        $this->templateOperators();
        $this->datatypes();
        $this->workflowEvents();
        $this->services();
        $this->iniFiles();
        $this->consoleCommands();
        $this->binScripts();
        $this->cronjobParts();
        $this->queueJobTypes();
        usort( $this->points, function ( $a, $b ) {
            return array( array_search( $a['area'], self::AREAS, true ), $a['name'] ) <=> array( array_search( $b['area'], self::AREAS, true ), $b['name'] );
        } );
        return array(
            'catalog' => 'tron-api-coverage',
            'format' => 1,
            'generated' => gmdate( 'Y-m-d\TH:i:s\Z', (int)$this->in['now'] ),
            'tron' => class_exists( 'tronVersion' ) ? tronVersion::VERSION : '',
            'extensions' => count( $this->in['extensions'] ),
            'adapters' => tronSystemCatalog::ADAPTERS,
            'summary' => tronSystemCatalog::summarize( $this->points ),
            'problems' => $this->problems,
            'points' => $this->points,
        );
    }

    // ---- areas ------------------------------------------------------------------------------------------------

    /** @return array[] array( origin, directory ) of every module, kernel first */
    private function moduleDirs(): array
    {
        $out = array();
        foreach ( glob( $this->in['root'] . '/kernel/*/module.php' ) ?: array() as $f )
            $out[] = array( 'kernel', dirname( $f ) );
        foreach ( $this->in['extensions'] as $ext )
            foreach ( glob( $this->in['root'] . "/extension/$ext/modules/*/module.php" ) ?: array() as $f )
                $out[] = array( $ext, dirname( $f ) );
        return $out;
    }

    private function modules(): void
    {
        $seen = array();
        foreach ( $this->moduleDirs() as list( $origin, $dir ) )
        {
            $file = $dir . '/module.php';
            $vars = $this->readPhp( $file, array( 'Module', 'ViewList', 'FunctionList' ) );
            if ( $vars === null )
                continue;
            $name = basename( $dir );                 // the module's identifier (URLs, fetch, policies); Module[name] is its display name
            if ( isset( $seen[$name] ) )
                continue;               // the first module of a name is the one the kernel runs
            $seen[$name] = true;
            $views = is_array( $vars['ViewList'] ?? null ) ? $vars['ViewList'] : array();
            $functions = is_array( $vars['FunctionList'] ?? null ) ? $vars['FunctionList'] : array();
            $this->add( 'module', $name, $origin, $file, tronSystemRisk::READ, count( $views ) . ' views, ' . count( $functions ) . ' policy functions',
                array( 'views' => count( $views ), 'functions' => array_keys( $functions ) ),
                array( 'system_module_view_describe', 'adapter', 'read' ) );
            foreach ( $views as $view => $v )
            {
                $view = (string)$view;
                if ( !is_array( $v ) )
                    continue;
                $needs = array_values( array_map( 'strval', (array)( $v['functions'] ?? array() ) ) );
                $posts = array_merge( array_keys( (array)( $v['single_post_actions'] ?? array() ) ), array_keys( (array)( $v['post_actions'] ?? array() ) ) );
                $risk = self::viewRisk( $name, $view, $v );
                $meta = array( 'params' => array_values( (array)( $v['params'] ?? array() ) ),
                               'unordered_params' => array_keys( (array)( $v['unordered_params'] ?? array() ) ),
                               'functions' => $needs, 'post_actions' => count( $posts ) );
                $refusal = $this->in['allow']->viewRefusal( $name, $view, $risk );
                $cover = $refusal === null ? array( 'system_module_view_read', 'adapter', 'read' ) : null;
                $plan = $risk === tronSystemRisk::READ ? 'not rendered: ' . $refusal
                    : ( $posts ? 'its POST actions (' . count( $posts ) . ') need a unit with a dry-run twin each' : 'a unit with a dry-run twin' );
                $this->add( 'module_view', $name . '/' . $view, $origin, $file, $risk,
                    'params: ' . ( $meta['params'] ? implode( ', ', $meta['params'] ) : '-' ) . ( $needs ? '; needs ' . $name . '/' . implode( ', ', $needs ) : '' ),
                    $meta, $cover, $cover ? '' : $plan );
            }
            foreach ( $functions as $fn => $limitations )
            {
                $this->add( 'policy_function', $name . '/' . $fn, $origin, $file, tronSystemRisk::READ,
                    ( is_array( $limitations ) && $limitations ? 'limitations: ' . implode( ', ', array_keys( $limitations ) ) : 'no limitations' ),
                    array( 'limitations' => is_array( $limitations ) ? array_keys( $limitations ) : array() ),
                    in_array( 'tron_policy_check', $this->in['coreTools'], true )
                        ? array( 'tron_policy_check', 'core', 'read' ) : array( 'system_module_view_describe', 'adapter', 'read' ) );
            }
        }
    }

    private function fetchFunctions(): void
    {
        $seen = array();
        foreach ( $this->moduleDirs() as list( $origin, $dir ) )
        {
            $file = $dir . '/function_definition.php';
            if ( !is_file( $file ) )
                continue;
            $vars = $this->readPhp( $file, array( 'FunctionList' ) );
            $list = is_array( $vars['FunctionList'] ?? null ) ? $vars['FunctionList'] : array();
            $module = basename( $dir );
            foreach ( $list as $fn => $d )
            {
                $id = $module . '/' . $fn;
                if ( !is_array( $d ) || isset( $seen[$id] ) )
                    continue;
                $seen[$id] = true;
                $risk = self::fetchRisk( (string)$fn, $d );
                $params = array();
                foreach ( (array)( $d['parameters'] ?? array() ) as $p )
                    if ( is_array( $p ) && isset( $p['name'] ) )
                        $params[] = array( 'name' => (string)$p['name'], 'type' => (string)( $p['type'] ?? 'mixed' ), 'required' => !empty( $p['required'] ) );
                $call = (array)( $d['call_method'] ?? array() );
                $method = isset( $call['class'], $call['method'] ) ? $call['class'] . '::' . $call['method'] : '';
                if ( $method !== '' )
                    $this->fetchMethods[strtolower( $method )] = $id;
                $this->add( 'fetch_function', $id, $origin, $file, $risk,
                    'params: ' . ( $params ? implode( ', ', array_map( function ( $p ) { return $p['name'] . ( $p['required'] ? '*' : '' ); }, $params ) ) : '-' ),
                    array( 'params' => $params, 'operation_types' => array_values( (array)( $d['operation_types'] ?? array() ) ), 'call' => $method ),
                    $risk === tronSystemRisk::READ ? array( 'system_fetch_call', 'adapter', 'read' ) : null,
                    'a write fetch: needs a unit with a dry-run twin' );
            }
        }
    }

    /** A view's class: its name and the policy functions it needs; every view of an admin module is at least an admin read. */
    public static function viewRisk( string $module, string $view, array $v ): string
    {
        $adminModule = in_array( $module, tronSystemAllow::ADMIN_MODULES, true );
        $risk = tronSystemRisk::classify( $view, tronSystemRisk::WRITE, $adminModule );
        foreach ( (array)( $v['functions'] ?? array() ) as $fn )
            $risk = tronSystemRisk::max( $risk, tronSystemRisk::classify( (string)$fn, tronSystemRisk::READ, $adminModule ) );
        return $risk;
    }

    /** An operator's class: a read, unless its name says it writes (images, files) or removes something. */
    public static function operatorRisk( string $name ): string
    {
        if ( in_array( $name, array( 'texttoimage', 'wordtoimage', 'image', 'imagefile' ), true ) )
            return tronSystemRisk::WRITE;
        $risk = tronSystemRisk::classify( $name, tronSystemRisk::READ );
        return $risk === tronSystemRisk::ADMIN ? tronSystemRisk::READ : $risk;   // "role" in has_role is no admin action
    }

    /** A fetch function's class: its operation_types where it declares them, its name otherwise (reads by default). */
    public static function fetchRisk( string $name, array $d ): string
    {
        $types = array_map( 'strval', (array)( $d['operation_types'] ?? array() ) );
        if ( $types && array_diff( $types, array( 'read' ) ) )
            return tronSystemRisk::WRITE;
        if ( $types === array( 'read' ) )
            return tronSystemRisk::READ;
        // a question about an action is a read: can_create, has_access, is_locked, count_children
        if ( in_array( tronSystemRisk::words( $name )[0] ?? '', array( 'can', 'has', 'is', 'count', 'check', 'fetch', 'get', 'list' ), true ) )
            return tronSystemRisk::READ;
        $r = tronSystemRisk::classify( $name, tronSystemRisk::READ );
        return $r === tronSystemRisk::ADMIN ? tronSystemRisk::READ : $r;
    }

    private function operations(): void
    {
        foreach ( $this->moduleDirs() as list( $origin, $dir ) )
        {
            $file = $dir . '/operation_definition.php';
            if ( !is_file( $file ) )
                continue;
            $vars = $this->readPhp( $file, array( 'OperationList' ) );
            foreach ( (array)( $vars['OperationList'] ?? array() ) as $op => $d )
            {
                $params = array();
                foreach ( (array)( $d['parameters'] ?? array() ) as $p )
                    if ( is_array( $p ) && isset( $p['name'] ) )
                        $params[] = (string)$p['name'];
                $this->add( 'operation', basename( $dir ) . '/' . $op, $origin, $file, tronSystemRisk::classify( (string)$op, tronSystemRisk::WRITE ),
                    'params: ' . ( $params ? implode( ', ', $params ) : '-' ), array( 'params' => $params ), null,
                    'an operation unit with a dry-run twin (the operation runs its triggers and workflows)' );
            }
        }
    }

    private function templateOperators(): void
    {
        $files = array();
        foreach ( array( 'lib', 'kernel' ) as $top )
        {
            $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->in['root'] . '/' . $top, FilesystemIterator::SKIP_DOTS ) );
            foreach ( $it as $f )
                if ( $f->getFilename() === 'eztemplateautoload.php' )
                    $files[] = array( $top, $f->getPathname() );
        }
        sort( $files );
        foreach ( $this->in['extensions'] as $ext )
            foreach ( glob( $this->in['root'] . "/extension/$ext/autoloads/eztemplateautoload.php" ) ?: array() as $f )
                $files[] = array( $ext, $f );
        $seen = array();
        foreach ( $files as list( $origin, $file ) )
        {
            $vars = $this->readPhp( $file, array( 'eZTemplateOperatorArray' ) );
            foreach ( (array)( $vars['eZTemplateOperatorArray'] ?? array() ) as $entry )
            {
                $class = (string)( $entry['class'] ?? '' );
                foreach ( (array)( $entry['operator_names'] ?? array() ) as $name )
                {
                    $name = (string)$name;
                    if ( $name === '' || isset( $seen[$name] ) )
                        continue;
                    $seen[$name] = true;
                    $risk = self::operatorRisk( $name );
                    $refusal = $this->in['allow']->operatorRefusal( $name, $risk );
                    $this->add( 'template_operator', $name, $origin, $file, $risk, 'class ' . $class, array( 'class' => $class ),
                        $refusal === null ? array( 'system_operator_eval', 'adapter', 'read' ) : null,
                        $refusal === null ? '' : 'not evaluated: ' . $refusal );
                }
            }
        }
    }

    private function datatypes(): void
    {
        $ini = $this->ini( 'content.ini' );
        $types = array_values( array_unique( array_map( 'strval', (array)( $ini['DataTypeSettings']['AvailableDataTypes'] ?? array() ) ) ) );
        $unit = $this->unit( 'node.read' );
        foreach ( $types as $t )
        {
            if ( $t === '' )
                continue;
            $file = $this->findFile( array( "kernel/classes/datatypes/$t/{$t}type.php" ), "datatypes/$t/{$t}type.php" );
            $this->add( 'datatype', $t, $file === '' ? '' : $this->originOf( $file ), $file, tronSystemRisk::READ,
                'reading a value: the node units render it to a plain value; storing one is a content write',
                array(), $unit ? array( $unit['tool'], 'unit', 'read' ) : null, 'the node units (wave A) read attribute values' );
        }
    }

    private function workflowEvents(): void
    {
        $ini = $this->ini( 'workflow.ini' );
        foreach ( array_unique( array_map( 'strval', (array)( $ini['EventSettings']['AvailableEventTypes'] ?? array() ) ) ) as $t )
        {
            if ( $t === '' )
                continue;
            $short = preg_replace( '/^event_/', '', $t );
            $file = $this->findFile( array( "kernel/classes/workflowtypes/event/$short/{$short}type.php" ), "workflowtypes/event/$short/{$short}type.php" );
            $this->add( 'workflow_event', $t, $file === '' ? '' : $this->originOf( $file ), $file, tronSystemRisk::WRITE,
                'runs inside a workflow on publish, sign-in and the other triggers', array(), null,
                'a workflow unit: show the workflows and triggers (read), run one only as a dry run' );
        }
    }

    private function services(): void
    {
        $classes = array();
        $kernel = $this->autoload( 'autoload/ezp_kernel.php' );
        foreach ( self::KERNEL_SERVICES as $class => $area )
            if ( isset( $kernel[$class] ) )
                $classes[$class] = array( 'kernel', $kernel[$class], $area );
        foreach ( $kernel as $class => $file )
            if ( preg_match( '/^exp[A-Z]\w*Service$/', $class ) && strpos( $file, 'kernel/' ) === 0 && !isset( $classes[$class] ) )
                $classes[$class] = array( 'kernel', $file, '' );
        $ext = $this->autoload( 'var/autoload/ezp_extension.php' ) ?: $this->autoload( 'autoload/ezp_extension.php' );
        foreach ( $ext as $class => $file )
        {
            if ( !preg_match( '#^extension/([^/]+)/#', $file, $m ) || !in_array( $m[1], $this->in['extensions'], true ) )
                continue;
            if ( !preg_match( '/(Service|Api|API)$/', $class ) || strpos( strtolower( $class ), 'test' ) !== false || strpos( $file, '/tests/' ) !== false )
                continue;
            $area = strpos( $m[1], 'explayouts' ) === 0 ? 'layout' : '';
            $classes[$class] = array( $m[1], $file, $area );
        }
        ksort( $classes );
        foreach ( $classes as $class => list( $origin, $file, $area ) )
        {
            $src = (string)@file_get_contents( $this->in['root'] . '/' . $file );
            if ( $src === '' )
                continue;
            preg_match_all( '/^[ \t]*((?:(?:public|protected|private|static|final|abstract)\s+)*)function\s+&?\s*([A-Za-z_]\w*)\s*\(/m', $src, $mm, PREG_SET_ORDER );
            $seen = array();
            foreach ( $mm as $m )
            {
                $mods = strtolower( $m[1] );
                $method = $m[2];
                if ( strpos( $mods, 'private' ) !== false || strpos( $mods, 'protected' ) !== false || strpos( $method, '__' ) === 0 || isset( $seen[$method] ) )
                    continue;
                $seen[$method] = true;
                $risk = tronSystemRisk::classify( $method, tronSystemRisk::WRITE );
                $cover = null;
                $plan = '';
                $viaFetch = $this->fetchMethods[strtolower( $class . '::' . $method )] ?? null;
                if ( $risk === tronSystemRisk::READ )
                {
                    $unit = $area !== '' && isset( self::SERVICE_UNITS[$area] ) ? $this->unit( self::SERVICE_UNITS[$area] ) : null;
                    if ( $viaFetch !== null )
                        $cover = array( 'system_fetch_call', 'adapter', 'read' );
                    else if ( $unit )
                        $cover = array( $unit['tool'], 'unit', 'read' );
                    else
                        $plan = $area !== '' ? "the $area units read what it reads" : 'a read unit of its extension, or a fetch function';
                }
                else
                    $plan = 'a unit with a dry-run twin';
                $this->add( 'service_method', $class . '::' . $method, $origin, $this->in['root'] . '/' . $file, $risk,
                    ( strpos( $mods, 'static' ) !== false ? 'static' : 'instance' ) . ( $viaFetch ? '; fetch ' . $viaFetch : '' ),
                    array( 'class' => $class, 'static' => strpos( $mods, 'static' ) !== false, 'fetch' => $viaFetch ), $cover, $plan );
            }
        }
    }

    private function iniFiles(): void
    {
        $files = array();
        foreach ( glob( $this->in['root'] . '/settings/*.ini' ) ?: array() as $f )
            $files[] = array( 'kernel', $f );
        foreach ( $this->in['extensions'] as $ext )
            foreach ( glob( $this->in['root'] . "/extension/$ext/settings/*.ini*" ) ?: array() as $f )
                $files[] = array( $ext, $f );
        $list = $this->unit( 'ini.list' );
        $resolve = $this->unit( 'ini.resolve' );
        $seenFile = array();
        $seenBlock = array();
        foreach ( $files as list( $origin, $f ) )
        {
            $name = preg_replace( '/\.append(\.php)?$/', '', basename( $f ) );
            if ( !preg_match( '/^[A-Za-z0-9_.-]+\.ini$/', $name ) )
                continue;
            $blocks = array_keys( self::parseIni( (string)@file_get_contents( $f ) ) );
            if ( !isset( $seenFile[$name] ) )
            {
                $seenFile[$name] = true;
                $this->add( 'ini_file', $name, $origin, $f, tronSystemRisk::READ, count( $blocks ) . ' blocks in its first file',
                    array(), $list ? array( $list['tool'], 'unit', 'read' ) : null, 'the ini units (wave A)' );
            }
            foreach ( $blocks as $b )
            {
                $id = $name . '/' . $b;
                if ( isset( $seenBlock[$id] ) )
                    continue;
                $seenBlock[$id] = true;
                $this->add( 'ini_block', $id, $origin, $f, tronSystemRisk::READ, 'settings read with the secret ones redacted', array(),
                    $resolve ? array( $resolve['tool'], 'unit', 'read' ) : null, 'the ini units (wave A)' );
            }
        }
    }

    private function consoleCommands(): void
    {
        foreach ( self::parseConsoleList( (string)$this->in['console'] ) as $c )
        {
            if ( $this->excludedText( $c['name'] . ' ' . $c['description'] ) )
                continue;
            list( $risk, $dryRun ) = tronSystemRisk::classifyCommand( $c['name'], $c['description'] );
            $entry = $this->in['allow']->console( $c['name'] );
            // the allowlist is the reviewed statement: a read entry is a read (or tron runs only its read sub-commands),
            // a dry-run entry is a write that tron runs only as its dry run
            $cover = $entry ? array( 'system_console_command', 'adapter', $entry['mode'] === 'dry_run' ? 'dry_run' : 'read' ) : null;
            if ( $cover )
                $risk = $cover[2] === 'read' ? tronSystemRisk::READ : tronSystemRisk::max( $risk, tronSystemRisk::WRITE );
            $this->add( 'console_command', $c['name'], $c['namespace'], '', $risk, $c['description'],
                array( 'namespace' => $c['namespace'], 'dry_run' => $dryRun ), $cover,
                $dryRun ? 'console.command can run it as a dry run once it is reviewed (tron.ini ConsoleAllow[]=' . $c['name'] . ';dry_run)'
                        : ( $risk === tronSystemRisk::READ ? 'review it, then tron.ini ConsoleAllow[]=' . $c['name'] : 'needs a dry-run mode of its own first' ) );
        }
    }

    private function binScripts(): void
    {
        $files = array();
        foreach ( glob( $this->in['root'] . '/bin/php/*.php' ) ?: array() as $f )
            $files[] = array( 'kernel', $f );
        foreach ( $this->in['extensions'] as $ext )
            foreach ( glob( $this->in['root'] . "/extension/$ext/bin/php/*.php" ) ?: array() as $f )
                $files[] = array( $ext, $f );
        foreach ( $files as list( $origin, $f ) )
        {
            $rel = $this->rel( $f );
            $desc = self::scriptDescription( $f );
            if ( $this->excludedText( $rel . ' ' . $desc ) )
                continue;
            list( $risk, $dryRun ) = tronSystemRisk::classifyCommand( basename( $f, '.php' ), $desc );
            $entry = $this->in['allow']->script( $rel );
            $cover = null;
            if ( $entry )
                $cover = array( 'system_cli_script', 'adapter', $entry['mode'] === 'dry_run' ? 'dry_run' : 'read' );
            if ( $cover )
                $risk = $cover[2] === 'read' ? tronSystemRisk::READ : tronSystemRisk::max( $risk, tronSystemRisk::WRITE );
            $this->add( 'bin_script', $rel, $origin, $f, $risk, $desc === '' ? '-' : $desc,
                array( 'command' => tronSystemAllow::commandOfScript( $rel ), 'dry_run' => $dryRun ), $cover,
                $dryRun ? 'cli.script can run it as a dry run once reviewed' : ( $risk === tronSystemRisk::READ ? 'review it, then tron.ini ScriptAllow[]' : 'needs a dry-run mode of its own first' ) );
        }
    }

    private function cronjobParts(): void
    {
        $ini = $this->ini( 'cronjob.ini' );
        foreach ( $ini as $group => $settings )
        {
            if ( strpos( (string)$group, 'CronjobPart-' ) !== 0 )
                continue;
            $part = substr( (string)$group, 12 );
            $scripts = array_values( array_filter( array_map( 'strval', (array)( $settings['Scripts'] ?? array() ) ) ) );
            $this->add( 'cronjob_part', $part, '', '', tronSystemRisk::ADMIN, 'scripts: ' . ( $scripts ? implode( ', ', $scripts ) : '-' ),
                array( 'scripts' => $scripts ), null, 'a cron unit: run a part as a journaled job, as a dry run where its scripts have one' );
        }
        $main = (array)( $ini['CronjobSettings']['Scripts'] ?? array() );
        if ( $main )
            $this->add( 'cronjob_part', '(default)', '', '', tronSystemRisk::ADMIN, 'scripts: ' . implode( ', ', array_map( 'strval', $main ) ),
                array( 'scripts' => array_values( array_map( 'strval', $main ) ) ), null, 'a cron unit: run a part as a journaled job, as a dry run where its scripts have one' );
    }

    private function queueJobTypes(): void
    {
        $ini = $this->ini( 'expqueue.ini' );
        foreach ( $ini as $group => $settings )
        {
            if ( strpos( (string)$group, 'JobType_' ) !== 0 )
                continue;
            $type = substr( (string)$group, 8 );
            $handler = (string)( $settings['Handler'] ?? '' );
            $this->add( 'queue_job_type', $type, '', '', tronSystemRisk::WRITE, 'handler ' . ( $handler === '' ? '-' : $handler )
                . '; its jobs: system_queue_jobs (read)', array( 'handler' => $handler, 'class' => (string)( $settings['Class'] ?? '' ),
                'inspect' => 'system_queue_jobs' ), null, 'queue.enqueue with a dry-run twin (the handler\'s plan, nothing queued)' );
        }
    }

    // ---- helpers ----------------------------------------------------------------------------------------------

    /**
     * One point. $cover: array( tool, kind adapter|unit|core, mode read|dry_run ) or null.
     */
    private function add( string $area, string $name, string $origin, string $file, string $risk, string $summary, array $meta,
                          ?array $cover, string $plan = '' ): void
    {
        if ( $this->excludedText( $name . ' ' . $origin . ' ' . $file . ' ' . $summary ) )
            return;
        $this->points[] = array(
            'id' => $area . ':' . $name,
            'area' => $area,
            'name' => $name,
            'origin' => $origin,
            'source' => $file === '' ? '' : $this->rel( $file ),
            'risk' => $risk,
            'summary' => mb_substr( $summary, 0, 200 ),
            'meta' => $meta,
            'covered' => $cover !== null,
            'by' => $cover[0] ?? '',
            'via' => $cover[1] ?? '',
            'mode' => $cover[2] ?? '',
            'planned' => $cover === null ? $plan : '',
        );
    }

    private function unit( string $id ): ?array
    {
        return $this->in['units'][$id] ?? null;
    }

    /** The merged settings of an INI file: the callable input, else settings/ plus the active extensions' files. */
    private function ini( string $file ): array
    {
        if ( is_callable( $this->in['ini'] ) )
            return (array)call_user_func( $this->in['ini'], $file );
        $out = array();
        $paths = array( $this->in['root'] . '/settings/' . $file );
        foreach ( $this->in['extensions'] as $ext )
            foreach ( array( '', '.append', '.append.php' ) as $suffix )
                $paths[] = $this->in['root'] . "/extension/$ext/settings/$file$suffix";
        foreach ( $paths as $p )
        {
            if ( !is_file( $p ) )
                continue;
            foreach ( self::parseIni( (string)file_get_contents( $p ) ) as $group => $vars )
                foreach ( $vars as $k => $v )
                    $out[$group][$k] = is_array( $v ) ? array_merge( (array)( $out[$group][$k] ?? array() ), $v ) : $v;
        }
        return $out;
    }

    /** The groups of an INI text: group => name => value (Name[]=x lines are lists; Name[] alone empties one). */
    public static function parseIni( string $text ): array
    {
        $out = array();
        $group = null;
        foreach ( preg_split( '/\r?\n/', $text ) as $line )
        {
            $line = trim( $line );
            if ( $line === '' || $line[0] === '#' || $line[0] === ';' || strpos( $line, '<?php' ) === 0 || strpos( $line, '/*' ) === 0 || strpos( $line, '*/' ) === 0 )
                continue;
            if ( preg_match( '/^\[([^\]]+)\]$/', $line, $m ) )
            {
                $group = $m[1];
                $out[$group] = $out[$group] ?? array();
                continue;
            }
            if ( $group === null || !preg_match( '/^([^=\[]+)(\[[^\]]*\])?\s*(=(.*))?$/', $line, $m ) )
                continue;
            $key = trim( $m[1] );
            if ( !empty( $m[2] ) )
            {
                if ( !isset( $m[3] ) || $m[3] === '' )
                    $out[$group][$key] = array();
                else if ( $m[2] === '[]' )
                    $out[$group][$key][] = $m[4];
                else
                    $out[$group][$key][substr( $m[2], 1, -1 )] = $m[4];
            }
            else
                $out[$group][$key] = $m[4] ?? '';
        }
        return $out;
    }

    /**
     * The commands of "bin/php/console list": array( name, namespace, description ). The built-in lines (list, help,
     * options) are left out.
     */
    public static function parseConsoleList( string $text ): array
    {
        $out = array();
        $ns = '';
        $text = preg_replace( '/\e\[[0-9;]*m/', '', $text );
        foreach ( preg_split( '/\r?\n/', $text ) as $line )
        {
            if ( preg_match( '/^([a-z][a-z0-9:_-]*)\s+—\s/u', $line, $m ) )
            {
                $ns = $m[1];
                continue;
            }
            if ( $ns === '' || !preg_match( '/^  ([a-z][A-Za-z0-9:_.\-]*)\s{2,}(\S.*)$/', $line, $m ) )
                continue;
            $out[] = array( 'name' => $m[1], 'namespace' => $ns, 'description' => trim( $m[2] ) );
        }
        return $out;
    }

    /** The one-line description of a script: @description, the eZScript description, or the first docblock line. */
    public static function scriptDescription( string $file ): string
    {
        $head = (string)@file_get_contents( $file, false, null, 0, 6000 );
        if ( preg_match( '/@description\s+(.+)/', $head, $m ) )
            return trim( $m[1] );
        if ( preg_match( "/'description'\s*=>\s*(?:\(\s*)?(['\"])(.+?)(?:\\\\n|\\1)/s", $head, $m ) )
            return trim( preg_replace( '/\s+/', ' ', $m[2] ) );
        if ( preg_match( '#/\*\*?\s*\n\s*\*\s*([^@\s*][^\n]+)#', $head, $m ) && stripos( $m[1], 'copyright' ) === false )
            return trim( $m[1] );
        return '';
    }

    /** class => file of an autoload array file, or an empty array. */
    private function autoload( string $rel ): array
    {
        $f = $this->in['root'] . '/' . $rel;
        if ( !is_file( $f ) )
            return array();
        $a = self::includeArray( $f );
        return is_array( $a ) ? $a : array();
    }

    private static function includeArray( string $file )
    {
        return include $file;
    }

    /**
     * The named variables of a declarative PHP file (module.php, function_definition.php ...), read in a scope of
     * their own. A file that fails is left out and listed under "problems".
     */
    private function readPhp( string $file, array $names ): ?array
    {
        if ( !is_file( $file ) )
            return null;
        set_error_handler( function () { return true; } );
        ob_start();
        try
        {
            $vars = ( static function ( string $__file ) {
                include $__file;
                return get_defined_vars();
            } )( $file );
        }
        catch ( Throwable $e )
        {
            $this->problems[] = array( 'file' => $this->rel( $file ), 'error' => get_class( $e ) . ': ' . $e->getMessage() );
            $vars = null;
        }
        finally
        {
            ob_end_clean();
            restore_error_handler();
        }
        if ( $vars === null )
            return null;
        return array_intersect_key( $vars, array_flip( $names ) );
    }

    private function findFile( array $candidates, string $suffix ): string
    {
        foreach ( $candidates as $c )
            if ( is_file( $this->in['root'] . '/' . $c ) )
                return $this->in['root'] . '/' . $c;
        foreach ( $this->in['extensions'] as $ext )
            foreach ( glob( $this->in['root'] . "/extension/$ext/*/" . $suffix ) ?: array() as $f )
                return $f;
        return '';
    }

    private function originOf( string $file ): string
    {
        $rel = $this->rel( $file );
        return preg_match( '#^extension/([^/]+)/#', $rel, $m ) ? $m[1] : explode( '/', $rel )[0];
    }

    private function rel( string $file ): string
    {
        $root = $this->in['root'] . '/';
        return strpos( $file, $root ) === 0 ? substr( $file, strlen( $root ) ) : $file;
    }

    private function excluded( string $extension ): bool
    {
        if ( is_callable( $this->in['exclude'] ?? null ) && call_user_func( $this->in['exclude'], $extension ) )
            return true;
        return self::privateName( $extension );
    }

    /** Text that names a private extension leaves the point out of the catalog (doc/api-coverage.md, "Left out"). */
    private function excludedText( string $text ): bool
    {
        if ( preg_match( '#extension/([^/\s]+)#', $text, $m ) && $this->excluded( $m[1] ) )
            return true;
        if ( preg_match( '/^ext:([^:\s]+)/', $text, $m ) && $this->excluded( $m[1] ) )
            return true;
        return self::privateName( $text );
    }

    /** @var string[]|null the installation's private names; null until read from the settings */
    private static $privateNames = null;

    /**
     * Sets the private names (null: read them again from the settings). An entry is a whole word ("name"), or the
     * beginning of a word when it ends with "*" ("name*"); case does not matter.
     */
    public static function setPrivateNames( ?array $names ): void
    {
        self::$privateNames = $names === null ? null : array_values( array_filter( array_map( function ( $n ) {
            return strtolower( trim( (string)$n ) );
        }, $names ), function ( $n ) { return $n !== '' && $n !== '*'; } ) );
    }

    /**
     * The installation's private names: tron.ini [SystemAdapterSettings] PrivateNames[], set in settings/override.
     * tron ships none. Without the kernel's INI (a test world) the list is empty unless set with setPrivateNames().
     * @return string[]
     */
    public static function privateNames(): array
    {
        if ( self::$privateNames === null )
        {
            $names = array();
            if ( class_exists( 'eZINI', false ) )
            {
                try
                {
                    $ini = eZINI::instance( 'tron.ini' );
                    if ( $ini->hasVariable( 'SystemAdapterSettings', 'PrivateNames' ) )
                        $names = (array)$ini->variable( 'SystemAdapterSettings', 'PrivateNames' );
                }
                catch ( Throwable $e )
                {
                    $names = array();
                }
            }
            self::setPrivateNames( $names );
        }
        return self::$privateNames;
    }

    /**
     * Names that never appear in a published catalog nor in the system tools: what the installation lists as private
     * (its other MCP servers, private client projects ...) in [SystemAdapterSettings] PrivateNames[].
     */
    public static function privateName( string $text ): bool
    {
        $parts = array();
        foreach ( self::privateNames() as $n )
            $parts[] = substr( $n, -1 ) === '*' ? preg_quote( substr( $n, 0, -1 ), '/' ) : preg_quote( $n, '/' ) . '\\b';
        return $parts && (bool)preg_match( '/\\b(?:' . implode( '|', $parts ) . ')/i', $text );
    }
}
