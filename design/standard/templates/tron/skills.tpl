{* Setup > tron > Skills (tron/skills): the packs with their provider and state, the skills with filters, the drafts.
   Variables: data (tronAdminSkillsData::data()), base, bases (view paths without one filter), notice, error, view.
   Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version). *}
{include uri='design:tron/parts/page_open.tpl' view=$view title='Skills'|i18n( 'extension/tron' )
         intro='A skill is a reviewed chain of units, written as data. It runs as the person who calls it, every unit checked against their policies and audited. A skill is offered to agents, as a prompt and on the command line only when the harness has proven it and an administrator has approved it here.'|i18n( 'extension/tron' )}

{if $error}<div class="a4-notice is-bad" role="alert"><p>{$error|wash}</p></div>{/if}
{if $notice}<div class="a4-notice is-ok" role="status"><p>{$notice|wash} {'It takes effect on the next request and the next stdio start.'|i18n( 'extension/tron' )}</p></div>{/if}

<ul class="a4-figures">
    <li class="a4-figure"><strong>{$data.counts.total}</strong><span>{'Skills'|i18n( 'extension/tron' )}</span></li>
    {foreach $data.states as $tron_s}
    <li class="a4-figure"><strong>{if is_set( $data.counts.states[$tron_s] )}{$data.counts.states[$tron_s]}{else}0{/if}</strong><span>{$tron_s|wash}</span></li>
    {/foreach}
</ul>

<section class="a4-card" aria-labelledby="tron-packs-title">
    <div class="a4-card-head"><h2 class="a4-card-title" id="tron-packs-title">{'Packs'|i18n( 'extension/tron' )}</h2></div>
    <div class="a4-table-wrap">
    <table class="a4-table a4-tron-packs">
    <thead><tr>
        <th scope="col">{'Pack'|i18n( 'extension/tron' )}</th><th scope="col">{'Provided by'|i18n( 'extension/tron' )}</th>
        <th scope="col">{'License'|i18n( 'extension/tron' )}</th><th scope="col" class="a4-tron-num">{'Skills'|i18n( 'extension/tron' )}</th>
        <th scope="col">{'State'|i18n( 'extension/tron' )}</th><th scope="col"><span class="a4-sr-only">{'Actions'|i18n( 'extension/tron' )}</span></th>
    </tr></thead>
    <tbody>
    {foreach $data.packs as $tron_p}
    <tr>
        <th scope="row"><a href={concat( '/', $bases.pack, '/(pack)/', $tron_p.id )|ezurl}><code class="a4-code">{$tron_p.id|wash}</code></a> {$tron_p.version|wash}<br /><span class="a4-muted">{$tron_p.title|wash}</span></th>
        <td>{$tron_p.origin|wash}{if $tron_p.provider.version} {$tron_p.provider.version|wash}{/if}{if $tron_p.for_extension}<br /><span class="a4-muted">{'for %ext'|i18n( 'extension/tron',, hash( '%ext', $tron_p.for_extension ) )|wash}</span>{/if}</td>
        <td>{$tron_p.license|wash}{if and( $tron_p.provider.license, ne( $tron_p.origin, 'tron' ) )}<br /><span class="a4-muted">{$tron_p.provider.license|wash}</span>{/if}</td>
        <td class="a4-tron-num">{$tron_p.skills}</td>
        <td><span class="a4-badge a4-tron-pack is-{$tron_p.state|wash}">{$tron_p.state|wash}</span>{if $tron_p.reason}<p class="a4-tron-detail">{$tron_p.reason|wash}</p>{/if}</td>
        <td>
            {if or( eq( $tron_p.state, 'loaded' ), eq( $tron_p.state, 'switched-off' ) )}
            <form method="post" action={concat( '/', $base )|ezurl} class="a4-tron-inline">
                <input type="hidden" name="TronPack" value="{$tron_p.id|wash}" />
                <input type="hidden" name="TronEnable" value="{if eq( $tron_p.state, 'switched-off' )}1{else}0{/if}" />
                <button type="submit" class="a4-btn a4-btn-small" name="PackSwitchButton" value="1">{if eq( $tron_p.state, 'switched-off' )}{'Switch on'|i18n( 'extension/tron' )}{else}{'Switch off'|i18n( 'extension/tron' )}{/if}</button>
            </form>
            {/if}
            {if eq( $tron_p.state, 'loaded' )}
            <form method="post" action={concat( '/', $base )|ezurl} class="a4-tron-inline">
                <input type="hidden" name="TronPack" value="{$tron_p.id|wash}" />
                <button type="submit" class="a4-btn a4-btn-small a4-btn-primary" name="ApprovePackButton" value="1">{'Approve ready skills'|i18n( 'extension/tron' )}</button>
            </form>
            {/if}
        </td>
    </tr>
    {/foreach}
    </tbody>
    </table>
    </div>
