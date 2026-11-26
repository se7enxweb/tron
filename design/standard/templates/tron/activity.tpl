{* Setup > tron > Activity (tron/activity): the activity journal. $mode list: sessions with filters; session: one session
   ((session)/<id>) with its story, timeline (in the order it happened), diffs, jobs and the hash chain check; object:
   everything tron did to one object ((object)/<kind:ref>). Shared components only (.a4-*), plus .a4-tron-* from
   stylesheets/modules/tron.css.
   Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version). *}
{include uri='design:tron/parts/page_open.tpl' view=$view title='Activity'|i18n( 'extension/tron' )
         intro='What tron did, as a story: every MCP session and background job with who, which client, why (the intent the agent gave), each step with what it touched and what came back, and the diff of every write. Each session is chained by a hash, so a changed or removed step shows.'|i18n( 'extension/tron' )}

{if $missing}<div class="a4-notice is-bad" role="alert"><p>{'The journal tables are missing. Run tron.php install on the server.'|i18n( 'extension/tron' )}</p></div>
{elseif $enabled|not}<div class="a4-notice is-warn"><p>{'The activity journal is switched off (tron.ini [JournalSettings] Enabled). What it recorded before stays readable here.'|i18n( 'extension/tron' )}</p></div>{/if}

{if eq( $mode, 'session' )}
{* ---------------------------------------------------------------------------------------------- one session *}
<p><a href={'/tron/activity'|ezurl}>{'All sessions'|i18n( 'extension/tron' )}</a></p>
{if $session|not}
<p class="a4-empty">{'No such session.'|i18n( 'extension/tron' )}</p>
{else}
<section class="a4-card a4-tron-session" aria-labelledby="tron-session-title">
    <div class="a4-card-head">
        <h2 class="a4-card-title" id="tron-session-title">{'Session %id'|i18n( 'extension/tron',, hash( '%id', $session.id ) )|wash}{if $session.title}: {$session.title|wash}{/if}</h2>
        <div class="a4-card-actions">
            {foreach $formats as $tron_f}
            <a class="a4-btn a4-btn-small" href={concat( '/', $export_base, $tron_f )|ezurl} download>{'Export %format'|i18n( 'extension/tron',, hash( '%format', $tron_f ) )|wash}</a>
            {/foreach}
        </div>
    </div>
    <div class="a4-card-body">
    {if $session.intent}<p class="a4-intro">{$session.intent|wash}</p>{/if}
    <dl class="a4-facts">
        <div><dt>{'User'|i18n( 'extension/tron' )}</dt><dd>{if $session.login}<a href={concat( '/tron/activity/(user)/', $session.login|view_parameter )|ezurl}>{$session.login|wash}</a>{else}&ndash;{/if}{if $session.token_id|gt( 0 )} <span class="a4-muted">{'token #%id'|i18n( 'extension/tron',, hash( '%id', $session.token_id ) )|wash}</span>{/if}</dd></div>
        <div><dt>{'Client'|i18n( 'extension/tron' )}</dt><dd>{if $session.client}{$session.client|wash}{else}<span class="a4-muted">&ndash;</span>{/if}</dd></div>
        <div><dt>{'Kind'|i18n( 'extension/tron' )}</dt><dd>{$session.kind|wash}, {$session.transport|wash}</dd></div>
        <div><dt>{'Node'|i18n( 'extension/tron' )}</dt><dd>{$session.node|wash}</dd></div>
        <div><dt>{'Started'|i18n( 'extension/tron' )}</dt><dd><time datetime="{$session.started|datetime( 'custom', '%Y-%m-%dT%H:%i:%s' )}">{$session.started|l10n( 'shortdatetime' )}</time></dd></div>
        <div><dt>{'Ended'|i18n( 'extension/tron' )}</dt><dd>{if $session.ended|gt( 0 )}<time datetime="{$session.ended|datetime( 'custom', '%Y-%m-%dT%H:%i:%s' )}">{$session.ended|l10n( 'shortdatetime' )}</time> <span class="a4-meta">{$session.end_reason|wash}</span>{else}<span class="a4-badge a4-tron-outcome is-started">{'open'|i18n( 'extension/tron' )}</span>{/if}</dd></div>
        <div><dt>{'Steps'|i18n( 'extension/tron' )}</dt><dd>{$session.steps}, {'%w writes, %r refused, %e errors'|i18n( 'extension/tron',, hash( '%w', $session.writes, '%r', $session.refused, '%e', $session.errors ) )|wash}</dd></div>
        {if $parent}<div><dt>{'Started by'|i18n( 'extension/tron' )}</dt><dd><a href={concat( '/tron/activity/(session)/', $parent.id )|ezurl}>{'Session %id'|i18n( 'extension/tron',, hash( '%id', $parent.id ) )|wash}</a></dd></div>{/if}
    </dl>
    </div>
