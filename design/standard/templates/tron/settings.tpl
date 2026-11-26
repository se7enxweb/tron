{* Setup > tron > Settings (tron/settings): tron.ini as this siteaccess resolves it, read only. Changes go through the
   kernel's INI editor, which asks "Verify it's you". Variables: groups, siteaccess, siteaccesses, view.
   Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version). *}
{include uri='design:tron/parts/page_open.tpl' view=$view title='Settings'|i18n( 'extension/tron' )
         intro='tron.ini as this siteaccess reads it. Change a value in the INI editor; it asks you to verify it is you before it saves. The HTTP endpoint reads the settings of the siteaccess that serves it.'|i18n( 'extension/tron' )}

<div class="a4-toolbar">
    <a class="a4-btn a4-btn-primary" href={concat( '/settings/view/', $siteaccess, '/tron.ini' )|ezurl}>{'Open tron.ini in the INI editor (%sa)'|i18n( 'extension/tron',, hash( '%sa', $siteaccess ) )|wash}</a>
</div>
<p class="a4-help">{'Other siteaccesses:'|i18n( 'extension/tron' )}
{foreach $siteaccesses as $tron_sa}{if ne( $tron_sa, $siteaccess )} <a href={concat( '/settings/view/', $tron_sa, '/tron.ini' )|ezurl}>{$tron_sa|wash}</a>{/if}{/foreach}</p>

{foreach $groups as $tron_g => $tron_values}
<section class="a4-card a4-tron-settings" aria-labelledby="tron-g-{$tron_g|wash}">
    <div class="a4-card-head"><h2 class="a4-card-title" id="tron-g-{$tron_g|wash}">[{$tron_g|wash}]</h2></div>
    <div class="a4-card-body">
    <dl class="a4-facts">
    {foreach $tron_values as $tron_n => $tron_v}
        <div><dt><code class="a4-code">{$tron_n|wash}</code></dt><dd>{if $tron_v|ne( '' )}<code class="a4-code">{$tron_v|wash}</code>{else}<span class="a4-muted">{'empty'|i18n( 'extension/tron' )}</span>{/if}
            <a class="a4-tron-edit" href={concat( '/settings/edit/', $siteaccess, '/tron.ini/', $tron_g, '/', $tron_n, '/override' )|ezurl} aria-label="{'Edit %setting'|i18n( 'extension/tron',, hash( '%setting', concat( $tron_g, ' ', $tron_n ) ) )|wash}">{'Edit'|i18n( 'extension/tron' )}</a></dd></div>
    {/foreach}
    </dl>
    </div>
</section>
{/foreach}

{include uri='design:tron/parts/page_close.tpl'}
