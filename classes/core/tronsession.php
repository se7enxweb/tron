<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The state of one connection. Over stdio it lives as long as the process and must start with initialize. Over
 * HTTP tron is stateless: each request is its own session, its protocol version taken from the
 * MCP-Protocol-Version header, so any cluster node behind the load balancer can answer it.
 */
final class tronSession
{
    /** @var tronPrincipal */ public $principal;
    /** @var string stdio|http */ public $transport;
    /** @var string|null */ public $protocolVersion;
    /** @var bool */ public $requireInitialize;
    /** @var bool */ public $initialized = false;
    /** @var string */ public $remote;
    /** @var array */ public $clientInfo = array();
    /** @var tronJournalHandle|false|null the activity journal of this connection (false: it could not open one) */ public $journal = null;
    /** @var array this session's own choices in the agent rules layer, e.g. array( 'master' => 'disabled' ) (doc/agent-rules.md) */ public $rules = array();

    public function __construct( tronPrincipal $principal, string $transport, bool $requireInitialize, ?string $protocolVersion = null, string $remote = '' )
    {
        $this->principal = $principal;
        $this->transport = $transport;
        $this->requireInitialize = $requireInitialize;
        $this->protocolVersion = $protocolVersion;
        $this->remote = $remote;
    }
}
