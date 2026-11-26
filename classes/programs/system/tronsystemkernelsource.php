<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The program "system" inside an installation: modules through eZModule, fetch functions through eZFunctionHandler,
 * operators through eZTemplate, views through eZModule::run(), commands with proc_open() (no shell), the queue
 * through expQueue.
 *
 * Code of the installation runs as the calling user (asUser(): the current user is switched for the call and put
 * back, the session is not touched) and inside a database transaction that is rolled back: a fetch, an operator or
 * a view that writes against its nature leaves nothing behind in the database.
 */
final class tronSystemKernelSource implements tronSystemSource
{
    /** @var array module => definition */ private $modules = array();
    /** @var array|null operator => class */ private $operators = null;

    public function available(): bool
    {
        return class_exists( 'eZModule' ) && class_exists( 'eZFunctionHandler' ) && class_exists( 'eZTemplate' );
    }

    public function reason(): string
    {
        return $this->available() ? '' : 'the kernel is not loaded (eZModule, eZFunctionHandler, eZTemplate)';
    }

    public function modules(): array
    {
        $out = array();
        foreach ( eZModule::globalPathList() as $dir )
            foreach ( glob( rtrim( $dir, '/' ) . '/*/module.php' ) ?: array() as $f )
                $out[basename( dirname( $f ) )] = true;
        $names = array_keys( $out );
        sort( $names );
        return array_values( array_filter( $names, function ( $n ) { return !tronSystemInventory::privateName( $n ); } ) );
    }

    public function moduleDefinition( string $module ): ?array
    {
        if ( !preg_match( '/^[a-z0-9_]{1,64}$/', $module ) || tronSystemInventory::privateName( $module ) )
            return null;
        if ( array_key_exists( $module, $this->modules ) )
            return $this->modules[$module];
        $def = null;
        foreach ( eZModule::globalPathList() as $dir )
        {
            $file = rtrim( $dir, '/' ) . '/' . $module . '/module.php';
            if ( !is_file( $file ) )
                continue;
            $vars = self::read( $file );
            if ( $vars === null )
                continue;
            $origin = preg_match( '#extension/([^/]+)/#', $file, $m ) ? $m[1] : 'kernel';
            $def = array( 'origin' => $origin, 'file' => $file, 'dir' => dirname( $file ),
                          'module' => (array)( $vars['Module'] ?? array() ), 'views' => (array)( $vars['ViewList'] ?? array() ),
                          'functions' => (array)( $vars['FunctionList'] ?? array() ) );
            break;
        }
        return $this->modules[$module] = $def;
    }

    public function fetchDefinition( string $module ): ?array
    {
        $def = $this->moduleDefinition( $module );
        if ( !$def || !is_file( $def['dir'] . '/function_definition.php' ) )
            return null;
        $vars = self::read( $def['dir'] . '/function_definition.php' );
        return is_array( $vars['FunctionList'] ?? null ) ? $vars['FunctionList'] : null;
    }

    public function fetchCall( int $userId, string $module, string $function, array $params )
    {
        return $this->asUser( $userId, function () use ( $module, $function, $params ) {
            return eZFunctionHandler::execute( $module, $function, $params );
        } );
    }

    public function operatorClass( string $operator ): ?string
    {
        if ( $this->operators === null )
        {
            $this->operators = array();
            $tpl = eZTemplate::factory();
            foreach ( (array)$tpl->Operators as $name => $op )
                $this->operators[(string)$name] = is_array( $op ) ? (string)( $op['class'] ?? 'autoload' ) : ( is_object( $op ) ? get_class( $op ) : 'registered' );
        }
        return $this->operators[$operator] ?? null;
    }

