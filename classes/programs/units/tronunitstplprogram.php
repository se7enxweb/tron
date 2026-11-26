<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The program "tpl": tpl.resolve, tpl.lint, tpl.source.
 *
 * tpl.resolve replays the kernel's override match (eZTemplateDesignResource::handleResource()) on the override list
 * of a siteaccess: overrides are tried in their Priority order; an override with conditions wins when every
 * condition matches the node's keys (url_alias as a prefix, a list key such as class_group by membership) and the
 * search stops there; an override without conditions is taken and the search goes on, so a later one may still win;
 * without any, the plain template of the most preferred design wins.
 *
 * tpl.source and tpl.lint read files only inside a design's templates directory: design/<d>/templates,
 * design/<d>/override/templates, and the same under extension/<ext>/, .tpl files only, no "..", and the real path
 * (symbolic links followed) must stay inside the installation or that extension.
 */
final class tronUnitsTplProgram extends tronUnitProgram
{
    const POLICY = array( 'setup', 'setup' );
    const DESIGN_PATH = '#^(extension/([A-Za-z0-9_.-]+)/)?design/[A-Za-z0-9_.-]+/(override/)?templates/[A-Za-z0-9_./-]+\.tpl$#';
    const MAX_SOURCE = 200000;

    protected function area(): string
    {
        return 'tpl';
    }

    protected function sourceInterface(): string
    {
        return 'tronUnitTplSource';
    }

