<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The HTML, CSS and SEO validators.
 *
 * HTML: libxml's parser for structure (mismatched and unexpected end tags, bad nesting, broken entities; its
 * complaints about HTML5 elements it does not know are dropped), then tron's own rules on the tree: doctype, lang,
 * title, duplicate ids, label targets, images without alt, buttons and links without a name, nested forms,
 * obsolete elements, inline styles and event handlers.
 *
 * CSS: a tokenizer that follows comments, strings, blocks and parentheses and reads every declaration: unclosed
 * comments, strings, blocks and parentheses, stray braces, declarations without ":" or value, property names,
 * bad hex colours, duplicate properties in one rule, empty rules, unknown at-rules, !important (info).
 *
 * SEO: title and description present and of a useful length, one h1, heading levels not skipped, canonical,
 * lang, viewport, noindex, images without alt, Open Graph title and description.
 */
final class tronValidateMarkup
{
    const HTML5 = array( 'article', 'aside', 'audio', 'bdi', 'canvas', 'data', 'datalist', 'details', 'dialog', 'embed', 'figcaption', 'figure',
                         'footer', 'header', 'main', 'mark', 'meter', 'nav', 'output', 'picture', 'progress', 'rp', 'rt', 'ruby', 'section', 'source',
                         'summary', 'svg', 'template', 'time', 'track', 'video', 'wbr', 'search', 'slot', 'math', 'path', 'g', 'circle', 'rect', 'line',
                         'polyline', 'polygon', 'use', 'symbol', 'defs', 'lineargradient', 'stop', 'clippath', 'mask', 'title', 'desc', 'ellipse', 'text', 'tspan' );
    const OBSOLETE = array( 'acronym', 'applet', 'basefont', 'big', 'blink', 'center', 'dir', 'font', 'frame', 'frameset', 'isindex', 'marquee', 'nobr', 'noframes', 'strike', 'tt' );
    const AT_RULES = array( 'charset', 'import', 'namespace', 'media', 'supports', 'font-face', 'keyframes', '-webkit-keyframes', 'page', 'layer', 'container',
                            'property', 'counter-style', 'font-feature-values', 'document', 'scope', 'starting-style', 'view-transition', 'position-try' );

