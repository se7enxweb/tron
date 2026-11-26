<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The SSRF guard of every outbound request tron makes (web, browser, validate_links, api). See doc/root-programs.md.
 *
 * - parse(): http and https only, no user name or password, ports 80 and 443 plus the allowed extra ports, a host
 *   that is a canonical IP literal or a valid DNS name (punycode for international names). Numeric forms an IPv4
 *   parser would accept but that are not canonical (0x7f.1, 2130706433, 127.1) are refused.
 * - classify(): what an address is. Only "public" passes. IPv4 carried inside IPv6 (mapped, NAT64, 6to4, compatible)
 *   is judged by the IPv4 address it carries.
 * - check(): refuses names that are never public (localhost, .local, .internal ...), resolves the host once, requires
 *   every address to be public (unless the host is named in [WebSettings] PrivateHosts[]) and returns the address to
 *   pin the connection to (curl CURLOPT_RESOLVE), so a second DNS answer cannot redirect the request (DNS rebinding).
 *
 * Resolution goes through the resolver given to the constructor (tests), else dns_get_record / gethostbynamel.
 */
final class tronWebGuard
{
    const MAX_URL = 2048;

    /** IPv4 ranges that are not public: CIDR => kind. Checked in this order (the first match names the kind). */
    const V4 = array(
        '169.254.169.254/32' => 'metadata', '169.254.170.2/32' => 'metadata', '100.100.100.200/32' => 'metadata',
        '192.0.0.192/32' => 'metadata',
        '0.0.0.0/8' => 'unspecified', '10.0.0.0/8' => 'private', '100.64.0.0/10' => 'cgnat', '127.0.0.0/8' => 'loopback',
        '169.254.0.0/16' => 'link-local', '172.16.0.0/12' => 'private', '192.0.0.0/24' => 'reserved',
        '192.0.2.0/24' => 'documentation', '192.88.99.0/24' => 'reserved', '192.168.0.0/16' => 'private',
        '198.18.0.0/15' => 'benchmark', '198.51.100.0/24' => 'documentation', '203.0.113.0/24' => 'documentation',
        '224.0.0.0/4' => 'multicast', '240.0.0.0/4' => 'reserved', '255.255.255.255/32' => 'broadcast',
    );

    /** IPv6 ranges that are not public (those that carry an IPv4 address are handled in classify()). */
    const V6 = array(
        'fd00:ec2::254/128' => 'metadata',
        '::/128' => 'unspecified', '::1/128' => 'loopback', '100::/64' => 'reserved', '2001::/32' => 'reserved',
        '2001:2::/48' => 'benchmark', '2001:10::/28' => 'reserved', '2001:20::/28' => 'reserved', '2001:db8::/32' => 'documentation',
        '3fff::/20' => 'documentation', '5f00::/16' => 'reserved', 'fc00::/7' => 'private', 'fe80::/10' => 'link-local',
        'fec0::/10' => 'reserved', 'ff00::/8' => 'multicast',
    );

    /** Names that are never public, matched on the whole name or as a suffix. */
    const NAMES = array( 'localhost', 'localhost.localdomain', 'ip6-localhost', 'ip6-loopback', 'metadata', 'metadata.google.internal', 'instance-data' );
    const SUFFIXES = array( '.localhost', '.local', '.internal', '.lan', '.home.arpa', '.intranet', '.corp', '.localdomain' );

    /**
     * The installation's private mesh, IPv4 CIDRs named "mesh" (after metadata, before the general ranges):
     * [WebSettings] MeshNetworks[], set in settings/override. tron ships none; without it they are "private".
     * @var string[]
     */
    private static $mesh = array();

    /** @var string[] */ private $privateHosts;
    /** @var string[] */ private $ports;
    /** @var callable|null */ private $resolver;

    public function __construct( array $privateHosts = array(), array $ports = array(), ?callable $resolver = null )
    {
        $this->privateHosts = array_values( array_filter( array_map( 'strval', $privateHosts ) ) );
        $this->ports = array_map( 'intval', array_filter( $ports ) );
        $this->resolver = $resolver;
    }

    /** The guard with [WebSettings] PrivateHosts[] and AllowedPorts[] and the environment's resolver ("web.resolver"). */
    public static function fromEnv( tronEnvironment $env ): self
    {
        $resolver = $env->has( 'web.resolver' ) ? $env->get( 'web.resolver' ) : null;
        if ( !$resolver && !self::networkOn( $env ) )
            $resolver = function ( string $host ) { return array(); };     // no DNS either while the network is off
        self::setMeshNetworks( (array)$env->setting( 'WebSettings', 'MeshNetworks', array() ) );
        return new self( (array)$env->setting( 'WebSettings', 'PrivateHosts', array() ), (array)$env->setting( 'WebSettings', 'AllowedPorts', array() ), $resolver );
    }

