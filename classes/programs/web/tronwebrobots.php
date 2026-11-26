<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * robots.txt as RFC 9309 reads it: the group of the most specific matching user agent (else "*"), the longest
 * matching rule wins, Allow wins a tie, "*" and "$" in paths. A 4xx answer (no file) allows everything; a 5xx or no
 * answer at all disallows everything (the site may be down, so it is not read). Crawl-delay is reported, not enforced.
 */
final class tronWebRobots
{
    /** @var int */ public $status;
    /** @var array agent => array( 'rules' => array( array( allow, path ) ), 'delay' => ?float ) */ private $groups = array();

    public function __construct( int $status, string $text )
    {
        $this->status = $status;
        if ( $status >= 200 && $status < 300 )
            $this->parse( $text );
    }

    private function parse( string $text ): void
    {
        $agents = array();
        $inRules = false;
        foreach ( preg_split( '/\r\n|\r|\n/', substr( $text, 0, 512000 ) ) as $line )
        {
            $line = trim( preg_replace( '/#.*$/', '', $line ) );
            if ( $line === '' || strpos( $line, ':' ) === false )
                continue;
            list( $k, $v ) = array_map( 'trim', explode( ':', $line, 2 ) );
            $k = strtolower( $k );
            if ( $k === 'user-agent' )
            {
                if ( $inRules )
                {
                    $agents = array();
                    $inRules = false;
                }
                $a = strtolower( $v );
                $agents[] = $a;
                $this->groups += array( $a => array( 'rules' => array(), 'delay' => null ) );
            }
            else if ( $k === 'allow' || $k === 'disallow' )
            {
                $inRules = true;
                if ( $v === '' && $k === 'disallow' )
                    continue;
                foreach ( $agents as $a )
                    $this->groups[$a]['rules'][] = array( $k === 'allow', $v );
            }
            else if ( $k === 'crawl-delay' )
            {
                $inRules = true;
                foreach ( $agents as $a )
                    $this->groups[$a]['delay'] = is_numeric( $v ) ? (float)$v : null;
            }
        }
    }

    /** @return array allowed, rule (the matching line or a reason), crawl_delay */
    public function check( string $agent, string $target ): array
    {
        if ( $this->status === 0 || $this->status >= 500 )
            return array( 'allowed' => false, 'rule' => 'robots.txt could not be read (status ' . $this->status . '): treated as disallow all', 'crawl_delay' => null );
        if ( $this->status >= 400 )
            return array( 'allowed' => true, 'rule' => 'no robots.txt (status ' . $this->status . ')', 'crawl_delay' => null );
        $agent = strtolower( $agent );
        $group = null;
        $best = -1;
        foreach ( $this->groups as $name => $g )
            if ( $name !== '*' && strpos( $agent, $name ) !== false && strlen( $name ) > $best )
            {
                $group = $g;
                $best = strlen( $name );
            }
        if ( $group === null )
            $group = $this->groups['*'] ?? array( 'rules' => array(), 'delay' => null );
        $path = rawurldecode( $target === '' ? '/' : $target );
        $win = null;
        foreach ( $group['rules'] as $r )
        {
            if ( !self::matches( rawurldecode( $r[1] ), $path ) )
                continue;
            $len = strlen( $r[1] );
            if ( $win === null || $len > strlen( $win[1] ) || $len === strlen( $win[1] ) && $r[0] )
                $win = $r;
        }
        if ( $win === null )
            return array( 'allowed' => true, 'rule' => 'no rule matches', 'crawl_delay' => $group['delay'] );
        return array( 'allowed' => $win[0], 'rule' => ( $win[0] ? 'Allow: ' : 'Disallow: ' ) . $win[1], 'crawl_delay' => $group['delay'] );
    }

    private static function matches( string $pattern, string $path ): bool
    {
        $anchored = substr( $pattern, -1 ) === '$';
        if ( $anchored )
            $pattern = substr( $pattern, 0, -1 );
        $re = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . ( $anchored ? '$' : '' ) . '#';
        return (bool)preg_match( $re, $path );
    }
}
