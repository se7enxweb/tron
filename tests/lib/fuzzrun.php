<?php
/**
 * One fuzz run (doc/contract-testing.md): every tool of a world, $perTool inputs each, every input called on two
 * identical worlds (A and B) by the same user, each answer checked (TronFuzz::check()) and the two answers compared
 * (a different answer to the same call in the same state is a non-deterministic error). Every tenth call goes through
 * handleRaw() as JSON text. After the tools: raw protocol garbage, then the audit log is searched for canaries.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

final class TronFuzzRun
{
    /**
     * @param callable $world function (): TronFuzzWorld, called twice
     * @return array( 'tools' => name => stats, 'inputs' => n, 'problems' => array of strings, 'ms' => n )
     */
    public static function run( callable $world, int $perTool, int $seed, ?callable $progress = null, ?string $show = null, array $programs = array() ): array
    {
        $t0 = microtime( true );
        $a = $world();
        $b = $world();
        $needles = TronFuzz::canaryNeedles();
        $hints = $a->hints();
        $users = array_keys( TronFixture::users() );
        $stats = array();
        $problems = array();
        $inputs = 0;
        $id = 0;
        foreach ( $a->server->registry()->tools() as $tool )
        {
            if ( $programs && !in_array( $tool->program, $programs, true ) )
                continue;
            $fz = new TronFuzz( $seed + crc32( $tool->name ) % 100000 );
            $s = array( 'inputs' => 0, 'ok' => 0, 'tool_error' => 0, 'rpc_error' => 0, 'problems' => 0, 'categories' => array() );
            foreach ( $fz->inputs( $tool->inputSchema, $perTool, $hints ) as $i => $in )
            {
                list( $cat, $args ) = $in;
                // half the calls as users who hold most policies, so valid inputs get past the gates
                $user = $fz->next( 2 ) === 0 ? $fz->pick( array( TronFixture::ADMIN, TronFixture::DECIDER ) ) : $fz->pick( $users );
                $session = TronFixture::session( $user, $fz->next( 5 ) === 0 ? 'http' : 'stdio', $fz->pick( array( '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05' ) ) );
                $msg = array( 'jsonrpc' => '2.0', 'id' => ++$id, 'method' => 'tools/call', 'params' => array( 'name' => $tool->name, 'arguments' => $args ) );
                if ( $fz->next( 12 ) === 0 )
                    $msg['params']['_meta'] = $fz->pick( array( array( 'tron/dryRun' => true ), array( 'tron/planId' => str_repeat( 'a', 32 ) ), array( 'tron/planId' => 5 ) ) );
                $raw = $i % 10 === 9;
                $a->before();
                $ra = self::send( $a->server, $msg, $session, $raw );
                $a->after();
                $b->before();
                $rb = self::send( $b->server, $msg, TronFixture::session( $user, $session->transport, $session->protocolVersion ), $raw );
                $b->after();
                // a dry run through _meta answers with a plan, which follows tron_plan's output schema
                $dry = ( $msg['params']['_meta']['tron/dryRun'] ?? false ) === true;
                $p = TronFuzz::check( $ra, $dry ? $a->server->registry()->findTool( 'tron_plan' ) : $tool, $user, $needles );
                // errors must not depend on anything but the call and the state (answers may carry timings)
                if ( TronFuzz::outcome( $ra ) !== TronFuzz::outcome( $rb ) )
                    $p[] = 'non-deterministic error: the same call in the same state answered ' . TronFuzz::outcome( $ra ) . ' and ' . TronFuzz::outcome( $rb );
                foreach ( array( $a, $b ) as $w )
                {
                    foreach ( $w->errors as $e )
                        $p[] = 'server logged an error: ' . substr( $e, 0, 300 );
                    $w->errors = array();
                }
                $s['inputs']++;
                $s['categories'][$cat] = ( $s['categories'][$cat] ?? 0 ) + 1;
                if ( isset( $ra['error'] ) )
                    $s['rpc_error']++;
                else if ( !empty( $ra['result']['isError'] ) )
                    $s['tool_error']++;
                else
                    $s['ok']++;
                if ( $p )
                {
                    $s['problems']++;
                    if ( count( $problems ) < 200 )
                        $problems[] = "{$tool->name} [$cat] user $user args " . substr( (string)json_encode( $args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR ), 0, 300 ) . ': ' . implode( '; ', $p );
                }
                $inputs++;
                if ( $show !== null && ( $show === '*' || $show === $tool->name ) )
                    printf( "  %-9s user %-2d %s\n      -> %s\n", $cat, $user, substr( (string)json_encode( $args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR ), 0, 160 ),
                            substr( TronFuzz::outcome( $ra ) === 'ok' ? 'ok ' . (string)( $ra['result']['content'][0]['text'] ?? '' ) : TronFuzz::outcome( $ra ), 0, 300 ) );
            }
            $stats[$tool->name] = $s;
            if ( $progress )
                $progress( $tool->name, $s );
        }

        // the protocol layer: garbage in, a clean JSON-RPC error out, never an internal error
        $fz = new TronFuzz( $seed );
        $garbage = array( '', ' ', '{', '[]', '[1,2]', 'null', '"x"', '{"jsonrpc":"2.0"}', '{"jsonrpc":"2.0","id":{},"method":"ping"}',
                          '{"jsonrpc":"1.0","id":1,"method":"ping"}', '{"jsonrpc":"2.0","id":1,"method":5}', '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":"x"}',
                          '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":[]}}', '{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"centre://ticket/../../1"}}',
                          '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{"cursor":"!!"}}', str_repeat( '[', 5000 ), "\xff\xfe", '{"a":' . str_repeat( '1', 400 ) . '}' );
        for ( $i = 0; $i < 200; $i++ )
        {
            $g = $i < count( $garbage ) ? $garbage[$i]
               : json_encode( array( 'jsonrpc' => '2.0', 'id' => $i, 'method' => $fz->pick( array( 'initialize', 'resources/read', 'prompts/get', 'tools/call', 'resources/templates/list', 'nope' ) ),
                                     'params' => $fz->input( array( 'type' => 'object', 'properties' => array( 'name' => array( 'type' => 'string' ), 'uri' => array( 'type' => 'string' ),
                                                 'protocolVersion' => array( 'type' => 'string' ), 'arguments' => array( 'type' => 'object' ) ) ), $fz->pick( array_keys( TronFuzz::CATEGORIES ) ), $hints ) ),
                              JSON_INVALID_UTF8_SUBSTITUTE );
            $r = $a->server->handleRaw( (string)$g, TronFixture::session( $fz->pick( $users ) ) );
            $decoded = $r === null ? null : json_decode( $r, true );
            $p = $r === null ? array() : TronFuzz::check( is_array( $decoded ) ? ( isset( $decoded[0] ) ? $decoded[0] : $decoded ) : array( 'error' => array( 'code' => 0 ) ), null, 0, $needles );
            foreach ( $a->errors as $e )
                $p[] = 'server logged an error: ' . substr( $e, 0, 300 );
            $a->errors = array();
            if ( $p && count( $problems ) < 200 )
                $problems[] = 'protocol input ' . substr( (string)$g, 0, 120 ) . ': ' . implode( '; ', $p );
            $inputs++;
        }

        // the audit log keeps arguments, masked: no canary there either
        foreach ( $a->audit->entries as $e )
        {
            $line = json_encode( $e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
            foreach ( $needles as $n )
                if ( strpos( (string)$line, $n ) !== false && count( $problems ) < 200 )
                    $problems[] = "canary in the audit log: $n (entry {$e['method']} {$e['target']})";
        }
        return array( 'tools' => $stats, 'inputs' => $inputs, 'problems' => $problems, 'audit_rows' => count( $a->audit->entries ),
                      'ms' => (int)round( ( microtime( true ) - $t0 ) * 1000 ) );
    }

    private static function send( tronServer $server, array $msg, tronSession $session, bool $raw ): ?array
    {
        if ( !$raw )
            return $server->handle( $msg, $session );
        $json = json_encode( $msg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR );
        $out = $server->handleRaw( (string)$json, $session );
        return $out === null ? null : json_decode( $out, true );
    }
}
