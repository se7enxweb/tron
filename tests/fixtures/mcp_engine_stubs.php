<?php
/**
 * Test-only MCP engines, registered by settings alone (McpEngineTest):
 *
 *   TronStubBridgeFork   a class with exactly the bridge API (seven public static methods) that does NOT declare
 *                        tronMcpBridgeApi: an engine written against the same API elsewhere, dropped in unchanged
 *   TronStubNotesEngine  the neutral contract (tronMcpEngine): one read and one write tool, an in-memory note list
 *   TronStubHalfBridge   a class with only part of the bridge API: neither kind, refused
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

if ( !class_exists( 'TronStubBridgeFork', false ) )
{
    final class TronStubBridgeFork
    {
        /** @var array[] the calls this run, as array( name, input ) */
        public static $calls = array();

        public static function tools()
        {
            return array(
                'stub_get'    => array( 'class' => __CLASS__, 'method' => 'get', 'description' => 'd', 'schema' => 's' ),
                'stub_search' => array( 'class' => __CLASS__, 'method' => 'search', 'description' => 'd', 'schema' => 's' ),
            );
        }

        public static function toolNames()
        {
            return array_keys( self::tools() );
        }

        public static function isBridgeSafe( $name )
        {
            return isset( self::tools()[(string)$name] );
        }

        public static function description( $name )
        {
            self::spec( $name );
            return 'Stub bridge tool ' . $name . ': reads one made-up item.';
        }

        public static function inputSchema( $name )
        {
            self::spec( $name );
            return array( 'type' => 'object', 'properties' => array( 'node_id' => array( 'type' => 'integer' ) ), 'additionalProperties' => false );
        }

        public static function tool( $name, $siteAccess = null, ?eZINI $ini = null )
        {
            self::spec( $name );
            return new stdClass();
        }

        public static function call( $name, array $input = array(), $siteAccess = null, ?eZINI $ini = null )
        {
            self::spec( $name );
            self::$calls[] = array( $name, $input );
            if ( isset( $input['node_id'] ) && (int)$input['node_id'] === 404 )
                return array( 'error' => 'Node 404 does not exist or is not readable.' );
            return array( 'tool' => $name, 'node_id' => (int)( $input['node_id'] ?? 2 ), 'name' => 'Example item' );
        }

        private static function spec( $name )
        {
            if ( !self::isBridgeSafe( $name ) )
                throw new InvalidArgumentException( "The tool '{$name}' is not safe for the in-process bridge." );
            return self::tools()[(string)$name];
        }
    }

    final class TronStubHalfBridge
    {
        public static function toolNames()
        {
            return array( 'x' );
        }

        public static function isBridgeSafe( $name )
        {
            return true;
        }
    }

    final class TronStubNotesEngine implements tronMcpEngine
    {
        /** @var array[] notes written this run */
        public static $notes = array();

        /** @var array */ private $settings;

        public function __construct( array $settings = array() )
        {
            $this->settings = $settings;
        }

        public function describeServer(): array
        {
            return array( 'name' => 'notes', 'title' => 'Notes (stub)', 'version' => '1.2.3', 'description' => 'Made-up notes.' );
        }

        public function listTools(): array
        {
            return array(
                array( 'name' => 'note_list', 'description' => 'Lists the notes.', 'inputSchema' => array( 'type' => 'object', 'properties' => array() ), 'write' => false ),
                array( 'name' => 'note_create', 'description' => 'Writes a note.', 'write' => true,
                       'inputSchema' => array( 'type' => 'object', 'properties' => array( 'text' => array( 'type' => 'string' ) ), 'required' => array( 'text' ) ) ),
                array( 'name' => 'Bad-Name', 'description' => 'Never registered.', 'inputSchema' => array( 'type' => 'object' ), 'write' => false ),
            );
        }

        public function callTool( string $name, array $arguments, tronPrincipal $principal )
        {
            if ( $name === 'note_create' )
            {
                self::$notes[] = array( 'text' => (string)$arguments['text'], 'by' => $principal->login );
                return array( 'created' => count( self::$notes ), 'by' => $principal->login );
            }
            if ( $name === 'note_list' )
                return array( 'notes' => self::$notes, 'asked_by' => $principal->login );
            throw new tronToolError( "No tool $name" );
        }

        public function writes( string $name ): bool
        {
            return $name === 'note_create';
        }

        public function schema( string $name ): array
        {
            foreach ( $this->listTools() as $t )
                if ( $t['name'] === $name )
                    return $t['inputSchema'];
            return array( 'type' => 'object' );
        }
    }
}
