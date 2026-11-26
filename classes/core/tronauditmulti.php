<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/** Sends each entry to several sinks; fails when any of them fails (an entry is kept everywhere or reported). */
final class tronAuditMulti implements tronAuditSink
{
    /** @var tronAuditSink[] */ private $sinks;

    public function __construct( array $sinks )
    {
        $this->sinks = $sinks;
    }

    public function record( array $entry ): void
    {
        $failed = array();
        foreach ( $this->sinks as $sink )
        {
            try
            {
                $sink->record( $entry );
            }
            catch ( Throwable $e )
            {
                $failed[] = get_class( $sink ) . ': ' . $e->getMessage();
            }
        }
        if ( $failed )
            throw new RuntimeException( 'audit: ' . implode( '; ', $failed ) );
    }
}
