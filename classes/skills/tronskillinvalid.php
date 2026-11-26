<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/** A skill or pack that breaks the standard (doc/extension-conventions.md), with every reason. */
final class tronSkillInvalid extends InvalidArgumentException
{
    /** @var string[] */ private $reasons;

    public function __construct( string $message, array $reasons = array() )
    {
        $this->reasons = array_values( array_map( 'strval', $reasons ) );
        parent::__construct( $message . ( $this->reasons ? ': ' . implode( '; ', $this->reasons ) : '' ) );
    }

    /** @return string[] */
    public function reasons(): array
    {
        return $this->reasons;
    }
}
