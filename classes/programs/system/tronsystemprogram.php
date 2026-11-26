<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The program "system": the generic adapters that reach the whole installation (doc/system-adapters.md).
 *
 *   system_catalog, system_coverage          the API coverage catalog: every API point, its risk class, what covers it
 *   system_fetch_describe, system_fetch_call any template fetch function, parameters checked against function_definition.php
 *   system_operator_eval                     a read template operator on an input
 *   system_module_view_describe              a module: its views, their parameters and policy functions
 *   system_module_view_read                  a read view rendered as the caller, as title, path, text and links
 *   system_console_command, system_cli_script allowlisted commands and scripts, read-only or as their dry run
 *   system_queue_jobs, system_queue_job      expqueue's jobs
 *
 * Every tool runs as the calling Exponential user (the kernel's own permission checks apply), its answer passes the
 * redactor, and the call is audited and journaled by the server. Code of the installation runs inside a database
 * transaction that is rolled back. Writes are not offered: a write, admin or dangerous point is listed in the
 * catalog with its planned unit and stays inactive until its dry-run twin exists.
 */
final class tronSystemProgram implements tronProgram
{
    const ADMIN = array( 'setup', 'setup' );
    const USE = array( 'tron', 'use' );
    const MODULE = '^[a-z][a-z0-9_]{0,63}$';
    const PARAM = '/^[A-Za-z0-9_\-.,:@\/ ]{0,200}$/';

    /** @var tronSystemCatalog|null */ private static $catalog = null;
    /** @var string */ private static $catalogFile = '';

    public function register( tronRegistry $registry, tronEnvironment $env ): void
    {
        // the catalog describes an installation: without one (the test suite, a bare checkout) nothing is offered
        $source = self::source( $env );
        if ( !$source || !$source->available() )
        {
            $why = $source ? $source->reason() : 'no installation here (the system adapters reach an Exponential installation)';
            foreach ( tronSystemCatalog::ADAPTERS as $a )
                $registry->unavailable( $a['tool'], $why );
            return;
        }
        $this->catalogTools( $registry, $env );
        $allow = tronSystemAllow::fromEnv( $env );
        $this->fetchTools( $registry, $env, $source );
        $this->operatorTools( $registry, $env, $source, $allow );
        $this->moduleTools( $registry, $env, $source, $allow );
        $this->commandTools( $registry, $env, $source, $allow );
        $this->queueTools( $registry, $env, $source );
    }

    /** The source: the environment service "system.source" (tests), else the kernel's, else none. */
    public static function source( tronEnvironment $env ): ?tronSystemSource
    {
        if ( $env->has( 'system.source' ) )
            return $env->get( 'system.source' );
        if ( !class_exists( 'eZModule' ) || !class_exists( 'eZFunctionHandler' ) )
            return null;
        $s = new tronSystemKernelSource();
        $env->set( 'system.source', $s );
        return $s;
    }

    public static function catalog( tronEnvironment $env ): tronSystemCatalog
    {
        if ( $env->has( 'system.catalog' ) )
            return $env->get( 'system.catalog' );
        $file = tronSystemCatalog::file( $env );
        if ( self::$catalog === null || self::$catalogFile !== $file )
        {
            try
            {
                self::$catalog = tronSystemCatalog::load( $file );
            }
            catch ( Throwable $e )
            {
                throw new tronToolError( 'The API catalog is not there yet: run php extension/tron/bin/php/tron-inventory.php --write (doc/api-coverage.md)' );
            }
            self::$catalogFile = $file;
        }
        return self::$catalog;
    }

    // ---- catalog ----------------------------------------------------------------------------------------------

    private function catalogTools( tronRegistry $registry, tronEnvironment $env ): void
    {
        $registry->tool( tronTool::define( array(
            'name' => 'system_catalog',
            'title' => 'Search the API catalog',
            'description' => 'The API points of this installation (modules and views, fetch functions, template operators, datatypes, workflow events, services, INI files and blocks, console commands, scripts, cronjob parts, queue job types), each with its risk class (read, write, admin, dangerous), whether tron covers it and with which tool, or what is planned. Filter by area, words, risk, covered, origin or covering tool. Start here to find the tool for a task.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
                'area' => array( 'type' => 'string', 'enum' => tronSystemInventory::AREAS ),
                'q' => array( 'type' => 'string', 'maxLength' => 200, 'description' => 'Words that must all appear in the id, summary, plan or origin' ),
                'risk' => array( 'type' => 'string', 'enum' => array_keys( tronSystemRisk::ORDER ) ),
                'covered' => array( 'type' => 'boolean' ),
                'origin' => array( 'type' => 'string', 'maxLength' => 100, 'description' => 'kernel, lib, or an extension name' ),
                'by' => array( 'type' => 'string', 'maxLength' => 80, 'description' => 'The covering tool, e.g. system_fetch_call' ),
                'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ),
                'offset' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
            ) ),
            'policy' => self::USE,
            'outputSchema' => array( 'type' => 'object', 'required' => array( 'generated', 'total', 'items' ), 'properties' => array(
                'generated' => array( 'type' => 'string' ), 'total' => array( 'type' => 'integer', 'minimum' => 0 ),
                'items' => array( 'type' => 'array', 'items' => array( 'type' => 'object',
                    'required' => array( 'id', 'area', 'name', 'risk', 'covered', 'by' ) ) ) ) ),
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $env ) {
                $c = self::catalog( $env );
                $f = array_intersect_key( $a, array_flip( array( 'area', 'q', 'risk', 'covered', 'origin', 'by' ) ) );
                $r = $c->search( $f, (int)( $a['limit'] ?? 50 ), (int)( $a['offset'] ?? 0 ) );
                return array( 'generated' => (string)$c->data()['generated'], 'total' => $r['total'], 'items' => $r['items'] );
            },
        ) ) );

        $registry->tool( tronTool::define( array(
            'name' => 'system_coverage',
            'title' => 'API coverage',
            'description' => 'How much of this installation tron covers: per area the API points, the read points and how many a tool runs, the write/admin/dangerous points and how many a dry run covers, and the covering tools. Also lists the generic adapters with their input and output.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array() ),
            'policy' => self::USE,
            'outputSchema' => array( 'type' => 'object', 'required' => array( 'generated', 'totals', 'areas', 'adapters' ), 'properties' => array(
                'generated' => array( 'type' => 'string' ), 'totals' => array( 'type' => 'object', 'required' => array( 'points', 'read', 'read_covered', 'write', 'write_covered' ) ),
                'areas' => array( 'type' => 'object' ), 'adapters' => array( 'type' => 'object' ) ) ),
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $env ) {
                $c = self::catalog( $env );
                $s = $c->summary();
                return array( 'generated' => (string)$c->data()['generated'], 'totals' => $s['totals'], 'areas' => $s['areas'],
                              'adapters' => tronSystemCatalog::ADAPTERS );
            },
        ) ) );
    }

    // ---- fetch functions --------------------------------------------------------------------------------------

    private function fetchTools( tronRegistry $registry, tronEnvironment $env, tronSystemSource $source ): void
    {
        $registry->tool( tronTool::define( array(
            'name' => 'system_fetch_describe',
            'title' => 'Describe fetch functions',
            'description' => 'The template fetch functions of a module (function_definition.php): each with its parameters (name, type, required), its risk class and whether system_fetch_call runs it. With function: that one only.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'module' ), 'properties' => array(
                'module' => array( 'type' => 'string', 'pattern' => self::MODULE ),
                'function' => array( 'type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,99}$' ),
            ) ),
            'policy' => self::USE,
            'outputSchema' => array( 'type' => 'object', 'required' => array( 'module', 'functions' ), 'properties' => array(
                'module' => array( 'type' => 'string' ), 'functions' => array( 'type' => 'array', 'items' => array( 'type' => 'object',
                    'required' => array( 'name', 'risk', 'params', 'callable' ) ) ) ) ),
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source ) {
                $list = self::fetchList( $source, (string)$a['module'] );
                $out = array();
                foreach ( $list as $name => $d )
                {
                    if ( isset( $a['function'] ) && $a['function'] !== $name )
                        continue;
                    $out[] = self::describeFetch( (string)$name, $d );
                }
                if ( isset( $a['function'] ) && !$out )
                    throw new tronToolError( "The module {$a['module']} has no fetch function {$a['function']}" );
                return array( 'module' => (string)$a['module'], 'functions' => $out );
            },
        ) ) );

        $registry->tool( tronTool::define( array(
            'name' => 'system_fetch_call',
            'title' => 'Call a fetch function',
            'description' => 'Runs any read template fetch function, as in fetch( module, function, hash( ... ) ) in a template, as the calling user: the kernel\'s permission checks apply to you, and when the module has a "read" policy function you need it. Parameters are checked against function_definition.php (names, types, required); a list fetch gets limit 100 unless you give one (at most 100). Objects come back as their stored fields plus name, url_alias and class_identifier. Write fetches are refused. Use system_fetch_describe for the parameters.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'module', 'function' ), 'properties' => array(
                'module' => array( 'type' => 'string', 'pattern' => self::MODULE ),
                'function' => array( 'type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,99}$' ),
                'params' => array( 'type' => 'object', 'description' => 'The fetch parameters by name, e.g. {"parent_node_id": 2, "limit": 10}' ),
            ) ),
            'policy' => self::USE,
            'outputSchema' => array( 'type' => 'object', 'required' => array( 'module', 'function', 'ran_as', 'result', 'truncated' ), 'properties' => array(
                'module' => array( 'type' => 'string' ), 'function' => array( 'type' => 'string' ), 'ran_as' => array( 'type' => 'string' ),
                'truncated' => array( 'type' => 'boolean' ) ) ),
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source, $env ) {
                $module = (string)$a['module'];
                $fn = (string)$a['function'];
                $list = self::fetchList( $source, $module );
                if ( !isset( $list[$fn] ) || !is_array( $list[$fn] ) )
                    throw new tronToolError( "The module $module has no fetch function $fn (system_fetch_describe lists them)" );
                $risk = tronSystemInventory::fetchRisk( $fn, $list[$fn] );
                if ( $risk !== tronSystemRisk::READ )
                    throw new tronToolError( "fetch( $module, $fn ) is a $risk fetch: inactive until its dry-run twin exists (system_catalog lists the plan)" );
                $def = $source->moduleDefinition( $module );
                if ( $def && isset( $def['functions']['read'] ) && !self::holds( $ctx, $env, $module, 'read' ) )
                    throw new tronToolError( "Permission denied: fetches of the module $module need the policy $module/read" );
                $params = self::fetchParams( $list[$fn], (array)( $a['params'] ?? array() ) );
                tronSystemValue::$truncated = false;
                $result = $source->fetchCall( $ctx->principal->userId, $module, $fn, $params );
                $plain = tronSystemValue::plain( $result );
                return array( 'module' => $module, 'function' => $fn, 'ran_as' => $ctx->principal->login, 'params' => $params,
                              'result' => $plain, 'truncated' => tronSystemValue::$truncated );
            },
        ) ) );
    }

    private static function fetchList( tronSystemSource $source, string $module ): array
    {
        if ( tronSystemInventory::privateName( $module ) || !$source->moduleDefinition( $module ) )
            throw new tronToolError( "No module $module" );
        $list = $source->fetchDefinition( $module );
        if ( !$list )
            throw new tronToolError( "The module $module has no fetch functions" );
        return $list;
    }

    public static function describeFetch( string $name, array $d ): array
    {
        $params = array();
        foreach ( (array)( $d['parameters'] ?? array() ) as $p )
            if ( is_array( $p ) && isset( $p['name'] ) )
                $params[] = array( 'name' => (string)$p['name'], 'type' => (string)( $p['type'] ?? 'mixed' ), 'required' => !empty( $p['required'] ) )
                          + ( array_key_exists( 'default', $p ) && is_scalar( $p['default'] ) ? array( 'default' => $p['default'] ) : array() );
        $risk = tronSystemInventory::fetchRisk( $name, $d );
        return array( 'name' => $name, 'risk' => $risk, 'params' => $params, 'callable' => $risk === tronSystemRisk::READ );
    }

    /**
     * The parameters of a fetch, checked against its definition: no unknown name, every required one present, each
     * value of its declared type. A list fetch (one with a "limit" parameter) gets limit 100, and never more.
     */
    public static function fetchParams( array $def, array $given ): array
    {
        $declared = array();
        foreach ( (array)( $def['parameters'] ?? array() ) as $p )
            if ( is_array( $p ) && isset( $p['name'] ) )
                $declared[(string)$p['name']] = $p;
        $out = array();
        foreach ( $given as $name => $value )
        {
            if ( !isset( $declared[$name] ) )
                throw new tronToolError( "The fetch has no parameter '$name'; it takes: " . ( $declared ? implode( ', ', array_keys( $declared ) ) : 'none' ) );
            $out[$name] = self::typed( (string)$name, (string)( $declared[$name]['type'] ?? 'mixed' ), $value );
        }
        foreach ( $declared as $name => $p )
            if ( !empty( $p['required'] ) && !array_key_exists( $name, $out ) )
                throw new tronToolError( "The fetch needs the parameter '$name'" );
        if ( isset( $declared['limit'] ) )
            $out['limit'] = max( 1, min( 100, (int)( $out['limit'] ?? 100 ) ) );
        return $out;
    }

    private static function typed( string $name, string $type, $v )
    {
        switch ( $type )
        {
            case 'integer':
                if ( is_int( $v ) || ( is_string( $v ) && preg_match( '/^-?\d{1,18}$/', $v ) ) )
                    return (int)$v;
                if ( is_array( $v ) && $v === array_filter( $v, 'is_int' ) && count( $v ) <= 100 )
                    return array_values( $v );        // a few kernel fetches take a list of ids where they declare integer
                if ( $v === false )
                    return false;
                break;
            case 'boolean':
            case 'bool':
                if ( is_bool( $v ) )
                    return $v;
                break;
            case 'string':
                if ( is_string( $v ) && strlen( $v ) <= 1000 )
                    return $v;
                if ( is_int( $v ) )
                    return (string)$v;
                if ( $v === false )
                    return false;
                break;
            case 'array':
            case 'mixed':
                if ( is_scalar( $v ) || $v === null || ( is_array( $v ) && strlen( (string)json_encode( $v ) ) <= 10000 ) )
                    return $v;
                break;
            default:
                // object and the like: not something a caller can hand over
                break;
        }
        throw new tronToolError( "The parameter '$name' must be of type $type" );
    }

    // ---- operators --------------------------------------------------------------------------------------------

    private function operatorTools( tronRegistry $registry, tronEnvironment $env, tronSystemSource $source, tronSystemAllow $allow ): void
    {
        $registry->tool( tronTool::define( array(
            'name' => 'system_operator_eval',
            'title' => 'Evaluate a template operator',
            'description' => 'Evaluates $input|operator( params ) with any read template operator (upcase, shorten, wash, sort, datetime, l10n, i18n, ezurl ...), as the calling user. The input and the parameters are passed as values, never as template code. Operators that read settings, the request or the session, or that write files, are refused (system_catalog area=template_operator shows which).',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'operator' ), 'properties' => array(
                'operator' => array( 'type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,63}$' ),
                'input' => array( 'description' => 'The value left of the pipe: a string, number, boolean, list or map' ),
                'params' => array( 'type' => 'array', 'maxItems' => 6, 'description' => 'The operator parameters, in order' ),
            ) ),
            'policy' => self::USE,
            'outputSchema' => array( 'type' => 'object', 'required' => array( 'operator', 'result' ), 'properties' => array(
                'operator' => array( 'type' => 'string' ) ) ),
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source, $allow ) {
                $op = (string)$a['operator'];
                if ( tronSystemInventory::privateName( $op ) || $source->operatorClass( $op ) === null )
                    throw new tronToolError( "No template operator $op" );
                $refusal = $allow->operatorRefusal( $op, tronSystemInventory::operatorRisk( $op ) );
                if ( $refusal !== null )
                    throw new tronToolError( "The operator $op is not evaluated here: $refusal" );
                $input = $a['input'] ?? null;
                $params = array_values( (array)( $a['params'] ?? array() ) );
                if ( strlen( (string)json_encode( array( $input, $params ) ) ) > 100000 )
                    throw new tronToolError( 'The input and parameters are larger than 100 kB' );
                tronSystemValue::$truncated = false;
                $result = $source->operatorEval( $ctx->principal->userId, $op, $input, $params );
                return array( 'operator' => $op, 'input' => tronSystemValue::plain( $input ), 'params' => $params,
                              'result' => tronSystemValue::plain( $result ), 'truncated' => tronSystemValue::$truncated );
            },
        ) ) );
    }

    // ---- modules and views ------------------------------------------------------------------------------------

    private function moduleTools( tronRegistry $registry, tronEnvironment $env, tronSystemSource $source, tronSystemAllow $allow ): void
    {
        $registry->tool( tronTool::define( array(
            'name' => 'system_module_view_describe',
            'title' => 'Describe a module',
            'description' => 'A module of the installation: its views with their ordered and unordered parameters, the policy functions each needs, its risk class and whether system_module_view_read renders it, and the module\'s policy functions with their limitations. With view: that view only. Without module: the module names.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
                'module' => array( 'type' => 'string', 'pattern' => self::MODULE ),
                'view' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_]{1,64}$' ),
            ) ),
            'policy' => self::USE,
            'outputSchema' => array( 'type' => 'object', 'required' => array( 'modules' ), 'properties' => array(
                'modules' => array( 'type' => 'array' ) ) ),
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source, $allow ) {
                if ( !isset( $a['module'] ) )
                    return array( 'modules' => $source->modules() );
                $m = (string)$a['module'];
                $def = $source->moduleDefinition( $m );
                if ( !$def )
                    throw new tronToolError( "No module $m" );
                $views = array();
                foreach ( $def['views'] as $name => $v )
                {
                    if ( !is_array( $v ) || ( isset( $a['view'] ) && $a['view'] !== (string)$name ) )
                        continue;
                    $risk = tronSystemInventory::viewRisk( $m, (string)$name, $v );
                    $refusal = $allow->viewRefusal( $m, (string)$name, $risk );
                    $views[] = array( 'name' => (string)$name, 'params' => array_values( (array)( $v['params'] ?? array() ) ),
                        'unordered_params' => array_keys( (array)( $v['unordered_params'] ?? array() ) ),
                        'functions' => array_values( array_map( 'strval', (array)( $v['functions'] ?? array() ) ) ),
                        'post_actions' => array_merge( array_keys( (array)( $v['single_post_actions'] ?? array() ) ), array_keys( (array)( $v['post_actions'] ?? array() ) ) ),
                        'risk' => $risk, 'readable' => $refusal === null ) + ( $refusal === null ? array() : array( 'why_not' => $refusal ) );
                }
                if ( isset( $a['view'] ) && !$views )
                    throw new tronToolError( "The module $m has no view {$a['view']}" );
                $functions = array();
                foreach ( $def['functions'] as $fn => $lim )
                    $functions[(string)$fn] = is_array( $lim ) ? array_keys( $lim ) : array();
                return array( 'modules' => array( $m ), 'module' => $m, 'origin' => (string)$def['origin'], 'views' => $views,
                              'functions' => $functions );
            },
        ) ) );

        $registry->tool( tronTool::define( array(
            'name' => 'system_module_view_read',
            'title' => 'Read a module view',
            'description' => 'Renders a read view (content/view, content/search results, user/... pages) as the calling user, as a browser GET would, and returns its title, path, text and links instead of HTML. The kernel\'s view access check applies to you. Views that change something, admin views and a few with side effects on GET are refused (system_module_view_describe says which and why).',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'module', 'view' ), 'properties' => array(
                'module' => array( 'type' => 'string', 'pattern' => self::MODULE ),
                'view' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_]{1,64}$' ),
                'params' => array( 'type' => 'array', 'maxItems' => 10, 'items' => array( 'type' => array( 'string', 'integer' ) ),
                                   'description' => 'The ordered parameters, e.g. ["full", 2] for content/view' ),
                'unordered' => array( 'type' => 'object', 'description' => 'Unordered parameters, e.g. {"offset": 10}' ),
                'max_text' => array( 'type' => 'integer', 'minimum' => 100, 'maximum' => 50000, 'default' => 20000 ),
            ) ),
            'policy' => self::USE,
            'outputSchema' => array( 'type' => 'object', 'required' => array( 'module', 'view', 'ran_as', 'title', 'path', 'text', 'links', 'truncated' ), 'properties' => array(
                'module' => array( 'type' => 'string' ), 'view' => array( 'type' => 'string' ), 'title' => array( 'type' => 'string' ),
                'path' => array( 'type' => 'array' ), 'text' => array( 'type' => 'string' ), 'links' => array( 'type' => 'array' ), 'truncated' => array( 'type' => 'boolean' ) ) ),
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source, $allow ) {
                $m = (string)$a['module'];
                $view = (string)$a['view'];
                $def = $source->moduleDefinition( $m );
                if ( !$def || !isset( $def['views'][$view] ) || !is_array( $def['views'][$view] ) )
                    throw new tronToolError( "No view $m/$view (system_module_view_describe lists them)" );
                $refusal = $allow->viewRefusal( $m, $view, tronSystemInventory::viewRisk( $m, $view, $def['views'][$view] ) );
                if ( $refusal !== null )
                    throw new tronToolError( "$m/$view is not rendered here: $refusal" );
                $params = array();
                foreach ( (array)( $a['params'] ?? array() ) as $p )
                {
                    if ( !preg_match( self::PARAM, (string)$p ) )
                        throw new tronToolError( 'A view parameter has characters tron does not pass on' );
                    $params[] = (string)$p;
                }
                $unordered = array();
                foreach ( (array)( $a['unordered'] ?? array() ) as $k => $v )
                {
                    if ( !preg_match( '/^[a-z_]{1,40}$/', (string)$k ) || !is_scalar( $v ) || !preg_match( self::PARAM, (string)$v ) )
                        throw new tronToolError( "The unordered parameter '$k' is not accepted" );
                    $unordered[(string)$k] = (string)$v;
                }
                if ( !$source->canView( $ctx->principal->userId, $m, $view, $params ) )
                    throw new tronToolError( "Permission denied: you may not use the view $m/$view" );
                tronSystemValue::$truncated = false;
                $r = $source->viewRead( $ctx->principal->userId, $m, $view, $params, $unordered );
                $text = tronSystemValue::text( (string)$r['html'], (int)( $a['max_text'] ?? 20000 ) );
                return array( 'module' => $m, 'view' => $view, 'ran_as' => $ctx->principal->login, 'title' => (string)$r['title'],
                              'path' => (array)$r['path'], 'text' => $text, 'links' => tronSystemValue::links( (string)$r['html'] ),
                              'status' => (int)( $r['status'] ?? 0 ), 'redirect' => (string)( $r['redirect'] ?? '' ), 'truncated' => tronSystemValue::$truncated );
            },
        ) ) );
    }

    // ---- commands ---------------------------------------------------------------------------------------------

    private function commandTools( tronRegistry $registry, tronEnvironment $env, tronSystemSource $source, tronSystemAllow $allow ): void
    {
        $out = array( 'type' => 'object', 'required' => array( 'mode', 'exit_code', 'ms', 'stdout', 'stderr', 'truncated' ), 'properties' => array(
            'mode' => array( 'type' => 'string', 'enum' => array( 'read', 'dry_run' ) ), 'exit_code' => array( 'type' => 'integer' ),
            'ms' => array( 'type' => 'integer' ), 'stdout' => array( 'type' => 'string' ), 'stderr' => array( 'type' => 'string' ),
            'truncated' => array( 'type' => 'boolean' ) ) );
        $args = array( 'type' => 'array', 'maxItems' => 20, 'items' => array( 'type' => 'string', 'maxLength' => 200 ),
                       'description' => 'Arguments and options, e.g. ["--siteaccess=site", "--limit=20"]; --apply, --force and the like are refused' );

        $registry->tool( tronTool::define( array(
            'name' => 'system_console_command',
            'title' => 'Run a console command',
            'description' => 'Runs an allowlisted command of bin/php/console as a journaled job: read commands (status, list, show, check) as they are, commands with a dry run only as their dry run (no --apply). Allowed: ' . implode( ', ', $allow->consoleCommands() ) . '. Output is cut to its last 30 000 characters.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'command' ), 'properties' => array(
                'command' => array( 'type' => 'string', 'pattern' => '^[a-z][a-z0-9_:.-]{1,80}$' ), 'args' => $args,
            ) ),
            'policy' => self::ADMIN,
            'outputSchema' => $out,
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source, $allow ) {
                $cmd = (string)$a['command'];
                $entry = $allow->console( $cmd );
                if ( !$entry )
                    throw new tronToolError( "The command $cmd is not allowed here (system_catalog area=console_command shows the plan for it)" );
                $argv = self::checkArgs( $allow, $entry, (array)( $a['args'] ?? array() ) );
                return array( 'command' => $cmd ) + self::runJob( $ctx, $source, $allow, "console $cmd",
                    array_merge( array( self::php( $ctx ), 'bin/php/console', $cmd ), $argv ), $entry['mode'] );
            },
        ) ) );

        $registry->tool( tronTool::define( array(
            'name' => 'system_cli_script',
            'title' => 'Run a bin/php script',
            'description' => 'Runs an allowlisted bin/php script (the script of an allowed console command, or one named in tron.ini ScriptAllow[]) as a journaled job, read-only or as its dry run. Same rules as system_console_command.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'script' ), 'properties' => array(
                'script' => array( 'type' => 'string', 'pattern' => '^(bin/php|extension/[a-z0-9_]+/bin/php)/[a-z0-9_.-]+\.php$' ), 'args' => $args,
            ) ),
            'policy' => self::ADMIN,
            'outputSchema' => $out,
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source, $allow ) {
                $script = (string)$a['script'];
                $entry = $allow->script( $script );
                if ( !$entry || !is_file( $script ) )
                    throw new tronToolError( "The script $script is not allowed here (system_catalog area=bin_script shows the plan for it)" );
                $argv = self::checkArgs( $allow, $entry, (array)( $a['args'] ?? array() ) );
                return array( 'script' => $script ) + self::runJob( $ctx, $source, $allow, "script $script",
                    array_merge( array( self::php( $ctx ), $script ), $argv ), $entry['mode'] );
            },
        ) ) );
    }

    private static function checkArgs( tronSystemAllow $allow, array $entry, array $args ): array
    {
        $out = array();
        foreach ( array_values( $args ) as $i => $arg )
        {
            $arg = (string)$arg;
            $why = $allow->argumentRefusal( $arg );
            if ( $why !== null )
                throw new tronToolError( $why );
            $out[] = $arg;
        }
        if ( !empty( $entry['first'] ) )
        {
            $first = '';
            foreach ( $out as $arg )
                if ( $arg[0] !== '-' )
                {
                    $first = $arg;
                    break;
                }
            if ( !in_array( $first, $entry['first'], true ) )
                throw new tronToolError( 'Only these sub-commands run here: ' . implode( ', ', $entry['first'] ) );
        }
        if ( function_exists( 'posix_geteuid' ) && posix_geteuid() === 0 )
            $out[] = '--allow-root-user';
        return $out;
    }

    /** The PHP command line binary (under PHP-FPM, PHP_BINARY is the FPM binary). */
    private static function php( tronCallContext $ctx ): string
    {
        $set = (string)$ctx->env->setting( 'SystemAdapterSettings', 'PhpBinary', '' );
        if ( $set !== '' && is_executable( $set ) )
            return $set;
        $bin = PHP_BINARY;
        if ( strpos( basename( $bin ), 'fpm' ) !== false )
        {
            $cli = dirname( $bin, 2 ) . '/bin/php';
            if ( is_executable( $cli ) )
                return $cli;
        }
        return $bin;
    }

    /** Runs a command line as a job of the caller's journal (doc/activity-journal.md), with progress every 10 s. */
    private static function runJob( tronCallContext $ctx, tronSystemSource $source, tronSystemAllow $allow, string $name, array $argv, string $mode ): array
    {
        $job = null;
        $journal = $ctx->session->journal ?? null;
        if ( is_object( $journal ) && method_exists( $journal, 'job' ) )
        {
            try
            {
                $job = $journal->job( $name . ( $mode === 'dry_run' ? ' (dry run)' : '' ) );
            }
            catch ( Throwable $e )
            {
                $job = null;
            }
        }
        $timeout = $allow->commandTimeout();
        $progress = $job ? function ( int $seconds, int $bytes ) use ( $job, $timeout ) {
            $job->progress( (int)min( 99, 100 * $seconds / max( 1, $timeout ) ), "$seconds s, $bytes bytes of output" );
        } : null;
        try
        {
            $r = $source->run( $argv, $timeout, $progress );
        }
        catch ( Throwable $e )
        {
            if ( $job )
                $job->fail( $e->getMessage() );
            throw $e;
        }
        $clean = function ( string $s, int $max ) use ( &$cut ) {
            $s = preg_replace( '/\e\[[0-9;]*[A-Za-z]/', '', $s );
            if ( strlen( (string)$s ) > $max )
            {
                $cut = true;
                $s = '...' . substr( (string)$s, -$max );
            }
            return (string)$s;
        };
        $cut = false;
        $stdout = $clean( (string)$r['stdout'], 30000 );
        $stderr = $clean( (string)$r['stderr'], 5000 );
        if ( $job )
            $r['exit_code'] === 0
                ? $job->finish( 'ok', "exit 0 in {$r['ms']} ms", array( 'exit_code' => 0, 'ms' => $r['ms'] ) )
                : $job->fail( "exit {$r['exit_code']}" . ( $r['timed_out'] ? ' (timed out)' : '' ) );
        return array( 'mode' => $mode, 'exit_code' => (int)$r['exit_code'], 'ms' => (int)$r['ms'], 'timed_out' => (bool)$r['timed_out'],
                      'stdout' => $stdout, 'stderr' => $stderr, 'truncated' => $cut, 'job' => $job ? $job->id() : null );
    }

    // ---- queue ------------------------------------------------------------------------------------------------

    private function queueTools( tronRegistry $registry, tronEnvironment $env, tronSystemSource $source ): void
    {
        $policy = array( 'expqueue', 'view' );
        $registry->tool( tronTool::define( array(
            'name' => 'system_queue_jobs',
            'title' => 'List queue jobs',
            'description' => 'The jobs of expqueue, newest first, filtered by state (queued, running, done, failed, dead, cancelled ...), job type and priority class, with the count per state. Needs expqueue/view.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
                'state' => array( 'type' => 'string', 'pattern' => '^[a-z_]{1,30}$' ),
                'type' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_.:-]{1,100}$' ),
                'class' => array( 'type' => 'string', 'pattern' => '^[a-z0-9_]{1,50}$' ),
                'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ),
                'offset' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
            ) ),
            'policy' => $policy, 'limitedOk' => true,
            'outputSchema' => array( 'type' => 'object', 'required' => array( 'installed', 'total', 'counts', 'items' ), 'properties' => array(
                'installed' => array( 'type' => 'boolean' ), 'total' => array( 'type' => 'integer' ), 'counts' => array( 'type' => 'object' ),
                'items' => array( 'type' => 'array' ) ) ),
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source ) {
                if ( !$source->queueInstalled() )
                    return array( 'installed' => false, 'total' => 0, 'counts' => new stdClass(), 'items' => array() );
                $f = array_intersect_key( $a, array_flip( array( 'state', 'type', 'class' ) ) );
                $r = $source->queueJobs( $f, (int)( $a['offset'] ?? 0 ), (int)( $a['limit'] ?? 20 ) );
                tronSystemValue::$truncated = false;
                return array( 'installed' => true, 'total' => (int)$r['total'], 'counts' => $r['counts'] ?: new stdClass(),
                              'items' => (array)tronSystemValue::plain( $r['items'] ) );
            },
        ) ) );

        $registry->tool( tronTool::define( array(
            'name' => 'system_queue_job',
            'title' => 'Inspect a queue job',
            'description' => 'One expqueue job by id or uuid: type, state, class, payload, result, checkpoint, attempts and last error. Needs expqueue/view.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'job' ), 'properties' => array(
                'job' => array( 'type' => 'string', 'pattern' => '^([0-9]{1,18}|[0-9a-f-]{32,36})$' ),
            ) ),
            'policy' => $policy, 'limitedOk' => true,
            'outputSchema' => array( 'type' => 'object', 'required' => array( 'job' ), 'properties' => array( 'job' => array( 'type' => 'object' ) ) ),
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source ) {
                if ( !$source->queueInstalled() )
                    throw new tronToolError( 'expqueue is not installed here' );
                $job = $source->queueJob( (string)$a['job'] );
                if ( !$job )
                    throw new tronToolError( "No job {$a['job']}" );
                return array( 'job' => tronSystemValue::plain( $job ) );
            },
        ) ) );
    }

    /** Whether the caller holds module/function, with or without limitations. */
    private static function holds( tronCallContext $ctx, tronEnvironment $env, string $module, string $function ): bool
    {
        if ( $ctx->can( $module, $function ) )
            return true;
        if ( !$env->has( 'access' ) )
            return false;
        return $env->get( 'access' )->check( $ctx->principal, $module, $function ) === tronAccessChecker::LIMITED;
    }
}
