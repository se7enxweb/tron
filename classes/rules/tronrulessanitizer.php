<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Turns a team's private AGENTS.md into a MASTER.md that can be shipped (doc/agent-rules.md, "Regenerating MASTER.md").
 *
 * The work happens in four passes, each deliberately strict:
 *
 * 1. Sections. Only the level-2 sections whose heading matches an entry of SECTIONS are kept (an allow list: a new
 *    section of the source is left out until someone adds it here on purpose). Headings inside code fences are text.
 * 2. Private blocks. Outside code fences, a line that names something private (PRIVATE_LINE, or one of the
 *    installation's own private names, privateFromSettings()) takes the innermost list item or paragraph it belongs to with it, so no half sentence is left behind; inside a fence only the line goes.
 *    A level-3 heading that names something private takes its whole subsection.
 * 3. Replacements. Secrets become placeholders (<ADMIN_PASSWORD>, <TOKEN>, <SECRET> ...), internal host names become
 *    example.org names, IP addresses documentation addresses, server paths /path/to/site, and the product's former
 *    name Exponential. Literal secrets the caller knows (a file outside the repository) are replaced as well.
 * 4. Check. leaks() lists what still looks private, by pattern name and line number, never the text itself. The
 *    command tron-rules.php sanitize refuses to write a MASTER.md that has a leak.
 */
final class tronRulesSanitizer
{
    /** The level-2 sections of the source that MASTER.md keeps, matched case-insensitively at the start of the heading. */
    const SECTIONS = array(
        'READ FIRST' => 'A published release is permanent',
        'Source Control Rules' => 'Source control rules',
        'Build & Run' => 'Build and run',
        'Release Versioning' => 'Release versioning',
        'Extension releases carry their version' => 'Extension releases carry their version in ezinfo.php and extension.xml',
        'Changelog & Commit Format' => 'Changelog and commit format',
        'eZ Database Gotchas' => 'Database gotchas',
        'Autoloading' => 'Autoloading',
        'Conventions' => 'Conventions',
        'Admin design and template styling rules' => 'Admin design and template styling rules (VERY IMPORTANT)',
        'File Removal' => 'File removal',
        'Verification Checklist' => 'Verification checklist',
        'Security Notes' => 'Security notes',
    );

    /** A line outside a fence that matches one of these is private: its item or paragraph is left out. */
    const PRIVATE_LINE = array(
        'private tree ai/'       => '~(^|[\s`\'"(=:])\.?/?ai/(bin|doc|tmp|one)?~',
        'private tree ./ai'      => '~(^|[\s`\'"(])\./ai\b~',
        'the ai directory'       => '~`\./ai`|`ai/`|\bthe\s+`?\./?ai`?\s+(directory|tree)~i',
        'internal ops packages'  => '~/opt/sevenx|/etc/gnubus|\bgnubus\b|\bxsh\b|\bxne\b|7x-rm~i',
        'agent worktrees'        => '~\.claude\b~',
        'root home'              => '~(^|[\s`\'"(])/root/~',
        'hosting panel paths'    => '~/opt/plesk|\bPlesk\b|\bpsaserv\b~',
        'private working notes'  => '~\b(portMediaSite|qbix|port|perf|iso[\w*]*|6015|ocl|registrations)\.md\b~',
        'staging credentials'    => '~\b(basic[- ]auth|admin login|ez admin login)\b.*`[^`]+`\s*/\s*`[^`]+`~i',
        'work-in-progress branch'=> '~\bwip-\d+~',
        'private network'        => '~\bmesh\b~i',
    );

    /** What leaks() looks for in a sanitized text: pattern name => regular expression. */
    const LEAKS = array(
        'alpha host'             => '~\b(?:[a-z0-9-]+\.)*alpha\.[a-z0-9-]+\.[a-z]{2,}\b~i',
        'IPv4 address'           => '~(?<![\w.])(?!192\.0\.2\.|198\.51\.100\.|203\.0\.113\.|127\.0\.0\.1)(?:[1-9]\d?|1\d\d|2[0-4]\d|25[0-5])\.(?:\d{1,3}\.){2}\d{1,3}(?![\w.])~',
        'server path'            => '~/var/www/vhosts|/web/vh/|/opt/sevenx|/opt/plesk|/etc/gnubus|(^|[\s`\'"(])/root/~',
        'private tree'           => '~(^|[\s`\'"(=:])\.?/?ai/(bin|doc|tmp|one)\b|`\./ai`~',
        'internal ops'           => '~\bgnubus\b|\bxsh\b|\bxne\b~i',
        'former product name'    => '~\be\s*z\s*-?\s*publish~i',
        'credential pair'        => '~^(?=.*\b(auth|login|logins|password|passwd|credential|credentials|sign[ -]?in)\b).*`[^`<\s]+`\s*/\s*`[^`<]+`~i',
        'tron token'             => '~\btron_[a-z0-9]{4,}_[A-Za-z0-9_-]{16,}~',
        'GitHub token'           => '~\bgh[pousr]_[A-Za-z0-9]{20,}~',
        'GitLab token'           => '~\bglpat-[A-Za-z0-9_-]{16,}~',
        'API key'                => '~\b(sk|rk|pk)-[A-Za-z0-9-]{20,}|\bxox[abprs]-[A-Za-z0-9-]{10,}|\bAKIA[0-9A-Z]{16}\b~',
        'JSON web token'         => '~\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}~',
        'private key'            => '~-----BEGIN [A-Z ]*PRIVATE KEY-----~',
        'credentials in a URL'   => '~[a-z][a-z0-9+.-]*://[^\s/:@`<]+:[^\s/@`<]+@~i',
        'secret assignment'      => '~\b(password|passwd|pwd|secret|api[_-]?key|apikey|access[_-]?key|auth[_-]?token)\s*[:=]\s*["\']?(?!<)[^\s"\'`<]{6,}~i',
    );

    /** @var string[] literal secrets to replace, besides the patterns */
    private $literals;

    /** @var string[] the installation's private names (servers, hosts, projects): a line naming one is private */
    private $names;

    /** @var string[] the installation's internal domains: replaced by example.org names */
    private $domains;

    /** @var string[] the installation's private IPv4 networks (CIDR): replaced by 192.0.2.20 */
    private $networks;

    /**
     * @param string[] $literals secrets known to the caller (read from a file outside the repository, never shipped)
     * @param array|null $private the installation's private profile, array( 'names' => [], 'domains' => [], 'networks' => [] );
     *        null: read it from the installation's settings (privateFromSettings()). tron ships none of these values.
     */
    public function __construct( array $literals = array(), ?array $private = null )
    {
        $this->literals = array_values( array_filter( array_map( 'strval', $literals ), function ( $s ) { return strlen( trim( $s ) ) >= 4; } ) );
        usort( $this->literals, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
        $private = $private ?? self::privateFromSettings();
        $clean = function ( $list ) {
            $out = array_values( array_unique( array_filter( array_map( function ( $v ) { return strtolower( trim( (string)$v ) ); }, (array)$list ), 'strlen' ) ) );
            usort( $out, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
            return $out;
        };
        $this->names = $clean( $private['names'] ?? array() );
        $this->domains = $clean( $private['domains'] ?? array() );
        $this->networks = array_values( array_filter( $clean( $private['networks'] ?? array() ), array( 'tronRulesSanitizer', 'validNetwork' ) ) );
    }

    /**
     * The installation's private profile, from settings that are never shipped: tron.ini [PromptRules] PrivateNames[]
     * and PrivateDomains[], [WebSettings] MeshNetworks[], set in settings/override (doc/agent-rules.md). Read through
     * the kernel's INI when it is loaded, else (the standalone command line) from settings/override/tron.ini.append.php
     * under $root (default: the current directory, the installation root). Empty lists when nothing is set.
     */
    public static function privateFromSettings( ?string $root = null ): array
    {
        $out = array( 'names' => array(), 'domains' => array(), 'networks' => array() );
        $map = array( 'names' => array( 'PromptRules', 'PrivateNames' ), 'domains' => array( 'PromptRules', 'PrivateDomains' ),
                      'networks' => array( 'WebSettings', 'MeshNetworks' ) );
        if ( $root === null && class_exists( 'eZINI', false ) )
        {
            try
            {
                $ini = eZINI::instance( 'tron.ini' );
                foreach ( $map as $key => list( $group, $name ) )
                    if ( $ini->hasVariable( $group, $name ) )
                        $out[$key] = (array)$ini->variable( $group, $name );
                return $out;
            }
            catch ( Throwable $e )
            {
                // fall through to the file
            }
        }
        $file = rtrim( $root ?? (string)getcwd(), '/' ) . '/settings/override/tron.ini.append.php';
        if ( !is_file( $file ) || !is_readable( $file ) )
            return $out;
        $group = '';
        foreach ( preg_split( '/\R/', (string)file_get_contents( $file ) ) as $line )
        {
            $line = trim( $line );
            if ( preg_match( '/^\[([^\]]+)\]$/', $line, $m ) )
            {
                $group = $m[1];
                continue;
            }
            foreach ( $map as $key => list( $g, $name ) )
            {
                if ( $group !== $g || !preg_match( '/^' . $name . '\[\]\s*(?:=(.*))?$/', $line, $m ) )
                    continue;
                if ( !isset( $m[1] ) )
                    $out[$key] = array();          // "Name[]" alone empties the list, as in the kernel's INI
                elseif ( trim( $m[1] ) !== '' )
                    $out[$key][] = trim( $m[1] );
            }
        }
        return $out;
    }

    /** Whether $cidr is an IPv4 network such as 10.0.0.0/16. */
    public static function validNetwork( string $cidr ): bool
    {
        if ( !preg_match( '~^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$~', $cidr, $m ) )
            return false;
        return ip2long( $m[1] ) !== false && (int)$m[2] <= 32;
    }

    /** Whether the IPv4 address $ip lies in one of the private networks. */
    private function inNetworks( string $ip ): bool
    {
        $long = ip2long( $ip );
        if ( $long === false )
            return false;
        foreach ( $this->networks as $cidr )
        {
            list( $net, $bits ) = explode( '/', $cidr );
            $mask = (int)$bits === 0 ? 0 : ( -1 << ( 32 - (int)$bits ) ) & 0xFFFFFFFF;
            if ( ( $long & $mask ) === ( ip2long( $net ) & $mask ) )
                return true;
        }
        return false;
    }

    /** The private names as one pattern, or null when none is set. */
    private function namesPattern(): ?string
    {
        if ( !$this->names )
            return null;
        return '~(?<![\w-])(?:' . implode( '|', array_map( function ( $n ) { return preg_quote( $n, '~' ); }, $this->names ) ) . ')(?![\w-])~i';
    }

    /** The internal domains (and their subdomains) as one pattern, or null when none is set. */
    private function domainsPattern(): ?string
    {
        if ( !$this->domains )
            return null;
        return '~\b((?:[a-z0-9-]+\.)*)(?:alpha\.)?(?:' . implode( '|', array_map( function ( $d ) { return preg_quote( $d, '~' ); }, $this->domains ) ) . ')\b~i';
    }

    /** Whether $line holds an address of the private networks. */
    private function hasNetworkAddress( string $line ): bool
    {
        if ( !$this->networks || !preg_match_all( '~(?<![\w.])\d{1,3}(?:\.\d{1,3}){3}(?!\w|\.\d)~', $line, $m ) )
            return false;
        foreach ( $m[0] as $ip )
            if ( $this->inNetworks( $ip ) )
                return true;
        return false;
    }

    /** Reads literal secrets from a file, one per line ("#" starts a comment line). */
    public static function literalsFromFile( string $path ): array
    {
        if ( !is_file( $path ) || !is_readable( $path ) )
            throw new RuntimeException( "The secrets file $path cannot be read" );
        $out = array();
        foreach ( preg_split( '/\R/', (string)file_get_contents( $path ) ) as $line )
        {
            $line = rtrim( $line );
            if ( $line !== '' && $line[0] !== '#' )
                $out[] = $line;
        }
        return $out;
    }

    /**
     * The shippable MASTER.md for a source AGENTS.md.
     * @return string
     */
    public function sanitize( string $source, string $generatedFrom = 'AGENTS.md' ): string
    {
        $source = str_replace( array( "\r\n", "\t" ), array( "\n", '    ' ), $source );
        // the commit-message rule lists the old spellings on purpose: say what it means instead of naming them
        $source = preg_replace( '/^(\s*)- Replace every spelling of the old name:.*?(?=^\s*- )/ms',
            "\$1- Replace every spelling of the product's former name (in any capitalisation, with or without a version or\n"
            . "\$1  \"legacy\" after it) with `Exponential`, or `Exponential 6.x` where the message really is about this major line.\n", $source );
        $source = preg_replace( '/the text string `eZ Publish`/', "the product's former name", $source );
        $lines = explode( "\n", $source );
        $lines = $this->literalPass( $lines );
        $sections = $this->sections( $lines );
        $out = array();
        foreach ( $sections as $title => $body )
        {
            $body = $this->dropPrivate( $body );
            $body = array_map( array( $this, 'replaceLine' ), $body );
            $body = self::renumber( self::tidy( $body ) );
            if ( trim( implode( '', $body ) ) === '' )
                continue;
            $out[] = '## ' . $title;
            $out[] = '';
            foreach ( $body as $l )
                $out[] = $l;
            $out[] = '';
        }
        $text = self::header( $generatedFrom ) . "\n" . implode( "\n", self::tidy( $out ) ) . "\n";
        return preg_replace( "/\n{3,}/", "\n\n", $text );
    }

    /**
     * What still looks private in $text.
     * @return array list of array( 'pattern' => name, 'line' => number ); never the matching text
     */
    public function leaks( string $text ): array
    {
        $out = array();
        foreach ( preg_split( '/\R/', $text ) as $i => $line )
        {
            foreach ( self::LEAKS as $name => $re )
                if ( preg_match( $re, $line ) )
                    $out[] = array( 'pattern' => $name, 'line' => $i + 1 );
            if ( ( $re = $this->domainsPattern() ) && preg_match( $re, $line ) )
                $out[] = array( 'pattern' => 'internal host', 'line' => $i + 1 );
            if ( $this->hasNetworkAddress( $line ) )
                $out[] = array( 'pattern' => 'private network address', 'line' => $i + 1 );
            if ( ( $re = $this->namesPattern() ) && preg_match( $re, $line ) )
                $out[] = array( 'pattern' => 'private name', 'line' => $i + 1 );
            foreach ( $this->literals as $n => $secret )
                if ( strpos( $line, $secret ) !== false )
                    $out[] = array( 'pattern' => 'known secret #' . ( $n + 1 ), 'line' => $i + 1 );
        }
        return $out;
    }

    private static function header( string $from ): string
    {
        return "# MASTER.md: Exponential rules for agents\n\n"
             . "> These are the Exponential project's own working rules for AI agents, shipped with tron. They come before\n"
             . "> the site's AGENTS.md: **where the two conflict, MASTER.md wins.** A site can replace this file with its own\n"
             . "> copy, or switch it off (see tron's doc/agent-rules.md). Placeholders such as `<ADMIN_PASSWORD>` and\n"
             . "> `www.example.org` stand for the values of your own installation; never write real secrets into a rules file.\n"
             . ">\n"
             . "> Generated by `php extension/tron/bin/php/tron-rules.php sanitize` from the project's " . $from . ". Do not edit by hand:\n"
             . "> change the source and generate it again, or put a site override in place.\n";
    }

    /** Replaces the caller's literal secrets first, so no later pass sees them. */
    private function literalPass( array $lines ): array
    {
        if ( !$this->literals )
            return $lines;
        foreach ( $lines as $i => $l )
            $lines[$i] = str_replace( $this->literals, '<SECRET>', $l );
        return $lines;
    }

    /** @return array kept title => body lines, in source order */
    private function sections( array $lines ): array
    {
        $out = array();
        $current = null;
        $fence = false;
        foreach ( $lines as $l )
        {
            if ( preg_match( '/^\s*(```|~~~)/', $l ) )
                $fence = !$fence;
            if ( !$fence && preg_match( '/^##\s+(.*)$/', $l, $m ) && !preg_match( '/^###/', $l ) )
            {
                $current = null;
                foreach ( self::SECTIONS as $prefix => $title )
                    if ( stripos( ltrim( $m[1] ), $prefix ) === 0 )
                    {
                        $current = $title;
                        $out[$current] = array();
                        break;
                    }
                continue;
            }
            if ( $current !== null && !( !$fence && preg_match( '/^---+\s*$/', $l ) ) )
                $out[$current][] = $l;
        }
        return $out;
    }

    private static function indent( string $l ): int
    {
        return strlen( $l ) - strlen( ltrim( $l, ' ' ) );
    }

    private static function isItem( string $l ): bool
    {
        return (bool)preg_match( '/^\s*([-*+]|\d+\.)\s+/', $l );
    }

    private function isPrivate( string $l ): bool
    {
        foreach ( self::PRIVATE_LINE as $re )
            if ( preg_match( $re, $l ) )
                return true;
        $re = $this->namesPattern();
        return $re !== null && (bool)preg_match( $re, $l );
    }

    /** Pass 2: drops private lines with the item, paragraph or subsection they belong to. */
    private function dropPrivate( array $body ): array
    {
        $n = count( $body );
        $inFence = array();
        $fence = false;
        for ( $i = 0; $i < $n; $i++ )
        {
            $isFenceLine = (bool)preg_match( '/^\s*(```|~~~)/', $body[$i] );
            if ( $isFenceLine )
                $fence = !$fence;
            $inFence[$i] = $fence || $isFenceLine;
        }
        $drop = array();
        for ( $i = 0; $i < $n; $i++ )
        {
            if ( !$this->isPrivate( $body[$i] ) )
                continue;
            if ( $inFence[$i] && !preg_match( '/^\s*(```|~~~)/', $body[$i] ) )
            {
                $drop[$i] = true;
                continue;
            }
            if ( preg_match( '/^###/', $body[$i] ) )
            {
                for ( $k = $i; $k < $n && ( $k === $i || !preg_match( '/^#{1,3}\s/', $body[$k] ) || $inFence[$k] ); $k++ )
                    $drop[$k] = true;
                continue;
            }
            // the innermost list item holding line $i: the item itself, or the nearest item above with less indent
            $start = null;
            if ( self::isItem( $body[$i] ) )
                $start = $i;
            else
            {
                $ind = self::indent( $body[$i] );
                for ( $j = $i - 1; $j >= 0; $j-- )
                {
                    if ( trim( $body[$j] ) === '' && !$inFence[$j] )
                    {
                        // a blank line ends a paragraph unless we are inside an item's indented continuation
                        if ( $ind === 0 )
                            break;
                        continue;
                    }
                    if ( self::isItem( $body[$j] ) && self::indent( $body[$j] ) < $ind )
                    {
                        $start = $j;
                        break;
                    }
                    if ( self::indent( $body[$j] ) === 0 && !self::isItem( $body[$j] ) && $ind > 0 && !$inFence[$j] )
                        break;
                }
            }
            if ( $start !== null )
            {
                $base = self::indent( $body[$start] );
                $drop[$start] = true;
                for ( $k = $start + 1; $k < $n; $k++ )
                {
                    if ( trim( $body[$k] ) === '' )
                    {
                        $next = $k + 1;
                        while ( $next < $n && trim( $body[$next] ) === '' )
                            $next++;
                        if ( $next < $n && self::indent( $body[$next] ) > $base )
                        {
                            $drop[$k] = true;
                            continue;
                        }
                        break;
                    }
                    if ( self::indent( $body[$k] ) <= $base && !$inFence[$k] )
                        break;
                    if ( self::indent( $body[$k] ) <= $base && $inFence[$k] && self::indent( $body[$k] ) < $base )
                        break;
                    $drop[$k] = true;
                }
                continue;
            }
            // a paragraph: the run of non-blank lines around $i
            for ( $a = $i; $a >= 0 && trim( $body[$a] ) !== ''; $a-- )
                $drop[$a] = true;
            for ( $b = $i; $b < $n && trim( $body[$b] ) !== ''; $b++ )
                $drop[$b] = true;
        }
        $out = array();
        foreach ( $body as $i => $l )
            if ( !isset( $drop[$i] ) )
                $out[] = $l;
        return self::closeFences( $out );
    }

    /** A fence whose closing line was dropped is closed again; an empty fence is removed. */
    private static function closeFences( array $lines ): array
    {
        $out = array();
        $open = null;
        $contentSinceOpen = 0;
        foreach ( $lines as $l )
        {
            if ( preg_match( '/^(\s*)(```|~~~)/', $l, $m ) )
            {
                if ( $open === null )
                {
                    $open = count( $out );
                    $contentSinceOpen = 0;
                    $out[] = $l;
                    continue;
                }
                if ( $contentSinceOpen === 0 )
                {
                    array_splice( $out, $open );
                    $open = null;
                    continue;
                }
                $out[] = $l;
                $open = null;
                continue;
            }
            if ( $open !== null && trim( $l ) !== '' )
                $contentSinceOpen++;
            $out[] = $l;
        }
        if ( $open !== null )
        {
            if ( $contentSinceOpen === 0 )
                array_splice( $out, $open );
            else
                $out[] = preg_match( '/^(\s*)/', $out[$open], $m ) ? $m[1] . '```' : '```';
        }
        return $out;
    }

    /** Pass 3: placeholders for secrets, hosts, addresses, paths and the former product name. */
    public function replaceLine( string $l ): string
    {
        $l = str_replace( 'plesk-php85-fpm', '<PHP_FPM_SERVICE>', $l );
        // credential pairs: `user` / `password`, on a line that speaks of signing in
        if ( preg_match( '~\b(auth|login|logins|password|passwd|credential|credentials|sign[ -]?in)\b~i', $l ) )
            $l = preg_replace( '~`[^`\s]+`\s*/\s*`[^`]+`~', '`<ADMIN_USER>` / `<ADMIN_PASSWORD>`', $l );
        // tokens and keys
        $l = preg_replace( '~\btron_[a-z0-9]{4,}_[A-Za-z0-9_-]{16,}~', '<TRON_TOKEN>', $l );
        $l = preg_replace( '~\bgh[pousr]_[A-Za-z0-9]{20,}|\bglpat-[A-Za-z0-9_-]{16,}|\b(?:sk|rk|pk)-[A-Za-z0-9-]{20,}|\bxox[abprs]-[A-Za-z0-9-]{10,}|\bAKIA[0-9A-Z]{16}\b~', '<API_KEY>', $l );
        $l = preg_replace( '~\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}~', '<JWT>', $l );
        $l = preg_replace( '~-----BEGIN [A-Z ]*PRIVATE KEY-----~', '<PRIVATE_KEY>', $l );
        // DSNs and URLs with credentials
        $l = preg_replace( '~\b(mysqli?|pgsql|postgres(?:ql)?|mongodb(?:\+srv)?|redis|valkey|amqp|oci|sqlsrv)://[^\s`\'"<>)]+~i', '$1://<DB_USER>:<DB_PASSWORD>@db.example.org/<DATABASE>', $l );
        $l = preg_replace( '~\b(mysql|pgsql|sqlite|oci|sqlsrv):(host|dbname)=[^\s`\'"<>)]+~i', '$1:host=db.example.org;dbname=<DATABASE>', $l );
        $l = preg_replace( '~([a-z][a-z0-9+.-]*://)[^\s/:@`<]+:[^\s/@`<]+@~i', '$1<USER>:<PASSWORD>@', $l );
        $l = preg_replace( '~\b(password|passwd|pwd|secret|api[_-]?key|apikey|access[_-]?key|auth[_-]?token)(\s*[:=]\s*)(["\']?)(?!<)[^\s"\'`<]{6,}\3~i', '$1$2$3<SECRET>$3', $l );
        // hosts
        if ( $re = $this->domainsPattern() )
            $l = preg_replace_callback( $re, function ( $m ) {
                $labels = array_values( array_filter( explode( '.', strtolower( rtrim( $m[1], '.' ) ) ), 'strlen' ) );
                $first = $labels[0] ?? '';
                if ( $first === 'edit' || $first === 'admin' )
                    return 'admin.example.org';
                if ( $first === 'app' || $first === 'api' )
                    return 'api.example.org';
                return $first === '' || $first === 'www' ? 'example.org' : 'www.example.org';
            }, $l );
        $l = preg_replace( '~\b(?:[a-z0-9-]+\.)*alpha\.[a-z0-9-]+\.[a-z]{2,}\b~i', 'www.example.org', $l );
        // addresses
        if ( $this->networks )
            $l = preg_replace_callback( '~(?<![\w.])\d{1,3}(?:\.\d{1,3}){3}(?!\w|\.\d)~', function ( $m ) {
                return $this->inNetworks( $m[0] ) ? '192.0.2.20' : $m[0];
            }, $l );
        $l = preg_replace( '~(?<![\w.])(?!192\.0\.2\.|198\.51\.100\.|203\.0\.113\.|127\.0\.0\.1)(?:[1-9]\d?|1\d\d|2[0-4]\d|25[0-5])\.(?:\d{1,3}\.){2}\d{1,3}(?![\w.])~', '192.0.2.10', $l );
        // server paths
        $l = preg_replace( '~(?:/var/www/vhosts|/web/vh)/[^\s`\'")<>]*~', '/path/to/site', $l );
        // the product's name
        $l = preg_replace( '~`eZ Publish`~', 'the former product name', $l );
        $l = preg_replace( '~\beZ\s*Publish(?:\s+(?:4|legacy|Legacy))?\b~i', 'Exponential', $l );
        $l = preg_replace( '~\bezpublish\b~i', 'exponential', $l );
        $l = preg_replace( '~\bNetgen Layouts\b~', 'Exponential Layouts', $l );
        $l = preg_replace( '~\beZ4\b~', 'Exponential', $l );
        return $l;
    }

    /** Numbers ordered lists 1, 2, 3 again after items were left out. */
    private static function renumber( array $lines ): array
    {
        $counters = array();
        foreach ( $lines as $i => $l )
        {
            if ( preg_match( '/^(\s*)(\d+)\.(\s+.*)$/', $l, $m ) )
            {
                $ind = strlen( $m[1] );
                foreach ( array_keys( $counters ) as $k )
                    if ( $k > $ind )
                        unset( $counters[$k] );
                $counters[$ind] = ( $counters[$ind] ?? 0 ) + 1;
                $lines[$i] = $m[1] . $counters[$ind] . '.' . $m[3];
            }
            else if ( trim( $l ) !== '' )
            {
                $ind = self::indent( $l );
                foreach ( array_keys( $counters ) as $k )
                    if ( $k >= $ind )
                        unset( $counters[$k] );
            }
        }
        return $lines;
    }

    /** Trims blank lines at both ends and collapses runs of blank lines. */
    private static function tidy( array $lines ): array
    {
        $out = array();
        $blank = true;
        foreach ( $lines as $l )
        {
            $isBlank = trim( $l ) === '';
            if ( $isBlank && $blank )
                continue;
            $out[] = rtrim( $l );
            $blank = $isBlank;
        }
        while ( $out && trim( end( $out ) ) === '' )
            array_pop( $out );
        return $out;
    }
}
