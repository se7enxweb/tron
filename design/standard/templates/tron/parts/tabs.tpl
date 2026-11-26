{* The tabs between the pages of Setup > tron. Input: $view. A tab the user may not open is left out.
   Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version). *}
{def $tron_tabs = array()}
{if fetch( 'user', 'has_access_to', hash( 'module', 'tron', 'function', 'admin' ) )}
    {set $tron_tabs = $tron_tabs|append( hash( 'key', 'dashboard', 'label', 'Dashboard'|i18n( 'extension/tron' ) ) )}
{/if}
{if fetch( 'user', 'has_access_to', hash( 'module', 'tron', 'function', 'tokens' ) )}
    {set $tron_tabs = $tron_tabs|append( hash( 'key', 'tokens', 'label', 'Tokens'|i18n( 'extension/tron' ) ) )}
{/if}
{if fetch( 'user', 'has_access_to', hash( 'module', 'tron', 'function', 'admin' ) )}
    {set $tron_tabs = $tron_tabs|append( hash( 'key', 'programs', 'label', 'Programs and tools'|i18n( 'extension/tron' ) ) )}
    {set $tron_tabs = $tron_tabs|append( hash( 'key', 'skills', 'label', 'Skills'|i18n( 'extension/tron' ) ) )}
{/if}
{if fetch( 'user', 'has_access_to', hash( 'module', 'tron', 'function', 'audit' ) )}
    {set $tron_tabs = $tron_tabs|append( hash( 'key', 'audit', 'label', 'Audit'|i18n( 'extension/tron' ) ) )}
    {set $tron_tabs = $tron_tabs|append( hash( 'key', 'activity', 'label', 'Activity'|i18n( 'extension/tron' ) ) )}
{/if}
{if fetch( 'user', 'has_access_to', hash( 'module', 'tron', 'function', 'diff' ) )}
    {set $tron_tabs = $tron_tabs|append( hash( 'key', 'diff', 'label', 'Diff'|i18n( 'extension/tron' ) ) )}
{/if}
{if fetch( 'user', 'has_access_to', hash( 'module', 'tron', 'function', 'admin' ) )}
    {set $tron_tabs = $tron_tabs|append( hash( 'key', 'rules', 'label', 'Agent rules'|i18n( 'extension/tron' ) ) )}
    {set $tron_tabs = $tron_tabs|append( hash( 'key', 'settings', 'label', 'Settings'|i18n( 'extension/tron' ) ) )}
{/if}
<nav class="a4-tron-nav" aria-label="tron">
<ul class="a4-tabs">
    {foreach $tron_tabs as $tron_tab}
    <li><a href={concat( '/tron/', $tron_tab.key )|ezurl}{if eq( $view, $tron_tab.key )} class="current" aria-current="page"{/if}>{$tron_tab.label|wash}</a></li>
    {/foreach}
</ul>
</nav>
{undef $tron_tabs}
