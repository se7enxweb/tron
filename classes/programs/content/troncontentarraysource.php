<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Content in an array, for tests: nodes as tronContentSource describes them, and who may read and edit which node
 * (user id => node ids, or '*' for all). update() makes a new version the way the kernel does: the next version
 * number becomes current, the attributes change, modified is the clock's time.
 */
final class tronContentArraySource implements tronContentSource
{
    /** @var array<int,array> */ public $nodes;
    /** @var array */ private $read;
    /** @var array */ private $edit;
    /** @var callable */ private $clock;

    public function __construct( array $nodes, array $read, array $edit, ?callable $clock = null )
    {
        $this->nodes = array();
        foreach ( $nodes as $n )
            $this->nodes[(int)$n['node_id']] = $n;
        $this->read = $read;
        $this->edit = $edit;
        $this->clock = $clock ?: function () { return time(); };
    }

    public function available(): bool
    {
        return true;
    }

    public function reason(): string
    {
        return '';
    }

    public function node( int $nodeId ): ?array
    {
        return $this->nodes[$nodeId] ?? null;
    }

    public function canRead( int $nodeId, int $userId ): bool
    {
        return isset( $this->nodes[$nodeId] ) && self::allows( $this->read[$userId] ?? array(), $nodeId );
    }

    public function canEdit( int $nodeId, int $userId ): bool
    {
        return isset( $this->nodes[$nodeId] ) && self::allows( $this->edit[$userId] ?? array(), $nodeId );
    }

    public function update( int $nodeId, array $values, int $userId ): array
    {
        $n = $this->nodes[$nodeId];
        foreach ( $values as $k => $v )
            $n['attributes'][$k]['value'] = (string)$v;
        $n['current_version'] = $n['next_version'];
        $n['next_version']++;
        $n['modified'] = (int)call_user_func( $this->clock );
        if ( isset( $values['name'] ) || isset( $values['title'] ) )
            $n['name'] = (string)( $values['name'] ?? $values['title'] );
        return $this->nodes[$nodeId] = $n;
    }

    private static function allows( $grant, int $nodeId ): bool
    {
        return $grant === '*' || in_array( $nodeId, (array)$grant, true );
    }
}
