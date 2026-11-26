<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The program "api" (doc/api.md): the installation's own APIs over HTTP, as a client would use them, and two
 * generic HTTP tools.
 *
 * - api_endpoints: what this installation offers (REST v1, REST v2, GraphQL), found from the classes and rest.ini.
 * - api_rest_v1, api_rest_v2 (reads), api_rest_v2_write (create, update, delete; a write tool with a dry run),
 *   api_graphql (queries only): requests to [ApiSettings] BaseUrl with a personal API key or token read from the
 *   secret store by name (key_secret, one of [ApiSettings] SecretNames[]). The REST layer then applies that key
 *   owner's policies on top of tron's own policy check of the caller.
 * - api_http_request: any method to a host on [ApiSettings] AllowedHosts[] only; credentials only by secret name
 *   (secrets[]), never inline; a write tool with a dry run.
 * - api_http_inspect: status, HTTP version, timings, headers, TLS and redirects of one public address.
 *
 * Every request passes the SSRF guard. A secret's value is read inside tron, sent, and replaced by
 * [redacted:secret] in every answer; the audit and the journal only ever see its name.
 */
final class tronApiProgram implements tronProgram
{
    const READ = array( 'tron', 'api_read' );
    const WRITE = array( 'tron', 'api_write' );
    const HTTP = array( 'tron', 'api_http' );
    const CREDENTIAL_HEADER = '/^(authorization|proxy-authorization|cookie|x-api-key|x-auth-token|.*token.*|.*secret.*|.*password.*|.*api[-_]?key.*)$/i';

