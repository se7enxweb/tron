{* Setup > tron > Skills > one skill (tron/skills/(skill)/<name>): manifest, parameters, steps, examples, proof, versions,
   recent runs, and Approve, Revoke, Fork. Variables: skill (tronAdminSkillsData::skill() or null), local_packs, notice,
   error, view.
   Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version). *}
{if $skill|not}
{include uri='design:tron/parts/page_open.tpl' view=$view title='Skills'|i18n( 'extension/tron' )}
<div class="a4-notice is-bad" role="alert"><p>{'There is no such skill.'|i18n( 'extension/tron' )} <a href={'/tron/skills'|ezurl}>{'All skills'|i18n( 'extension/tron' )}</a></p></div>
{include uri='design:tron/parts/page_close.tpl'}
{else}
{include uri='design:tron/parts/page_open.tpl' view=$view title=$skill.title_local intro=$skill.purpose_local}

{if $error}<div class="a4-notice is-bad" role="alert"><p>{$error|wash}</p></div>{/if}
{if $notice}<div class="a4-notice is-ok" role="status"><p>{$notice|wash}</p></div>{/if}

<p><a href={concat( '/tron/skills/(pack)/', $skill.pack )|ezurl}>{'Back to the pack %pack'|i18n( 'extension/tron',, hash( '%pack', $skill.pack ) )|wash}</a></p>

<section class="a4-card" aria-labelledby="tron-skill-facts">
    <div class="a4-card-head a4-tron-program-head">
        <h2 class="a4-card-title" id="tron-skill-facts"><code class="a4-code">{$skill.name|wash}</code> {$skill.version|wash}</h2>
        <span class="a4-badge a4-tron-skill is-{$skill.state|wash}">{$skill.state|wash}</span>
        <div class="a4-card-actions">
        {if or( eq( $skill.state, 'ready' ), and( $skill.draft_id, ne( $skill.state, 'live' ) ) )}
        <form method="post" action={concat( '/tron/skills/(skill)/', $skill.name )|ezurl} class="a4-tron-inline">
            <input type="hidden" name="TronSkill" value="{$skill.name|wash}" />
            {if $skill.draft_id}<input type="hidden" name="TronDraft" value="{$skill.draft_id}" />{/if}
            <button type="submit" class="a4-btn a4-btn-small a4-btn-primary" name="ApproveButton" value="1">{'Prove and approve'|i18n( 'extension/tron' )}</button>
        </form>
        {/if}
        {if eq( $skill.state, 'live' )}
        <form method="post" action={concat( '/tron/skills/(skill)/', $skill.name )|ezurl} class="a4-tron-inline">
            <input type="hidden" name="TronSkill" value="{$skill.name|wash}" />
            <button type="submit" class="a4-btn a4-btn-small" name="RevokeButton" value="1">{'Revoke'|i18n( 'extension/tron' )}</button>
        </form>
        {/if}
        </div>
    </div>
    <div class="a4-card-body">
    {if $skill.reason}<p class="a4-help">{$skill.reason|wash}</p>{/if}
    <dl class="a4-facts">
        <div><dt>{'Use case'|i18n( 'extension/tron' )}</dt><dd>{$skill.use_case|wash}</dd></div>
        <div><dt>{'Pack'|i18n( 'extension/tron' )}</dt><dd>{$skill.pack|wash} ({$skill.origin|wash}{if is_set( $skill.provider_info.version )} {$skill.provider_info.version|wash}{/if})</dd></div>
        <div><dt>{'Author and license'|i18n( 'extension/tron' )}</dt><dd>{$skill.author|wash}, {$skill.license|wash}</dd></div>
        {if $skill.derived_from}<div><dt>{'Derived from'|i18n( 'extension/tron' )}</dt><dd><code class="a4-code">{$skill.derived_from.name|wash}</code> {$skill.derived_from.version|wash} ({$skill.derived_from.license|wash})</dd></div>{/if}
        <div><dt>{'MCP tool and prompt'|i18n( 'extension/tron' )}</dt><dd><code class="a4-code">{$skill.tool|wash}</code></dd></div>
        <div><dt>{'Units'|i18n( 'extension/tron' )}</dt><dd>{foreach $skill.units as $tron_u}<code class="a4-code">{$tron_u|wash}</code> {/foreach}</dd></div>
        <div><dt>{'Tags'|i18n( 'extension/tron' )}</dt><dd>{foreach $skill.tags as $tron_t}<a class="a4-tag" href={concat( '/tron/skills/(tag)/', $tron_t )|ezurl}>{$tron_t|wash}</a> {/foreach}</dd></div>
        <div><dt>{'Manifest hash'|i18n( 'extension/tron' )}</dt><dd><code class="a4-code a4-tron-hash">{$skill.hash|wash}</code></dd></div>
    </dl>
    </div>
