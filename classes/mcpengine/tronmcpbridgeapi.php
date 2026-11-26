<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The bridge API: a second way to be an MCP engine, for extensions that already offer their tools to other
 * extensions in process through a class of static methods. Every method, name and signature here is the shape such
 * a bridge has; tron wraps a class of this shape in tronMcpBridgeEngine without any adapter code.
 *
 * A class does not need to declare "implements tronMcpBridgeApi": tron also accepts a class that has these seven
 * public static methods with these parameters (tronMcpBridgeEngine::matches()), so an engine written against the
 * same API elsewhere drops in unchanged. Register it like any engine:
 *
 *   [McpEngine_<id>]
 *   Class=<the bridge class>
 *
 * Bridge tools are read tools: they run in process as the signed-in user (eZUser::currentUser()), answer only what
 * that user may read, and never write.
 */
interface tronMcpBridgeApi
{
    /**
     * Every bridge-safe tool, as name => array( 'class', 'method', 'description', 'schema' ).
     *
     * @return array
     */
    public static function tools();

    /**
     * @return string[] the names of the bridge-safe tools
     */
    public static function toolNames();

    /** @return bool */
    public static function isBridgeSafe( $name );

    /**
     * @return string the tool's description
     * @throws InvalidArgumentException for a tool that is not bridge-safe
     */
    public static function description( $name );

    /**
     * @return array the tool's JSON input schema
     * @throws InvalidArgumentException for a tool that is not bridge-safe
     */
    public static function inputSchema( $name );

    /**
     * The tool object in bridge mode.
     *
     * @param string|null $siteAccess default: the current siteaccess
     * @param eZINI|null  $ini        default: the bridge's own settings
     * @return object
     * @throws InvalidArgumentException for a tool that is not bridge-safe
     */
    public static function tool( $name, $siteAccess = null, ?eZINI $ini = null );

    /**
     * Runs a bridge-safe tool as the current user with the arguments of its input schema, by name, and returns its
     * answer (an array; a refusal or a miss is an answer with an "error" key).
     *
     * @param array $input argument name => value, as the input schema names them
     * @return mixed
     * @throws InvalidArgumentException for a tool that is not bridge-safe, an argument the tool does not take or a
     *         required one left out
     * @throws RuntimeException when the siteaccess is not allowed
     */
    public static function call( $name, array $input = array(), $siteAccess = null, ?eZINI $ini = null );
}