    public function operatorEval( int $userId, string $operator, $input, array $params )
    {
        if ( !preg_match( '/^[a-z][a-z0-9_]*$/', $operator ) )
            throw new tronToolError( 'Not an operator name' );
        return $this->asUser( $userId, function () use ( $operator, $input, $params ) {
            $tpl = eZTemplate::factory();
            $tpl->resetErrorLog();
            $tpl->setVariable( 'tron_input', $input );
            $names = array();
            foreach ( array_values( $params ) as $i => $p )
            {
                $tpl->setVariable( 'tron_p' . $i, $p );
                $names[] = '$tron_p' . $i;
            }
            $tpl->setVariable( 'tron_out', false );
            // the input and the parameters are variables, never template text: nothing the caller sends is parsed
            $source = '{set $tron_out = $tron_input|' . $operator . '(' . implode( ',', $names ) . ')}';
            $root = array( eZTemplate::NODE_ROOT, false );
            $resourceData = array( 'text' => $source, 'template-name' => 'tron:operator', 'template-filename' => 'tron:operator',
                                   'resource' => false, 'locales' => false, 'uri' => 'tron:operator' );
            $tpl->parse( $source, $root, '', $resourceData );
            $text = '';
            $tpl->process( $root, $text, '', '' );
            $errors = method_exists( $tpl, 'errorLog' ) ? (array)$tpl->errorLog() : array();
            if ( $errors )
                throw new tronToolError( 'The operator failed: ' . mb_substr( self::errorText( $errors ), 0, 300 ) );
            return $tpl->variable( 'tron_out' );
        } );
    }

    public function canView( int $userId, string $module, string $view, array $params ): bool
    {
        $user = eZUser::fetch( $userId );
        $mod = eZModule::exists( $module );
        if ( !$user instanceof eZUser || !$user->isEnabled() || !$mod )
            return false;
        $p = $params;
        $r = $user->hasAccessToView( $mod, $view, $p );
        return is_array( $r ) ? !empty( $r['accessAllowed'] ?? $r[0] ?? false ) : (bool)$r;
    }

    public function viewRead( int $userId, string $module, string $view, array $params, array $unordered ): array
    {
        return $this->asUser( $userId, function () use ( $module, $view, $params, $unordered ) {
            $mod = eZModule::exists( $module );
            if ( !$mod )
                throw new tronToolError( "No module $module" );
            $post = $_POST;
            $_POST = array();                 // a GET: no form is ever submitted
            try
            {
                $result = $mod->run( $view, $params, false, $unordered );
            }
            finally
            {
                $_POST = $post;
            }
            if ( !is_array( $result ) )
                $result = array();
            $status = method_exists( $mod, 'exitStatus' ) ? (int)$mod->exitStatus() : 0;
            $redirect = method_exists( $mod, 'redirectURI' ) ? (string)$mod->redirectURI() : '';
            $path = array();
            foreach ( (array)( $result['path'] ?? array() ) as $el )
                if ( is_array( $el ) )
                    $path[] = array( 'text' => (string)( $el['text'] ?? '' ), 'url' => is_string( $el['url'] ?? null ) ? $el['url'] : '' );
            $title = '';
            foreach ( $path as $el )
                $title = $el['text'];
            return array( 'title' => (string)( $result['title'] ?? $title ), 'path' => $path, 'html' => (string)( $result['content'] ?? '' ),
                          'status' => $status, 'redirect' => $redirect );
        } );
    }

