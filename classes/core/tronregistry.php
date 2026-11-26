<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The tools, resources and prompts every loaded program registered.
 *
 * A program registers inside begin()/commit(): everything it adds is staged and becomes visible only on commit, so
 * a program that fails half way leaves nothing behind (rollback()). The registry also enforces the naming
 * convention that keeps programs apart: a program named "centre" may only add tools and prompts named centre_* and
 * resources under centre://. A name already taken by another program is refused.
 */
final class tronRegistry
{
    /** @var array<string,tronTool> */ private $tools = array();
    /** @var array<string,tronResource> uri => resource or template */ private $resources = array();
    /** @var array<string,tronPrompt> */ private $prompts = array();
    /** @var array<string,string> name => reason, tools a program left out on purpose */ private $unavailable = array();

    /** @var string|null */ private $program = null;
    /** @var array */ private $stage = array();

    public function begin( string $program ): void
    {
        if ( $this->program !== null )
            throw new LogicException( "Program '{$this->program}' is still registering" );
        $this->program = $program;
        $this->stage = array( 'tools' => array(), 'resources' => array(), 'prompts' => array(), 'unavailable' => array() );
    }

    /** @return array counts of what the program added */
    public function commit(): array
    {
        $this->requireOpen();
        $this->tools += $this->stage['tools'];
        $this->resources += $this->stage['resources'];
        $this->prompts += $this->stage['prompts'];
        $this->unavailable += $this->stage['unavailable'];
        $counts = array( 'tools' => count( $this->stage['tools'] ), 'resources' => count( $this->stage['resources'] ),
                         'prompts' => count( $this->stage['prompts'] ), 'unavailable' => count( $this->stage['unavailable'] ) );
        $this->program = null;
        $this->stage = array();
        return $counts;
    }

    public function rollback(): void
    {
        $this->program = null;
        $this->stage = array();
    }

    public function tool( tronTool $tool ): void
    {
        $this->requireOpen();
        $this->requirePrefix( $tool->name, 'tool' );
        if ( isset( $this->tools[$tool->name] ) || isset( $this->stage['tools'][$tool->name] ) )
            throw new InvalidArgumentException( "Tool '{$tool->name}' is already registered" );
        $tool->program = $this->program;
        $this->stage['tools'][$tool->name] = $tool;
    }

    public function resource( tronResource $resource ): void
    {
        $this->requireOpen();
        if ( $resource->scheme() !== $this->program )
            throw new InvalidArgumentException( "Resource '{$resource->uri}' must use the scheme {$this->program}://" );
        if ( isset( $this->resources[$resource->uri] ) || isset( $this->stage['resources'][$resource->uri] ) )
            throw new InvalidArgumentException( "Resource '{$resource->uri}' is already registered" );
        $resource->program = $this->program;
        $this->stage['resources'][$resource->uri] = $resource;
    }

    public function prompt( tronPrompt $prompt ): void
    {
        $this->requireOpen();
        $this->requirePrefix( $prompt->name, 'prompt' );
        if ( isset( $this->prompts[$prompt->name] ) || isset( $this->stage['prompts'][$prompt->name] ) )
            throw new InvalidArgumentException( "Prompt '{$prompt->name}' is already registered" );
        $prompt->program = $this->program;
        $this->stage['prompts'][$prompt->name] = $prompt;
    }

    /** Records that a tool is deliberately not offered here (a missing optional part), with the reason. */
    public function unavailable( string $name, string $reason ): void
    {
        $this->requireOpen();
        $this->stage['unavailable'][$name] = $reason;
    }

    /**
     * Takes a registered tool out again (an administrator switched it off), naming it with the reason. The core
     * program's tools stay. Returns whether a tool was taken out.
     */
    public function withdraw( string $name, string $reason ): bool
    {
        if ( !isset( $this->tools[$name] ) || $this->tools[$name]->program === 'tron' )
            return false;
        unset( $this->tools[$name] );
        $this->unavailable[$name] = $reason;
        return true;
    }

    public function findTool( string $name ): ?tronTool
    {
        return $this->tools[$name] ?? null;
    }

    public function findPrompt( string $name ): ?tronPrompt
    {
        return $this->prompts[$name] ?? null;
    }

    /** @return array|null array( tronResource, vars ) for the first resource whose URI or template matches */
    public function matchResource( string $uri ): ?array
    {
        if ( isset( $this->resources[$uri] ) && !$this->resources[$uri]->isTemplate() )
            return array( $this->resources[$uri], array() );
        foreach ( $this->resources as $r )
        {
            if ( !$r->isTemplate() )
                continue;
            $vars = $r->match( $uri );
            if ( $vars !== null )
                return array( $r, $vars );
        }
        return null;
    }

    /** @return tronTool[] sorted by name */
    public function tools(): array
    {
        $t = $this->tools;
        ksort( $t );
        return array_values( $t );
    }

    /** @return tronResource[] */
    public function resources(): array
    {
        $r = $this->resources;
        ksort( $r );
        return array_values( $r );
    }

    /** @return tronPrompt[] */
    public function prompts(): array
    {
        $p = $this->prompts;
        ksort( $p );
        return array_values( $p );
    }

    /** @return array<string,string> */
    public function unavailableTools(): array
    {
        $u = $this->unavailable;
        ksort( $u );
        return $u;
    }

    private function requireOpen(): void
    {
        if ( $this->program === null )
            throw new LogicException( 'Registrations happen only inside begin() and commit()' );
    }

    private function requirePrefix( string $name, string $what ): void
    {
        if ( $name !== $this->program && strpos( $name, $this->program . '_' ) !== 0 )
            throw new InvalidArgumentException( "A $what of the program '{$this->program}' must be named {$this->program}_*, not '$name'" );
    }
}