</section>

{if $verify.ok}
<div class="a4-notice is-ok"><p>{'The hash chain of its %n steps is intact.'|i18n( 'extension/tron',, hash( '%n', $verify.steps ) )|wash} <code class="a4-code a4-tron-hash">{$session.head_hash|wash}</code></p></div>
{else}
<div class="a4-notice is-bad" role="alert"><p><strong class="a4-notice-title">{'The hash chain is broken at step %n.'|i18n( 'extension/tron',, hash( '%n', $verify.broken_at ) )|wash}</strong>{$verify.reason|wash}</p></div>
{/if}

<section class="a4-section" aria-labelledby="tron-story-title">
    <div class="a4-section-head"><h2 class="a4-h" id="tron-story-title">{'Story'|i18n( 'extension/tron' )}</h2></div>
    <div class="a4-tron-narrative">
    {foreach $narrative as $tron_p}<p>{$tron_p|wash}</p>{/foreach}
    </div>
</section>

{if $children}
<section class="a4-section" aria-labelledby="tron-jobs-title">
    <div class="a4-section-head"><h2 class="a4-h" id="tron-jobs-title">{'Background jobs it started'|i18n( 'extension/tron' )}</h2></div>
    <ul class="a4-tron-jobs">
    {foreach $children as $tron_c}
        <li><a href={concat( '/tron/activity/(session)/', $tron_c.id )|ezurl}>{$tron_c.title|wash}</a> <span class="a4-badge a4-tron-outcome is-{$tron_c.status|wash}">{$tron_c.status|wash}</span></li>
    {/foreach}
    </ul>
</section>
{/if}

<section class="a4-section" aria-labelledby="tron-timeline-title">
    <div class="a4-section-head"><h2 class="a4-h" id="tron-timeline-title">{'Timeline'|i18n( 'extension/tron' )}</h2><p>{'In the order it happened. A write shows its outcome; the step announced before it ran is folded into it.'|i18n( 'extension/tron' )}</p></div>
    <ol class="a4-timeline a4-tron-timeline">
    {foreach $timeline as $tron_r}
        <li class="{if or( eq( $tron_r.outcome, 'error' ) )}is-bad{elseif eq( $tron_r.outcome, 'refused' )}is-warn{elseif or( $tron_r.write, eq( $tron_r.outcome, 'dry-run' ) )}is-info{elseif eq( $tron_r.outcome, 'ok' )}is-ok{/if}" id="step-{$tron_r.seq}">
            <time class="a4-timeline-time" datetime="{$tron_r.created|datetime( 'custom', '%Y-%m-%dT%H:%i:%s' )}">#{$tron_r.seq} {$tron_r.time|wash}</time>
            <div class="a4-timeline-body">
                <span class="a4-tron-step">{$tron_r.label|wash}</span>
                {if $tron_r.write} <span class="a4-badge a4-tron-kind is-write">{'write'|i18n( 'extension/tron' )}</span>{/if}
                {if $tron_r.outcome} <span class="a4-badge a4-tron-outcome is-{$tron_r.outcome|wash}">{$tron_r.outcome|wash}{if $tron_r.code} &middot; {$tron_r.code|wash}{/if}</span>{/if}
                {if eq( $tron_r.kind, 'call' )} <span class="a4-muted">{'%ms ms'|i18n( 'extension/tron',, hash( '%ms', $tron_r.duration_ms ) )|wash}</span>{/if}
                {if $tron_r.objects_links}
                <div class="a4-timeline-meta">
                    {foreach $tron_r.objects_links as $tron_o}<a class="a4-tag a4-tron-object{if eq( $tron_o.action, 'write' )} is-write{/if}" href={concat( '/', $tron_o.url )|ezurl}>{$tron_o.label|wash}</a> {/foreach}
                </div>
                {/if}
                {if and( eq( $tron_r.kind, 'call' ), $tron_r.result )}<div class="a4-timeline-meta a4-tron-detail">{$tron_r.result|wash}</div>{/if}
                {if or( eq( $tron_r.kind, 'call' ), eq( $tron_r.kind, 'checkpoint' ), eq( $tron_r.kind, 'result' ) )}{if and( $tron_r.input_pretty, ne( $tron_r.input_pretty, '[]' ) )}
                <details class="a4-fold a4-tron-fold"><summary>{'Arguments'|i18n( 'extension/tron' )}</summary><div class="a4-fold-body"><pre class="a4-pre">{$tron_r.input_pretty|wash}</pre></div></details>
                {/if}{/if}
                {if or( $tron_r.diff_pretty, $tron_r.diff_ref )}
                <details class="a4-fold a4-tron-fold"{if $tron_r.write} open="open"{/if}><summary>{'Change'|i18n( 'extension/tron' )}{if $tron_r.diff_ref} <code class="a4-code">{$tron_r.diff_ref|wash}</code>{/if}</summary>
                    <div class="a4-fold-body">{if $tron_r.diff_pretty}<pre class="a4-pre">{$tron_r.diff_pretty|wash}</pre>{else}<p class="a4-muted">{'Only the reference was kept.'|i18n( 'extension/tron' )}</p>{/if}</div>
                </details>
                {/if}
            </div>
        </li>
    {/foreach}
    </ol>
