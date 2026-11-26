<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The network transport of tronWebClient: one curl transfer, http and https only, no redirects (the client follows
 * them hop by hop), the guard's address pinned with CURLOPT_RESOLVE, no proxy from the environment, the body cut at
 * max_bytes, TLS verified. With inspect it also reads the certificate and the TLS version and cipher curl negotiated
 * (from curl's own connection log, of which only the "SSL connection using" line is kept).
 */
final class tronWebCurl
{
    public static function send( array $req ): array
    {
        if ( !function_exists( 'curl_init' ) )
            throw new tronToolError( 'The PHP curl extension is not installed on this server' );
        $ch = curl_init();
        $body = '';
        $truncated = false;
        $max = (int)$req['max_bytes'];
        $headers = array();
        $log = null;
        $opts = array(
            CURLOPT_URL => $req['url'],
            CURLOPT_CUSTOMREQUEST => $req['method'],
            CURLOPT_NOBODY => $req['method'] === 'HEAD',
            CURLOPT_HTTPHEADER => $req['headers'],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => (int)$req['timeout'],
            CURLOPT_CONNECTTIMEOUT => min( 10, (int)$req['timeout'] ),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_RESOLVE => (array)$req['pin'],
            CURLOPT_CERTINFO => !empty( $req['inspect'] ),
            CURLOPT_HEADERFUNCTION => function ( $ch, $line ) use ( &$headers ) {
                $len = strlen( $line );
                if ( preg_match( '/^HTTP\//', $line ) )
                    $headers = array();
                else if ( strpos( $line, ':' ) !== false && count( $headers ) < 200 )
                {
                    list( $n, $v ) = explode( ':', $line, 2 );
                    $n = strtolower( trim( $n ) );
                    $v = substr( trim( $v ), 0, 4096 );
                    $headers[$n] = isset( $headers[$n] ) && $n !== 'location' ? $headers[$n] . ', ' . $v : $v;
                }
                return $len;
            },
            CURLOPT_WRITEFUNCTION => function ( $ch, $chunk ) use ( &$body, &$truncated, $max ) {
                $room = $max - strlen( $body );
                if ( $room <= 0 )
                {
                    $truncated = true;
                    return 0;           // stops the transfer: the rest is not read
                }
                $body .= strlen( $chunk ) > $room ? substr( $chunk, 0, $room ) : $chunk;
                if ( strlen( $chunk ) > $room )
                {
                    $truncated = true;
                    return 0;
                }
                return strlen( $chunk );
            },
        );
        if ( defined( 'CURLOPT_PROTOCOLS' ) )
            $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        if ( !in_array( $req['method'], array( 'GET', 'HEAD' ), true ) || (string)$req['body'] !== '' )
            $opts[CURLOPT_POSTFIELDS] = (string)$req['body'];
        if ( !empty( $req['inspect'] ) )
        {
            $log = fopen( 'php://temp', 'w+' );
            $opts[CURLOPT_VERBOSE] = true;
            $opts[CURLOPT_STDERR] = $log;
        }
        curl_setopt_array( $ch, $opts );
        $ok = curl_exec( $ch );
        $errno = curl_errno( $ch );
        $info = curl_getinfo( $ch );
        $tls = null;
        if ( !empty( $req['inspect'] ) && strpos( (string)$req['url'], 'https:' ) === 0 )
        {
            $tls = array( 'version' => null, 'cipher' => null, 'subject' => null, 'issuer' => null, 'expires' => null, 'verified' => ( $info['ssl_verify_result'] ?? 1 ) === 0 );
            rewind( $log );
            while ( ( $line = fgets( $log ) ) !== false )
                if ( preg_match( '/SSL connection using (\S+) \/ (\S+)/', $line, $m ) )
                {
                    $tls['version'] = $m[1];
                    $tls['cipher'] = $m[2];
                }
            $cert = $info['certinfo'][0] ?? array();
            $tls['subject'] = isset( $cert['Subject'] ) ? substr( (string)$cert['Subject'], 0, 300 ) : null;
            $tls['issuer'] = isset( $cert['Issuer'] ) ? substr( (string)$cert['Issuer'], 0, 300 ) : null;
            $tls['expires'] = isset( $cert['Expire date'] ) ? (string)$cert['Expire date'] : null;
        }
        if ( $log )
            fclose( $log );
        curl_close( $ch );
        if ( $ok === false && !( $truncated && $errno === 23 ) )
        {
            $host = (string)parse_url( (string)$req['url'], PHP_URL_HOST );
            if ( $errno === 28 )
                throw new tronToolError( "The request to $host did not finish in {$req['timeout']} s", array( 'rule' => 'http.timeout' ) );
            if ( in_array( $errno, array( 35, 51, 58, 60 ), true ) )
                throw new tronToolError( "The TLS connection to $host failed (curl error $errno)", array( 'rule' => 'http.tls' ) );
            throw new tronToolError( "The request to $host failed (curl error $errno)", array( 'rule' => 'http.transport' ) );
        }
        $ms = function ( $k ) use ( $info ) {
            return isset( $info[$k] ) ? round( (float)$info[$k] * 1000, 1 ) : null;
        };
        $versions = array( 1 => '1.0', 2 => '1.1', 3 => '2', 4 => '2', 5 => '2', 30 => '3' );
        return array(
            'status' => (int)( $info['http_code'] ?? 0 ), 'headers' => $headers, 'body' => $body, 'truncated' => $truncated,
            'primary_ip' => (string)( $info['primary_ip'] ?? '' ),
            'http_version' => $versions[(int)( $info['http_version'] ?? 0 )] ?? '',
            'timings' => array( 'dns' => $ms( 'namelookup_time' ), 'connect' => $ms( 'connect_time' ), 'tls' => $ms( 'appconnect_time' ),
                                'ttfb' => $ms( 'starttransfer_time' ), 'total' => $ms( 'total_time' ) ),
            'tls' => $tls,
        );
    }
}
