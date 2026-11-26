<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/** Setup > tron (dashboard): version, programs, tools, tokens, the last day's calls, the ways in. */
final class tronAdminDashboardData
{
    /** @var tronAdminContext */ private $c;

    public function __construct( tronAdminContext $c )
    {
        $this->c = $c;
    }

    public function data(): array
    {
        $db = $this->c->db();
        $missing = $this->c->missingTables();
        $settings = $this->c->settings();
        $server = $this->c->server();
        $programs = $server->option( 'programs' ) ?: array();
        $tokens = array( 'active' => 0, 'expired' => 0, 'revoked' => 0 );
        $outcomes = array_fill_keys( tronAdminAuditData::OUTCOMES, 0 );
        $calls = 0;
        $admin = 0;
        $last = null;
        if ( !in_array( 'tron_token', $missing, true ) )
        {
            foreach ( $this->c->tokens()->listTokens() as $t )
                $tokens[$t['state']]++;
        }
        if ( !in_array( 'tron_audit', $missing, true ) )
        {
            $since = $this->c->now() - 86400;
            foreach ( $db->select( 'SELECT transport, outcome, count(*) AS n FROM tron_audit WHERE created >= :s GROUP BY transport, outcome', array( 's' => $since ) ) as $r )
            {
                if ( $r['transport'] === 'admin' )
                {
                    $admin += (int)$r['n'];
                    continue;
                }
                $outcomes[$r['outcome']] = ( $outcomes[$r['outcome']] ?? 0 ) + (int)$r['n'];
                $calls += (int)$r['n'];
            }
            $l = $db->select( "SELECT created, login, target, outcome FROM tron_audit WHERE transport <> 'admin' ORDER BY id DESC", array(), 1 );
            $last = $l ? $l[0] : null;
        }
        $h = (array)( $settings['HTTPSettings'] ?? array() );
        return array(
            'version' => tronVersion::VERSION,
            'protocols' => tronProtocol::VERSIONS,
            'missing' => $missing,
            'programs' => $programs,
            'programs_loaded' => count( array_filter( $programs, function ( $p ) { return $p['state'] === 'loaded'; } ) ),
            'programs_failed' => count( array_filter( $programs, function ( $p ) { return in_array( $p['state'], array( 'failed', 'conflict', 'incompatible' ), true ); } ) ),
            'tools' => count( $server->registry()->tools() ),
            'not_registered' => count( $server->registry()->unavailableTools() ),
            'tokens' => $tokens,
            'outcomes' => $outcomes,
            'calls' => $calls,
            'admin_actions' => $admin,
            'last' => $last,
            'http' => array( 'enabled' => ( $h['Enabled'] ?? 'false' ) === 'true',
                             'siteaccesses' => array_values( array_filter( (array)( $h['SiteAccesses'] ?? array() ) ) ),
                             'hosts' => array_values( array_filter( (array)( $h['AllowedHosts'] ?? array() ) ) ) ),
            'sinks' => array_values( array_filter( (array)( $settings['AuditSettings']['Sinks'] ?? array() ) ) ),
        );
    }
}
