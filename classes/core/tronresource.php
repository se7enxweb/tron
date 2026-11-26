<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * A resource (fixed URI) or a resource template (URI with {variables}), as a program declares it:
 *
 *   tronResource::define( array(
 *       'uri' => 'centre://ticket/{id}',     // the scheme is the program's name; {name} makes it a template
 *       'name' => 'centre_ticket', 'title' => 'Ticket', 'description' => '...', 'mimeType' => 'application/json',
 *       'policy' => array( 'tron', 'centre_read' ),
 *       'reader' => function ( array $vars, tronCallContext $ctx ) { return array( ... ) or 'text'; },
 *   ) );
 *
 * A reader returns an array (sent as JSON) or a string; it returns null for "no such resource".
 */
final class tronResource
{
    /** @var string */ public $uri;
    /** @var string */ public $name;
    /** @var string */ public $title;
    /** @var string */ public $description;
    /** @var string */ public $mimeType;
    /** @var array|null */ public $policy;
    /** @var bool */ public $limitedOk;
    /** @var callable */ public $reader;
    /** @var string[] */ public $vars = array();
    /** @var string|null */ public $regex = null;
    /** @var string */ public $program = '';

    private function __construct()
    {
    }

    public static function define( array $d ): self
    {
        $r = new self();
        $r->uri = (string)( $d['uri'] ?? '' );
        if ( !preg_match( '#^[a-z][a-z0-9]*://[^\s]+$#', $r->uri ) )
            throw new InvalidArgumentException( "Resource URI '{$r->uri}' is not a scheme://path URI" );
        $r->name = (string)( $d['name'] ?? $r->uri );
        $r->title = (string)( $d['title'] ?? $r->name );
        $r->description = (string)( $d['description'] ?? '' );
        $r->mimeType = (string)( $d['mimeType'] ?? 'application/json' );
        $policy = $d['policy'] ?? null;
        if ( $policy !== null && ( !is_array( $policy ) || count( $policy ) !== 2 ) )
            throw new InvalidArgumentException( "Resource '{$r->uri}': policy must be array( module, function ) or null" );
        $r->policy = $policy === null ? null : array_values( $policy );
        $r->limitedOk = !empty( $d['limitedOk'] );
        if ( !isset( $d['reader'] ) || !is_callable( $d['reader'] ) )
            throw new InvalidArgumentException( "Resource '{$r->uri}' needs a callable reader" );
        $r->reader = $d['reader'];

        if ( preg_match_all( '/\{([a-z][a-z0-9_]*)\}/', $r->uri, $m ) )
        {
            $r->vars = $m[1];
            $parts = preg_split( '/\{[a-z][a-z0-9_]*\}/', $r->uri );
            $re = '';
            foreach ( $parts as $i => $part )
            {
                $re .= preg_quote( $part, '~' );
                if ( isset( $r->vars[$i] ) )
                    $re .= '(?P<' . $r->vars[$i] . '>[^/?#]+)';
            }
            $r->regex = '~^' . $re . '$~';
        }
        return $r;
    }

    public function isTemplate(): bool
    {
        return $this->regex !== null;
    }

    public function scheme(): string
    {
        return (string)substr( $this->uri, 0, (int)strpos( $this->uri, '://' ) );
    }

    /** @return array|null the variables when $uri matches this template, null when not */
    public function match( string $uri ): ?array
    {
        if ( $this->regex === null )
            return $uri === $this->uri ? array() : null;
        if ( !preg_match( $this->regex, $uri, $m ) )
            return null;
        $vars = array();
        foreach ( $this->vars as $v )
            $vars[$v] = rawurldecode( $m[$v] );
        return $vars;
    }

    public function definition(): array
    {
        $d = array( 'name' => $this->name, 'title' => $this->title, 'mimeType' => $this->mimeType );
        if ( $this->description !== '' )
            $d['description'] = $this->description;
        if ( $this->isTemplate() )
            return array( 'uriTemplate' => $this->uri ) + $d;
        return array( 'uri' => $this->uri ) + $d;
    }
}
