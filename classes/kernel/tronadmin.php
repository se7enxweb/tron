<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The kernel side of the administration views (Setup > tron). The views stay thin: they read their view parameters,
 * call one data class (classes/admin: tronAdminDashboardData, tronAdminTokensData, tronAdminProgramsData,
 * tronAdminAuditData, tronAdminSettingsData) with the request's context, and hand the result to a template. The data
 * classes have their own unit tests on SQLite (tests/unit/AdminDataTest.php).
 */
final class tronAdmin
{
    /** @var tronAdminContext|null */ private static $context = null;

    /** The context of this request (built once). */
    public static function context(): tronAdminContext
    {
        if ( self::$context === null )
            self::$context = tronAdminContext::fromKernel();
        return self::$context;
    }

    /** Forgets the context (Velocity workers serve many requests; result() calls it at the end of every view). */
    public static function reset(): void
    {
        self::$context = null;
    }

    /** The breadcrumb and content of a view's $Result. */
    public static function result( string $view, string $content, string $title ): array
    {
        self::reset();
        $path = array(
            array( 'url' => 'setup/menu', 'text' => ezpI18n::tr( 'design/admin/parts/setup/menu', 'Setup' ) ),
            array( 'url' => 'tron/dashboard', 'text' => 'tron' ),
        );
        if ( $view !== 'dashboard' )
            $path[] = array( 'url' => false, 'text' => $title );
        return array( 'content' => $content, 'path' => $path );
    }
}
