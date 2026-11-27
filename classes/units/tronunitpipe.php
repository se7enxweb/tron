<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * tron_pipe: runs units one after another, the output of one feeding the next by type (doc/units.md, "Pipes").
 *
 *   node.find class=article limit=3 | node.read | tpl.resolve siteaccess=site
 *
 * A step's answer is turned into the next step's input with tronUnitTypes::convert(): a node-summary gives a node-ref
 * from node_id, a template-chain a template-file from winner.path. A list answer feeds the next step item by item (at
 * most max_items items per step); identical arguments are called once. Every step is a real tool call of the session:
 * the same scope, policy and argument checks, its own audit entry, the same redaction on the way out. Only units may
 * be piped, and in this release only read units; a pipe has at most MAX_STEPS steps and MAX_CALLS calls.
 */
final class tronUnitPipe
{
    const MAX_STEPS = 8;
    const MAX_CALLS = 100;
    const MAX_ITEMS = 25;

    /** Registers tron_pipe and the resource tron://units in the core program, where units are available. */
    public static function register( tronRegistry $registry, tronEnvironment $env ): void
    {
        if ( !tronUnitSources::any( $env ) )
        {
            $registry->unavailable( 'tron_pipe', 'no units here (they read through an Exponential installation)' );
            return;
        }
        $registry->tool( tronTool::define( array(
            'name' => 'tron_pipe',
            'title' => 'Pipe units',
            'description' => 'Runs read units one after another, each one\'s output feeding the next by type, like a Unix pipe: "node.find class=article limit=3 | node.read | tpl.resolve siteaccess=site". A list feeds the next unit item by item (max_items per step). Give the pipe as text (pipe) or as steps [{unit, args}]; explain=true shows the plan with the types per step without running it. Every step is checked and audited as its own call. Units, types and examples: resource tron://units, doc/units.md.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
                'pipe' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 4000 ),
                'steps' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_STEPS, 'items' => array(
                    'type' => 'object', 'required' => array( 'unit' ), 'properties' => array(
                        'unit' => array( 'type' => 'string', 'maxLength' => 64 ), 'args' => array( 'type' => 'object' ) ) ) ),
                'max_items' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_ITEMS, 'default' => 10 ),
                'explain' => array( 'type' => 'boolean', 'default' => false ),
            ) ),
            'policy' => array( 'tron', 'use' ),
            'outputSchema' => array( 'type' => 'object', 'required' => array( 'pipe', 'steps', 'out', 'count', 'results' ) ),
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $env ) {
                $server = self::server( $env );
                if ( isset( $a['pipe'] ) === isset( $a['steps'] ) )
                    throw new tronToolError( 'Give pipe (text) or steps (a list), one of them' );
                $steps = isset( $a['pipe'] ) ? self::parse( (string)$a['pipe'], $server->registry() ) : self::steps( (array)$a['steps'] );
                $plan = self::plan( $steps, $server, $ctx->session );
                if ( !empty( $a['explain'] ) )
                    return array( 'pipe' => self::text( $plan ), 'steps' => $plan, 'out' => end( $plan )['out'], 'count' => 0, 'results' => array(), 'explained' => true );
                return self::run( $plan, $server, $ctx->session, (int)$a['max_items'] );
            },
        ) ) );

        $registry->resource( tronResource::define( array(
            'uri' => 'tron://units', 'name' => 'tron_units', 'title' => 'tron units',
            'description' => 'The units (id, tool, input and output types) and the type catalog the pipe uses.',
            'policy' => array( 'tron', 'use' ),
            'reader' => function ( array $vars, tronCallContext $ctx ) {
                return array( 'units' => array_values( tronUnitCatalog::units() ), 'types' => tronUnitTypes::catalog(),
                              'limits' => array( 'steps' => self::MAX_STEPS, 'calls' => self::MAX_CALLS, 'items_per_step' => self::MAX_ITEMS ) );
            },
        ) ) );
    }

    /**
     * The server the steps run through: the one serving this session (environment service "tron.server"), or in an
     * installation whose server does not offer itself there, a server built the same way (same programs, policies and
     * audit sinks).
     */
    private static function server( tronEnvironment $env )
    {
        if ( $env->has( 'tron.server' ) )
            return $env->get( 'tron.server' );
        if ( tronUnitSources::inKernel() && class_exists( 'tronKernel' ) )
        {
            $server = tronKernel::server();
            $env->set( 'tron.server', $server );
            return $server;
        }
        throw new tronToolError( 'tron_pipe needs the server in the environment (tron.server)' );
    }

    /** @return array[] unit, args */
    public static function steps( array $raw ): array
    {
        $out = array();
        foreach ( $raw as $i => $s )
        {
            if ( !is_array( $s ) || !isset( $s['unit'] ) )
                throw new tronToolError( 'Step ' . ( $i + 1 ) . ' names no unit' );
            $args = $s['args'] ?? array();
            if ( !is_array( $args ) || ( $args !== array() && tronSchemaValidator::isList( $args ) ) )
                throw new tronToolError( 'Step ' . ( $i + 1 ) . ': args must be an object' );
            $out[] = array( 'unit' => (string)$s['unit'], 'args' => $args );
        }
        return $out;
    }

    /**
     * The text form: steps split on "|" (outside quotes), each the unit followed by key=value pairs ("key value"
     * works too; a key alone is true). Values are typed by the unit tool's input schema.
     */
    public static function parse( string $pipe, ?tronRegistry $registry = null ): array
    {
        $steps = array();
        foreach ( self::split( $pipe, '|' ) as $i => $part )
        {
            $tokens = self::tokens( $part );
            if ( !$tokens )
                throw new tronToolError( 'Step ' . ( $i + 1 ) . ' of the pipe is empty' );
            $unitName = array_shift( $tokens );
            $unit = tronUnitCatalog::find( $unitName );
            $props = array();
            if ( $unit && $registry && ( $tool = $registry->findTool( $unit['tool'] ) ) )
                $props = (array)( $tool->inputSchema['properties'] ?? array() );
            $args = array();
            for ( $j = 0; $j < count( $tokens ); $j++ )
            {
                $t = $tokens[$j];
                if ( strpos( $t, '=' ) !== false )
                {
                    list( $k, $v ) = explode( '=', $t, 2 );
                }
                else if ( isset( $tokens[$j + 1] ) && strpos( $tokens[$j + 1], '=' ) === false )
                {
                    $k = $t;
                    $v = $tokens[++$j];
                }
                else
                {
                    $k = $t;
                    $v = true;
                }
                $args[$k] = self::typed( $v, $props[$k] ?? array() );
            }
            $steps[] = array( 'unit' => $unitName, 'args' => $args );
        }
        if ( count( $steps ) > self::MAX_STEPS )
            throw new tronToolError( 'A pipe has at most ' . self::MAX_STEPS . ' steps' );
        return $steps;
    }

    /**
     * Checks the steps and works out the types: each step's unit, its tool, the input type it is fed with and its
     * output type. Throws tronToolError with the reason when the pipe cannot run.
     */
    public static function plan( array $steps, $server, tronSession $session ): array
    {
        if ( !$steps || count( $steps ) > self::MAX_STEPS )
            throw new tronToolError( 'A pipe has 1 to ' . self::MAX_STEPS . ' steps' );
        $plan = array();
        $prev = null;
        foreach ( $steps as $i => $s )
        {
            $n = $i + 1;
            $unit = tronUnitCatalog::find( (string)$s['unit'] );
            if ( !$unit )
                throw new tronToolError( "Step $n: '{$s['unit']}' is not a unit (units: " . implode( ', ', array_keys( tronUnitCatalog::units() ) ) . ')' );
            $tool = $server->registry()->findTool( $unit['tool'] );
            if ( !$tool || !$session->principal->inScope( $tool->program ) )
                throw new tronToolError( "Step $n: the unit {$unit['id']} is not available here (its tool {$unit['tool']} is not registered or switched off)" );
            if ( $tool->write )
                throw new tronToolError( "Step $n: {$unit['id']} writes; a pipe holds read units only" );
            if ( !$server->permitted( $session, $tool->policy, $tool->limitedOk ) )
                throw new tronToolError( "Step $n: {$unit['id']} needs the policy " . implode( '/', (array)$tool->policy ) . ", which the user '{$session->principal->login}' does not hold" );
            $in = 'none';
            if ( $prev !== null )
            {
                $item = tronUnitTypes::itemType( $prev['out'] );
                $in = null;
                foreach ( $unit['in'] as $t )
                {
                    if ( $t !== 'none' && tronUnitTypes::feeds( $item, $t ) )
                    {
                        $in = $t;
                        break;
                    }
                }
                if ( $in === null )
                    throw new tronToolError( "Step $n: {$unit['id']} takes " . implode( ' or ', $unit['in'] ) . ", but step " . ( $n - 1 )
                                             . " ({$prev['unit']}) gives $item" . ( $prev['out'] !== $item ? " (items of {$prev['out']})" : '' ) );
            }
            else if ( !in_array( 'none', $unit['in'], true ) && !$s['args'] )
                throw new tronToolError( "Step 1: {$unit['id']} needs its input (" . implode( ' or ', $unit['in'] ) . ') as arguments' );
            $plan[] = array( 'step' => $n, 'unit' => $unit['id'], 'tool' => $unit['tool'], 'in' => $in, 'out' => $unit['out'],
                             'fan_out' => $prev !== null && tronUnitTypes::isList( $prev['out'] ), 'args' => $s['args'] );
            $prev = $plan[count( $plan ) - 1];
        }
        return $plan;
    }

    /** Runs a checked plan. */
    public static function run( array $plan, $server, tronSession $session, int $maxItems ): array
    {
        $maxItems = max( 1, min( self::MAX_ITEMS, $maxItems ) );
        $calls = 0;
        $stream = null;           // list of values of the previous step's output type
        $prevOut = null;
        $report = array();
        $t0 = microtime( true );
        foreach ( $plan as $p )
        {
            $ts = microtime( true );
            $row = array( 'step' => $p['step'], 'unit' => $p['unit'], 'tool' => $p['tool'], 'in' => $p['in'], 'out' => $p['out'],
                          'inputs' => 0, 'calls' => 0, 'reused' => 0, 'dropped' => 0, 'errors' => array() );
            $argSets = array();
            if ( $stream === null )
                $argSets[] = $p['args'];
            else
            {
                $item = tronUnitTypes::itemType( $prevOut );
                $elements = array();
                foreach ( $stream as $v )
                {
                    if ( tronUnitTypes::isList( $prevOut ) )
                        foreach ( (array)( $v['items'] ?? array() ) as $it )
                            $elements[] = $it;
                    else
                        $elements[] = $v;
                }
                if ( count( $elements ) > $maxItems )
                {
                    $row['dropped'] = count( $elements ) - $maxItems;
                    $elements = array_slice( $elements, 0, $maxItems );
                }
                foreach ( $elements as $el )
                {
                    $ref = is_array( $el ) ? tronUnitTypes::convert( $item, $el, $p['in'] ) : null;
                    if ( $ref === null )
                    {
                        $row['errors'][] = "an item of $item has no " . $p['in'] . ' (skipped)';
                        continue;
                    }
                    $argSets[] = $ref + $p['args'];
                }
            }
            $row['inputs'] = count( $argSets );
            $results = array();
            $seen = array();
            foreach ( $argSets as $args )
            {
                $key = json_encode( $args );
                if ( isset( $seen[$key] ) )
                {
                    $row['reused']++;
                    continue;
                }
                if ( ++$calls > self::MAX_CALLS )
                {
                    $row['errors'][] = 'the pipe reached ' . self::MAX_CALLS . ' calls and stopped';
                    break;
                }
                $row['calls']++;
                $r = self::call( $server, $p['tool'], $args, $session );
                if ( $r['ok'] )
                {
                    $seen[$key] = true;
                    $results[] = $r['value'];
                }
                else
                    $row['errors'][] = $r['error'];
            }
            $row['ms'] = (int)round( ( microtime( true ) - $ts ) * 1000 );
            $report[] = $row;
            $stream = $results;
            $prevOut = $p['out'];
            if ( !$stream )
                break;
        }
        $complete = count( $report ) === count( $plan );
        return array( 'pipe' => self::text( $plan ), 'complete' => $complete && $stream, 'steps' => $report,
                      'out' => $complete ? $prevOut : null, 'count' => $complete ? count( (array)$stream ) : 0,
                      'calls' => $calls, 'ms' => (int)round( ( microtime( true ) - $t0 ) * 1000 ),
                      'results' => $complete ? array_values( (array)$stream ) : array() );
    }

    /** One step call through the server, as the session: array( ok, value ) or array( ok false, error ). */
    private static function call( $server, string $toolName, array $args, tronSession $session ): array
    {
        $tool = $server->registry()->findTool( $toolName );
        if ( $tool && method_exists( $server, 'invoke' ) )
        {
            $o = $server->invoke( $tool, $args, $session );
            if ( !$o['ok'] )
                return array( 'ok' => false, 'error' => "$toolName: " . $o['error'] );
            $v = $o['value'];
            // the value as the client would see it (JSON), so a step never hands on an object
            $v = json_decode( tronServer::encode( $v ), true );
            return is_array( $v ) ? array( 'ok' => true, 'value' => $v ) : array( 'ok' => false, 'error' => "$toolName: the answer is not an object" );
        }
        $r = $server->call( array( 'jsonrpc' => '2.0', 'id' => 'pipe', 'method' => 'tools/call',
                                     'params' => array( 'name' => $toolName, 'arguments' => $args ) ), $session );
        if ( isset( $r['error'] ) )
            return array( 'ok' => false, 'error' => "$toolName: " . ( $r['error']['message'] ?? 'error' ) );
        $text = (string)( $r['result']['content'][0]['text'] ?? '' );
        $v = json_decode( $text, true );
        if ( !empty( $r['result']['isError'] ) )
            return array( 'ok' => false, 'error' => "$toolName: " . ( is_array( $v ) ? (string)( $v['error'] ?? $text ) : $text ) );
        if ( !is_array( $v ) )
            return array( 'ok' => false, 'error' => "$toolName: the answer was too large to pipe (lower limit or max_items)" );
        return array( 'ok' => true, 'value' => $v );
    }

    /** The plan as pipe text. */
    public static function text( array $plan ): string
    {
        $parts = array();
        foreach ( $plan as $p )
        {
            $s = $p['unit'];
            foreach ( (array)$p['args'] as $k => $v )
                $s .= ' ' . $k . '=' . ( is_bool( $v ) ? ( $v ? 'true' : 'false' ) : ( is_array( $v ) ? json_encode( $v ) : (string)$v ) );
            $parts[] = $s;
        }
        return implode( ' | ', $parts );
    }

    private static function typed( $v, array $prop )
    {
        if ( $v === true )
            return true;
        $types = (array)( $prop['type'] ?? array( 'string' ) );
        $s = (string)$v;
        if ( in_array( 'integer', $types, true ) && preg_match( '/^-?\d+$/', $s ) )
            return (int)$s;
        if ( in_array( 'number', $types, true ) && is_numeric( $s ) )
            return $s + 0;
        if ( in_array( 'boolean', $types, true ) && in_array( strtolower( $s ), array( 'true', 'false', 'yes', 'no', '1', '0' ), true ) )
            return in_array( strtolower( $s ), array( 'true', 'yes', '1' ), true );
        if ( in_array( 'object', $types, true ) && ( $s[0] ?? '' ) === '{' )
        {
            $d = json_decode( $s, true );
            if ( is_array( $d ) )
                return $d;
        }
        if ( in_array( 'array', $types, true ) )
            return array_values( array_filter( array_map( 'trim', explode( ',', $s ) ), 'strlen' ) );
        return $s;
    }

    /** Splits on a separator outside single or double quotes. */
    private static function split( string $s, string $sep ): array
    {
        $out = array();
        $cur = '';
        $q = '';
        $len = strlen( $s );
        for ( $i = 0; $i < $len; $i++ )
        {
            $c = $s[$i];
            if ( $q !== '' )
            {
                if ( $c === $q )
                    $q = '';
                $cur .= $c;
            }
            else if ( $c === '"' || $c === "'" )
            {
                $q = $c;
                $cur .= $c;
            }
            else if ( $c === $sep )
            {
                $out[] = $cur;
                $cur = '';
            }
            else
                $cur .= $c;
        }
        $out[] = $cur;
        return $out;
    }

    /** Whitespace-separated tokens; quotes group and are removed (k="a b" gives k=a b). */
    private static function tokens( string $s ): array
    {
        $out = array();
        $cur = '';
        $q = '';
        $has = false;
        $len = strlen( $s );
        for ( $i = 0; $i < $len; $i++ )
        {
            $c = $s[$i];
            if ( $q !== '' )
            {
                if ( $c === $q )
                    $q = '';
                else
                    $cur .= $c;
            }
            else if ( $c === '"' || $c === "'" )
            {
                $q = $c;
                $has = true;
            }
            else if ( ctype_space( $c ) )
            {
                if ( $cur !== '' || $has )
                    $out[] = $cur;
                $cur = '';
                $has = false;
            }
            else
                $cur .= $c;
        }
        if ( $cur !== '' || $has )
            $out[] = $cur;
        return $out;
    }
}
