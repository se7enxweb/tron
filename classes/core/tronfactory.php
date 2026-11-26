<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Puts a server together from its parts. The kernel binding (tronKernel) and the tests both come through here, so
 * what the tests prove is what an installation runs; only the parts differ.
 *
 * Parts:
 *   settings    array group => name => value (the tron.ini groups)
 *   access      tronAccessChecker                      (required)
 *   audit       tronAuditSink                          (required)
 *   db          tronDb|null
 *   users       tronUserDirectory|null
 *   extensions  array name => directory, active extensions in load order
 *   builtinDir  directory of tron's own programs (default: <tron>/programs)
 *   services    more environment services, name => value or Closure
 *   clock       callable returning unix time
 *   logger      callable( level, message )
 *   currentUserId  callable returning the signed-in user's id (bridge-API engines; default from the kernel)
 *   scanned     rows of tronMcpEngineFactory::scan(), registered when [McpEngineSettings] ScanRegister=enabled
 *   inProcess   true: the server answers a host in the same request (tronMcpEngineHub), so bridge-API engines are offered
 */
final class tronFactory
{
    public static function builtinDir(): string
    {
        return dirname( __DIR__, 2 ) . '/programs';
    }

    public static function build( array $parts ): tronServer
    {
        $settings = (array)( $parts['settings'] ?? array() );
        $services = (array)( $parts['services'] ?? array() );
        if ( isset( $parts['db'] ) )
            $services['db'] = $parts['db'];
        if ( isset( $parts['users'] ) )
            $services['users'] = $parts['users'];
        $services['access'] = $parts['access'];
        $env = new tronEnvironment( $settings, $services, $parts['clock'] ?? null );

        $registry = new tronRegistry();
        $audit = tronJournalSink::wrap( $parts['audit'], $env, $registry, $parts['logger'] ?? null );   // the activity journal, when its tables are there
        $loader = new tronProgramLoader();
        $disabled = array();
        foreach ( (array)( $settings['ProgramSettings']['Disabled'] ?? array() ) as $name )
            $disabled[(string)$name] = '[ProgramSettings] Disabled[]';
        $switchedOff = (array)( $parts['switchedOff'] ?? array() );          // array( 'program' => names, 'tool' => names )
        foreach ( (array)( $switchedOff['program'] ?? array() ) as $name )
            $disabled[(string)$name] = 'switched off in Setup > tron';
        unset( $disabled['tron'] );
        $extensions = ( $settings['ProgramSettings']['ExtensionScan'] ?? 'enabled' ) === 'disabled' ? array() : (array)( $parts['extensions'] ?? array() );
        $status = $loader->load( $registry, $env, (string)( $parts['builtinDir'] ?? self::builtinDir() ), $extensions, $disabled );

        // MCP engines ([McpEngine_<id>] groups): each a program named by its id, its tools <id>__<tool>
        $built = tronMcpEngineFactory::build( $settings, $parts['currentUserId'] ?? null, (array)( $parts['scanned'] ?? array() ) );
        $engineStatus = $built['status'];
        $engines = array();
        foreach ( $built['engines'] as $id => $entry )
        {
            if ( isset( $disabled[$id] ) )
            {
                foreach ( $engineStatus as $i => $row )
                    if ( $row['name'] === $id && $row['state'] === 'loaded' )
                        $engineStatus[$i] = array( 'state' => 'disabled', 'reason' => $disabled[$id] ) + $row;
                continue;
            }
            $engines[$id] = $entry;
        }
        tronMcpEngineFactory::register( $registry, $engines, $engineStatus, array_column( $status, 'name' ), !empty( $parts['inProcess'] ) );
        $engineLabels = array();
        foreach ( $engineStatus as $row )
            if ( $row['state'] === 'loaded' )
                $engineLabels[$row['name']] = (string)( $engines[$row['name']]['settings']['Label'] ?? $row['name'] );
        $status = array_merge( $status, $engineStatus );

        // the live skills, once every unit is known (doc/skills.md); before the switches, so a skill's tool can be switched off
        if ( !$env->has( 'extensions' ) )
            $env->set( 'extensions', $extensions );
        tronSkillProgram::finalize( $registry, $env );

        foreach ( (array)( $switchedOff['tool'] ?? array() ) as $name )
            $registry->withdraw( (string)$name, 'switched off in Setup > tron' );

        $logger = $parts['logger'] ?? null;
        foreach ( $status as $row )
        {
            if ( $logger && in_array( $row['state'], array( 'failed', 'conflict', 'incompatible' ), true ) )
                call_user_func( $logger, 'warning', "program {$row['name']} ({$row['origin']}) {$row['state']}: {$row['reason']}" );
        }
        $env->set( 'tron.status', function () use ( $status, $registry ) {
            return array( 'programs' => $status, 'tools' => count( $registry->tools() ), 'unavailable' => $registry->unavailableTools() );
        } );

        $s = (array)( $settings['ServerSettings'] ?? array() );
        return new tronServer( $registry, $parts['access'], $audit, $env, array(
            'name' => 'tron',
            'title' => (string)( $s['Title'] ?? 'Exponential tron' ),
            'instructions' => trim( (string)( $s['Instructions'] ?? '' ) ),
            'pageSize' => (int)( $s['PageSize'] ?? 100 ),
            'responseMaxBytes' => (int)( $s['ResponseMaxBytes'] ?? 200000 ),
            'programs' => $status,
            'engines' => $engineLabels,
        ), $logger );
    }
}
