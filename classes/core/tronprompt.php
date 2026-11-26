<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * A prompt template, as a program declares it:
 *
 *   tronPrompt::define( array(
 *       'name' => 'centre_weekly_review', 'title' => '...', 'description' => '...',
 *       'arguments' => array( array( 'name' => 'week', 'description' => 'ISO week', 'required' => false ) ),
 *       'policy' => array( 'tron', 'centre_read' ),
 *       'renderer' => function ( array $args, tronCallContext $ctx ) { return 'text of the user message'; },
 *   ) );
 *
 * A renderer returns a string (one user message) or a list of messages
 * ( array( 'role' => 'user'|'assistant', 'text' => '...' ) ).
 */
final class tronPrompt
{
    /** @var string */ public $name;
    /** @var string */ public $title;
    /** @var string */ public $description;
    /** @var array */ public $arguments;
    /** @var array|null */ public $policy;
    /** @var bool */ public $limitedOk;
    /** @var callable */ public $renderer;
    /** @var string */ public $program = '';

    private function __construct()
    {
    }

    public static function define( array $d ): self
    {
        $p = new self();
        $p->name = (string)( $d['name'] ?? '' );
        if ( !preg_match( tronTool::NAME_PATTERN, $p->name ) )
            throw new InvalidArgumentException( "Prompt name '{$p->name}' must match " . tronTool::NAME_PATTERN );
        $p->title = (string)( $d['title'] ?? $p->name );
        $p->description = (string)( $d['description'] ?? '' );
        $p->arguments = array();
        foreach ( (array)( $d['arguments'] ?? array() ) as $a )
        {
            if ( !is_array( $a ) || !isset( $a['name'] ) || !preg_match( '/^[a-z][a-z0-9_]*$/', (string)$a['name'] ) )
                throw new InvalidArgumentException( "Prompt '{$p->name}': every argument needs a name" );
            $p->arguments[] = array( 'name' => (string)$a['name'],
                                     'description' => (string)( $a['description'] ?? '' ),
                                     'required' => !empty( $a['required'] ) );
        }
        $policy = $d['policy'] ?? null;
        if ( $policy !== null && ( !is_array( $policy ) || count( $policy ) !== 2 ) )
            throw new InvalidArgumentException( "Prompt '{$p->name}': policy must be array( module, function ) or null" );
        $p->policy = $policy === null ? null : array_values( $policy );
        $p->limitedOk = !empty( $d['limitedOk'] );
        if ( !isset( $d['renderer'] ) || !is_callable( $d['renderer'] ) )
            throw new InvalidArgumentException( "Prompt '{$p->name}' needs a callable renderer" );
        $p->renderer = $d['renderer'];
        return $p;
    }

    public function definition(): array
    {
        return array( 'name' => $this->name, 'title' => $this->title,
                      'description' => $this->description, 'arguments' => $this->arguments );
    }
}
