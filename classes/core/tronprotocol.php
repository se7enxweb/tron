<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/** MCP protocol constants: the versions tron speaks, JSON-RPC error codes, version negotiation. */
final class tronProtocol
{
    const LATEST = '2025-11-25';

    /** Newest first. */
    const VERSIONS = array( '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05' );

    /** Assumed over HTTP when a request carries no MCP-Protocol-Version header (the specification's rule). */
    const HTTP_DEFAULT = '2025-03-26';

    const PARSE_ERROR = -32700;
    const INVALID_REQUEST = -32600;
    const METHOD_NOT_FOUND = -32601;
    const INVALID_PARAMS = -32602;
    const INTERNAL_ERROR = -32603;
    const RESOURCE_NOT_FOUND = -32002;

    public static function isSupported( string $version ): bool
    {
        return in_array( $version, self::VERSIONS, true );
    }

    /** The version the server answers an initialize with: the one asked for when tron speaks it, else the newest. */
    public static function negotiate( $requested ): string
    {
        return is_string( $requested ) && self::isSupported( $requested ) ? $requested : self::LATEST;
    }

    /** JSON-RPC batches exist in 2025-03-26 and before; 2025-06-18 removed them. */
    public static function allowsBatch( ?string $version ): bool
    {
        return $version === null || strcmp( $version, '2025-06-18' ) < 0;
    }

    /** structuredContent in a tools/call result exists from 2025-06-18 on. */
    public static function hasStructuredContent( ?string $version ): bool
    {
        return $version !== null && strcmp( $version, '2025-06-18' ) >= 0;
    }
}