    /** Sets the mesh networks (IPv4 CIDRs; anything else is ignored). */
    public static function setMeshNetworks( array $cidrs ): void
    {
        self::$mesh = array_values( array_filter( array_map( function ( $c ) { return trim( (string)$c ); }, $cidrs ), function ( $c ) {
            return (bool)preg_match( '~^\d{1,3}(?:\.\d{1,3}){3}/(?:[0-9]|[12][0-9]|3[0-2])$~', $c ) && ip2long( explode( '/', $c )[0] ) !== false;
        } ) );
    }

    /** The mesh networks in force. @return string[] */
    public static function meshNetworks(): array
    {
        return self::$mesh;
    }

    /**
     * Whether tron may reach the network at all: [WebSettings] Network=enabled (tron.ini ships it on). Without the
     * setting (a test world, a broken settings file) it is off: fail closed.
     */
    public static function networkOn( tronEnvironment $env ): bool
    {
        return strtolower( (string)$env->setting( 'WebSettings', 'Network', 'disabled' ) ) === 'enabled';
    }

    /**
     * @return array|null scheme, host, port, target (path and query), url (normalised, no fragment); null when the URL
     *                    is not a web address that may be requested
     */
    public function parse( string $url ): ?array
    {
        $url = trim( $url );
        if ( $url === '' || strlen( $url ) > self::MAX_URL || preg_match( '/[\x00-\x20\x7f]/', $url ) )
            return null;
        if ( !preg_match( '~^(https?)://([^/?#]*)([^#]*)~i', $url, $m ) )
            return null;
        $scheme = strtolower( $m[1] );
        $authority = $m[2];
        $target = $m[3] === '' ? '/' : $m[3];
        if ( $target[0] !== '/' )
            $target = '/' . $target;
        if ( $authority === '' || strpos( $authority, '@' ) !== false )
            return null;
        if ( $authority[0] === '[' )
        {
            if ( !preg_match( '/^\[([0-9a-fA-F:.]+)\](?::(\d{1,5}))?$/', $authority, $a ) )
                return null;
        }
        else if ( !preg_match( '/^([^:]+)(?::(\d{1,5}))?$/', $authority, $a ) )
            return null;
        $host = self::normalizeHost( $a[1] );
        if ( $host === null )
            return null;
        $default = $scheme === 'https' ? 443 : 80;
        $port = isset( $a[2] ) && $a[2] !== '' ? (int)$a[2] : $default;
        if ( $port !== 80 && $port !== 443 && !in_array( $port, $this->ports, true ) )
            return null;
        $hostPart = strpos( $host, ':' ) !== false ? '[' . $host . ']' : $host;
        return array( 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'target' => $target,
                      'url' => $scheme . '://' . $hostPart . ( $port !== $default ? ':' . $port : '' ) . $target );
    }

