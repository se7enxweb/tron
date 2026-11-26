<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/** Setup > tron > Settings: tron.ini as this siteaccess resolves it, group => name => value (arrays joined). */
final class tronAdminSettingsData
{
    /** @var tronAdminContext */ private $c;

    public function __construct( tronAdminContext $c )
    {
        $this->c = $c;
    }

    public function groups(): array
    {
        $out = array();
        foreach ( $this->c->settings() as $group => $values )
        {
            foreach ( (array)$values as $name => $value )
                $out[$group][$name] = is_array( $value ) ? implode( ', ', array_filter( array_map( 'strval', $value ), 'strlen' ) ) : (string)$value;
        }
        ksort( $out );
        return $out;
    }
}
