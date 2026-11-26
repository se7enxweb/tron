<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The neutral contract of an MCP engine: an extension that serves tools to tron and, through tron, to every host
 * that asks tron for tools (expai's assistant, tron's own stdio and HTTP transports).
 *
 * An extension registers its engine in its own settings, extension/<ext>/settings/tron.ini.append.php:
 *
 *   [McpEngine_notes]
 *   Class=myNotesEngine          # implements tronMcpEngine (or the bridge API, see tronMcpBridgeApi)
 *   Enabled=enabled
 *   Label=Notes
 *   ReadPolicy=                  # module/function a read tool without its own policy needs (empty: none)
 *   WritePolicy=notes/write      # module/function a write tool without its own policy needs; empty: no write tools
 *
 * Activating the extension is enough. The engine's tools are offered as <id>__<tool> (notes__note_list); tron checks
 * the caller's policies and writes every call to its audit log before the engine runs, so an engine only has to
 * answer for what the user may see inside a call.
 *
 * tron builds the engine with new <Class>( array $settings ) (the INI group), or <Class>::fromSettings( $settings )
 * when the class has that static method.
 */
interface tronMcpEngine
{
    /**
     * What the engine is: name, title, version, description (all strings; version and description may be empty).
     */
    public function describeServer(): array;

    /**
     * The engine's whole catalogue, independent of the caller: a list of tool definitions, each an array with
     *   name         a-z0-9_, at most 40 characters (tron adds "<id>__")
     *   title        optional
     *   description  for the model
     *   inputSchema  a JSON schema of type object
     *   write        bool: the tool changes something
     *   policy       optional array( module, function ): the Exponential policy a caller needs
     * tron shows each tool only to callers who hold its policy; callTool() still answers for the data.
     */
    public function listTools(): array;

    /**
     * Runs a tool as the Exponential user of $principal and returns its answer (an array, or a string).
     * Throws tronToolError for an answer the caller should see as an error (a refusal, a miss).
     */
    public function callTool( string $name, array $arguments, tronPrincipal $principal );

    /** Whether the tool changes something. */
    public function writes( string $name ): bool;

    /** The tool's input schema (JSON schema of type object). */
    public function schema( string $name ): array;
}