    protected function units( tronRegistry $registry, tronEnvironment $env, $source ): void
    {
        $sa = array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{1,64}$', 'description' => 'A siteaccess (default: the current one)' );
        $path = array( 'type' => 'string', 'maxLength' => 500, 'description' => 'A .tpl file from the installation root, inside a design templates directory' );

        $this->unit( $registry, 'tpl.resolve', array(
            'title' => 'Resolve a template override chain',
            'description' => 'Which template file renders a node view (node_id, view) or a template (template) in a siteaccess, and why: the designs searched, the plain template, every override in the order tried with its conditions and which of them failed, and the winner. keys adds or replaces match keys (to ask "what if").',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
                'node_id' => array( 'type' => 'integer', 'minimum' => 1 ),
                'template' => array( 'type' => 'string', 'pattern' => '^/?[A-Za-z0-9_./-]+\.tpl$', 'maxLength' => 300, 'description' => 'A design template, e.g. node/view/full.tpl, pagelayout.tpl' ),
                'view' => array( 'type' => 'string', 'pattern' => '^[a-z0-9_]{1,40}$', 'default' => 'full' ),
                'siteaccess' => $sa,
                'keys' => array( 'type' => 'object', 'description' => 'Extra match keys, e.g. {"class_identifier": "article"}' ),
            ) ),
            'policy' => self::POLICY,
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source ) {
                $site = (string)( $a['siteaccess'] ?? '' );
                if ( $site !== '' && !in_array( $site, $source->siteAccesses(), true ) )
                    throw new tronToolError( "'$site' is not a siteaccess of this installation" );
                $keys = array();
                $nodeId = null;
                if ( isset( $a['node_id'] ) )
                {
                    $nodeId = (int)$a['node_id'];
                    $keys = $source->nodeKeys( $nodeId, (string)$a['view'], $ctx->principal->userId );
                    if ( $keys === null )
                        throw new tronToolError( "Node $nodeId was not found or you may not read it" );
                    $template = $a['template'] ?? 'node/view/' . $a['view'] . '.tpl';
                }
                else if ( isset( $a['template'] ) )
                    $template = (string)$a['template'];
                else
                    throw new tronToolError( 'Give node_id (and view) or template' );
                foreach ( (array)( $a['keys'] ?? array() ) as $k => $v )
                    $keys[(string)$k] = is_array( $v ) ? array_map( 'strval', $v ) : (string)$v;
                $template = ltrim( $template, '/' );
                $chain = $source->overrides( $template, $site );
                if ( $chain === null )
                    throw new tronToolError( "No design of this siteaccess has the template $template" );
                return self::resolve( $template, $site, $nodeId, $keys, $chain );
            },
        ) );

        $this->unit( $registry, 'tpl.lint', array(
            'title' => 'Check a template',
            'description' => 'Runs the template parser on a template file (path) or on template text (source): errors, warnings, the functions and operators it uses, and the ones this installation does not know.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
                'path' => $path,
                'source' => array( 'type' => 'string', 'maxLength' => self::MAX_SOURCE, 'description' => 'Template text to check instead of a file' ),
            ) ),
            'policy' => self::POLICY,
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source ) {
                if ( isset( $a['path'] ) === isset( $a['source'] ) )
                    throw new tronToolError( 'Give path or source (one of them)' );
                $p = isset( $a['path'] ) ? self::guard( $source, (string)$a['path'] ) : '(source)';
                $text = isset( $a['source'] ) ? (string)$a['source'] : (string)$source->read( $p );
                $r = $source->lint( $p, isset( $a['source'] ) ? $text : null );
                $errors = array_values( array_map( array( 'tronUnitValue', 'safeText' ), array_map( 'strval', array_merge( (array)( $r['errors'] ?? array() ), self::unclosed( $text ) ) ) ) );
                $warnings = array_values( array_map( array( 'tronUnitValue', 'safeText' ), array_map( 'strval', (array)( $r['warnings'] ?? array() ) ) ) );
                $unknown = array_values( array_unique( array_map( 'strval', (array)( $r['unknown'] ?? array() ) ) ) );
                return array( 'path' => $p, 'ok' => !$errors && !$unknown, 'errors' => $errors, 'warnings' => $warnings,
                              'functions' => array_values( array_unique( array_map( 'strval', (array)( $r['functions'] ?? array() ) ) ) ),
                              'operators' => array_values( array_unique( array_map( 'strval', (array)( $r['operators'] ?? array() ) ) ) ),
                              'unknown' => $unknown );
            },
        ) );

        $this->unit( $registry, 'tpl.source', array(
            'title' => 'Read a template file',
            'description' => 'The text of a .tpl file inside a design templates directory (design/<d>/templates, design/<d>/override/templates, also under extension/<ext>/), whole or a line range. Secrets and e-mail addresses in it are masked.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'path' ), 'properties' => array(
                'path' => $path,
                'from_line' => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
                'to_line' => array( 'type' => 'integer', 'minimum' => 1 ),
            ) ),
            'policy' => self::POLICY,
            'handler' => function ( array $a, tronCallContext $ctx ) use ( $source ) {
                $p = self::guard( $source, (string)$a['path'] );
                $text = $source->read( $p );
                if ( $text === null )
                    throw new tronToolError( "The file $p cannot be read" );
                $lines = preg_split( '/\r\n|\n|\r/', $text );
                if ( end( $lines ) === '' )
                    array_pop( $lines );
                $n = count( $lines );
                $from = min( max( 1, (int)$a['from_line'] ), max( 1, $n ) );
                $to = isset( $a['to_line'] ) ? min( $n, (int)$a['to_line'] ) : $n;
                if ( $to < $from )
                    throw new tronToolError( "to_line $to is before from_line $from" );
                $part = implode( "\n", array_slice( $lines, $from - 1, $to - $from + 1 ) );
                $cut = strlen( $part ) > self::MAX_SOURCE;
                if ( $cut )
                    $part = substr( $part, 0, self::MAX_SOURCE );
                return array( 'path' => $p, 'lines' => $n, 'from' => $from, 'to' => $to, 'truncated' => $cut,
                              'text' => tronUnitValue::safeText( $part ) );
            },
        ) );
    }

    /**
     * The override match, as the kernel does it, with the reason for every override.
     */
    public static function resolve( string $template, string $siteAccess, ?int $nodeId, array $keys, array $chain ): array
    {
        $winner = null;
        $found = false;
        $stopped = false;
        $overrides = array();
        foreach ( (array)$chain['overrides'] as $o )
        {
            $conditions = (array)( $o['conditions'] ?? array() );
            $row = array( 'name' => (string)$o['name'], 'match_file' => $o['match_file'] ?? null, 'conditions' => $conditions,
                          'failed' => array(), 'result' => 'not tried' );
            if ( $stopped )
            {
                $overrides[] = $row;
                continue;
            }
            if ( !$conditions )
            {
                $row['result'] = 'taken (no conditions; a later override may still win)';
                $winner = array( 'path' => $o['match_file'] ?? null, 'by' => (string)$o['name'], 'reason' => 'the override has no conditions' );
                $found = true;
            }
            else
            {
                foreach ( $conditions as $k => $want )
                {
                    $have = $keys[$k] ?? null;
                    if ( $k === 'url_alias' )
                        $ok = $have !== null && strpos( (string)$have, (string)$want ) === 0;
                    else if ( $have === null )
                        $ok = false;
                    else if ( is_array( $have ) )
                        $ok = in_array( (string)$want, array_map( 'strval', $have ), true );
                    else
                        $ok = (string)$have === (string)$want;
                    if ( !$ok )
                        $row['failed'][] = array( 'key' => (string)$k, 'wants' => (string)$want, 'has' => $have );
                }
                if ( !$row['failed'] )
                {
                    $row['result'] = 'matched: wins';
                    $winner = array( 'path' => $o['match_file'] ?? null, 'by' => (string)$o['name'],
                                     'reason' => 'every condition matched (' . self::conds( $conditions ) . ')' );
                    $found = true;
                    $stopped = true;
                }
                else
                    $row['result'] = 'failed';
            }
            if ( ( $o['match_file'] ?? null ) === null )
                $row['result'] .= '; its file is missing in every design';
            $overrides[] = $row;
        }
        $base = $chain['base'] ?? null;
        if ( !$found )
            $winner = array( 'path' => $base['path'] ?? null, 'by' => 'base',
                             'reason' => $overrides ? 'no override matched; the template of the most preferred design that has it' : 'the template has no overrides; the most preferred design that has it' );
        return array(
            'template' => $template, 'siteaccess' => $siteAccess !== '' ? $siteAccess : (string)( $chain['siteaccess'] ?? '' ),
            'node_id' => $nodeId, 'keys' => tronUnitValue::safe( tronUnitValue::plain( $keys ) ),
            'designs' => array_values( (array)( $chain['designs'] ?? array() ) ), 'base' => $base,
            'overrides' => $overrides, 'winner' => $winner,
        );
    }

    /** The path when it is a template file inside a design directory, else a tool error. */
    public static function guard( tronUnitTplSource $source, string $path ): string
    {
        $p = preg_replace( '#/+#', '/', ltrim( str_replace( '\\', '/', trim( $path ) ), '/' ) );
        $root = $source->root();
        if ( strpos( $p, rtrim( $root, '/' ) . '/' ) === 0 )
            $p = substr( $p, strlen( rtrim( $root, '/' ) ) + 1 );
        if ( strpos( $p, '..' ) !== false || !preg_match( self::DESIGN_PATH, $p, $m ) )
            throw new tronToolError( "'$path' is not a .tpl file inside a design templates directory (design/<d>/templates, design/<d>/override/templates, or the same under extension/<ext>/)" );
        $real = realpath( $root . $p );
        $base = realpath( $root . ( !empty( $m[2] ) ? 'extension/' . $m[2] : '' ) );
        $rootReal = realpath( $root );
        if ( $real === false || $base === false || !is_file( $real ) )
            throw new tronToolError( "There is no template file $p" );
        if ( strpos( $real, $base . '/' ) !== 0 && strpos( $real, $rootReal . '/' ) !== 0 )
            throw new tronToolError( "$p leads outside its design directory" );
        if ( strpos( $real, '/design/' ) === false || substr( $real, -4 ) !== '.tpl' )
            throw new tronToolError( "$p is not inside a design directory" );
        return $p;
    }

    /** Block functions the parser accepts without their end tag (it ignores the rest of the template silently). */
    const BLOCK_TAGS = array( 'if', 'foreach', 'section', 'while', 'do', 'for', 'switch', 'let', 'default', 'cache-block',
                              'set-block', 'append-block', 'run-once', 'literal' );

    /**
     * Block functions opened more often than closed ("line 3: {if} is not closed"). Comments and literal blocks are
     * left out first. The parser reports the opposite case (an end tag without a start tag) itself.
     *
     * @return string[]
     */
    public static function unclosed( string $text ): array
    {
        $blank = function ( array $m ) { return preg_replace( '/[^\n]/', ' ', $m[0] ); };
        $text = (string)preg_replace_callback( '/\{\*.*?\*\}/s', $blank, $text );
        $text = (string)preg_replace_callback( '/\{literal\}.*?\{\/literal\}/s', $blank, $text );
        $out = array();
        foreach ( self::BLOCK_TAGS as $tag )
        {
            $q = preg_quote( $tag, '/' );
            if ( !preg_match_all( '/\{' . $q . '(?=[\s}])/', $text, $open, PREG_OFFSET_CAPTURE ) )
                continue;
            $close = preg_match_all( '/\{\/' . $q . '\s*\}/', $text );
            $missing = count( $open[0] ) - $close;
            if ( $missing > 0 )
            {
                $last = $open[0][count( $open[0] ) - 1][1];
                $out[] = 'line ' . ( substr_count( $text, "\n", 0, $last ) + 1 ) . ": {{$tag}} is opened " . count( $open[0] ) . " times and closed $close times";
            }
        }
        return $out;
    }

    private static function conds( array $c ): string
    {
        $out = array();
        foreach ( $c as $k => $v )
            $out[] = "$k=$v";
        return implode( ', ', $out );
    }
}
