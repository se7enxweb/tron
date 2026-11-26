<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * One outbound HTTP request under the SSRF guard, for every program that makes one.
 *
 * Every hop is checked by tronWebGuard from the start (scheme, host, every resolved address) and the connection is
 * pinned to the checked address; after the transfer the address curl really connected to must be that address (a
 * DNS rebinding that slipped past the pin would show here). Redirects are followed by this class, never by curl:
 * at most max_redirects hops, each one checked again. A body larger than max_bytes is cut (truncated: true), the
 * whole request has one deadline. Nothing is sent that the caller did not give: no cookies, no referrer, no proxy
 * from the environment.
 *
 * The transport is a callable( array $request ): array, so tests run without a network (environment service
 * "web.transport"); the default is tronWebCurl::send().
 *   request:  method, url, headers (list of "Name: value"), body, timeout, max_bytes, pin (CURLOPT_RESOLVE), address
 *   response: status, headers (lower-case name => value), body, truncated, primary_ip, http_version, timings, tls
 */
final class tronWebClient
{
    /** @var tronWebGuard */ private $guard;
    /** @var callable */ private $transport;
    /** @var array */ private $defaults;

    public function __construct( tronWebGuard $guard, ?callable $transport = null, array $defaults = array() )
    {
        $this->guard = $guard;
        $this->transport = $transport ?: array( 'tronWebCurl', 'send' );
        $this->defaults = $defaults + array( 'timeout' => 15, 'max_bytes' => 2097152, 'max_redirects' => 5 );
    }

    public static function fromEnv( tronEnvironment $env ): self
    {
        $transport = $env->has( 'web.transport' ) ? $env->get( 'web.transport' ) : null;
        if ( !$transport && !tronWebGuard::networkOn( $env ) )
            $transport = function ( array $req ) {
                throw new tronToolError( 'Network access is switched off here ([WebSettings] Network)', array( 'rule' => 'network.off' ) );
            };
        return new self( tronWebGuard::fromEnv( $env ), $transport, array(
            'timeout' => max( 1, min( 120, (int)$env->setting( 'WebSettings', 'Timeout', 15 ) ) ),
            'max_bytes' => max( 1024, min( 67108864, (int)$env->setting( 'WebSettings', 'MaxBytes', 2097152 ) ) ),
            'max_redirects' => max( 0, min( 10, (int)$env->setting( 'WebSettings', 'MaxRedirects', 5 ) ) ),
        ) );
    }

    public function guard(): tronWebGuard
    {
        return $this->guard;
    }

    /** The User-Agent tron sends (its robots.txt product token is "tron"). */
    public static function userAgent(): string
    {
        return 'tron/' . tronVersion::VERSION . ' (Exponential; read-only agent tools)';
    }