</section>
{/if}

{elseif eq( $mode, 'object' )}
{* ---------------------------------------------------------------------------------------------- one object *}
<p><a href={'/tron/activity'|ezurl}>{'All sessions'|i18n( 'extension/tron' )}</a></p>
<h2 class="a4-h">{'Everything tron did to %object'|i18n( 'extension/tron',, hash( '%object', $object_label ) )|wash}</h2>
{if $valid|not}<div class="a4-notice is-warn"><p>{'Name an object as kind:ref, for example node:123, object:57, layout:4 or ini:site.ini[SiteSettings]IndexPage.'|i18n( 'extension/tron' )}</p></div>{/if}
<div class="a4-toolbar a4-tron-livebar"><span class="a4-muted">{'%first to %last of %total'|i18n( 'extension/tron',, hash( '%first', $first, '%last', $last, '%total', $total ) )|wash}</span></div>
<div class="a4-table-wrap">
<table class="a4-table a4-tron-activity">
<thead><tr>
    <th scope="col">{'Time'|i18n( 'extension/tron' )}</th><th scope="col">{'User'|i18n( 'extension/tron' )}</th>
    <th scope="col">{'Step'|i18n( 'extension/tron' )}</th><th scope="col">{'Outcome'|i18n( 'extension/tron' )}</th>
    <th scope="col">{'Objects'|i18n( 'extension/tron' )}</th><th scope="col">{'Session'|i18n( 'extension/tron' )}</th>
</tr></thead>
<tbody>
{foreach $steps as $tron_s}
<tr>
    <td><time datetime="{$tron_s.created|datetime( 'custom', '%Y-%m-%dT%H:%i:%s' )}">{$tron_s.created|l10n( 'shortdatetime' )}</time></td>
    <td>{$tron_s.login|wash}</td>
    <td>{$tron_s.label|wash}{if $tron_s.write_call} <span class="a4-badge a4-tron-kind is-write">{'write'|i18n( 'extension/tron' )}</span>{/if}</td>
    <td>{if $tron_s.outcome}<span class="a4-badge a4-tron-outcome is-{$tron_s.outcome|wash}">{$tron_s.outcome|wash}</span>{/if}</td>
    <td class="a4-tron-detail">{$tron_s.objects_text|wash}</td>
    <td><a href={concat( '/tron/activity/(session)/', $tron_s.session_id )|ezurl}#step-{$tron_s.seq}>{'Session %id, step %n'|i18n( 'extension/tron',, hash( '%id', $tron_s.session_id, '%n', $tron_s.seq ) )|wash}</a>{if $tron_s.title} <span class="a4-muted">{$tron_s.title|wash}</span>{/if}</td>
</tr>
{/foreach}
</tbody>
</table>
</div>
{if $steps|not}<p class="a4-empty">{'Nothing recorded for this object.'|i18n( 'extension/tron' )}</p>{/if}
{if or( $prev_url, $next_url )}
<div class="a4-pager">
    {if $prev_url}<a class="a4-btn a4-btn-small" href={concat( '/', $prev_url )|ezurl} rel="prev">{'Newer'|i18n( 'extension/tron' )}</a>{/if}
    {if $next_url}<a class="a4-btn a4-btn-small" href={concat( '/', $next_url )|ezurl} rel="next">{'Older'|i18n( 'extension/tron' )}</a>{/if}
