<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * A tool's own refusal or failure, meant for the agent: tools/call answers it as a result with isError: true and
 * this message (plus hints), so the model can correct itself. Throw it from a handler; any other exception is an
 * internal error and its message is not passed on.
 */
class tronToolError extends RuntimeException
{
    /** @var array */
    private $details;

    public function __construct( string $message, array $details = array() )
    {
        parent::__construct( $message );
        $this->details = $details;
    }

    public function details(): array
    {
        return $this->details;
    }
}
