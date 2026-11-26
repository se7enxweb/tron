<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The activity journal's tables, in tronSchema's declaration format (tronSchema::tables() takes them in, so
 * tron.php install creates them and sql/<engine>/schema.sql carry them).
 *
 *   tron_journal_session  one row per MCP session (stdio process, HTTP client within the idle window) or background job
 *   tron_journal_step     what happened in it, in order, each row chained to the one before by a SHA-256 hash
 *   tron_journal_object   which objects each step touched (node, object, layout, INI file and key, file ...): the
 *                         index behind "everything tron did to node 123". Derived from the steps; never chained.
 */
final class tronJournalSchema
{
    public static function tables(): array
    {
        return array(
            'tron_journal_session' => array(
                'columns' => array(
                    'id' => 'serial', 'session_key' => 'string:32', 'kind' => 'string:16', 'parent_id' => 'int',
                    'user_id' => 'int', 'login' => 'string:150', 'token_id' => 'int', 'transport' => 'string:16',
                    'client_name' => 'string:100', 'client_version' => 'string:50', 'protocol' => 'string:16',
                    'node' => 'string:100', 'remote' => 'string:64', 'title' => 'string:255', 'intent' => 'text',
                    'started' => 'bigint', 'last_activity' => 'bigint', 'ended' => 'bigint', 'end_reason' => 'string:255',
                    'status' => 'string:16', 'steps' => 'int', 'writes' => 'int', 'errors' => 'int', 'refused' => 'int',
                    'head_hash' => 'string:64',
                ),
                'unique' => array( 'tron_journal_session_key' => array( 'session_key' ) ),
                'index' => array( 'tron_journal_session_started' => array( 'started' ),
                                  'tron_journal_session_user' => array( 'user_id', 'started' ) ),
            ),
            'tron_journal_step' => array(
                'columns' => array(
                    'id' => 'serial', 'session_id' => 'int', 'seq' => 'int', 'created' => 'bigint', 'kind' => 'string:16',
                    'method' => 'string:64', 'tool' => 'string:255', 'program' => 'string:64', 'write_call' => 'int',
                    'input' => 'text', 'result' => 'text', 'outcome' => 'string:16', 'code' => 'string:64',
                    'duration_ms' => 'int', 'objects' => 'text', 'diff_ref' => 'string:255', 'diff' => 'text',
                    'ref_seq' => 'int', 'prev_hash' => 'string:64', 'hash' => 'string:64',
                ),
                'unique' => array( 'tron_journal_step_seq' => array( 'session_id', 'seq' ) ),
                'index' => array( 'tron_journal_step_tool' => array( 'tool' ), 'tron_journal_step_created' => array( 'created' ) ),
            ),
            'tron_journal_object' => array(
                'columns' => array( 'id' => 'serial', 'session_id' => 'int', 'step_id' => 'int', 'kind' => 'string:32',
                                    'ref' => 'string:255', 'action' => 'string:16', 'created' => 'bigint' ),
                'index' => array( 'tron_journal_object_ref' => array( 'kind', 'ref' ),
                                  'tron_journal_object_session' => array( 'session_id' ) ),
            ),
        );
    }

    public static function names(): array
    {
        return array_keys( self::tables() );
    }
}