</section>

<div class="a4-filterbar a4-tron-filter">
    <form method="post" action={concat( '/', $bases.q )|ezurl} class="a4-tron-search">
        <label class="a4-label" for="tron-skill-q">{'Search'|i18n( 'extension/tron' )}</label>
        <input type="search" id="tron-skill-q" name="TronSkillQuery" class="a4-input" value="{if is_set( $data.filters.q )}{$data.filters.q|wash}{/if}" maxlength="100" />
        <button type="submit" class="a4-btn a4-btn-small" name="SearchButton" value="1">{'Search'|i18n( 'extension/tron' )}</button>
    </form>
    <div class="a4-field a4-field-wide">
        <span class="a4-label">{'State'|i18n( 'extension/tron' )}</span>
        <ul class="a4-chips">
            <li><a class="a4-chip{if is_set( $data.filters.state )|not} is-current{/if}" href={concat( '/', $bases.state )|ezurl}><span>{'All'|i18n( 'extension/tron' )}</span></a></li>
            {foreach $data.states as $tron_s}
            <li><a class="a4-chip{if and( is_set( $data.filters.state ), eq( $data.filters.state, $tron_s ) )} is-current{/if}" href={concat( '/', $bases.state, '/(state)/', $tron_s )|ezurl}><span>{$tron_s|wash}</span></a></li>
            {/foreach}
        </ul>
    </div>
    <div class="a4-field a4-field-wide">
        <span class="a4-label">{'Tags'|i18n( 'extension/tron' )}</span>
        <ul class="a4-chips">
            <li><a class="a4-chip{if is_set( $data.filters.tag )|not} is-current{/if}" href={concat( '/', $bases.tag )|ezurl}><span>{'All'|i18n( 'extension/tron' )}</span></a></li>
            {foreach $data.tags as $tron_t => $tron_n}
            <li><a class="a4-chip{if and( is_set( $data.filters.tag ), eq( $data.filters.tag, $tron_t ) )} is-current{/if}" href={concat( '/', $bases.tag, '/(tag)/', $tron_t )|ezurl}><span>{$tron_t|wash} ({$tron_n})</span></a></li>
            {/foreach}
        </ul>
    </div>
    {if is_set( $data.filters.pack )}
    <p class="a4-help">{'Pack %pack.'|i18n( 'extension/tron',, hash( '%pack', $data.filters.pack ) )|wash} <a href={concat( '/', $bases.pack )|ezurl}>{'All packs'|i18n( 'extension/tron' )}</a></p>
    {/if}
</div>