    /** Lower-case ASCII host (punycode) or a canonical IP address; null when it is not a valid host. */
    public static function normalizeHost( string $host ): ?string
    {
        $host = rtrim( trim( $host ), '.' );
        if ( $host === '' )
            return null;
        if ( $host[0] === '[' && substr( $host, -1 ) === ']' )
            $host = substr( $host, 1, -1 );
        if ( filter_var( $host, FILTER_VALIDATE_IP ) )
        {
            $packed = @inet_pton( $host );
            return $packed === false ? null : strtolower( (string)inet_ntop( $packed ) );
        }
        // forms an IPv4 parser would accept but that are not a canonical address (0x7f.1, 2130706433, 127.1, 017.0.0.1)
        if ( preg_match( '/^[0-9.]+$/', $host ) || preg_match( '/^(0x[0-9a-f]*|\d+)(\.(0x[0-9a-f]*|\d+))*$/i', $host ) )
            return null;
        if ( preg_match( '/[^\x21-\x7e]/', $host ) )
        {
            if ( !function_exists( 'idn_to_ascii' ) )
                return null;
            $ascii = idn_to_ascii( $host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46 );
            if ( !is_string( $ascii ) || $ascii === '' )
                return null;
            $host = $ascii;
        }
        $host = strtolower( $host );
        if ( strlen( $host ) > 253 || !preg_match( '/^(?:[a-z0-9_](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host ) )
            return null;
        return $host;
    }

    /** Whether a normalised host matches a list of exact hosts and *.domain patterns (label boundaries). */
    public static function hostMatches( string $host, array $patterns ): bool
    {
        foreach ( $patterns as $p )
        {
            $p = strtolower( trim( (string)$p ) );
            if ( $p === '' )
                continue;
            if ( strpos( $p, '*.' ) === 0 )
            {
                $d = substr( $p, 1 );
                if ( strlen( $host ) > strlen( $d ) && substr( $host, -strlen( $d ) ) === $d )
                    return true;
            }
            else if ( self::normalizeHost( $p ) === $host )
                return true;
        }
        return false;
    }

    /** "public", or the kind of a non-public address (loopback, private, mesh, metadata, ...); "invalid" for no address. */
    public static function classify( string $ip ): string
    {
        $packed = @inet_pton( $ip );
        if ( $packed === false )
            return 'invalid';
        if ( strlen( $packed ) === 4 )
            return self::classifyV4( $packed );
        // IPv4 carried inside IPv6: judged by the IPv4 address
        $prefix12 = substr( $packed, 0, 12 );
        if ( $prefix12 === str_repeat( "\0", 10 ) . "\xff\xff" )                       // ::ffff:a.b.c.d (mapped)
            return self::carried( substr( $packed, 12 ) );
        if ( $prefix12 === str_repeat( "\0", 12 ) && substr( $packed, 12 ) !== "\0\0\0\0" && substr( $packed, 12 ) !== "\0\0\0\1" )
            return self::carried( substr( $packed, 12 ) );                               // ::a.b.c.d (compatible, deprecated)
        if ( substr( $packed, 0, 12 ) === "\x00\x64\xff\x9b" . str_repeat( "\0", 8 ) )   // 64:ff9b::/96 (NAT64)
            return self::carried( substr( $packed, 12 ) );
        if ( substr( $packed, 0, 6 ) === "\x00\x64\xff\x9b\x00\x01" )                    // 64:ff9b:1::/48 (local NAT64)
            return 'private';
        if ( substr( $packed, 0, 2 ) === "\x20\x02" )                                    // 2002::/16 (6to4)
            return self::carried( substr( $packed, 2, 4 ) );
        foreach ( self::V6 as $cidr => $kind )
            if ( self::inRange( $packed, $cidr ) )
                return $kind;
        return 'public';
    }

    private static function carried( string $v4 ): string
    {
        $k = self::classifyV4( $v4 );
        return $k === 'public' ? 'public' : $k . ' (IPv4 in IPv6)';
    }

    private static function classifyV4( string $packed ): string
    {
        foreach ( self::V4 as $cidr => $kind )
            if ( $kind === 'metadata' && self::inRange( $packed, $cidr ) )
                return $kind;
        foreach ( self::$mesh as $cidr )
            if ( self::inRange( $packed, $cidr ) )
                return 'mesh';
        foreach ( self::V4 as $cidr => $kind )
            if ( self::inRange( $packed, $cidr ) )
                return $kind;
        return 'public';
    }

    private static function inRange( string $packed, string $cidr ): bool
    {
        list( $net, $bits ) = explode( '/', $cidr );
        $n = @inet_pton( $net );
        if ( $n === false || strlen( $n ) !== strlen( $packed ) )
            return false;
        $bits = (int)$bits;
        $bytes = intdiv( $bits, 8 );
        if ( $bytes && substr( $packed, 0, $bytes ) !== substr( $n, 0, $bytes ) )
            return false;
        $rest = $bits % 8;
        if ( $rest === 0 )
            return true;
        $mask = ( 0xff << ( 8 - $rest ) ) & 0xff;
        return ( ord( $packed[$bytes] ) & $mask ) === ( ord( $n[$bytes] ) & $mask );
    }

    /** Whether a name is one that is never public (checked before any DNS lookup). */
    public static function internalName( string $host ): bool
    {
        if ( in_array( $host, self::NAMES, true ) || strpos( $host, '.' ) === false && !filter_var( $host, FILTER_VALIDATE_IP ) )
            return true;
        foreach ( self::SUFFIXES as $s )
            if ( substr( $host, -strlen( $s ) ) === $s )
                return true;
        return false;
    }

    /** @return string[] the IPv4 and IPv6 addresses of a host */
    public function resolve( string $host ): array
    {
        if ( $this->resolver )
            return array_values( array_map( 'strval', (array)call_user_func( $this->resolver, $host ) ) );
        $out = array();
        $records = @dns_get_record( $host, DNS_A | DNS_AAAA );
        foreach ( is_array( $records ) ? $records : array() as $r )
        {
            if ( isset( $r['ip'] ) )
                $out[] = $r['ip'];
            else if ( isset( $r['ipv6'] ) )
                $out[] = $r['ipv6'];
        }
        if ( !$out )
        {
            $v4 = @gethostbynamel( $host );
            if ( is_array( $v4 ) )
                $out = $v4;
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * The verdict for a URL without throwing: allowed, reason, rule, the parsed parts, every address with its kind,
     * and the address to pin. Nothing but DNS is asked.
     */
    public function verdict( string $url ): array
    {
        $parts = $this->parse( $url );
        $out = array( 'url' => $url, 'allowed' => false, 'reason' => '', 'rule' => '', 'host' => $parts['host'] ?? null,
                      'addresses' => array(), 'parts' => $parts, 'pin' => array(), 'address' => null );
        if ( !$parts )
        {
            $scheme = preg_match( '~^([a-z][a-z0-9+.-]*):~i', trim( $url ), $m ) ? strtolower( $m[1] ) : '';
            if ( $scheme !== '' && $scheme !== 'http' && $scheme !== 'https' )
                return array( 'reason' => "the scheme '$scheme' is not allowed: only http and https", 'rule' => 'ssrf.scheme' ) + $out;
            if ( preg_match( '~^https?://[^/?#]*@~i', trim( $url ) ) )
                return array( 'reason' => 'a user name or password in the address is not allowed', 'rule' => 'ssrf.credentials' ) + $out;
            if ( preg_match( '~^https?://[^/?#]*:(\d+)~i', trim( $url ), $m ) && !in_array( (int)$m[1], array( 80, 443 ), true ) )
                return array( 'reason' => "the port {$m[1]} is not allowed (80 and 443 only, plus [WebSettings] AllowedPorts[])", 'rule' => 'ssrf.port' ) + $out;
            return array( 'reason' => 'not a web address that may be requested (http or https with a valid host)', 'rule' => 'ssrf.url' ) + $out;
        }
        $host = $parts['host'];
        $exception = self::hostMatches( $host, $this->privateHosts );
        $literal = (bool)filter_var( $host, FILTER_VALIDATE_IP );
        if ( !$literal && !$exception && self::internalName( $host ) )
            return array( 'reason' => "the host $host is an internal name", 'rule' => 'ssrf.internal-name' ) + $out;
        $addresses = $literal ? array( $host ) : $this->resolve( $host );
        if ( !$addresses )
            return array( 'reason' => "the host $host does not resolve", 'rule' => 'ssrf.dns' ) + $out;
        $rows = array();
        $bad = null;
        foreach ( $addresses as $a )
        {
            $kind = self::classify( $a );
            $rows[] = array( 'address' => $a, 'kind' => $kind );
            if ( $kind !== 'public' && $bad === null )
                $bad = array( $a, $kind );
        }
        $out['addresses'] = $rows;
        if ( $bad !== null && !$exception )
            return array( 'reason' => "the host $host points to a non-public address ({$bad[1]})", 'rule' => 'ssrf.' . preg_replace( '/[^a-z0-9-]+/', '-', strtok( $bad[1], ' ' ) ) ) + $out;
        // pin IPv4 first: not every node has a working IPv6 route
        $pinned = $addresses[0];
        foreach ( $addresses as $a )
            if ( strpos( $a, ':' ) === false )
            {
                $pinned = $a;
                break;
            }
        $out['address'] = $pinned;
        $out['pin'] = $literal ? array() : array( $host . ':' . $parts['port'] . ':' . ( strpos( $pinned, ':' ) !== false ? '[' . $pinned . ']' : $pinned ) );
        return array( 'allowed' => true, 'reason' => $exception && $bad !== null ? 'allowed by [WebSettings] PrivateHosts[]' : 'public', 'rule' => 'ssrf.ok' ) + $out;
    }

    /** The verdict of an allowed URL; a tronToolError with the reason otherwise. */
    public function check( string $url ): array
    {
        $v = $this->verdict( $url );
        if ( !$v['allowed'] )
            throw new tronToolError( 'Refused by the SSRF guard: ' . $v['reason'], array( 'rule' => $v['rule'] ) );
        return $v;
    }
}
