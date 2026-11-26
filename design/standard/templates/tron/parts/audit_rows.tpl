{* Rows of the audit table (tron/audit and its live tail, (part)/rows). Input: $rows.
   Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version). *}
{foreach $rows as $tron_a}
<tr data-id="{$tron_a.id}">
    <td><time datetime="{$tron_a.created|datetime( 'custom', '%Y-%m-%dT%H:%i:%s' )}">{$tron_a.created|l10n( 'shortdatetime' )}</time></td>
    <td>{if $tron_a.login}<a href={concat( '/tron/audit/(user)/', $tron_a.login|view_parameter )|ezurl}>{$tron_a.login|wash}</a>{else}<span class="a4-muted">&ndash;</span>{/if}{if $tron_a.token_id|gt( 0 )} <span class="a4-muted">#{$tron_a.token_id}</span>{/if}</td>
    <td>{$tron_a.transport|wash}</td>
    <td>{$tron_a.method|wash}</td>
    <td><code class="a4-code">{$tron_a.target|wash}</code></td>
    <td><span class="a4-badge a4-tron-outcome is-{$tron_a.outcome|wash}">{$tron_a.outcome|wash}</span></td>
    <td class="a4-tron-num">{$tron_a.duration_ms}</td>
    <td class="a4-tron-detail">{if $tron_a.args}<code class="a4-code">{$tron_a.args|wash}</code>{/if}{if $tron_a.note} <span class="a4-muted">{$tron_a.note|wash}</span>{/if}</td>
</tr>
{/foreach}
