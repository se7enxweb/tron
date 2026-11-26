<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/** Keeps audit entries in memory (tests); can be told to fail, to prove the write tools then refuse to run. */
final class tronAuditMemory implements tronAuditSink
{
    /** @var array[] */ public $entries = array();
    /** @var bool */ public $fail = false;

    public function record( array $entry ): void
    {
        if ( $this->fail )
            throw new RuntimeException( 'audit sink unavailable' );
        $this->entries[] = $entry;
    }
}