    public static function html( string $html ): tronValidateResult
    {
        $r = new tronValidateResult( 'html' );
        if ( !preg_match( '/^\s*(<!--.*?-->\s*)*<!doctype\s+html/is', $html ) )
            $r->add( 'warning', 'html.doctype', 'The document does not start with <!DOCTYPE html>', 1, 1 );
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors( true );
        $doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT );
        foreach ( libxml_get_errors() as $e )
        {
            $msg = trim( $e->message );
            if ( $e->code === 801 && preg_match( '/^Tag (\S+) invalid/', $msg, $m ) && in_array( strtolower( $m[1] ), self::HTML5, true ) )
                continue;                                       // an HTML5 element libxml's HTML 4 parser does not know
            if ( $e->code === 801 && preg_match( '/^Tag ([a-z][a-z0-9]*-[a-z0-9-]+) invalid/', $msg ) )
                continue;                                       // a custom element
            if ( stripos( $msg, 'htmlParseStartTag: misplaced <html>' ) !== false || stripos( $msg, 'misplaced <body>' ) !== false )
                $rule = 'html.structure';
            else if ( $e->code === 76 || stripos( $msg, 'end tag' ) !== false || stripos( $msg, 'mismatch' ) !== false )
                $rule = 'html.tag-mismatch';
            else if ( stripos( $msg, 'entity' ) !== false || stripos( $msg, 'EntityRef' ) !== false )
                $rule = 'html.entity';
            else if ( $e->code === 801 )
                $rule = 'html.unknown-element';
            else
                $rule = 'html.parse';
            $r->add( $rule === 'html.unknown-element' ? 'warning' : 'error', $rule, $msg, (int)$e->line, (int)$e->column );
        }
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );
        $x = new DOMXPath( $doc );
        $root = $doc->getElementsByTagName( 'html' )->item( 0 );
        if ( $root instanceof DOMElement && trim( $root->getAttribute( 'lang' ) ) === '' )
            $r->add( 'warning', 'html.lang', 'The <html> element has no lang attribute', $root->getLineNo() );
        $titles = $doc->getElementsByTagName( 'title' );
        if ( $titles->length === 0 || trim( $titles->item( 0 )->textContent ) === '' )
            $r->add( 'error', 'html.title', 'The document has no title (or an empty one)', 1 );
        else if ( $titles->length > 1 )
            $r->add( 'error', 'html.title', 'The document has ' . $titles->length . ' title elements', $titles->item( 1 )->getLineNo() );
        $ids = array();
        foreach ( $x->query( '//*[@id]' ) as $e )
        {
            $id = $e->getAttribute( 'id' );
            if ( $id === '' || preg_match( '/\s/', $id ) )
                $r->add( 'error', 'html.id-syntax', 'An id must not be empty or contain spaces', $e->getLineNo() );
            if ( isset( $ids[$id] ) )
                $r->add( 'error', 'html.duplicate-id', "The id '" . mb_substr( $id, 0, 80 ) . "' is used again (first on line {$ids[$id]})", $e->getLineNo() );
            else
                $ids[$id] = $e->getLineNo();
        }
        foreach ( $x->query( '//label[@for]' ) as $e )
            if ( !isset( $ids[$e->getAttribute( 'for' )] ) )
                $r->add( 'error', 'html.label-for', "A label points to the id '" . mb_substr( $e->getAttribute( 'for' ), 0, 80 ) . "', which does not exist", $e->getLineNo() );
        foreach ( $x->query( '//img[not(@alt)]' ) as $e )
            $r->add( 'warning', 'html.img-alt', 'An image without an alt attribute (use alt="" for a decorative one)', $e->getLineNo() );
        foreach ( $x->query( '//button' ) as $e )
            if ( trim( $e->textContent ) === '' && trim( $e->getAttribute( 'aria-label' ) . $e->getAttribute( 'title' ) . $e->getAttribute( 'aria-labelledby' ) ) === '' && !$x->query( './/img[@alt!=""]', $e )->length )
                $r->add( 'warning', 'html.button-name', 'A button without text or an accessible name', $e->getLineNo() );
        foreach ( $x->query( '//a[@href]' ) as $e )
        {
            if ( trim( $e->textContent ) === '' && trim( $e->getAttribute( 'aria-label' ) . $e->getAttribute( 'title' ) . $e->getAttribute( 'aria-labelledby' ) ) === '' && !$x->query( './/img[@alt!=""]', $e )->length )
                $r->add( 'warning', 'html.link-name', 'A link without text or an accessible name', $e->getLineNo() );
            if ( preg_match( '/^\s*javascript:/i', $e->getAttribute( 'href' ) ) )
                $r->add( 'warning', 'html.javascript-url', 'A link with a javascript: address', $e->getLineNo() );
            if ( $e->getAttribute( 'target' ) === '_blank' && !preg_match( '/\bno(opener|referrer)\b/', $e->getAttribute( 'rel' ) ) )
                $r->add( 'info', 'html.target-blank', 'target="_blank" without rel="noopener"', $e->getLineNo() );
        }
        foreach ( $x->query( '//form//form' ) as $e )
            $r->add( 'error', 'html.nested-form', 'A form inside a form', $e->getLineNo() );
        foreach ( $x->query( '//input[not(@type="hidden") and not(@type="submit") and not(@type="button") and not(@type="image") and not(@type="reset")] | //select | //textarea' ) as $e )
        {
            $id = $e->getAttribute( 'id' );
            $labelled = $id !== '' && $x->query( '//label[@for="' . str_replace( '"', '', $id ) . '"]' )->length
                     || $x->query( 'ancestor::label', $e )->length
                     || trim( $e->getAttribute( 'aria-label' ) . $e->getAttribute( 'aria-labelledby' ) . $e->getAttribute( 'title' ) ) !== '';
            if ( !$labelled )
                $r->add( 'warning', 'html.input-label', 'A form field without a label', $e->getLineNo() );
        }
        foreach ( self::OBSOLETE as $tag )
            foreach ( $doc->getElementsByTagName( $tag ) as $e )
                $r->add( 'warning', 'html.obsolete', "<$tag> is obsolete", $e->getLineNo() );
        foreach ( $x->query( '//*[@style]' ) as $i => $e )
            if ( $i < 50 )
                $r->add( 'info', 'html.inline-style', 'An inline style attribute', $e->getLineNo() );
        foreach ( $x->query( '//@*[starts-with(name(), "on")]' ) as $i => $a )
            if ( $i < 50 )
                $r->add( 'info', 'html.inline-handler', 'An inline event handler (' . $a->nodeName . ')', $a->parentNode->getLineNo() );
        $r->meta = array( 'elements' => $x->query( '//*' )->length, 'ids' => count( $ids ) );
        return $r;
    }

    public static function css( string $css ): tronValidateResult
    {
        $r = new tronValidateResult( 'css' );
        $len = strlen( $css );
        $line = 1;
        $col = 1;
        $stack = array();           // open blocks: array( kind, line, props )
        $buf = '';
        $bufLine = 1;
        $bufCol = 1;
        $rules = 0;
        $decls = 0;
        $parens = 0;
        $step = function ( string $c ) use ( &$line, &$col ) {
            if ( $c === "\n" )
            {
                $line++;
                $col = 1;
            }
            else
                $col++;
        };
        for ( $i = 0; $i < $len; $i++ )
        {
            $c = $css[$i];
            if ( $c === '/' && ( $css[$i + 1] ?? '' ) === '*' )
            {
                $end = strpos( $css, '*/', $i + 2 );
                if ( $end === false )
                {
                    $r->add( 'error', 'css.comment', 'A comment is not closed with */', $line, $col );
                    break;
                }
                for ( $k = $i; $k < $end + 2; $k++ )
                    $step( $css[$k] );
                $i = $end + 1;
                continue;
            }
            if ( $c === '"' || $c === "'" )
            {
                $start = array( $line, $col );
                $buf .= $c;
                $step( $c );
                $closed = false;
                for ( $i++; $i < $len; $i++ )
                {
                    $d = $css[$i];
                    $buf .= $d;
                    $step( $d );
                    if ( $d === '\\' && $i + 1 < $len )
                    {
                        $buf .= $css[++$i];
                        $step( $css[$i] );
                        continue;
                    }
                    if ( $d === "\n" )
                        break;
                    if ( $d === $c )
                    {
                        $closed = true;
                        break;
                    }
                }
                if ( !$closed )
                    $r->add( 'error', 'css.string', 'A string is not closed on its line', $start[0], $start[1] );
                continue;
            }
            if ( trim( $buf ) === '' && !ctype_space( $c ) )
            {
                $bufLine = $line;
                $bufCol = $col;
            }
            if ( $c === '(' )
                $parens++;
            else if ( $c === ')' )
            {
                if ( --$parens < 0 )
                {
                    $r->add( 'error', 'css.paren', 'A ")" without "("', $line, $col );
                    $parens = 0;
                }
            }
            if ( $parens > 0 && $c !== '(' )
            {
                $buf .= $c;
                $step( $c );
                continue;
            }
            if ( $c === '{' )
            {
                $prelude = trim( $buf );
                $kind = 'rule';
                if ( $prelude === '' )
                    $r->add( 'error', 'css.selector', 'A block without a selector', $line, $col );
                else if ( $prelude[0] === '@' )
                {
                    $name = strtolower( (string)preg_replace( '/^@([-a-z0-9]+).*$/is', '$1', $prelude ) );
                    if ( !in_array( $name, self::AT_RULES, true ) && strpos( $name, '-' ) !== 0 )
                        $r->add( 'warning', 'css.at-rule', "Unknown at-rule @$name", $bufLine, $bufCol );
                    $kind = in_array( $name, array( 'font-face', 'page', 'property', 'counter-style', 'font-feature-values', 'position-try', 'view-transition' ), true ) ? 'rule' : 'group';
                }
                else if ( ( $stack[count( $stack ) - 1][0] ?? '' ) === 'rule' )
                    $kind = 'rule';                                   // CSS nesting: a rule inside a rule
                $stack[] = array( $kind, $bufLine, array(), 0, $prelude );
                if ( $kind === 'rule' )
                    $rules++;
                $buf = '';
                $step( $c );
                continue;
            }
            if ( $c === ';' || $c === '}' )
            {
                $text = trim( $buf );
                if ( $text !== '' )
                {
                    if ( !$stack || $stack[count( $stack ) - 1][0] === 'group' )
                    {
                        if ( $text[0] !== '@' )
                            $r->add( 'error', 'css.declaration-outside', 'A declaration outside a rule: ' . mb_substr( $text, 0, 60 ), $bufLine, $bufCol );
                        else if ( !in_array( strtolower( (string)preg_replace( '/^@([-a-z0-9]+).*$/is', '$1', $text ) ), self::AT_RULES, true ) )
                            $r->add( 'warning', 'css.at-rule', 'Unknown at-rule ' . strtok( $text, " \t" ), $bufLine, $bufCol );
                    }
                    else
                    {
                        $decls++;
                        $top = count( $stack ) - 1;
                        $stack[$top][3]++;
                        self::declaration( $text, $bufLine, $bufCol, $r, $stack[$top][2] );
                    }
                }
                $buf = '';
                if ( $c === '}' )
                {
                    if ( !$stack )
                        $r->add( 'error', 'css.brace', 'A "}" without "{"', $line, $col );
                    else
                    {
                        $b = array_pop( $stack );
                        if ( $b[0] === 'rule' && $b[3] === 0 && !self::hasChildren( $b ) )
                            $r->add( 'warning', 'css.empty-rule', 'An empty rule: ' . mb_substr( $b[4], 0, 60 ), $b[1] );
                    }
                }
                $step( $c );
                continue;
            }
            $buf .= $c;
            $step( $c );
        }
        if ( $parens > 0 )
            $r->add( 'error', 'css.paren', 'A "(" is not closed', $line, $col );
        foreach ( $stack as $b )
            $r->add( 'error', 'css.brace', 'A block is not closed with "}"', $b[1] );
        if ( trim( $buf ) !== '' )
            $r->add( 'error', 'css.trailing', 'Text after the last rule: ' . mb_substr( trim( $buf ), 0, 60 ), $bufLine, $bufCol );
        $r->meta = array( 'rules' => $rules, 'declarations' => $decls );
        return $r;
    }

    private static function hasChildren( array $b ): bool
    {
        return false;
    }

    private static function declaration( string $text, int $line, int $col, tronValidateResult $r, array &$props ): void
    {
        if ( strpos( $text, ':' ) === false )
        {
            // a nested selector without a block, or a typo
            $r->add( 'error', 'css.declaration', 'Not a declaration (no ":"): ' . mb_substr( $text, 0, 60 ), $line, $col );
            return;
        }
        list( $prop, $value ) = array_map( 'trim', explode( ':', $text, 2 ) );
        if ( !preg_match( '/^(--[A-Za-z0-9_-]+|-?[a-zA-Z][a-zA-Z0-9-]*)$/', $prop ) )
            $r->add( 'error', 'css.property', "Not a property name: '" . mb_substr( $prop, 0, 60 ) . "'", $line, $col );
        if ( $value === '' && strpos( $prop, '--' ) !== 0 )
            $r->add( 'error', 'css.value', "The property $prop has no value", $line, $col );
        if ( preg_match( '/!\s*important\s*$/i', $value ) )
            $r->add( 'info', 'css.important', "$prop uses !important", $line, $col );
        if ( strpos( $prop, '--' ) !== 0 && preg_match_all( '/#([0-9A-Za-z]+)\b/', preg_replace( '/url\([^)]*\)/i', '', $value ), $m ) )
            foreach ( $m[1] as $hex )
                if ( !preg_match( '/^([0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $hex ) )
                    $r->add( 'error', 'css.color', "Not a hex colour: #$hex", $line, $col );
        $key = strtolower( $prop );
        if ( isset( $props[$key] ) && strpos( $key, '--' ) !== 0 && !preg_match( '/-(webkit|moz|ms|o)-|\b(-webkit-|-moz-)/', $value . ' ' . $props[$key] ) )
            $r->add( 'warning', 'css.duplicate-property', "$prop is set twice in one rule (the later one wins)", $line, $col );
        $props[$key] = $value;
    }

    public static function seo( string $html, string $base = '' ): tronValidateResult
    {
        $r = new tronValidateResult( 'seo' );
        $m = tronWebExtract::metadata( $html, $base !== '' ? $base : 'https://example.invalid/' );
        $tl = mb_strlen( $m['title'] );
        if ( $tl === 0 )
            $r->add( 'error', 'seo.title', 'There is no title' );
        else if ( $tl < 10 || $tl > 65 )
            $r->add( 'warning', 'seo.title-length', "The title has $tl characters (10 to 65 show well in results)" );
        $dl = $m['description'] === null ? 0 : mb_strlen( trim( $m['description'] ) );
        if ( $dl === 0 )
            $r->add( 'warning', 'seo.description', 'There is no meta description' );
        else if ( $dl < 50 || $dl > 160 )
            $r->add( 'warning', 'seo.description-length', "The meta description has $dl characters (50 to 160 show well)" );
        $h1 = count( $m['headings']['h1'] );
        if ( $h1 === 0 )
            $r->add( 'warning', 'seo.h1', 'There is no h1 heading' );
        else if ( $h1 > 1 )
            $r->add( 'warning', 'seo.h1', "There are $h1 h1 headings (one is clearer)" );
        if ( $m['canonical'] === null )
            $r->add( 'info', 'seo.canonical', 'There is no canonical link' );
        if ( $m['lang'] === null )
            $r->add( 'warning', 'seo.lang', 'The <html> element has no lang attribute' );
        if ( $m['viewport'] === null )
            $r->add( 'warning', 'seo.viewport', 'There is no viewport meta tag (mobile)' );
        if ( $m['robots'] !== null && preg_match( '/noindex/i', $m['robots'] ) )
            $r->add( 'warning', 'seo.noindex', 'The page asks not to be indexed (robots noindex)' );
        if ( !isset( $m['og']['title'] ) || !isset( $m['og']['description'] ) )
            $r->add( 'info', 'seo.open-graph', 'Open Graph title or description is missing (link previews)' );
        $doc = tronWebExtract::dom( $html );
        if ( $doc )
        {
            $level = 0;
            foreach ( ( new DOMXPath( $doc ) )->query( '//h1|//h2|//h3|//h4|//h5|//h6' ) as $h )
            {
                $l = (int)substr( $h->nodeName, 1 );
                if ( $level && $l > $level + 1 )
                    $r->add( 'warning', 'seo.heading-order', "A heading jumps from h$level to h$l", $h->getLineNo() );
                $level = $l;
            }
            $noAlt = ( new DOMXPath( $doc ) )->query( '//img[not(@alt)]' )->length;
            if ( $noAlt )
                $r->add( 'warning', 'seo.img-alt', "$noAlt images without alt text" );
        }
        $r->meta = array( 'title' => $m['title'], 'description_length' => $dl, 'h1' => $m['headings']['h1'], 'canonical' => $m['canonical'], 'lang' => $m['lang'] );
        return $r;
    }
}
