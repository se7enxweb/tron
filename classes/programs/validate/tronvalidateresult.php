<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The one result format of every validator (doc/root-programs.md):
 *   validator, valid (no error), counts{error, warning, info}, findings[{severity, rule, message, location{line,
 *   column, path}}], meta{}
 * Findings are sorted by line, then column; at most MAX_FINDINGS are kept (meta.cut says how many were left out).
 */
final class tronValidateResult
{
    const SEVERITIES = array( 'error', 'warning', 'info' );
    const MAX_FINDINGS = 500;

    /** @var string */ private $validator;
    /** @var array[] */ private $findings = array();
    /** @var array */ public $meta = array();

    public function __construct( string $validator )
    {
        $this->validator = $validator;
    }

    public function add( string $severity, string $rule, string $message, ?int $line = null, ?int $column = null, ?string $path = null ): self
    {
        if ( !in_array( $severity, self::SEVERITIES, true ) )
            $severity = 'error';
        $this->findings[] = array( 'severity' => $severity, 'rule' => $rule, 'message' => $message,
                                   'location' => array( 'line' => $line, 'column' => $column, 'path' => $path ) );
        return $this;
    }

    /** Adds findings already in the uniform shape. */
    public function merge( array $findings ): self
    {
        foreach ( $findings as $f )
            $this->add( (string)$f['severity'], (string)$f['rule'], (string)$f['message'], $f['location']['line'] ?? null,
                        $f['location']['column'] ?? null, $f['location']['path'] ?? null );
        return $this;
    }

    public function hasErrors(): bool
    {
        foreach ( $this->findings as $f )
            if ( $f['severity'] === 'error' )
                return true;
        return false;
    }

    public function toArray(): array
    {
        $f = $this->findings;
        usort( $f, function ( $a, $b ) {
            return array( $a['location']['line'] ?? PHP_INT_MAX, $a['location']['column'] ?? 0 ) <=> array( $b['location']['line'] ?? PHP_INT_MAX, $b['location']['column'] ?? 0 );
        } );
        $counts = array( 'error' => 0, 'warning' => 0, 'info' => 0 );
        foreach ( $f as $x )
            $counts[$x['severity']]++;
        $meta = $this->meta;
        if ( count( $f ) > self::MAX_FINDINGS )
        {
            $meta['cut'] = count( $f ) - self::MAX_FINDINGS;
            $f = array_slice( $f, 0, self::MAX_FINDINGS );
        }
        return array( 'validator' => $this->validator, 'valid' => $counts['error'] === 0, 'counts' => $counts, 'findings' => $f, 'meta' => $meta );
    }

    /** The output schema every validator tool declares. */
    public static function schema(): array
    {
        return array( 'type' => 'object', 'required' => array( 'validator', 'valid', 'counts', 'findings', 'meta' ), 'properties' => array(
            'validator' => array( 'type' => 'string' ), 'valid' => array( 'type' => 'boolean' ),
            'counts' => array( 'type' => 'object', 'required' => array( 'error', 'warning', 'info' ), 'properties' => array(
                'error' => array( 'type' => 'integer', 'minimum' => 0 ), 'warning' => array( 'type' => 'integer', 'minimum' => 0 ), 'info' => array( 'type' => 'integer', 'minimum' => 0 ) ) ),
            'findings' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'required' => array( 'severity', 'rule', 'message', 'location' ), 'properties' => array(
                'severity' => array( 'type' => 'string', 'enum' => self::SEVERITIES ), 'rule' => array( 'type' => 'string' ), 'message' => array( 'type' => 'string' ),
                'location' => array( 'type' => 'object', 'required' => array( 'line', 'column', 'path' ) ) ) ) ),
            'meta' => array( 'type' => 'object' ),
        ) );
    }
}
