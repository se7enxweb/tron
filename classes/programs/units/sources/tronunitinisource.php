<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/** The INI settings of the installation, per siteaccess, in eZINI's own load order. */
interface tronUnitIniSource extends tronUnitSource
{
    /** @return string[] the siteaccesses the installation declares */
    public function siteAccesses(): array;

    /** @return string[] the INI files (base names: site, content, explayouts ...) */
    public function files(): array;

    /**
     * One setting (siteaccess "" is the current one): siteaccess (the one used), found, value (the effective value), files (every file of the load order that sets it, in order:
     * path, scope, value), notLoaded (files of inactive extensions that set it: path, scope, reason, value).
     */
    public function where( string $file, string $block, string $setting, string $siteAccess ): array;

    /** @return array siteaccess (the one used; "" asks for the current one), blocks: block => name => array( value, winner (path), kind (plain, list, hash, mixed) ) */
    public function settings( string $file, string $siteAccess ): array;
}