    /**
     * @param array $o headers (list), body, timeout, max_bytes, max_redirects, follow (default true),
     *                 hosts (allowlist of hosts and *.domain patterns every hop must match; null: any public host),
     *                 before (callable( array $parts ) run before each hop, e.g. robots.txt; throws to refuse)
     * @return array url, final_url, status, headers, body, truncated, redirects[], primary_ip, http_version, timings, tls, ms
     */
    public function request( string $method, string $url, array $o = array() ): array
    {
        $o += $this->defaults + array( 'headers' => array(), 'body' => '', 'follow' => true, 'hosts' => null, 'before' => null );
        $method = strtoupper( $method );
        if ( !preg_match( '/^(GET|HEAD|POST|PUT|PATCH|DELETE|OPTIONS)$/', $method ) )
            throw new tronToolError( "The method $method is not supported" );
        $deadline = microtime( true ) + (float)$o['timeout'];
        $t0 = microtime( true );
        $redirects = array();
        $current = $url;
        for ( $hop = 0; ; $hop++ )
        {
            $v = $this->guard->check( $current );
            $parts = $v['parts'];
            if ( is_array( $o['hosts'] ) && !tronWebGuard::hostMatches( $parts['host'], $o['hosts'] ) )
                throw new tronToolError( "The host {$parts['host']} is not on the allowlist of this tool", array( 'rule' => 'allowlist.host' ) );
            if ( $o['before'] )
                call_user_func( $o['before'], $parts );
            $left = $deadline - microtime( true );
            if ( $left <= 0 )
                throw new tronToolError( "The request to {$parts['host']} did not finish in {$o['timeout']} s", array( 'rule' => 'http.timeout' ) );
            $headers = array_merge( array( 'User-Agent: ' . self::userAgent() ), array_values( (array)$o['headers'] ) );
            $r = call_user_func( $this->transport, array(
                'method' => $method, 'url' => $parts['url'], 'headers' => $headers,
                'body' => $hop === 0 || in_array( $method, array( 'GET', 'HEAD' ), true ) ? (string)$o['body'] : '',
                'timeout' => max( 1, (int)ceil( $left ) ), 'max_bytes' => (int)$o['max_bytes'],
                'pin' => $v['pin'], 'address' => $v['address'], 'inspect' => !empty( $o['inspect'] ),
            ) ) + array( 'status' => 0, 'headers' => array(), 'body' => '', 'truncated' => false, 'primary_ip' => '',
                         'http_version' => '', 'timings' => array(), 'tls' => null );
            // the rebinding check: the connection must have gone to the address the guard checked
            if ( $r['primary_ip'] !== '' && $v['address'] !== null && @inet_pton( $r['primary_ip'] ) !== @inet_pton( $v['address'] ) )
                throw new tronToolError( "The connection to {$parts['host']} went to {$r['primary_ip']}, not to the checked address {$v['address']}", array( 'rule' => 'ssrf.rebinding' ) );
            $status = (int)$r['status'];
            $location = (string)( $r['headers']['location'] ?? '' );
            if ( $o['follow'] && $status >= 300 && $status < 400 && $location !== '' )
            {
                if ( $hop >= (int)$o['max_redirects'] )
                    throw new tronToolError( "Too many redirects (more than {$o['max_redirects']})", array( 'rule' => 'http.redirects' ) );
                $next = self::resolveUrl( $parts['url'], $location );
                if ( $next === null )
                    throw new tronToolError( 'A redirect points to an address that is not http or https', array( 'rule' => 'ssrf.scheme' ) );
                $redirects[] = array( 'status' => $status, 'from' => $parts['url'], 'to' => $next );
                $current = $next;
                if ( $status === 303 || ( $status === 301 || $status === 302 ) && $method === 'POST' )
                {
                    $method = 'GET';
                    $o['body'] = '';
                }
                continue;
            }
            return array(
                'url' => $url, 'final_url' => $parts['url'], 'status' => $status, 'headers' => (array)$r['headers'],
                'body' => (string)$r['body'], 'truncated' => (bool)$r['truncated'], 'redirects' => $redirects,
                'primary_ip' => (string)$r['primary_ip'], 'http_version' => (string)$r['http_version'],
                'timings' => (array)$r['timings'], 'tls' => $r['tls'], 'host' => $parts['host'],
                'ms' => (int)round( ( microtime( true ) - $t0 ) * 1000 ),
            );
        }
    }

    /** An absolute http(s) URL for a link relative to $base; null for other schemes. */
    public static function resolveUrl( string $base, string $rel ): ?string
    {
        $rel = trim( html_entity_decode( $rel, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( $rel === '' )
            return null;
        if ( $rel[0] === '#' )
            return preg_replace( '/#.*$/', '', $base ) . $rel;
        if ( preg_match( '~^([a-z][a-z0-9+.-]*):~i', $rel, $m ) )
            return in_array( strtolower( $m[1] ), array( 'http', 'https' ), true ) ? $rel : null;
        if ( !preg_match( '~^(https?:)//([^/?#]*)([^?#]*)~i', $base, $b ) )
            return null;
        if ( strpos( $rel, '//' ) === 0 )
            return $b[1] . $rel;
        $origin = $b[1] . '//' . $b[2];
        if ( $rel[0] === '/' )
            return $origin . self::dots( $rel );
        if ( $rel[0] === '?' )
            return $origin . ( $b[3] === '' ? '/' : $b[3] ) . $rel;
        $dir = $b[3] === '' ? '/' : substr( $b[3], 0, strrpos( $b[3], '/' ) + 1 );
        return $origin . self::dots( $dir . $rel );
    }

    private static function dots( string $path ): string
    {
        $q = '';
        $cut = strcspn( $path, '?#' );
        if ( $cut < strlen( $path ) )
        {
            $q = substr( $path, $cut );
            $path = substr( $path, 0, $cut );
        }
        $out = array();
        foreach ( explode( '/', $path ) as $seg )
        {
            if ( $seg === '.' )
                continue;
            if ( $seg === '..' )
            {
                if ( count( $out ) > 1 )
                    array_pop( $out );
                continue;
            }
            $out[] = $seg;
        }
        $r = implode( '/', $out );
        if ( preg_match( '~/\.\.?$~', $path ) )
            $r .= '/';
        return ( $r === '' || $r[0] !== '/' ? '/' . ltrim( $r, '/' ) : $r ) . $q;
    }
}
