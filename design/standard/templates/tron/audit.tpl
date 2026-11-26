{* Setup > tron > Audit (tron/audit): the audit log newest first. Filters and paging are view parameters only; the live
   tail (tron_audit_live.js) asks (part)/rows/(after)/<newest id> every few seconds while "Live" is on.
   Variables: rows, total, filters, outcomes, transports, programs, bases, first, last, prev_url, next_url, live_url,
   live, max_id, missing, view.
   Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version). *}
{ezscript_require( 'tron_audit_live.js' )}
{include uri='design:tron/parts/page_open.tpl' view=$view title='Audit'|i18n( 'extension/tron' )
         intro='Every tool call, resource read, prompt and refused sign-in, with who, how, what and the outcome. A write tool has two rows: started, before it runs, and its outcome. Secrets in arguments are masked.'|i18n( 'extension/tron' )}

{if $missing|contains( 'tron_audit' )}<div class="a4-notice is-bad" role="alert"><p>{'The audit table is missing. Run tron.php install on the server.'|i18n( 'extension/tron' )}</p></div>{/if}

<div class="a4-filterbar a4-tron-filter">
    <div class="a4-field a4-field-wide">
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
        <span class="a4-label">{'Program'|i18n( 'extension/tron' )}</span>
        <ul class="a4-chips">
            <li><a class="a4-chip{if is_set( $filters.program )|not} is-current{/if}" href={concat( '/', $bases.program )|ezurl}><span>{'All'|i18n( 'extension/tron' )}</span></a></li>
            {foreach $programs as $tron_p}
            <li><a class="a4-chip{if and( is_set( $filters.program ), eq( $filters.program, $tron_p ) )} is-current{/if}" href={concat( '/', $bases.program, '/(program)/', $tron_p )|ezurl}><span>{$tron_p|wash}</span></a></li>
            {/foreach}
        </ul>
    </div>
    <form method="post" action={concat( '/', $bases.user )|ezurl} class="a4-field a4-tron-userfilter">
        <label class="a4-label" for="tron-filter-user">{'User (login)'|i18n( 'extension/tron' )}</label>
        <span class="a4-tron-userfilter-row">
            <input class="a4-input" type="text" id="tron-filter-user" name="TronFilterUser" value="{if is_set( $filters.user )}{$filters.user|wash}{/if}" maxlength="150" autocomplete="off" />
            <button type="submit" class="a4-btn a4-btn-small" name="FilterButton" value="1">{'Filter'|i18n( 'extension/tron' )}</button>
        </span>
    </form>
    {if is_set( $filters.user )}
    <p class="a4-help">{'User %login.'|i18n( 'extension/tron',, hash( '%login', $filters.user ) )|wash} <a href={concat( '/', $bases.user )|ezurl}>{'All users'|i18n( 'extension/tron' )}</a></p>
    {/if}
</div>

<div class="a4-toolbar a4-tron-livebar">
    <span class="a4-muted">{'%first to %last of %total'|i18n( 'extension/tron',, hash( '%first', $first, '%last', $last, '%total', $total ) )|wash}</span>
    {if $live}
    <label class="a4-check a4-tron-live" hidden="hidden" data-tron-live-toggle="1"><input type="checkbox" id="tron-live" /> {'Live: show new entries as they come'|i18n( 'extension/tron' )}</label>
    <span class="a4-sr-only" role="status" aria-live="polite" id="tron-live-status"></span>
    {/if}
</div>

<div class="a4-table-wrap">
<table class="a4-table a4-tron-audit">
<thead><tr>
    <th scope="col">{'Time'|i18n( 'extension/tron' )}</th><th scope="col">{'User'|i18n( 'extension/tron' )}</th>
    <th scope="col">{'Transport'|i18n( 'extension/tron' )}</th><th scope="col">{'Method'|i18n( 'extension/tron' )}</th>
    <th scope="col">{'Target'|i18n( 'extension/tron' )}</th><th scope="col">{'Outcome'|i18n( 'extension/tron' )}</th>
    <th scope="col">{'ms'|i18n( 'extension/tron' )}</th><th scope="col">{'Arguments and note'|i18n( 'extension/tron' )}</th>
</tr></thead>
<tbody id="tron-audit-rows" data-live-url={$live_url|ezurl} data-after="{$max_id}">
{include uri='design:tron/parts/audit_rows.tpl' rows=$rows}
</tbody>
</table>
</div>
{if $rows|not}<p class="a4-empty">{'No entries.'|i18n( 'extension/tron' )}</p>{/if}

{if $total|gt( 0 )}
<div class="a4-pager">
    {if $prev_url}<a class="a4-btn a4-btn-small" href={concat( '/', $prev_url )|ezurl} rel="prev">{'Newer'|i18n( 'extension/tron' )}</a>{/if}
    {if $next_url}<a class="a4-btn a4-btn-small" href={concat( '/', $next_url )|ezurl} rel="next">{'Older'|i18n( 'extension/tron' )}</a>{/if}
</div>
{/if}

{include uri='design:tron/parts/page_close.tpl'}
