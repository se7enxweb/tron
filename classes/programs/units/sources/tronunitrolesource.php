<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/** Roles and user accounts. Of a user only id, login, name, enabled and roles leave the server. */
interface tronUnitRoleSource extends tronUnitSource
{
    /** @return array[] id, name */
    public function roles(): array;

    /**
     * One role by name or id, or null: id, name, policies (module, function, limitations (identifier, values)),
     * assignments (kind user or group, object_id, name, limit_identifier, limit_value)
     */
    public function role( string $role ): ?array;

    /**
     * Users whose login or name contains the text: total, items (user_id, login, name, enabled, roles).
     */
    public function findUsers( string $text, int $limit, int $offset ): array;
}
