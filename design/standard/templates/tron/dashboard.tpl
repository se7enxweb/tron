{* Setup > tron (tron/dashboard): version, programs, tools, tokens, the last day's calls, the HTTP endpoint.
   Variables: data (tronAdminDashboardData::data()), view.
   Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version). *}
{include uri='design:tron/parts/page_open.tpl' view=$view title='tron'
         intro='The MCP server of this installation: AI agents work here as users of the site, with their roles and policies, through tokens, and every call is written to the audit log.'|i18n( 'extension/tron' )}

{if $data.missing}
<div class="a4-notice is-bad" role="alert"><p>{'Tables missing: %tables. Run tron.php install on the server.'|i18n( 'extension/tron',, hash( '%tables', $data.missing|implode( ', ' ) ) )|wash}</p></div>
{/if}
{if $data.programs_failed|gt( 0 )}
<div class="a4-notice is-warn" role="status"><p>{'%count program(s) failed to load; see Programs and tools.'|i18n( 'extension/tron',, hash( '%count', $data.programs_failed ) )|wash}</p></div>
{/if}

<ul class="a4-figures">
    <li class="a4-figure"><strong>{$data.version|wash}</strong><span>{'Version'|i18n( 'extension/tron' )}</span></li>
    <li class="a4-figure{if $data.programs_failed|gt( 0 )} is-bad{else} is-ok{/if}"><strong>{$data.programs_loaded}</strong><span>{'Programs loaded'|i18n( 'extension/tron' )}</span></li>
    <li class="a4-figure"><strong>{$data.tools}</strong><span>{'Tools'|i18n( 'extension/tron' )}</span></li>
    <li class="a4-figure"><strong>{$data.tokens.active}</strong><span>{'Active tokens'|i18n( 'extension/tron' )}</span></li>
    <li class="a4-figure"><strong>{$data.calls}</strong><span>{'Calls, last 24 h'|i18n( 'extension/tron' )}</span></li>
    <li class="a4-figure{if $data.outcomes.denied|gt( 0 )} is-warn{/if}"><strong>{$data.outcomes.denied}</strong><span>{'Refused by policy, last 24 h'|i18n( 'extension/tron' )}</span></li>
</ul>

<div class="a4-cards a4-tron-cards">
<section class="a4-card" aria-labelledby="tron-calls-title">
    <div class="a4-card-head"><h2 class="a4-card-title" id="tron-calls-title">{'Calls, last 24 h'|i18n( 'extension/tron' )}</h2></div>
    <div class="a4-card-body">
    <dl class="a4-facts">
        {foreach $data.outcomes as $tron_o => $tron_n}
        <div><dt><a href={concat( '/tron/audit/(outcome)/', $tron_o )|ezurl}><span class="a4-badge a4-tron-outcome is-{$tron_o}">{$tron_o|wash}</span></a></dt><dd>{$tron_n}</dd></div>
        {/foreach}
    </dl>
    {if $data.last}
    <p class="a4-help">{'Last call: %target by %login, %outcome, %time.'|i18n( 'extension/tron',, hash( '%target', $data.last.target, '%login', $data.last.login, '%outcome', $data.last.outcome, '%time', $data.last.created|l10n( 'shortdatetime' ) ) )|wash}</p>
    {/if}
    </div>
</section>

<section class="a4-card" aria-labelledby="tron-endpoint-title">
    <div class="a4-card-head"><h2 class="a4-card-title" id="tron-endpoint-title">{'Ways in'|i18n( 'extension/tron' )}</h2></div>
    <div class="a4-card-body">
    <dl class="a4-facts">
        <div><dt>stdio</dt><dd><code class="a4-code">php extension/tron/bin/php/tron.php serve --siteaccess=&lt;sa&gt;</code></dd></div>
        <div><dt>HTTP</dt><dd>{if $data.http.enabled}<span class="a4-badge a4-tron-outcome is-ok">{'On'|i18n( 'extension/tron' )}</span> {'siteaccess %sa, hosts %hosts'|i18n( 'extension/tron',, hash( '%sa', $data.http.siteaccesses|implode( ', ' ), '%hosts', cond( $data.http.hosts, $data.http.hosts|implode( ', ' ), '*' ) ) )|wash}{else}<span class="a4-badge">{'Off'|i18n( 'extension/tron' )}</span>{/if}</dd></div>
        <div><dt>{'Audit'|i18n( 'extension/tron' )}</dt><dd>{$data.sinks|implode( ', ' )|wash}</dd></div>
        <div><dt>{'Protocol'|i18n( 'extension/tron' )}</dt><dd>{$data.protocol|wash}: {$data.protocols|implode( ', ' )|wash}</dd></div>
        <div><dt>{'Admin actions, last 24 h'|i18n( 'extension/tron' )}</dt><dd><a href={'/tron/audit/(transport)/admin'|ezurl}>{$data.admin_actions}</a></dd></div>
        <div><dt>{'Tokens'|i18n( 'extension/tron' )}</dt><dd>{'%active active, %expired expired, %revoked revoked'|i18n( 'extension/tron',, hash( '%active', $data.tokens.active, '%expired', $data.tokens.expired, '%revoked', $data.tokens.revoked ) )|wash}</dd></div>
    </dl>
    </div>
</section>
</div>

<section class="a4-card a4-tron-programs" aria-labelledby="tron-programs-title">
    <div class="a4-card-head"><h2 class="a4-card-title" id="tron-programs-title">{'Programs'|i18n( 'extension/tron' )}</h2></div>
    <div class="a4-table-wrap">
    <table class="a4-table">
    <thead><tr>
        <th scope="col">{'Program'|i18n( 'extension/tron' )}</th><th scope="col">{'Version'|i18n( 'extension/tron' )}</th>
        <th scope="col">{'From'|i18n( 'extension/tron' )}</th><th scope="col">{'State'|i18n( 'extension/tron' )}</th>
        <th scope="col">{'Tools'|i18n( 'extension/tron' )}</th><th scope="col">{'Load time'|i18n( 'extension/tron' )}</th>
    </tr></thead>
    <tbody>
    {foreach $data.programs as $tron_p}
    <tr>
        <th scope="row"><code class="a4-code">{$tron_p.name|wash}</code></th>
        <td>{$tron_p.version|wash}</td><td>{$tron_p.origin|wash}</td>
        <td><span class="a4-badge a4-tron-state is-{$tron_p.state|wash}">{$tron_p.state|wash}</span>{if $tron_p.reason} <span class="a4-muted">{$tron_p.reason|wash}</span>{/if}</td>
        <td>{if is_set( $tron_p.tools )}{$tron_p.tools}{else}&ndash;{/if}</td>
        <td>{$tron_p.ms|wash} ms</td>
    </tr>
    {/foreach}
    </tbody>
    </table>
    </div>
</section>

{include uri='design:tron/parts/page_close.tpl'}