    public function register( tronRegistry $registry, tronEnvironment $env ): void
    {
        if ( !tronRootPrograms::open( $registry, $env, array( 'api_endpoints', 'api_rest_v1', 'api_rest_v2', 'api_rest_v2_write', 'api_graphql', 'api_http_request', 'api_http_inspect' ) ) )
            return;
        $caps = self::capabilities( $env );
        $base = self::baseUrl( $env );
        $secretNames = self::secretNames( $env );
        $keySecret = array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_.:-]{1,128}$',
                            'description' => 'The name of a secret holding an API key or token (one of [ApiSettings] SecretNames[]: ' . ( $secretNames ? implode( ', ', $secretNames ) : 'none configured' ) . '); without it the request is anonymous' );
        $path = array( 'type' => 'string', 'pattern' => '^/[A-Za-z0-9_.,~/()-]*$', 'maxLength' => 500, 'description' => 'The route below the API prefix, e.g. /content/node/2' );
        $query = array( 'type' => 'object', 'description' => 'Query parameters, e.g. {"ResponseGroups": "Fields"}' );
        $resp = array( 'type' => 'object', 'required' => array( 'status', 'url', 'ms' ), 'properties' => array(
            'status' => array( 'type' => 'integer' ), 'url' => array( 'type' => 'string' ), 'ms' => array( 'type' => 'integer' ),
            'headers' => array( 'type' => 'object' ), 'json' => array( 'type' => array( 'object', 'array', 'string', 'number', 'boolean', 'null' ) ),
            'body' => array( 'type' => 'string' ), 'truncated' => array( 'type' => 'boolean' ), 'redirects' => array( 'type' => 'array' ) ) );

        $registry->tool( tronTool::define( array(
            'name' => 'api_endpoints', 'title' => 'The APIs of this installation',
            'description' => 'Which Exponential APIs this installation offers and where: REST v1 (/api/ezp/v1, reads), REST v2 (/api/ezp/v2, reads and writes), GraphQL (/api/graphql), with the authentication they take and the secret names tron may use for them. An API that is not there is reported as not available, with the reason.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array() ),
            'policy' => self::READ,
            'outputSchema' => array( 'type' => 'object', 'required' => array( 'base_url', 'rest_v1', 'rest_v2', 'graphql', 'secret_names' ), 'properties' => array(
                'base_url' => array( 'type' => array( 'string', 'null' ) ),
                'rest_v1' => array( 'type' => 'object', 'required' => array( 'available' ) ), 'rest_v2' => array( 'type' => 'object', 'required' => array( 'available' ) ),
                'graphql' => array( 'type' => 'object', 'required' => array( 'available' ) ), 'secret_names' => array( 'type' => 'array' ) ) ),
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $caps, $base, $secretNames ) {
                return array( 'base_url' => $base, 'rest_v1' => $caps['rest_v1'], 'rest_v2' => $caps['rest_v2'], 'graphql' => $caps['graphql'], 'secret_names' => $secretNames );
            },
        ) ) );

        foreach ( array( 'v1' => 'rest_v1', 'v2' => 'rest_v2' ) as $v => $cap )
        {
            $name = 'api_rest_' . $v;
            if ( $base === null || !$caps[$cap]['available'] )
            {
                $registry->unavailable( $name, $base === null ? 'no [ApiSettings] BaseUrl is set' : $caps[$cap]['reason'] );
                continue;
            }
            $registry->tool( tronTool::define( array(
                'name' => $name, 'title' => "REST $v read",
                'description' => "A GET request to the installation's REST API $v (/api/ezp/$v + path), e.g. /content/node/2, /content/node/2/list/offset/0/limit/20, /content/object/57/fields. The answer's JSON is returned decoded. The REST layer checks the key owner's content/read policies.",
                'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'path' ), 'properties' => array(
                    'path' => $path, 'query' => $query, 'key_secret' => $keySecret ) ),
                'policy' => self::READ, 'outputSchema' => $resp,
                'handler' => function ( array $a, tronCallContext $ctx ) use ( $env, $base, $v ) {
                    return self::call( $env, 'GET', $base . '/api/ezp/' . $v . self::path( $a['path'] ) . self::query( (array)( $a['query'] ?? array() ) ), array(), null, $a['key_secret'] ?? null );
                },
            ) ) );
        }

        if ( $base === null || !$caps['rest_v2']['available'] )
            $registry->unavailable( 'api_rest_v2_write', $base === null ? 'no [ApiSettings] BaseUrl is set' : $caps['rest_v2']['reason'] );
        else
            $registry->tool( tronTool::define( array(
                'name' => 'api_rest_v2_write', 'title' => 'REST v2 write',
                'description' => 'Creates, updates or removes content through REST v2: POST /content/node/create (body: parentNodeID, classIdentifier, fields), POST or PUT /content/node/<id> (a new published version with the values sent), DELETE /content/node/delete/<id>. Needs a key (key_secret) whose owner may do it. A write tool: tron_plan shows the request first.',
                'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'method', 'path', 'key_secret' ), 'properties' => array(
                    'method' => array( 'type' => 'string', 'enum' => array( 'POST', 'PUT', 'DELETE' ) ), 'path' => $path,
                    'body' => array( 'type' => 'object', 'description' => 'The JSON body' ), 'key_secret' => $keySecret ) ),
                'policy' => self::WRITE, 'write' => true, 'destructive' => true, 'outputSchema' => $resp,
                'plan' => function ( array $a, tronCallContext $ctx ) use ( $env, $base ) {
                    self::secretValue( $env, $a['key_secret'] );
                    $url = $base . '/api/ezp/v2' . self::path( $a['path'] );
                    return array( 'changes' => array( array( 'method' => $a['method'], 'url' => $url, 'body_fields' => array_keys( (array)( $a['body'] ?? array() ) ), 'key_secret' => $a['key_secret'] ) ),
                                  'state' => array( 'url' => $url ), 'effects' => array( "{$a['method']} $url as the owner of the key '{$a['key_secret']}'" ) );
                },
                'handler' => function ( array $a, tronCallContext $ctx ) use ( $env, $base ) {
                    $body = isset( $a['body'] ) ? (string)json_encode( $a['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '';
                    return self::call( $env, $a['method'], $base . '/api/ezp/v2' . self::path( $a['path'] ), $body !== '' ? array( 'Content-Type: application/json' ) : array(), $body, $a['key_secret'] );
                },
            ) ) );

        if ( $base === null || !$caps['graphql']['available'] )
            $registry->unavailable( 'api_graphql', $base === null ? 'no [ApiSettings] BaseUrl is set' : $caps['graphql']['reason'] );
        else
            $registry->tool( tronTool::define( array(
                'name' => 'api_graphql', 'title' => 'GraphQL query',
                'description' => 'A GraphQL query against the installation (/api/graphql). Queries only: a mutation or subscription is refused here. Without key_secret it runs as the anonymous user.',
                'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'query' ), 'properties' => array(
                    'query' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 20000 ), 'variables' => array( 'type' => 'object' ),
                    'operation_name' => array( 'type' => 'string', 'pattern' => '^[_A-Za-z][_0-9A-Za-z]{0,100}$' ), 'key_secret' => $keySecret ) ),
                'policy' => self::READ,
                'outputSchema' => array( 'type' => 'object', 'required' => array( 'status', 'data', 'errors' ), 'properties' => array(
                    'status' => array( 'type' => 'integer' ), 'data' => array( 'type' => array( 'object', 'array', 'null' ) ), 'errors' => array( 'type' => 'array' ), 'ms' => array( 'type' => 'integer' ) ) ),
                'handler' => function ( array $a, tronCallContext $ctx ) use ( $env, $base ) {
                    if ( self::isWriteOperation( $a['query'] ) )
                        throw new tronToolError( 'Only queries: a mutation or subscription is not run by api_graphql', array( 'rule' => 'graphql.mutation' ) );
                    $payload = array( 'query' => $a['query'] ) + ( isset( $a['variables'] ) ? array( 'variables' => (object)$a['variables'] ) : array() )
                             + ( isset( $a['operation_name'] ) ? array( 'operationName' => $a['operation_name'] ) : array() );
                    $r = self::call( $env, 'POST', $base . '/api/graphql', array( 'Content-Type: application/json' ), (string)json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), $a['key_secret'] ?? null );
                    $j = is_array( $r['json'] ?? null ) ? $r['json'] : array();
                    return array( 'status' => $r['status'], 'data' => $j['data'] ?? null, 'errors' => (array)( $j['errors'] ?? ( $r['status'] >= 400 ? array( array( 'message' => 'HTTP ' . $r['status'] ) ) : array() ) ), 'ms' => $r['ms'] );
                },
            ) ) );

        $registry->tool( tronTool::define( array(
            'name' => 'api_http_request', 'title' => 'HTTP request to an allowlisted host',
            'description' => 'Any HTTP request (GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS) to a host on [ApiSettings] AllowedHosts[] (now: ' . ( self::allowedHosts( $env ) ? implode( ', ', self::allowedHosts( $env ) ) : 'none' ) . '). Credentials only by secret name (secrets[]: name, header, format with {secret}), never inline: a credential-looking header with a value is refused. Secret values are masked in the answer. A write tool: tron_plan shows the request first.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'method', 'url' ), 'properties' => array(
                'method' => array( 'type' => 'string', 'enum' => array( 'GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS' ) ),
                'url' => array( 'type' => 'string', 'minLength' => 8, 'maxLength' => tronWebGuard::MAX_URL ),
                'headers' => array( 'type' => 'object', 'description' => 'Header name => value (no credentials)' ),
                'body' => array( 'type' => 'string', 'maxLength' => 1048576 ), 'json' => array( 'type' => array( 'object', 'array' ) ),
                'secrets' => array( 'type' => 'array', 'maxItems' => 4, 'items' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'name', 'header' ), 'properties' => array(
                    'name' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_.:-]{1,128}$' ),
                    'header' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9-]{1,64}$' ),
                    'format' => array( 'type' => 'string', 'maxLength' => 200, 'description' => 'The header value with {secret} where the secret goes (default "Bearer {secret}" for Authorization, else "{secret}")' ) ) ) ),
                'timeout' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 60, 'default' => 20 ),
                'max_bytes' => array( 'type' => 'integer', 'minimum' => 1024, 'maximum' => 8388608, 'default' => 1048576 ) ) ),
            'policy' => self::HTTP, 'write' => true, 'outputSchema' => $resp,
            'plan' => function ( array $a, tronCallContext $ctx ) use ( $env ) {
                list( $headers, , $names ) = self::httpHeaders( $env, $a );
                $v = self::allowlisted( $env, $a['url'] );
                return array( 'changes' => array( array( 'method' => $a['method'], 'url' => $v['parts']['url'], 'headers' => array_map( function ( $h ) { return strtok( $h, ':' ); }, $headers ),
                                                         'secrets' => $names, 'body_bytes' => strlen( self::httpBody( $a )[0] ) ) ),
                              'state' => array( 'url' => $v['parts']['url'] ),
                              'effects' => array( "{$a['method']} {$v['parts']['url']}" . ( $names ? ' with the secret(s) ' . implode( ', ', $names ) : '' ) ) );
            },
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $env ) {
                self::allowlisted( $env, $a['url'] );
                list( $headers, $values ) = self::httpHeaders( $env, $a );
                list( $body, $type ) = self::httpBody( $a );
                if ( $type !== null )
                    $headers[] = 'Content-Type: ' . $type;
                $client = tronWebClient::fromEnv( $env );
                $r = $client->request( $a['method'], $a['url'], array( 'headers' => $headers, 'body' => $body, 'timeout' => (int)$a['timeout'],
                                                                     'max_bytes' => (int)$a['max_bytes'], 'hosts' => self::allowedHosts( $env ) ) );
                return self::mask( self::response( $r ), $values );
            },
        ) ) );

        $registry->tool( tronTool::define( array(
            'name' => 'api_http_inspect', 'title' => 'Inspect an HTTP address',
            'description' => 'One GET or HEAD request to a public address without any credentials, reporting what a client sees: status, HTTP version, timings (DNS, connect, TLS, first byte, total), response headers, TLS version, cipher and certificate (subject, issuer, expiry), every redirect and the address connected to.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'url' ), 'properties' => array(
                'url' => array( 'type' => 'string', 'minLength' => 8, 'maxLength' => tronWebGuard::MAX_URL ),
                'method' => array( 'type' => 'string', 'enum' => array( 'GET', 'HEAD' ), 'default' => 'HEAD' ),
                'follow' => array( 'type' => 'boolean', 'default' => true ) ) ),
            'policy' => self::READ,
            'outputSchema' => array( 'type' => 'object', 'required' => array( 'url', 'final_url', 'status', 'http_version', 'timings', 'headers', 'redirects', 'primary_ip' ), 'properties' => array(
                'url' => array( 'type' => 'string' ), 'final_url' => array( 'type' => 'string' ), 'status' => array( 'type' => 'integer' ),
                'http_version' => array( 'type' => 'string' ), 'timings' => array( 'type' => 'object' ), 'headers' => array( 'type' => 'object' ),
                'tls' => array( 'type' => array( 'object', 'null' ) ), 'redirects' => array( 'type' => 'array' ), 'primary_ip' => array( 'type' => 'string' ),
                'bytes' => array( 'type' => 'integer' ), 'ms' => array( 'type' => 'integer' ) ) ),
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $env ) {
                $r = tronWebClient::fromEnv( $env )->request( $a['method'], $a['url'], array( 'inspect' => true, 'follow' => (bool)$a['follow'], 'max_bytes' => 262144, 'timeout' => 20 ) );
                return array( 'url' => $r['url'], 'final_url' => $r['final_url'], 'status' => $r['status'], 'http_version' => $r['http_version'],
                              'timings' => $r['timings'], 'headers' => tronWebProgram::publicHeaders( $r['headers'] ), 'tls' => $r['tls'],
                              'redirects' => $r['redirects'], 'primary_ip' => $r['primary_ip'], 'bytes' => strlen( $r['body'] ), 'ms' => $r['ms'] );
            },
        ) ) );
    }

    /** rest_v1, rest_v2, graphql: available, prefix, auth, reason (environment service "api.capabilities" in tests). */
    public static function capabilities( tronEnvironment $env ): array
    {
        if ( $env->has( 'api.capabilities' ) )
            return $env->get( 'api.capabilities' );
        $providers = array();
        if ( class_exists( 'eZINI' ) )
        {
            try
            {
                $providers = (array)eZINI::instance( 'rest.ini' )->variable( 'ApiProvider', 'ProviderClass' );
            }
            catch ( Throwable $e )
            {
                $providers = array();
            }
        }
        $rest = class_exists( 'ezpRestMvcController' ) || class_exists( 'ezpRestRouter' );
        $content = class_exists( 'ezp7xRestApiProvider' );
        $auth = 'personal API key or OAuth2 token (Authorization: Bearer), by secret name';
        $no = function ( string $why ) { return array( 'available' => false, 'reason' => $why ); };
        return array(
            'rest_v1' => $rest && $content ? array( 'available' => true, 'prefix' => '/api/ezp/v1', 'methods' => array( 'GET' ), 'auth' => $auth, 'reason' => '' )
                                           : $no( $rest ? 'the extension ezprestapi (content provider ezp7xRestApiProvider) is not active' : 'the REST framework of the kernel is not available' ),
            'rest_v2' => $rest && $content ? array( 'available' => true, 'prefix' => '/api/ezp/v2', 'methods' => array( 'GET', 'POST', 'PUT', 'DELETE' ), 'auth' => $auth, 'reason' => '' )
                                           : $no( 'REST v2 is served by the extension ezprestapi, which is not active here' ),
            'graphql' => $rest && class_exists( 'expGraphQLRestProvider' ) && in_array( 'expGraphQLRestProvider', $providers, true )
                         ? array( 'available' => true, 'prefix' => '/api/graphql', 'methods' => array( 'GET', 'POST' ), 'auth' => $auth . '; anonymous without one', 'reason' => '' )
                         : $no( 'no GraphQL provider is registered (rest.ini [ApiProvider] ProviderClass[graphql])' ),
        );
    }

    public static function baseUrl( tronEnvironment $env ): ?string
    {
        $b = rtrim( trim( (string)$env->setting( 'ApiSettings', 'BaseUrl', '' ) ), '/' );
        return $b === '' ? null : $b;
    }

    public static function secretNames( tronEnvironment $env ): array
    {
        return array_values( array_filter( array_map( 'strval', (array)$env->setting( 'ApiSettings', 'SecretNames', array() ) ) ) );
    }

    public static function allowedHosts( tronEnvironment $env ): array
    {
        return array_values( array_filter( array_map( 'strval', (array)$env->setting( 'ApiSettings', 'AllowedHosts', array() ) ) ) );
    }

    /** The value of a secret on the list, read through the environment's "secrets" service or expSecrets. */
    public static function secretValue( tronEnvironment $env, string $name ): string
    {
        if ( !in_array( $name, self::secretNames( $env ), true ) )
            throw new tronToolError( "The secret '$name' is not one tron may use ([ApiSettings] SecretNames[])", array( 'rule' => 'secret.not-allowed' ) );
        $v = null;
        if ( $env->has( 'secrets' ) )
            $v = call_user_func( $env->get( 'secrets' ), $name );
        else if ( class_exists( 'expSecrets' ) )
            $v = expSecrets::get( $name );
        if ( $v === null || $v === '' )
            throw new tronToolError( "The secret '$name' is not set", array( 'rule' => 'secret.missing' ) );
        return (string)$v;
    }

    private static function path( string $p ): string
    {
        if ( strpos( $p, '..' ) !== false || strpos( $p, '//' ) !== false )
            throw new tronToolError( 'The path may not contain ".." or "//"' );
        return $p;
    }

    private static function query( array $q ): string
    {
        $flat = array();
        foreach ( $q as $k => $v )
            if ( is_scalar( $v ) )
                $flat[(string)$k] = is_bool( $v ) ? ( $v ? 'true' : 'false' ) : (string)$v;
        return $flat ? '?' . http_build_query( $flat, '', '&', PHP_QUERY_RFC3986 ) : '';
    }

    public static function isWriteOperation( string $q ): bool
    {
        $q = preg_replace( '/#[^\n]*/', '', $q );
        $q = preg_replace( '/"(?:[^"\\\\]|\\\\.)*"/s', '""', (string)$q );
        return (bool)preg_match( '/(^|[}\s])(mutation|subscription)\b/i', ' ' . trim( (string)$q ) );
    }

    /** One request to the installation's API, with the key secret as a bearer token. */
    private static function call( tronEnvironment $env, string $method, string $url, array $headers, ?string $body, ?string $secret ): array
    {
        $values = array();
        $headers[] = 'Accept: application/json';
        if ( $secret !== null )
        {
            $v = self::secretValue( $env, $secret );
            $values[] = $v;
            $headers[] = 'Authorization: Bearer ' . $v;
        }
        $host = (string)parse_url( $url, PHP_URL_HOST );
        $r = tronWebClient::fromEnv( $env )->request( $method, $url, array( 'headers' => $headers, 'body' => (string)$body, 'hosts' => array( $host ),
                                                                         'timeout' => 30, 'max_bytes' => 4194304 ) );
        return self::mask( self::response( $r ), $values );
    }

    private static function response( array $r ): array
    {
        $out = array( 'status' => $r['status'], 'url' => $r['final_url'], 'ms' => $r['ms'], 'headers' => tronWebProgram::publicHeaders( $r['headers'] ),
                      'truncated' => (bool)$r['truncated'], 'redirects' => $r['redirects'] );
        $type = tronWebProgram::mime( $r );
        if ( $type === 'application/json' || substr( $type, -5 ) === '+json' )
        {
            $j = json_decode( (string)$r['body'], true );
            if ( $j !== null || trim( (string)$r['body'] ) === 'null' )
                return $out + array( 'json' => $j );
        }
        $body = mb_scrub( (string)$r['body'], 'UTF-8' );
        return $out + array( 'body' => mb_strlen( $body ) > 50000 ? mb_substr( $body, 0, 50000 ) . "\n... [cut]" : $body );
    }

    /** Every secret value replaced, wherever it appears in an answer. */
    public static function mask( $value, array $secrets )
    {
        $secrets = array_filter( $secrets, function ( $s ) { return strlen( (string)$s ) >= 4; } );
        if ( !$secrets )
            return $value;
        if ( is_array( $value ) )
        {
            foreach ( $value as $k => $v )
                $value[$k] = self::mask( $v, $secrets );
            return $value;
        }
        return is_string( $value ) ? str_replace( $secrets, '[redacted:secret]', $value ) : $value;
    }

    /** The guard's verdict for a URL whose host is on [ApiSettings] AllowedHosts[]. */
    private static function allowlisted( tronEnvironment $env, string $url ): array
    {
        $v = tronWebGuard::fromEnv( $env )->check( $url );
        if ( !tronWebGuard::hostMatches( $v['parts']['host'], self::allowedHosts( $env ) ) )
            throw new tronToolError( "The host {$v['parts']['host']} is not on [ApiSettings] AllowedHosts[]", array( 'rule' => 'allowlist.host' ) );
        return $v;
    }

    /** @return array( header lines, secret values, secret names ) */
    private static function httpHeaders( tronEnvironment $env, array $a ): array
    {
        $lines = array();
        foreach ( (array)( $a['headers'] ?? array() ) as $n => $v )
        {
            $n = (string)$n;
            if ( !preg_match( '/^[A-Za-z0-9-]{1,64}$/', $n ) || !is_scalar( $v ) || preg_match( '/[\r\n]/', (string)$v ) )
                throw new tronToolError( "The header '$n' is not a valid header" );
            if ( preg_match( self::CREDENTIAL_HEADER, $n ) )
                throw new tronToolError( "The header '$n' carries a credential: give it as secrets[] by name, never inline", array( 'rule' => 'secret.inline' ) );
            if ( in_array( strtolower( $n ), array( 'host', 'content-length', 'transfer-encoding', 'connection' ), true ) )
                throw new tronToolError( "The header '$n' is set by tron" );
            $lines[] = $n . ': ' . $v;
        }
        $values = array();
        $names = array();
        foreach ( (array)( $a['secrets'] ?? array() ) as $s )
        {
            $value = self::secretValue( $env, (string)$s['name'] );
            $format = (string)( $s['format'] ?? ( strtolower( $s['header'] ) === 'authorization' ? 'Bearer {secret}' : '{secret}' ) );
            if ( strpos( $format, '{secret}' ) === false || preg_match( '/[\r\n]/', $format ) )
                throw new tronToolError( 'A secret format must contain {secret} and no line break' );
            $lines[] = $s['header'] . ': ' . str_replace( '{secret}', $value, $format );
            $values[] = $value;
            $names[] = (string)$s['name'];
        }
        return array( $lines, $values, $names );
    }

    /** @return array( body, content type or null ) */
    private static function httpBody( array $a ): array
    {
        if ( isset( $a['json'] ) )
            return array( (string)json_encode( $a['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 'application/json' );
        return array( (string)( $a['body'] ?? '' ), null );
    }
}