</div>
{/if}

{else}
{* ---------------------------------------------------------------------------------------------- sessions *}
<div class="a4-filterbar a4-tron-filter">
    <div class="a4-field">
        <span class="a4-label">{'Outcome'|i18n( 'extension/tron' )}</span>
        <ul class="a4-chips">
            <li><a class="a4-chip{if is_set( $filters.outcome )|not} is-current{/if}" href={concat( '/', $bases.outcome )|ezurl}><span>{'All'|i18n( 'extension/tron' )}</span></a></li>
            {foreach $outcomes as $tron_o}
            <li><a class="a4-chip{if and( is_set( $filters.outcome ), eq( $filters.outcome, $tron_o ) )} is-current{/if}" href={concat( '/', $bases.outcome, '/(outcome)/', $tron_o )|ezurl}><span>{$tron_o|wash}</span></a></li>
            {/foreach}
        </ul>
    </div>
    <div class="a4-field">
        <span class="a4-label">{'Transport'|i18n( 'extension/tron' )}</span>
        <ul class="a4-chips">
            <li><a class="a4-chip{if is_set( $filters.transport )|not} is-current{/if}" href={concat( '/', $bases.transport )|ezurl}><span>{'All'|i18n( 'extension/tron' )}</span></a></li>
            {foreach $transports as $tron_t}
            <li><a class="a4-chip{if and( is_set( $filters.transport ), eq( $filters.transport, $tron_t ) )} is-current{/if}" href={concat( '/', $bases.transport, '/(transport)/', $tron_t )|ezurl}><span>{$tron_t|wash}</span></a></li>
            {/foreach}
        </ul>
    </div>
    <div class="a4-field">
        <span class="a4-label">{'Kind'|i18n( 'extension/tron' )}</span>
        <ul class="a4-chips">
            <li><a class="a4-chip{if is_set( $filters.kind )|not} is-current{/if}" href={concat( '/', $bases.kind )|ezurl}><span>{'All'|i18n( 'extension/tron' )}</span></a></li>
            <li><a class="a4-chip{if and( is_set( $filters.kind ), eq( $filters.kind, 'mcp' ) )} is-current{/if}" href={concat( '/', $bases.kind, '/(kind)/mcp' )|ezurl}><span>{'Sessions'|i18n( 'extension/tron' )}</span></a></li>
            <li><a class="a4-chip{if and( is_set( $filters.kind ), eq( $filters.kind, 'job' ) )} is-current{/if}" href={concat( '/', $bases.kind, '/(kind)/job' )|ezurl}><span>{'Background jobs'|i18n( 'extension/tron' )}</span></a></li>
        </ul>
    </div>
    <form method="post" action={concat( '/', $bases.all )|ezurl} class="a4-field a4-field-wide a4-tron-activityform">
        <span class="a4-tron-userfilter-row">
            <span class="a4-tron-formcell"><label class="a4-label" for="tron-filter-user">{'User (login)'|i18n( 'extension/tron' )}</label>
            <input class="a4-input" type="text" id="tron-filter-user" name="TronFilterUser" value="{if is_set( $filters.user )}{$filters.user|wash}{/if}" maxlength="150" autocomplete="off" /></span>
            <span class="a4-tron-formcell"><label class="a4-label" for="tron-filter-tool">{'Tool'|i18n( 'extension/tron' )}</label>
            <input class="a4-input" type="text" id="tron-filter-tool" name="TronFilterTool" value="{if is_set( $filters.tool )}{$filters.tool|wash}{/if}" maxlength="64" autocomplete="off" /></span>
            <span class="a4-tron-formcell"><label class="a4-label" for="tron-filter-from">{'From'|i18n( 'extension/tron' )}</label>
            <input class="a4-input" type="date" id="tron-filter-from" name="TronFilterFrom" value="{if is_set( $filters.from )}{$filters.from|wash}{/if}" /></span>
            <span class="a4-tron-formcell"><label class="a4-label" for="tron-filter-to">{'To'|i18n( 'extension/tron' )}</label>
            <input class="a4-input" type="date" id="tron-filter-to" name="TronFilterTo" value="{if is_set( $filters.to )}{$filters.to|wash}{/if}" /></span>
            <span class="a4-tron-formcell"><label class="a4-label" for="tron-filter-object">{'Object (node:123)'|i18n( 'extension/tron' )}</label>
            <input class="a4-input" type="text" id="tron-filter-object" name="TronFilterObject" value="" maxlength="290" autocomplete="off" /></span>
            <button type="submit" class="a4-btn a4-btn-small" name="FilterButton" value="1">{'Filter'|i18n( 'extension/tron' )}</button>
        </span>
    </form>
