<?php
/**
 * Setup > tron > Settings: tron.ini as this siteaccess resolves it, read only, with links into the kernel's INI editor
 * (settings/view and settings/edit), which asks "Verify it's you" before a change ([ReauthGroup_system]).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

$current = eZSiteAccess::current();
$tpl = eZTemplate::factory();
$tpl->setVariable( 'groups', ( new tronAdminSettingsData( tronAdmin::context() ) )->groups() );
$tpl->setVariable( 'siteaccess', is_array( $current ) && isset( $current['name'] ) ? (string)$current['name'] : '' );
$tpl->setVariable( 'siteaccesses', array_values( (array)eZINI::instance()->variable( 'SiteAccessSettings', 'AvailableSiteAccessList' ) ) );
$tpl->setVariable( 'view', 'settings' );
$Result = tronAdmin::result( 'settings', $tpl->fetch( 'design:tron/settings.tpl' ), ezpI18n::tr( 'extension/tron', 'Settings' ) );