<section class="a4-card" aria-labelledby="tron-skills-title">
    <div class="a4-card-head"><h2 class="a4-card-title" id="tron-skills-title">{'%n skills'|i18n( 'extension/tron',, hash( '%n', $data.total ) )|wash}</h2></div>
    {if $data.skills}
    <div class="a4-table-wrap">
    <table class="a4-table a4-tron-skills">
    <thead><tr>
        <th scope="col">{'Skill'|i18n( 'extension/tron' )}</th><th scope="col">{'Pack'|i18n( 'extension/tron' )}</th>
        <th scope="col">{'Units'|i18n( 'extension/tron' )}</th><th scope="col">{'State'|i18n( 'extension/tron' )}</th>
    </tr></thead>
    <tbody>
    {foreach $data.skills as $tron_k}
    <tr>
        <th scope="row"><a href={concat( '/tron/skills/(skill)/', $tron_k.name )|ezurl}><code class="a4-code">{$tron_k.name|wash}</code></a> {$tron_k.version|wash}<br /><span class="a4-muted">{$tron_k.title_local|wash}</span></th>
        <td>{$tron_k.pack|wash}</td>
        <td>{foreach $tron_k.units as $tron_u}<code class="a4-code">{$tron_u|wash}</code> {/foreach}</td>
        <td><span class="a4-badge a4-tron-skill is-{$tron_k.state|wash}">{$tron_k.state|wash}</span>{if and( $tron_k.reason, ne( $tron_k.state, 'ready' ) )}<p class="a4-tron-detail">{$tron_k.reason|wash}</p>{/if}</td>
    </tr>
    {/foreach}
    </tbody>
    </table>
    </div>
    {else}
    <div class="a4-card-body"><p class="a4-help">{'No skill matches.'|i18n( 'extension/tron' )}</p></div>
    {/if}
</section>

{if gt( $data.total, $data.page )}
<div class="a4-pager">
    {if gt( $data.offset, 0 )}<a class="a4-btn a4-btn-small" href={concat( '/', $bases.offset, '/(offset)/', max( 0, sub( $data.offset, $data.page ) ) )|ezurl}>{'Previous'|i18n( 'extension/tron' )}</a>{/if}
    <span class="a4-muted">{'%from to %to of %total'|i18n( 'extension/tron',, hash( '%from', sum( $data.offset, 1 ), '%to', min( $data.total, sum( $data.offset, $data.page ) ), '%total', $data.total ) )|wash}</span>
    {if lt( sum( $data.offset, $data.page ), $data.total )}<a class="a4-btn a4-btn-small" href={concat( '/', $bases.offset, '/(offset)/', sum( $data.offset, $data.page ) )|ezurl}>{'Next'|i18n( 'extension/tron' )}</a>{/if}
</div>
{/if}

{if $data.drafts}
<section class="a4-card" aria-labelledby="tron-drafts-title">
    <div class="a4-card-head"><h2 class="a4-card-title" id="tron-drafts-title">{'Drafts and forks'|i18n( 'extension/tron' )}</h2></div>
    <div class="a4-table-wrap">
    <table class="a4-table">
    <thead><tr><th scope="col">{'Skill'|i18n( 'extension/tron' )}</th><th scope="col">{'Pack'|i18n( 'extension/tron' )}</th><th scope="col">{'Derived from'|i18n( 'extension/tron' )}</th><th scope="col">{'State'|i18n( 'extension/tron' )}</th><th scope="col"><span class="a4-sr-only">{'Actions'|i18n( 'extension/tron' )}</span></th></tr></thead>
    <tbody>
    {foreach $data.drafts as $tron_d}
    <tr>
        <th scope="row"><code class="a4-code">{$tron_d.name|wash}</code> {$tron_d.version|wash}</th>
        <td>{$tron_d.pack|wash}</td>
        <td>{if $tron_d.derived_from}<code class="a4-code">{$tron_d.derived_from|wash}</code>{else}<span class="a4-muted">{'new'|i18n( 'extension/tron' )}</span>{/if}</td>
        <td><span class="a4-badge a4-tron-skill is-{$tron_d.state|wash}">{$tron_d.state|wash}</span></td>
        <td><form method="post" action={concat( '/', $base )|ezurl} class="a4-tron-inline">
            <input type="hidden" name="TronSkill" value="{$tron_d.name|wash}" /><input type="hidden" name="TronDraft" value="{$tron_d.id}" />
            <button type="submit" class="a4-btn a4-btn-small a4-btn-primary" name="ApproveButton" value="1">{'Prove and approve'|i18n( 'extension/tron' )}</button>
        </form></td>
    </tr>
    {/foreach}
    </tbody>
    </table>
    </div>
</section>
{/if}

<p class="a4-help">{'How skills, packs and remixing work:'|i18n( 'extension/tron' )} doc/skills.md, doc/extension-conventions.md</p>

{include uri='design:tron/parts/page_close.tpl'}