</section>

{if $skill.parameters}
<section class="a4-card" aria-labelledby="tron-skill-params">
    <div class="a4-card-head"><h2 class="a4-card-title" id="tron-skill-params">{'Parameters'|i18n( 'extension/tron' )}</h2></div>
    <div class="a4-table-wrap">
    <table class="a4-table">
    <thead><tr><th scope="col">{'Parameter'|i18n( 'extension/tron' )}</th><th scope="col">{'Option'|i18n( 'extension/tron' )}</th><th scope="col">{'Type'|i18n( 'extension/tron' )}</th><th scope="col">{'Default'|i18n( 'extension/tron' )}</th><th scope="col">{'Range'|i18n( 'extension/tron' )}</th><th scope="col">{'Examples'|i18n( 'extension/tron' )}</th></tr></thead>
    <tbody>
    {foreach $skill.parameters as $tron_p}
    <tr><th scope="row"><code class="a4-code">{$tron_p.name|wash}</code><br /><span class="a4-muted">{$tron_p.description|wash}</span></th>
        <td><code class="a4-code">{$tron_p.option|wash}</code></td><td>{$tron_p.type|wash}</td><td><code class="a4-code">{$tron_p.default|wash}</code></td>
        <td class="a4-tron-detail">{$tron_p.range|wash}</td><td><code class="a4-code">{$tron_p.examples|wash}</code></td></tr>
    {/foreach}
    </tbody>
    </table>
    </div>
</section>
{/if}

<section class="a4-card" aria-labelledby="tron-skill-steps">
    <div class="a4-card-head"><h2 class="a4-card-title" id="tron-skill-steps">{'Steps'|i18n( 'extension/tron' )}</h2></div>
    <ol class="a4-tron-timeline">
    {foreach $skill.steps as $tron_s}
        <li class="a4-tron-step"><code class="a4-code">{$tron_s.id|wash}</code> {if eq( $tron_s.kind, 'unit' )}<span class="a4-badge">{'unit'|i18n( 'extension/tron' )}</span>{else}<span class="a4-badge">{'op'|i18n( 'extension/tron' )}</span>{/if} <code class="a4-code">{$tron_s.what|wash}</code>
            <p class="a4-tron-detail"><code class="a4-code">{$tron_s.detail|wash}</code></p></li>
    {/foreach}
    </ol>
</section>

<section class="a4-card" aria-labelledby="tron-skill-examples">
    <div class="a4-card-head"><h2 class="a4-card-title" id="tron-skill-examples">{'Examples'|i18n( 'extension/tron' )}</h2></div>
    <div class="a4-card-body">
    <dl class="a4-facts">
    {foreach $skill.examples as $tron_e}
        <div><dt>{$tron_e.title|wash}</dt><dd><code class="a4-code">{$tron_e.cli|wash}</code><br /><code class="a4-code">{$tron_e.json|wash}</code></dd></div>
    {/foreach}
    </dl>
    </div>
</section>

<section class="a4-card" aria-labelledby="tron-skill-proof">
    <div class="a4-card-head"><h2 class="a4-card-title" id="tron-skill-proof">{'Proof'|i18n( 'extension/tron' )}</h2></div>
    <div class="a4-card-body">
    {if $skill.proof}
    <dl class="a4-facts">
        <div><dt>{'Result'|i18n( 'extension/tron' )}</dt><dd><span class="a4-badge a4-tron-outcome is-{if eq( $skill.proof.result, 'pass' )}ok{else}error{/if}">{$skill.proof.result|wash}</span></dd></div>
        {if is_set( $skill.proof.runs )}{foreach $skill.proof.runs as $tron_mode => $tron_res}<div><dt>{$tron_mode|wash}</dt><dd>{$tron_res|wash}</dd></div>{/foreach}{/if}
        {if is_set( $skill.proof.checks )}{foreach $skill.proof.checks as $tron_c => $tron_v}<div><dt>{$tron_c|wash}</dt><dd>{$tron_v|wash}</dd></div>{/foreach}{/if}
    </dl>
    {else}
    <p class="a4-help">{'The harness has not proven this manifest yet.'|i18n( 'extension/tron' )}</p>
    {/if}
    </div>
</section>

