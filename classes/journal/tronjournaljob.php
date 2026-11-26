<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * A background job's journal: a session of kind "job" that the job writes its progress, checkpoints and final result
 * into, so "what did it do in the background" has an answer after the fact.
 *
 *   $job = $journal->job( 'reindex the media site', array( 'login' => 'claude-cluster' ), $parentSessionId );
 *   $job->progress( 40, '400 of 1000 nodes' );
 *   $job->checkpoint( 'batch 4 done', array( 'last_node' => 1234 ) );
 *   $job->finish( 'ok', '1000 nodes reindexed', array( 'nodes' => 1000 ) );     // or ->fail( 'why' )
 *
 * From a tool: $ctx->session->journal->job( 'name' ). From the command line: tron-journal.php job:run wraps any
 * command and journals its "progress:" and "checkpoint:" lines and its exit code (doc/activity-journal.md).
 */
final class tronJournalJob
{
    /** @var tronJournal */ private $journal;
    /** @var int */ private $sessionId;
    /** @var bool */ private $finished = false;

    public function __construct( tronJournal $journal, int $sessionId )
    {
        $this->journal = $journal;
        $this->sessionId = $sessionId;
    }

    public function id(): int
    {
        return $this->sessionId;
    }

    public function progress( int $percent, string $text = '' ): array
    {
        return $this->journal->append( $this->sessionId, array( 'kind' => 'progress',
            'input' => array( 'percent' => max( 0, min( 100, $percent ) ), 'text' => $text ) ) );
    }

    public function checkpoint( string $label, array $data = array(), array $objects = array() ): array
    {
        return $this->journal->append( $this->sessionId, array( 'kind' => 'checkpoint',
            'input' => array( 'label' => $label ) + ( $data ? array( 'data' => $data ) : array() ), 'objects' => $objects ) );
    }

    public function note( string $text ): array
    {
        return $this->journal->note( $this->sessionId, $text );
    }

    /** The final result ($outcome ok or error) and the end of the job's session. */
    public function finish( string $outcome = 'ok', string $summary = '', array $result = array(), array $objects = array() ): array
    {
        $outcome = $outcome === 'ok' ? 'ok' : 'error';
        $step = $this->journal->append( $this->sessionId, array( 'kind' => 'result', 'outcome' => $outcome,
            'result' => $summary, 'input' => $result, 'objects' => $objects ) );
        $this->journal->end( $this->sessionId, $outcome === 'ok' ? 'finished' : 'failed', $outcome );
        $this->finished = true;
        return $step;
    }

    public function fail( string $reason ): array
    {
        return $this->finish( 'error', $reason );
    }

    public function finished(): bool
    {
        return $this->finished;
    }
}
