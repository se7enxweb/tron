<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Where audit entries go. An entry is an array with: created (unix time), user_id, login, token_id, transport,
 * remote, method (tools/call, resources/read, prompts/get, auth), target (tool name, URI or prompt), program,
 * outcome (started, ok, error, denied, invalid, unauthenticated), duration_ms, detail (redacted text).
 *
 * record() throws when the entry could not be kept; for a write tool tron then refuses to run it.
 */
interface tronAuditSink
{
    public function record( array $entry ): void;
}