<section class="a4-card" aria-labelledby="tron-skill-fork">
    <div class="a4-card-head"><h2 class="a4-card-title" id="tron-skill-fork">{'Fork'|i18n( 'extension/tron' )}</h2></div>
    <div class="a4-card-body">
    <p class="a4-help">{'A fork is a copy under a new name in a local pack, as a draft. It keeps a link to this skill and its license; the provider\'s pack is never changed.'|i18n( 'extension/tron' )}</p>
    <form method="post" action={concat( '/tron/skills/(skill)/', $skill.name )|ezurl} class="a4-filterbar">
        <input type="hidden" name="TronSkill" value="{$skill.name|wash}" />
        <div class="a4-field"><label class="a4-label" for="tron-fork-name">{'New name'|i18n( 'extension/tron' )}</label>
            <input type="text" id="tron-fork-name" name="TronNewName" class="a4-input" value="local.{$skill.name|wash}" maxlength="57" /></div>
        <div class="a4-field"><label class="a4-label" for="tron-fork-pack">{'Local pack'|i18n( 'extension/tron' )}</label>
            <select id="tron-fork-pack" name="TronLocalPack" class="a4-input">{foreach $local_packs as $tron_lp}<option value="{$tron_lp|wash}">{$tron_lp|wash}</option>{/foreach}</select></div>
        <button type="submit" class="a4-btn a4-btn-small" name="ForkButton" value="1">{'Fork'|i18n( 'extension/tron' )}</button>
    </form>
    </div>
</section>

{if $skill.runs}
<section class="a4-card" aria-labelledby="tron-skill-runs">
    <div class="a4-card-head"><h2 class="a4-card-title" id="tron-skill-runs">{'Recent runs'|i18n( 'extension/tron' )}</h2></div>
    <div class="a4-table-wrap">
    <table class="a4-table">
    <thead><tr><th scope="col">{'When'|i18n( 'extension/tron' )}</th><th scope="col">{'User'|i18n( 'extension/tron' )}</th><th scope="col">{'Transport'|i18n( 'extension/tron' )}</th><th scope="col">{'Outcome'|i18n( 'extension/tron' )}</th><th scope="col" class="a4-tron-num">ms</th></tr></thead>
    <tbody>
    {foreach $skill.runs as $tron_r}
    <tr><td>{$tron_r.at|wash}</td><td>{$tron_r.login|wash}</td><td>{$tron_r.transport|wash}</td><td><span class="a4-badge a4-tron-outcome is-{$tron_r.outcome|wash}">{$tron_r.outcome|wash}</span></td><td class="a4-tron-num">{$tron_r.ms}</td></tr>
    {/foreach}
    </tbody>
    </table>
    </div>
    <p class="a4-help"><a href={concat( '/tron/audit' )|ezurl}>{'Audit'|i18n( 'extension/tron' )}</a></p>
</section>
{/if}

{if $skill.versions}
<section class="a4-card" aria-labelledby="tron-skill-versions">
    <div class="a4-card-head"><h2 class="a4-card-title" id="tron-skill-versions">{'Versions and decisions'|i18n( 'extension/tron' )}</h2></div>
    <div class="a4-table-wrap">
    <table class="a4-table">
    <thead><tr><th scope="col">{'Version'|i18n( 'extension/tron' )}</th><th scope="col">{'State'|i18n( 'extension/tron' )}</th><th scope="col">{'Hash'|i18n( 'extension/tron' )}</th><th scope="col">{'When'|i18n( 'extension/tron' )}</th></tr></thead>
    <tbody>
    {foreach $skill.versions as $tron_v}
    <tr><td>{$tron_v.version|wash}</td><td><span class="a4-badge a4-tron-skill is-{$tron_v.state|wash}">{$tron_v.state|wash}</span></td><td><code class="a4-code">{$tron_v.hash|shorten( 12, '' )|wash}</code></td><td>{$tron_v.changed|l10n( 'shortdatetime' )}</td></tr>
    {/foreach}
    </tbody>
    </table>
    </div>
</section>
{/if}

<section class="a4-card" aria-labelledby="tron-skill-manifest">
    <details class="a4-tron-fold">
        <summary class="a4-card-head"><h2 class="a4-card-title" id="tron-skill-manifest">{'Manifest'|i18n( 'extension/tron' )}</h2></summary>
        <pre class="a4-tron-rules-pre">{$skill.manifest_json|wash}</pre>
    </details>
</section>

{include uri='design:tron/parts/page_close.tpl'}
{/if}
