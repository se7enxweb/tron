<?php
/**
 * The MCP endpoint over streamable HTTP: /tron/mcp (behind the load balancer: /mcp, see doc/hosting.md).
 *
 * Answers 404 unless tron.ini [HTTPSettings] Enabled=true and the siteaccess is listed in SiteAccesses[].
 * Everything else (token, policies, audit) is tronHttpTransport's.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

$current = eZSiteAccess::current();
$siteAccess = is_array( $current ) && isset( $current['name'] ) ? (string)$current['name'] : '';
$transport = tronKernel::httpTransport( $siteAccess );
if ( $transport === null )
{
    $response = tronHttpResponse::json( 404, tronServer::errorResponse( null, tronProtocol::INVALID_REQUEST, 'Not found' ) );
}
else
{
    $max = (int)eZINI::instance( 'tron.ini' )->variable( 'HTTPSettings', 'MaxBodyBytes' );
    $response = $transport->handle( tronHttpRequest::fromGlobals( $max > 0 ? $max : 1048576 ) );
}

// Echo and end like the kernel's other raw views. Output buffers are left alone: under Velocity the outermost one is
// the server's capture of the response, and closing it left the client waiting (found on alpha 2026-11-24).
$response->emit();
eZExecution::cleanExit();
