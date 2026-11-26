<?php
/**
 * Standalone class loader for tron's own classes.
 *
 * Inside an installation the kernel autoload (php bin/php/ezpgenerateautoloads.php -e) finds every tron class.
 * This loader exists for the moments before the kernel autoload is there: the test suite, the CLI before the
 * kernel boots, and a fresh checkout. It answers only for class names starting with "tron" and maps a class to the
 * file with its lower-case name anywhere under classes/ (the Exponential convention: tronCentreStore lives in
 * troncentrestore.php).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

if ( !class_exists( 'tronAutoload', false ) )
{
    final class tronAutoload
    {
        /** @var array<string,string>|null lower-case class name => file */
        private static $map = null;

        public static function register(): void
        {
            spl_autoload_register( array( __CLASS__, 'load' ) );
        }

        public static function load( string $class ): void
        {
            if ( strncasecmp( $class, 'tron', 4 ) !== 0 )
                return;
            if ( self::$map === null )
                self::$map = self::build( __DIR__ . '/classes' );
            $key = strtolower( $class );
            if ( isset( self::$map[$key] ) )
                require_once self::$map[$key];
        }

        /** @return array<string,string> */
        private static function build( string $dir ): array
        {
            $map = array();
            $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
            foreach ( $it as $file )
            {
                if ( $file->isFile() && substr( $file->getFilename(), -4 ) === '.php' )
                    $map[strtolower( substr( $file->getFilename(), 0, -4 ) )] = $file->getPathname();
            }
            return $map;
        }
    }

    tronAutoload::register();
}
