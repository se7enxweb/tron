<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Where the katalyst program reads from. Each method returns array( 'total' => int, 'items' => array[] ) with the
 * rows as column => value; the program decides which columns leave the server.
 *
 * Filters a source understands (unknown ones are ignored):
 *   plans:    source, hidden (bool), gone (bool)
 *   services: status, server_id, plan_id, customer_id, domain (substring), gone (bool)
 *   orders:   state, customer_id, since (unix time)
 */
interface tronKatalystSource
{
    /** Whether the source can answer here; reason() says why not. */
    public function available(): bool;

    public function reason(): string;

    public function plans( array $filters, int $limit, int $offset ): array;

    public function services( array $filters, int $limit, int $offset ): array;

    public function orders( array $filters, int $limit, int $offset ): array;
}
