<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Where the program "system" reaches the installation. The kernel implements it with the installation's own APIs
 * (tronSystemKernelSource), the tests with fixtures (tronSystemArraySource). Every call that runs code of the
 * installation runs as the user named by $userId: the kernel's own permission checks apply to that user, not to the
 * process.
 */
interface tronSystemSource
{
    public function available(): bool;

    public function reason(): string;

    /** @return array|null array( origin, file, module => Module, views => ViewList, functions => FunctionList ) */
    public function moduleDefinition( string $module ): ?array;

    /** @return string[] the module names */
    public function modules(): array;

    /** @return array|null the FunctionList of the module's function_definition.php */
    public function fetchDefinition( string $module ): ?array;

    /** Runs a fetch function as the user; returns its result as it comes (objects too). */
    public function fetchCall( int $userId, string $module, string $function, array $params );

    /** @return string|null the class of a template operator, null when there is no such operator */
    public function operatorClass( string $operator ): ?string;

    /** Evaluates $input|operator( params ) as the user; returns the operator's value. */
    public function operatorEval( int $userId, string $operator, $input, array $params );

    /** Whether the user may run the view (its policy functions, as the kernel checks them for a request). */
    public function canView( int $userId, string $module, string $view, array $params ): bool;

    /** @return array( title, path[], html ) of the view rendered as the user */
    public function viewRead( int $userId, string $module, string $view, array $params, array $unordered ): array;

    /**
     * Runs a command line (no shell) from the installation root.
     *
     * @return array( exit_code, stdout, stderr, ms, timed_out )
     */
    public function run( array $argv, int $timeout, ?callable $progress = null ): array;

    public function queueInstalled(): bool;

    /** @return array( total, counts => state => n, items => job arrays ) */
    public function queueJobs( array $filter, int $offset, int $limit ): array;

    /** @return array|null one job with its payload, result and checkpoint */
    public function queueJob( string $ref ): ?array;
}
