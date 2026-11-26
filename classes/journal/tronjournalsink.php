<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The journal's place among the audit sinks: every audit entry that carries a session handle ("journal") becomes a
 * step of that session. Entries without one (refused sign-ins, administrators' token actions) stay in the audit only.
 *
 * By default a journal that cannot write never stops a call: the audit log is the record tron insists on, the journal
 * is the story told from it. With [JournalSettings] Required=enabled it counts like an audit sink, so a write tool whose
 * "started" step cannot be journaled does not run.
 */
final class tronJournalSink implements tronAuditSink
{
    /** @var bool */ private $required;
    /** @var callable|null */ private $logger;

    public function __construct( bool $required = false, ?callable $logger = null )
    {
        $this->required = $required;
        $this->logger = $logger;
    }

    public function record( array $entry ): void
    {
        $h = $entry['journal'] ?? null;
        if ( !$h instanceof tronJournalHandle )
            return;
        try
        {
            $h->record( $entry );
        }
        catch ( Throwable $e )
        {
            if ( $this->logger )
                call_user_func( $this->logger, 'error', 'journal step lost: ' . tronRedactor::maskTokens( $e->getMessage() ) );
            if ( $this->required )
                throw new RuntimeException( 'journal: ' . $e->getMessage() );
        }
    }

    /**
     * The server's audit with the journal added, when the environment has a journal (tronJournal::fromEnvironment);
     * also makes it the environment service "tron.journal". Without a journal the audit comes back unchanged.
     */
    public static function wrap( tronAuditSink $audit, tronEnvironment $env, ?tronRegistry $registry = null, ?callable $logger = null ): tronAuditSink
    {
        try
        {
            $journal = tronJournal::fromEnvironment( $env );
        }
        catch ( Throwable $e )
        {
            if ( $logger )
                call_user_func( $logger, 'warning', 'journal unavailable: ' . $e->getMessage() );
            $journal = null;
        }
        if ( !$journal )
            return $audit;
        $env->set( 'tron.journal', $journal );
        if ( $registry )
            $env->set( 'tron.tooltitles', function () use ( $registry ) {
                $t = array();
                foreach ( $registry->tools() as $tool )
                    $t[$tool->name] = $tool->title;
                return $t;
            } );
        return new tronAuditMulti( array( $audit, new self( $journal->setting( 'Required' ) === 'enabled', $logger ) ) );
    }
}
