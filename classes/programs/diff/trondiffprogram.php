<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The program "diff" (doc/diff.md): diff_text, diff_json, diff_xml, diff_ini, diff_yaml, diff_merge3,
 * diff_content_versions, diff_class, diff_layout. Engines in tronDiffEngine (Myers, patience), structured
 * comparison in tronDiffStructured, the merge in tronDiffMerge, renderers in tronDiffRender. No diff library of the
 * installation is needed.
 *
 * The kernel-backed tools read through sources: content versions through the service "diff.content" (else
 * tronDiffContentKernelSource inside an installation), classes and layouts through the units sources
 * (tronUnitSources "class" and "layout"), so the caller's own read rights apply. Without a source the tool is left
 * out with the reason.
 */
final class tronDiffProgram implements tronProgram
{
    const POLICY = array( 'tron', 'diff' );
    const MAX_TEXT = 2000000;
    const FORMATS = array( 'unified', 'side_by_side', 'ansi', 'html', 'ops' );

    public function register( tronRegistry $registry, tronEnvironment $env ): void
    {
        if ( !tronRootPrograms::open( $registry, $env, array( 'diff_text', 'diff_json', 'diff_xml', 'diff_ini', 'diff_yaml', 'diff_merge3', 'diff_content_versions', 'diff_class', 'diff_layout' ) ) )
            return;
        $text = array( 'type' => 'string', 'maxLength' => self::MAX_TEXT );
        $format = array( 'type' => 'string', 'enum' => self::FORMATS, 'default' => 'unified',
                         'description' => 'unified (text), side_by_side, ansi (coloured terminal text), html (admin4 classes), ops (the edit script only)' );
        $changeItem = array( 'type' => 'object', 'required' => array( 'op', 'path' ), 'properties' => array(
            'op' => array( 'type' => 'string', 'enum' => array( 'add', 'remove', 'change' ) ), 'path' => array( 'type' => 'string' ) ) );
        $summary = array( 'type' => 'object', 'required' => array( 'added', 'removed', 'changed' ), 'properties' => array(
            'added' => array( 'type' => 'integer', 'minimum' => 0 ), 'removed' => array( 'type' => 'integer', 'minimum' => 0 ), 'changed' => array( 'type' => 'integer', 'minimum' => 0 ) ) );
        $structuredOut = array( 'type' => 'object', 'required' => array( 'changes', 'summary', 'identical' ), 'properties' => array(
            'changes' => array( 'type' => 'array', 'items' => $changeItem ), 'summary' => $summary, 'identical' => array( 'type' => 'boolean' ),
            'rendered' => array( 'type' => 'string' ), 'format' => array( 'type' => 'string' ) ) );

        $registry->tool( tronTool::define( array(
            'name' => 'diff_text', 'title' => 'Diff two texts',
            'description' => 'The difference between two texts by line, word or character, with Myers or patience diff (patience keeps moved blocks and code structure readable). Rendered as a unified diff, side by side, ANSI-coloured text or admin HTML; ops returns the edit script.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'left', 'right' ), 'properties' => array(
                'left' => $text, 'right' => $text,
                'granularity' => array( 'type' => 'string', 'enum' => array( 'line', 'word', 'char' ), 'default' => 'line' ),
                'algorithm' => array( 'type' => 'string', 'enum' => array( 'myers', 'patience' ), 'default' => 'myers' ),
                'context' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 100, 'default' => 3 ),
                'ignore_whitespace' => array( 'type' => 'boolean', 'default' => false ),
                'labels' => array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
                    'left' => array( 'type' => 'string', 'maxLength' => 200 ), 'right' => array( 'type' => 'string', 'maxLength' => 200 ) ) ),
                'format' => $format,
            ) ),
            'policy' => self::POLICY,
            'outputSchema' => array( 'type' => 'object', 'required' => array( 'granularity', 'algorithm', 'identical', 'summary', 'hunks' ), 'properties' => array(
                'granularity' => array( 'type' => 'string' ), 'algorithm' => array( 'type' => 'string' ), 'identical' => array( 'type' => 'boolean' ),
                'summary' => array( 'type' => 'object', 'required' => array( 'added', 'removed', 'unchanged' ) ),
                'hunks' => array( 'type' => 'array' ), 'rendered' => array( 'type' => 'string' ), 'rows' => array( 'type' => 'array' ), 'ops' => array( 'type' => 'array' ),
            ) ),
            'handler' => function ( array $a, tronCallContext $ctx ) {
                return self::text( $a );
            },
        ) ) );

        $structured = function ( string $name, string $title, string $what, callable $read ) use ( $registry, $text, $format, $structuredOut ) {
            $registry->tool( tronTool::define( array(
                'name' => $name, 'title' => $title,
                'description' => "The structural difference between two $what documents: every path that was added, removed or changed, with the old and new value. Lists of objects that share an id, identifier, name or key are matched by it.",
                'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'left', 'right' ), 'properties' => array(
                    'left' => $text, 'right' => $text, 'format' => $format ) ),
                'policy' => self::POLICY,
                'outputSchema' => $structuredOut,
                'handler' => function ( array $a, tronCallContext $ctx ) use ( $read ) {
                    $changes = $read( $a['left'], $a['right'] );
                    return self::structuredResult( $changes, (string)$a['format'] );
                },
            ) ) );
        };

        $structured( 'diff_json', 'Diff two JSON documents', 'JSON', function ( string $l, string $r ) {
            return tronDiffStructured::compare( self::json( $l, 'left' ), self::json( $r, 'right' ) );
        } );
        $structured( 'diff_xml', 'Diff two XML documents', 'XML', function ( string $l, string $r ) {
            return tronDiffStructured::flat( tronDiffStructured::xmlFlat( self::xml( $l, 'left' ) ), tronDiffStructured::xmlFlat( self::xml( $r, 'right' ) ) );
        } );
        $structured( 'diff_ini', 'Diff two INI files', 'Exponential INI', function ( string $l, string $r ) {
            return tronDiffStructured::ini( tronIniParser::parse( $l )['blocks'], tronIniParser::parse( $r )['blocks'] );
        } );
        $structured( 'diff_yaml', 'Diff two YAML documents', 'YAML', function ( string $l, string $r ) {
            return tronDiffStructured::compare( self::yaml( $l, 'left' ), self::yaml( $r, 'right' ) );
        } );

        $registry->tool( tronTool::define( array(
            'name' => 'diff_merge3', 'title' => 'Three-way merge',
            'description' => 'Merges two texts that both changed a common base (diff3): changes on one side are taken, identical changes once, and changes both sides made differently become conflicts with markers. Answers the merged text, whether it is clean, and every conflict with its base line.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'base', 'left', 'right' ), 'properties' => array(
                'base' => $text, 'left' => $text, 'right' => $text,
                'algorithm' => array( 'type' => 'string', 'enum' => array( 'myers', 'patience' ), 'default' => 'myers' ),
                'labels' => array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
                    'left' => array( 'type' => 'string', 'maxLength' => 100 ), 'base' => array( 'type' => 'string', 'maxLength' => 100 ), 'right' => array( 'type' => 'string', 'maxLength' => 100 ) ) ),
            ) ),
            'policy' => self::POLICY,
            'outputSchema' => array( 'type' => 'object', 'required' => array( 'merged', 'clean', 'conflicts' ), 'properties' => array(
                'merged' => array( 'type' => 'string' ), 'clean' => array( 'type' => 'boolean' ),
                'conflicts' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'required' => array( 'base_line', 'left', 'base', 'right' ) ) ),
                'chunks' => array( 'type' => 'array' ) ) ),
            'handler' => function ( array $a, tronCallContext $ctx ) {
                return tronDiffMerge::merge( $a['base'], $a['left'], $a['right'], (array)( $a['labels'] ?? array() ), (string)$a['algorithm'] );
            },
        ) ) );

        $this->registerKernel( $registry, $env, $format, $structuredOut );
    }

    private function registerKernel( tronRegistry $registry, tronEnvironment $env, array $format, array $structuredOut ): void
    {
        $content = $env->has( 'diff.content' ) ? $env->get( 'diff.content' ) : ( tronUnitSources::inKernel() ? new tronDiffContentKernelSource() : null );
        if ( !$content )
            $registry->unavailable( 'diff_content_versions', 'no content source here (reads through an Exponential installation)' );
        else
            $registry->tool( tronTool::define( array(
                'name' => 'diff_content_versions', 'title' => 'Diff two versions of a content object',
                'description' => 'What changed between two versions of a content object, attribute by attribute (the kernel\'s text form of each datatype), plus the version metadata. Default: the version before the published one against the published one. Needs content/read, and content/versionread for versions other than the published one.',
                'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'object_id' ), 'properties' => array(
                    'object_id' => array( 'type' => 'integer', 'minimum' => 1 ),
                    'from_version' => array( 'type' => 'integer', 'minimum' => 1 ), 'to_version' => array( 'type' => 'integer', 'minimum' => 1 ),
                    'language' => array( 'type' => 'string', 'pattern' => '^[a-z]{3}-[A-Z]{2}$' ), 'format' => $format,
                    'text_diff' => array( 'type' => 'boolean', 'default' => true, 'description' => 'Add a word diff of each changed text attribute' ) ) ),
                'policy' => self::POLICY,
                'outputSchema' => array( 'type' => 'object', 'required' => array( 'object', 'from', 'to', 'changes', 'summary' ) ),
                'handler' => function ( array $a, tronCallContext $ctx ) use ( $content ) {
                    $uid = $ctx->principal->userId;
                    $to = $content->version( (int)$a['object_id'], isset( $a['to_version'] ) ? (int)$a['to_version'] : null, $a['language'] ?? null, $uid );
                    if ( $to === null )
                        throw new tronToolError( "The object {$a['object_id']} or that version does not exist" );
                    $fromNo = isset( $a['from_version'] ) ? (int)$a['from_version'] : (int)$to['version']['number'] - 1;
                    if ( $fromNo < 1 )
                        throw new tronToolError( 'There is no earlier version to compare with; give from_version' );
                    $from = $content->version( (int)$a['object_id'], $fromNo, $to['version']['language'], $uid );
                    if ( $from === null )
                        throw new tronToolError( "The version $fromNo of the object {$a['object_id']} does not exist" );
                    $changes = tronDiffStructured::compare( $from['attributes'], $to['attributes'], '$' );
                    if ( !empty( $a['text_diff'] ) )
                        foreach ( $changes as $i => $c )
                            if ( $c['op'] === 'change' && is_string( $c['from'] ) && is_string( $c['to'] ) )
                            {
                                $x = tronDiffEngine::tokens( $c['from'], 'word' );
                                $y = tronDiffEngine::tokens( $c['to'], 'word' );
                                if ( count( $x ) + count( $y ) < 20000 )
                                    $changes[$i]['inline'] = tronDiffRender::inline( $x, $y, tronDiffEngine::diff( $x, $y ) );
                            }
                    return array( 'object' => $to['object'], 'from' => $from['version'], 'to' => $to['version'] )
                         + self::structuredResult( $changes, (string)$a['format'] );
                },
            ) ) );

        $classes = tronUnitSources::get( $env, 'class' );
        if ( !$classes instanceof tronUnitClassSource || !$classes->available() )
            $registry->unavailable( 'diff_class', 'no class source here (reads through an Exponential installation)' );
        else
            $registry->tool( tronTool::define( array(
                'name' => 'diff_class', 'title' => 'Diff two class definitions',
                'description' => 'The structural difference between two content class definitions (settings and attributes, matched by identifier). For the same class on two installations, use class_diff of the class units.',
                'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'left', 'right' ), 'properties' => array(
                    'left' => array( 'type' => 'string', 'pattern' => '^[a-z0-9_]{1,100}$' ), 'right' => array( 'type' => 'string', 'pattern' => '^[a-z0-9_]{1,100}$' ),
                    'format' => $format ) ),
                'policy' => self::POLICY,
                'outputSchema' => $structuredOut,
                'handler' => function ( array $a, tronCallContext $ctx ) use ( $classes ) {
                    $l = $classes->describe( $a['left'] );
                    $r = $classes->describe( $a['right'] );
                    foreach ( array( 'left' => $l, 'right' => $r ) as $side => $v )
                        if ( $v === null )
                            throw new tronToolError( "There is no class '{$a[$side]}'" );
                    return self::structuredResult( tronDiffStructured::compare( $l, $r ), (string)$a['format'] );
                },
            ) ) );

        $layouts = tronUnitSources::get( $env, 'layout' );
        if ( !$layouts instanceof tronUnitLayoutSource || !$layouts->available() )
            $registry->unavailable( 'diff_layout', 'no layout source here (Exponential Layouts not installed)' );
        else
            $registry->tool( tronTool::define( array(
                'name' => 'diff_layout', 'title' => 'Diff two layouts',
                'description' => 'The structural difference between two Exponential Layouts layouts (zones, blocks, linked zones), or between a layout\'s draft and its published version: give "draft:<id>" for a draft.',
                'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'left', 'right' ), 'properties' => array(
                    'left' => array( 'type' => 'string', 'pattern' => '^(draft:)?[A-Za-z0-9_-]{1,100}$' ), 'right' => array( 'type' => 'string', 'pattern' => '^(draft:)?[A-Za-z0-9_-]{1,100}$' ),
                    'format' => $format ) ),
                'policy' => self::POLICY,
                'outputSchema' => $structuredOut,
                'handler' => function ( array $a, tronCallContext $ctx ) use ( $layouts ) {
                    $get = function ( string $ref ) use ( $layouts ) {
                        $draft = strpos( $ref, 'draft:' ) === 0;
                        $v = $layouts->layout( $draft ? substr( $ref, 6 ) : $ref, $draft );
                        if ( $v === null )
                            throw new tronToolError( "There is no layout '$ref'" );
                        return $v;
                    };
                    return self::structuredResult( tronDiffStructured::compare( $get( $a['left'] ), $get( $a['right'] ) ), (string)$a['format'] );
                },
            ) ) );
    }

    /** diff_text's answer. */
    public static function text( array $a ): array
    {
        $g = (string)( $a['granularity'] ?? 'line' );
        $algo = (string)( $a['algorithm'] ?? 'myers' );
        $format = (string)( $a['format'] ?? 'unified' );
        $context = (int)( $a['context'] ?? 3 );
        $x = tronDiffEngine::tokens( (string)$a['left'], $g );
        $y = tronDiffEngine::tokens( (string)$a['right'], $g );
        $key = !empty( $a['ignore_whitespace'] ) ? function ( $t ) { return preg_replace( '/\s+/u', ' ', trim( $t ) ); } : null;
        $ops = tronDiffEngine::diff( $x, $y, $algo, $key );
        $summary = tronDiffEngine::summary( $ops );
        $out = array( 'granularity' => $g, 'algorithm' => $algo, 'identical' => $summary['added'] + $summary['removed'] === 0, 'summary' => $summary, 'hunks' => array() );
        if ( $g === 'line' )
            foreach ( tronDiffEngine::hunks( $ops, $context ) as $h )
                $out['hunks'][] = array( 'a_start' => $h['a_start'], 'a_len' => $h['a_len'], 'b_start' => $h['b_start'], 'b_len' => $h['b_len'] );
        $labels = (array)( $a['labels'] ?? array() ) + array( 'left' => 'a', 'right' => 'b' );
        switch ( $format )
        {
            case 'ops':
                $out['ops'] = array_map( function ( $o ) { return array( 'op' => $o[0], 'left' => $o[1], 'right' => $o[2] ); }, $ops );
                break;
            case 'side_by_side':
                if ( $g === 'line' )
                {
                    $out['rows'] = tronDiffRender::rows( $x, $y, $ops, $context );
                    $out['rendered'] = tronDiffRender::sideBySideText( $out['rows'] );
                }
                else
                    $out['rendered'] = tronDiffRender::inline( $x, $y, $ops );
                break;
            case 'html':
                $out['rendered'] = $g === 'line' ? tronDiffRender::html( tronDiffRender::rows( $x, $y, $ops, $context ), 'Differences', $labels['left'], $labels['right'] )
                                                 : tronDiffRender::inlineHtml( $x, $y, $ops );
                break;
            default:
                $ansi = $format === 'ansi';
                $out['rendered'] = $g === 'line' ? ( $out['identical'] ? '' : tronDiffRender::unified( $x, $y, $ops, $context, $labels['left'], $labels['right'], $ansi ) )
                                                 : tronDiffRender::inline( $x, $y, $ops, $ansi );
        }
        return $out;
    }

    public static function structuredResult( array $changes, string $format ): array
    {
        $out = array( 'changes' => $changes, 'summary' => tronDiffStructured::summary( $changes ), 'identical' => !$changes, 'format' => $format );
        if ( $format === 'html' )
            $out['rendered'] = tronDiffRender::changesHtml( $changes );
        else if ( $format !== 'ops' )
            $out['rendered'] = tronDiffRender::changesText( $changes, $format === 'ansi' );
        return $out;
    }

    private static function json( string $s, string $side )
    {
        $v = json_decode( $s, true, 512 );
        if ( $v === null && trim( $s ) !== 'null' )
            throw new tronToolError( "The $side side is not valid JSON: " . json_last_error_msg() );
        return $v;
    }

    private static function xml( string $s, string $side ): DOMDocument
    {
        $errors = array();
        $doc = tronWebExtract::xml( $s, $errors );
        if ( !$doc )
            throw new tronToolError( "The $side side is not well-formed XML: " . ( $errors[0]['message'] ?? 'unknown error' ) . ( isset( $errors[0]['line'] ) ? ' (line ' . $errors[0]['line'] . ')' : '' ) );
        return $doc;
    }

    private static function yaml( string $s, string $side )
    {
        list( $v, $f ) = tronYamlParser::parse( $s );
        foreach ( $f as $x )
            if ( $x['severity'] === 'error' )
                throw new tronToolError( "The $side side is not valid YAML: {$x['message']} (line {$x['location']['line']})" );
        return $v;
    }
}