</div>

<div class="a4-toolbar a4-tron-livebar">
    <span class="a4-muted">{'%first to %last of %total'|i18n( 'extension/tron',, hash( '%first', $first, '%last', $last, '%total', $total ) )|wash}</span>
    {if $filters}<a href={'/tron/activity'|ezurl}>{'Clear the filters'|i18n( 'extension/tron' )}</a>{/if}
</div>

<div class="a4-table-wrap">
<table class="a4-table a4-tron-activity">
<thead><tr>
    <th scope="col">{'Started'|i18n( 'extension/tron' )}</th><th scope="col">{'Session'|i18n( 'extension/tron' )}</th>
    <th scope="col">{'User'|i18n( 'extension/tron' )}</th><th scope="col">{'Client'|i18n( 'extension/tron' )}</th>
    <th scope="col">{'Transport'|i18n( 'extension/tron' )}</th><th scope="col">{'Steps'|i18n( 'extension/tron' )}</th>
    <th scope="col">{'State'|i18n( 'extension/tron' )}</th>
</tr></thead>
<tbody>
{foreach $sessions as $tron_s}
<tr>
    <td><time datetime="{$tron_s.started|datetime( 'custom', '%Y-%m-%dT%H:%i:%s' )}">{$tron_s.started|l10n( 'shortdatetime' )}</time></td>
    <td><a href={concat( '/tron/activity/(session)/', $tron_s.id )|ezurl}>{if $tron_s.title}{$tron_s.title|wash}{else}{'Session %id'|i18n( 'extension/tron',, hash( '%id', $tron_s.id ) )|wash}{/if}</a>{if eq( $tron_s.kind, 'job' )} <span class="a4-badge">{'job'|i18n( 'extension/tron' )}</span>{/if} <span class="a4-muted">#{$tron_s.id}</span></td>
    <td>{if $tron_s.login}<a href={concat( '/tron/activity/(user)/', $tron_s.login|view_parameter )|ezurl}>{$tron_s.login|wash}</a>{else}<span class="a4-muted">&ndash;</span>{/if}</td>
    <td>{$tron_s.client|wash}</td>
    <td>{$tron_s.transport|wash} <span class="a4-muted">{$tron_s.node|wash}</span></td>
    <td class="a4-tron-num">{$tron_s.steps}{if $tron_s.writes|gt( 0 )} <span class="a4-badge a4-tron-kind is-write">{'%n writes'|i18n( 'extension/tron',, hash( '%n', $tron_s.writes ) )|wash}</span>{/if}{if $tron_s.refused|gt( 0 )} <span class="a4-badge a4-tron-outcome is-refused">{'%n refused'|i18n( 'extension/tron',, hash( '%n', $tron_s.refused ) )|wash}</span>{/if}{if $tron_s.errors|gt( 0 )} <span class="a4-badge a4-tron-outcome is-error">{'%n errors'|i18n( 'extension/tron',, hash( '%n', $tron_s.errors ) )|wash}</span>{/if}</td>
    <td><span class="a4-badge a4-tron-outcome is-{$tron_s.status|wash}">{$tron_s.status|wash}</span></td>
</tr>
{/foreach}
</tbody>
</table>
</div>
{if $sessions|not}<p class="a4-empty">{'No sessions.'|i18n( 'extension/tron' )}</p>{/if}
{if or( $prev_url, $next_url )}
<div class="a4-pager">
    {if $prev_url}<a class="a4-btn a4-btn-small" href={concat( '/', $prev_url )|ezurl} rel="prev">{'Newer'|i18n( 'extension/tron' )}</a>{/if}
    {if $next_url}<a class="a4-btn a4-btn-small" href={concat( '/', $next_url )|ezurl} rel="next">{'Older'|i18n( 'extension/tron' )}</a>{/if}
</div>
{/if}
{/if}

{include uri='design:tron/parts/page_close.tpl'}