    public function run( array $argv, int $timeout, ?callable $progress = null ): array
    {
        $cmd = $argv;
        if ( is_executable( '/usr/bin/nice' ) )
            array_unshift( $cmd, '/usr/bin/nice', '-n', '15' );
        $env = array( 'PATH' => getenv( 'PATH' ) ?: '/usr/bin:/bin', 'TERM' => 'dumb', 'NO_COLOR' => '1', 'LANG' => 'C.UTF-8' );
        $t0 = microtime( true );
        $proc = proc_open( $cmd, array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, getcwd(), $env );
        if ( !is_resource( $proc ) )
            throw new tronToolError( 'The command could not be started' );
        stream_set_blocking( $pipes[1], false );
        stream_set_blocking( $pipes[2], false );
        $out = '';
        $err = '';
        $timedOut = false;
        $lastTick = $t0;
        while ( true )
        {
            $r = array( $pipes[1], $pipes[2] );
            $w = null;
            $e = null;
            if ( @stream_select( $r, $w, $e, 1 ) === false )
                break;
            foreach ( $r as $s )
            {
                $chunk = (string)fread( $s, 65536 );
                if ( $s === $pipes[1] )
                    $out = substr( $out . $chunk, -400000 );
                else
                    $err = substr( $err . $chunk, -100000 );
            }
            $st = proc_get_status( $proc );
            if ( !$st['running'] )
            {
                $out .= (string)stream_get_contents( $pipes[1] );
                $err .= (string)stream_get_contents( $pipes[2] );
                $code = (int)$st['exitcode'];
                break;
            }
            if ( microtime( true ) - $t0 > $timeout )
            {
                $timedOut = true;
                proc_terminate( $proc, 15 );
                usleep( 200000 );
                proc_terminate( $proc, 9 );
                $code = 124;
                break;
            }
            if ( $progress && microtime( true ) - $lastTick >= 10 )
            {
                $lastTick = microtime( true );
                call_user_func( $progress, (int)( microtime( true ) - $t0 ), strlen( $out ) );
            }
        }
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        $closed = proc_close( $proc );
        if ( !isset( $code ) || $code === -1 )
            $code = $closed;
        return array( 'exit_code' => (int)$code, 'stdout' => $out, 'stderr' => $err, 'ms' => (int)round( ( microtime( true ) - $t0 ) * 1000 ),
                      'timed_out' => $timedOut );
    }

    public function queueInstalled(): bool
    {
        return class_exists( 'expQueue' ) && expQueue::installed();
    }

    public function queueJobs( array $filter, int $offset, int $limit ): array
    {
        $items = array();
        foreach ( expQueue::listJobs( $filter, $offset, $limit ) as $job )
            $items[] = self::job( $job, false );
        $counts = array();
        foreach ( (array)expQueue::countBy( 'state', array_diff_key( $filter, array( 'state' => 1 ) ) ) as $state => $n )
            $counts[(string)$state] = (int)$n;
        return array( 'total' => (int)expQueue::count( $filter ), 'counts' => $counts, 'items' => $items );
    }

    public function queueJob( string $ref ): ?array
    {
        $job = ctype_digit( $ref ) ? expQueueJob::fetchByID( (int)$ref ) : expQueueJob::fetchByUUID( $ref );
        return $job ? self::job( $job, true ) : null;
    }

    private static function job( $job, bool $withData ): array
    {
        $a = (array)$job->toArray( $withData );
        return $a;
    }

    /**
     * Runs $fn with the user as the current user, inside a database transaction that is rolled back, and puts the
     * previous user back. The session is not touched.
     */
    private function asUser( int $userId, callable $fn )
    {
        $user = eZUser::fetch( $userId );
        if ( !$user instanceof eZUser || !$user->isEnabled() )
            throw new tronToolError( 'The calling user does not exist or is disabled' );
        $prev = $GLOBALS['eZUserGlobalInstance_'] ?? null;
        $GLOBALS['eZUserGlobalInstance_'] = $user;
        $db = eZDB::instance();
        $db->begin();
        try
        {
            return $fn();
        }
        finally
        {
            $db->rollback();
            if ( $prev === null )
                unset( $GLOBALS['eZUserGlobalInstance_'] );
            else
                $GLOBALS['eZUserGlobalInstance_'] = $prev;
        }
    }

    private static function errorText( array $errors ): string
    {
        $parts = array();
        foreach ( $errors as $e )
            $parts[] = is_array( $e ) ? (string)( $e['text'] ?? $e['message'] ?? json_encode( $e ) ) : (string)$e;
        return implode( '; ', $parts );
    }

    private static function read( string $file ): ?array
    {
        set_error_handler( function () { return true; } );
        ob_start();
        try
        {
            return ( static function ( string $__file ) {
                include $__file;
                return get_defined_vars();
            } )( $file );
        }
        catch ( Throwable $e )
        {
            return null;
        }
        finally
        {
            ob_end_clean();
            restore_error_handler();
        }
    }
}
