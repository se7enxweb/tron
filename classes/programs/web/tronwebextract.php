<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * What web_fetch makes of a body: HTML as text (Markdown through expai's converter where expai is installed, else
 * tron's own plain-text walk), links with absolute URLs, metadata (title, description, canonical, language, Open
 * Graph, headings); JSON decoded; XML summarised (root, namespaces, elements); RSS and Atom feeds as items.
 * Everything here is outside data: callers wrap it as such.
 */
final class tronWebExtract
{
    public static function dom( string $html ): ?DOMDocument
    {
        if ( trim( $html ) === '' || !class_exists( 'DOMDocument' ) )
            return null;
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors( true );
        $ok = $doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );
        return $ok ? $doc : null;
    }

    public static function title( DOMDocument $doc ): string
    {
        $t = $doc->getElementsByTagName( 'title' )->item( 0 );
        return $t ? trim( preg_replace( '/\s+/u', ' ', $t->textContent ) ) : '';
    }

    /** Plain text of the page (scripts, styles, navigation templates left out), headings as Markdown "#" lines. */
    public static function text( string $html, string $base ): string
    {
        if ( class_exists( 'expAiWebHtml', true ) && method_exists( 'expAiWebHtml', 'toMarkdown' ) )
        {
            try
            {
                $c = expAiWebHtml::toMarkdown( $html, $base );
                if ( is_array( $c ) && isset( $c['text'] ) )
                    return (string)$c['text'];
            }
            catch ( Throwable $e )
            {
                // fall through to tron's own walk
            }
        }
        $doc = self::dom( $html );
        if ( !$doc )
            return trim( strip_tags( $html ) );
        $body = $doc->getElementsByTagName( 'body' )->item( 0 ) ?: $doc->documentElement;
        $out = '';
        self::walk( $body, $out );
        return trim( preg_replace( "/\n{3,}/", "\n\n", preg_replace( '/[ \t]+/', ' ', $out ) ) );
    }

    private static function walk( DOMNode $n, string &$out ): void
    {
        foreach ( $n->childNodes as $c )
        {
            if ( $c instanceof DOMText )
            {
                $out .= $c->textContent;
                continue;
            }
            if ( !$c instanceof DOMElement )
                continue;
            $tag = strtolower( $c->tagName );
            if ( in_array( $tag, array( 'script', 'style', 'noscript', 'template', 'svg', 'iframe', 'head' ), true ) )
                continue;
            if ( preg_match( '/^h([1-6])$/', $tag, $m ) )
            {
                $out .= "\n\n" . str_repeat( '#', (int)$m[1] ) . ' ' . trim( preg_replace( '/\s+/u', ' ', $c->textContent ) ) . "\n\n";
                continue;
            }
            $block = in_array( $tag, array( 'p', 'div', 'section', 'article', 'li', 'tr', 'br', 'ul', 'ol', 'table', 'header', 'footer', 'main', 'nav', 'aside', 'blockquote', 'pre', 'form', 'dt', 'dd' ), true );
            if ( $tag === 'li' )
                $out .= "\n- ";
            self::walk( $c, $out );
            if ( $block )
                $out .= "\n";
        }
    }

    /** @return array[] text, url (absolute), rel, internal (same host as base) */
    public static function links( string $html, string $base, int $max = 500 ): array
    {
        $doc = self::dom( $html );
        if ( !$doc )
            return array();
        $baseEl = $doc->getElementsByTagName( 'base' )->item( 0 );
        if ( $baseEl instanceof DOMElement && $baseEl->getAttribute( 'href' ) !== '' )
            $base = tronWebClient::resolveUrl( $base, $baseEl->getAttribute( 'href' ) ) ?? $base;
        $host = strtolower( (string)parse_url( $base, PHP_URL_HOST ) );
        $out = array();
        $seen = array();
        foreach ( $doc->getElementsByTagName( 'a' ) as $a )
        {
            $href = $a->getAttribute( 'href' );
            $url = $href === '' ? null : tronWebClient::resolveUrl( $base, $href );
            if ( $url === null || isset( $seen[$url] ) )
                continue;
            $seen[$url] = true;
            $out[] = array( 'text' => mb_substr( trim( preg_replace( '/\s+/u', ' ', $a->textContent ) ), 0, 200 ), 'url' => $url,
                            'rel' => $a->getAttribute( 'rel' ), 'internal' => strtolower( (string)parse_url( $url, PHP_URL_HOST ) ) === $host );
            if ( count( $out ) >= $max )
                break;
        }
        return $out;
    }

    /** title, description, canonical, lang, robots, og{}, twitter{}, headings{h1..h3}, feeds[] */
    public static function metadata( string $html, string $base ): array
    {
        $doc = self::dom( $html );
        $m = array( 'title' => '', 'description' => null, 'canonical' => null, 'lang' => null, 'robots' => null, 'viewport' => null,
                    'og' => array(), 'twitter' => array(), 'headings' => array( 'h1' => array(), 'h2' => array(), 'h3' => array() ), 'feeds' => array() );
        if ( !$doc )
            return $m;
        $m['title'] = self::title( $doc );
        $html = $doc->getElementsByTagName( 'html' )->item( 0 );
        $m['lang'] = $html instanceof DOMElement && $html->getAttribute( 'lang' ) !== '' ? $html->getAttribute( 'lang' ) : null;
        foreach ( $doc->getElementsByTagName( 'meta' ) as $meta )
        {
            $name = strtolower( $meta->getAttribute( 'name' ) ?: $meta->getAttribute( 'property' ) );
            $content = mb_substr( $meta->getAttribute( 'content' ), 0, 1000 );
            if ( $name === 'description' || $name === 'robots' || $name === 'viewport' )
                $m[$name] = $content;
            else if ( strpos( $name, 'og:' ) === 0 && count( $m['og'] ) < 30 )
                $m['og'][substr( $name, 3 )] = $content;
            else if ( strpos( $name, 'twitter:' ) === 0 && count( $m['twitter'] ) < 30 )
                $m['twitter'][substr( $name, 8 )] = $content;
        }
        foreach ( $doc->getElementsByTagName( 'link' ) as $l )
        {
            $rel = strtolower( $l->getAttribute( 'rel' ) );
            $href = tronWebClient::resolveUrl( $base, $l->getAttribute( 'href' ) );
            if ( $rel === 'canonical' && $href !== null )
                $m['canonical'] = $href;
            if ( $rel === 'alternate' && preg_match( '#(rss|atom)\+xml#', $l->getAttribute( 'type' ) ) && $href !== null )
                $m['feeds'][] = array( 'type' => $l->getAttribute( 'type' ), 'url' => $href, 'title' => $l->getAttribute( 'title' ) );
        }
        foreach ( array( 'h1', 'h2', 'h3' ) as $h )
            foreach ( $doc->getElementsByTagName( $h ) as $i => $e )
                if ( $i < 30 )
                    $m['headings'][$h][] = mb_substr( trim( preg_replace( '/\s+/u', ' ', $e->textContent ) ), 0, 300 );
        return $m;
    }

    /** A safe XML document (no network, no entity expansion), or null with the parse errors in $errors. */
    public static function xml( string $xml, array &$errors = array() ): ?DOMDocument
    {
        $errors = array();
        if ( trim( $xml ) === '' )
        {
            $errors[] = array( 'line' => 1, 'column' => 1, 'message' => 'The document is empty', 'level' => 'error' );
            return null;
        }
        if ( preg_match( '/<!ENTITY/i', $xml ) )
        {
            $errors[] = array( 'line' => null, 'column' => null, 'message' => 'Entity declarations are not accepted (XXE)' );
            return null;
        }
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors( true );
        $ok = $doc->loadXML( $xml, LIBXML_NONET | LIBXML_COMPACT );
        foreach ( libxml_get_errors() as $e )
            $errors[] = array( 'line' => (int)$e->line, 'column' => (int)$e->column, 'message' => trim( $e->message ),
                               'level' => $e->level === LIBXML_ERR_WARNING ? 'warning' : 'error' );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );
        return $ok ? $doc : null;
    }

    /** root, namespaces, element counts (first 50 names), and the root's direct children */
    public static function xmlSummary( DOMDocument $doc ): array
    {
        $root = $doc->documentElement;
        $counts = array();
        foreach ( $doc->getElementsByTagName( '*' ) as $e )
        {
            $n = $e->nodeName;
            if ( isset( $counts[$n] ) || count( $counts ) < 50 )
                $counts[$n] = ( $counts[$n] ?? 0 ) + 1;
        }
        $ns = array();
        $xp = new DOMXPath( $doc );
        foreach ( $xp->query( 'namespace::*', $root ) ?: array() as $node )
            if ( $node->nodeName !== 'xmlns:xml' )
                $ns[$node->nodeName] = $node->nodeValue;
        return array( 'root' => $root ? $root->nodeName : null, 'namespaces' => $ns, 'elements' => $counts );
    }

    /** RSS 2.0, RSS 1.0 and Atom: title, link, items[{title, link, date, summary}]; null when it is not a feed */
    public static function feed( string $xml, int $max = 50 ): ?array
    {
        $errors = array();
        $doc = self::xml( $xml, $errors );
        if ( !$doc || !$doc->documentElement )
            return null;
        $root = strtolower( $doc->documentElement->localName );
        $xp = new DOMXPath( $doc );
        $first = function ( DOMNode $ctx, string $q ) use ( $xp ) {
            $n = $xp->query( $q, $ctx );
            return $n && $n->length ? trim( $n->item( 0 )->textContent ) : '';
        };
        $items = array();
        if ( $root === 'rss' || $root === 'rdf' )
        {
            $chan = $xp->query( '//*[local-name()="channel"]' )->item( 0 );
            foreach ( $xp->query( '//*[local-name()="item"]' ) as $i => $it )
            {
                if ( $i >= $max )
                    break;
                $items[] = array( 'title' => $first( $it, '*[local-name()="title"]' ), 'link' => $first( $it, '*[local-name()="link"]' ),
                                  'date' => $first( $it, '*[local-name()="pubDate" or local-name()="date"]' ),
                                  'summary' => mb_substr( strip_tags( $first( $it, '*[local-name()="description"]' ) ), 0, 500 ) );
            }
            return array( 'kind' => $root === 'rss' ? 'rss' : 'rss1', 'title' => $chan ? $first( $chan, '*[local-name()="title"]' ) : '',
                          'link' => $chan ? $first( $chan, '*[local-name()="link"]' ) : '', 'items' => $items );
        }
        if ( $root === 'feed' )
        {
            $link = function ( DOMNode $ctx ) use ( $xp ) {
                foreach ( $xp->query( '*[local-name()="link"]', $ctx ) as $l )
                    if ( in_array( $l->getAttribute( 'rel' ), array( '', 'alternate' ), true ) )
                        return $l->getAttribute( 'href' );
                return '';
            };
            foreach ( $xp->query( '/*/*[local-name()="entry"]' ) as $i => $e )
            {
                if ( $i >= $max )
                    break;
                $items[] = array( 'title' => $first( $e, '*[local-name()="title"]' ), 'link' => $link( $e ),
                                  'date' => $first( $e, '*[local-name()="updated" or local-name()="published"]' ),
                                  'summary' => mb_substr( strip_tags( $first( $e, '*[local-name()="summary" or local-name()="content"]' ) ), 0, 500 ) );
            }
            return array( 'kind' => 'atom', 'title' => $first( $doc->documentElement, '*[local-name()="title"]' ), 'link' => $link( $doc->documentElement ), 'items' => $items );
        }
        return null;
    }
}
