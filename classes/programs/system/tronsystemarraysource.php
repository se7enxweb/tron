<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The program "system" on fixtures (tests, examples): modules, fetch functions, operators, views, commands and queue
 * jobs as arrays and callables. Records every call, with the user it ran as, in $calls.
 *
 *   modules     name => array( origin, file, dir, module, views, functions, fetch => FunctionList )
 *   fetch       "module/function" => callable( array $params, int $userId )
 *   operators   name => callable( $input, array $params )
 *   views       "module/view" => callable( array $params, array $unordered, int $userId ): array( title, path, html )
 *   canView     callable( int $userId, string $module, string $view ): bool (default: every user)
 *   commands    callable( array $argv, int $timeout ): array( exit_code, stdout, stderr, ms, timed_out )
 *   queue       list of job arrays (id, uuid, type, state, class ...), or false when expqueue is not installed
 */
final class tronSystemArraySource implements tronSystemSource
{
    /** @var array */ private $d;
    /** @var array[] */ public $calls = array();

    public function __construct( array $data )
    {
        $this->d = $data + array( 'modules' => array(), 'fetch' => array(), 'operators' => array(), 'views' => array(),
                                  'canView' => null, 'commands' => null, 'queue' => array() );
    }

    public function available(): bool
    {
        return true;
    }

    public function reason(): string
    {
        return '';
    }

    public function modules(): array
    {
        $names = array_keys( $this->d['modules'] );
        sort( $names );
        return $names;
    }

    public function moduleDefinition( string $module ): ?array
    {
        $m = $this->d['modules'][$module] ?? null;
        return $m === null ? null : $m + array( 'origin' => 'kernel', 'file' => "kernel/$module/module.php", 'dir' => "kernel/$module",
                                                'module' => array( 'name' => $module ), 'views' => array(), 'functions' => array() );
    }

    public function fetchDefinition( string $module ): ?array
    {
        return $this->d['modules'][$module]['fetch'] ?? null;
    }

    public function fetchCall( int $userId, string $module, string $function, array $params )
    {
        $this->calls[] = array( 'fetch', $userId, "$module/$function", $params );
        $fn = $this->d['fetch']["$module/$function"] ?? null;
        if ( !$fn )
            throw new tronToolError( "no fixture for fetch $module/$function" );
        return $fn( $params, $userId );
    }

    public function operatorClass( string $operator ): ?string
    {
        return isset( $this->d['operators'][$operator] ) ? 'fixture' : null;
    }

    public function operatorEval( int $userId, string $operator, $input, array $params )
    {
        $this->calls[] = array( 'operator', $userId, $operator, $params );
        return ( $this->d['operators'][$operator] )( $input, $params );
    }

    public function canView( int $userId, string $module, string $view, array $params ): bool
    {
        return $this->d['canView'] ? (bool)( $this->d['canView'] )( $userId, $module, $view ) : true;
    }

    public function viewRead( int $userId, string $module, string $view, array $params, array $unordered ): array
    {
        $this->calls[] = array( 'view', $userId, "$module/$view", $params );
        $fn = $this->d['views']["$module/$view"] ?? null;
        if ( !$fn )
            throw new tronToolError( "no fixture for view $module/$view" );
        return $fn( $params, $unordered, $userId ) + array( 'title' => '', 'path' => array(), 'html' => '' );
    }

    public function run( array $argv, int $timeout, ?callable $progress = null ): array
    {
        $this->calls[] = array( 'run', 0, implode( ' ', $argv ), array() );
        if ( !$this->d['commands'] )
            return array( 'exit_code' => 0, 'stdout' => '', 'stderr' => '', 'ms' => 1, 'timed_out' => false );
        return ( $this->d['commands'] )( $argv, $timeout ) + array( 'exit_code' => 0, 'stdout' => '', 'stderr' => '', 'ms' => 1, 'timed_out' => false );
    }

    public function queueInstalled(): bool
    {
        return $this->d['queue'] !== false;
    }

    public function queueJobs( array $filter, int $offset, int $limit ): array
    {
        $rows = array_values( array_filter( (array)$this->d['queue'], function ( $j ) use ( $filter ) {
            foreach ( $filter as $k => $v )
                if ( (string)( $j[$k] ?? '' ) !== (string)$v )
                    return false;
            return true;
        } ) );
        $counts = array();
        foreach ( (array)$this->d['queue'] as $j )
            $counts[$j['state']] = ( $counts[$j['state']] ?? 0 ) + 1;
        return array( 'total' => count( $rows ), 'counts' => $counts, 'items' => array_slice( $rows, $offset, $limit ) );
    }

    public function queueJob( string $ref ): ?array
    {
        foreach ( (array)$this->d['queue'] as $j )
            if ( (string)$j['id'] === $ref || (string)( $j['uuid'] ?? '' ) === $ref )
                return $j;
        return null;
    }
}
