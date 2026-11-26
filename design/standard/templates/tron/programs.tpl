{* Setup > tron > Programs and tools (tron/programs): every program and tool, its state, who may call it (the policy and
   the roles that grant it, with links to the roles) and a switch. Variables: data (tronAdminProgramsData::data()), notice,
   error, view.
   Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version). *}
{include uri='design:tron/parts/page_open.tpl' view=$view title='Programs and tools'|i18n( 'extension/tron' )
         intro='A program is a set of tools that tron found in an extension. A switched-off program or tool is not offered to any agent; the core program tron cannot be switched off. Who may call a tool is decided by the roles that grant its policy.'|i18n( 'extension/tron' )}

{if $error}<div class="a4-notice is-bad" role="alert"><p>{$error|wash}</p></div>{/if}
{if $notice}<div class="a4-notice is-ok" role="status"><p>{$notice|wash} {'It takes effect on the next request and the next stdio start.'|i18n( 'extension/tron' )}</p></div>{/if}

<ul class="a4-figures">
    <li class="a4-figure"><strong>{$data.programs|count}</strong><span>{'Programs'|i18n( 'extension/tron' )}</span></li>
    <li class="a4-figure"><strong>{$data.counts.on} / {$data.counts.tools}</strong><span>{'Tools switched on'|i18n( 'extension/tron' )}</span></li>
    <li class="a4-figure"><strong>{$data.counts.write}</strong><span>{'Write tools'|i18n( 'extension/tron' )}</span></li>
    <li class="a4-figure{if $data.counts.ungranted|gt( 0 )} is-warn{/if}"><strong>{$data.counts.ungranted}</strong><span>{'Tools no role grants'|i18n( 'extension/tron' )}</span></li>
</ul>

{foreach $data.programs as $tron_p}
<section class="a4-card a4-tron-program" aria-labelledby="tron-p-{$tron_p.name|wash}">
    <div class="a4-card-head a4-tron-program-head">
        <h2 class="a4-card-title" id="tron-p-{$tron_p.name|wash}"><code class="a4-code">{$tron_p.name|wash}</code> {$tron_p.version|wash}</h2>
        <span class="a4-badge a4-tron-state is-{$tron_p.state|wash}">{$tron_p.state|wash}</span>
        {if $tron_p.core|not}
        <form method="post" action={'/tron/programs'|ezurl} class="a4-tron-inline a4-card-actions">
            <input type="hidden" name="TronKind" value="program" />
            <input type="hidden" name="TronName" value="{$tron_p.name|wash}" />
            {if $tron_p.switched_off}
            <input type="hidden" name="TronEnable" value="1" />
            <button type="submit" class="a4-btn a4-btn-small a4-btn-primary" name="SwitchButton" value="1">{'Switch on'|i18n( 'extension/tron' )}</button>
            {else}
            <input type="hidden" name="TronEnable" value="0" />
            <button type="submit" class="a4-btn a4-btn-small" name="SwitchButton" value="1">{'Switch off'|i18n( 'extension/tron' )}</button>
            {/if}
        </form>
        {/if}
    </div>
    <div class="a4-card-body">
    <p class="a4-help">{'From %origin.'|i18n( 'extension/tron',, hash( '%origin', $tron_p.origin ) )|wash}{if $tron_p.reason} {$tron_p.reason|wash}{/if}{if is_set( $tron_p.description )} {$tron_p.description|wash}{/if}</p>
    </div>
    {if $tron_p.tools_list}
    <div class="a4-table-wrap">
    <table class="a4-table a4-tron-tools">
    <thead><tr>
        <th scope="col">{'Tool'|i18n( 'extension/tron' )}</th><th scope="col">{'Kind'|i18n( 'extension/tron' )}</th>
        <th scope="col">{'Policy'|i18n( 'extension/tron' )}</th><th scope="col">{'Roles that grant it'|i18n( 'extension/tron' )}</th>
        <th scope="col">{'State'|i18n( 'extension/tron' )}</th><th scope="col"><span class="a4-sr-only">{'Switch'|i18n( 'extension/tron' )}</span></th>
    </tr></thead>
    <tbody>
    {foreach $tron_p.tools_list as $tron_t}
    <tr>
        <th scope="row"><code class="a4-code">{$tron_t.name|wash}</code><br /><span class="a4-muted">{$tron_t.title|wash}</span></th>
        <td>{if $tron_t.write}<span class="a4-badge a4-tron-kind is-write">{'write'|i18n( 'extension/tron' )}</span>{else}<span class="a4-badge">{'read'|i18n( 'extension/tron' )}</span>{/if}</td>
        <td><code class="a4-code">{$tron_t.policy|wash}</code></td>
        <td>{if $tron_t.roles}<ul class="a4-tron-roles">{foreach $tron_t.roles as $tron_r}<li><a href={concat( '/role/view/', $tron_r.id )|ezurl}>{$tron_r.name|wash}</a>{if $tron_r.limited} <span class="a4-muted">({'limited'|i18n( 'extension/tron' )})</span>{/if}</li>{/foreach}</ul>{else}<span class="a4-muted">{'none yet'|i18n( 'extension/tron' )}</span>{/if}</td>
        <td>{if $tron_t.enabled}<span class="a4-badge a4-tron-state is-loaded">{'on'|i18n( 'extension/tron' )}</span>{else}<span class="a4-badge a4-tron-state is-disabled">{'off'|i18n( 'extension/tron' )}</span>{/if}</td>
        <td>{if and( $tron_t.core|not, $tron_p.switched_off|not )}
            <form method="post" action={'/tron/programs'|ezurl} class="a4-tron-inline">
                <input type="hidden" name="TronKind" value="tool" />
                <input type="hidden" name="TronName" value="{$tron_t.name|wash}" />
                <input type="hidden" name="TronEnable" value="{if $tron_t.enabled}0{else}1{/if}" />
                <button type="submit" class="a4-btn a4-btn-small" name="SwitchButton" value="1" aria-label="{if $tron_t.enabled}{'Switch off %tool'|i18n( 'extension/tron',, hash( '%tool', $tron_t.name ) )|wash}{else}{'Switch on %tool'|i18n( 'extension/tron',, hash( '%tool', $tron_t.name ) )|wash}{/if}">{if $tron_t.enabled}{'Switch off'|i18n( 'extension/tron' )}{else}{'Switch on'|i18n( 'extension/tron' )}{/if}</button>
            </form>{/if}</td>
    </tr>
    {/foreach}
    </tbody>
    </table>
    </div>
    {/if}
</section>
{/foreach}

{if $data.unavailable}
<section class="a4-card" aria-labelledby="tron-unavailable-title">
    <div class="a4-card-head"><h2 class="a4-card-title" id="tron-unavailable-title">{'Tools not offered here'|i18n( 'extension/tron' )}</h2></div>
    <div class="a4-card-body">
    <dl class="a4-facts">
    {foreach $data.unavailable as $tron_n => $tron_why}
        <div><dt><code class="a4-code">{$tron_n|wash}</code></dt><dd>{$tron_why|wash}</dd></div>
    {/foreach}
    </dl>
    </div>
</section>
{/if}

<p class="a4-help">{'Grant a policy in a role:'|i18n( 'extension/tron' )} <a href={'/role/list'|ezurl}>{'Roles and policies'|i18n( 'extension/tron' )}</a></p>

{include uri='design:tron/parts/page_close.tpl'}
