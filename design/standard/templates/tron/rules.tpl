{* Setup > tron > Agent rules (tron/rules, doc/agent-rules.md): which MASTER.md and AGENTS.md agents receive and from
   where, every MASTER.md switch in precedence order, the AGENTS.md editor with upload, questionnaire and expai draft, the
   MASTER.md override, and the combined text. Variables: data (tronAdminRulesData::data()), notice, error, warnings, view.
   Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version). *}
{include uri='design:tron/parts/page_open.tpl' view=$view title='Agent rules'|i18n( 'extension/tron' )
         intro="The rules every agent receives with its session: the site's own AGENTS.md, and MASTER.md, the Exponential rules that come first and win where the two conflict. MASTER.md helps most while the site's AGENTS.md is missing, a skeleton or short."|i18n( 'extension/tron' )}

{if $error}<div class="a4-notice is-bad" role="alert"><p>{$error|wash}</p></div>{/if}
{if $notice}<div class="a4-notice is-ok" role="status"><p>{$notice|wash}</p></div>{/if}
{foreach $warnings as $tron_w}<div class="a4-notice is-warn" role="status"><p>{$tron_w|wash}</p></div>{/foreach}

<ul class="a4-figures">
    <li class="a4-figure{if $data.master.active|not} is-warn{/if}"><strong>{if $data.master.active}{$data.master.source|wash}{else}{'off'|i18n( 'extension/tron' )}{/if}</strong><span>{'MASTER.md'|i18n( 'extension/tron' )}</span></li>
    <li class="a4-figure"><strong>{$data.decided_by|wash}</strong><span>{'Decided by'|i18n( 'extension/tron' )}</span></li>
    <li class="a4-figure{if $data.agents.active|not} is-warn{/if}"><strong>{$data.agents.source|wash}</strong><span>{'AGENTS.md'|i18n( 'extension/tron' )}</span></li>
    <li class="a4-figure{if or( eq( $data.agents.strength, 'hollow' ), eq( $data.agents.strength, 'missing' ) )} is-warn{/if}"><strong>{$data.agents.strength|wash}</strong><span>{'AGENTS.md strength'|i18n( 'extension/tron' )}</span></li>
</ul>

<section class="a4-card a4-tron-cards" aria-labelledby="tron-rules-files">
    <div class="a4-card-head"><h2 class="a4-card-title" id="tron-rules-files">{'Which rules agents receive'|i18n( 'extension/tron' )}</h2></div>
    <div class="a4-card-body">
    <dl class="a4-facts">
        <div><dt>{'MASTER.md'|i18n( 'extension/tron' )}</dt><dd><span class="a4-badge a4-tron-state is-{if $data.master.active}loaded{else}disabled{/if}" data-tron-rules="master-source">{$data.master.source|wash}</span> <code class="a4-code">{$data.master.path_shown|wash}</code>{if $data.master.sha256} <span class="a4-muted">sha256 {$data.master.sha256|shorten( 12, '' )|wash}</span>{/if}</dd></div>
        <div><dt>{'MASTER.md override'|i18n( 'extension/tron' )}</dt><dd><code class="a4-code">{$data.master.override_shown|wash}</code> {if eq( $data.master.file, 'override' )}<span class="a4-badge">{'in use'|i18n( 'extension/tron' )}</span>{else}<span class="a4-muted">{'not there: the shipped default applies'|i18n( 'extension/tron' )}</span>{/if}</dd></div>
        <div><dt>{'AGENTS.md'|i18n( 'extension/tron' )}</dt><dd><span class="a4-badge a4-tron-state is-{if $data.agents.active}loaded{else}disabled{/if}" data-tron-rules="agents-source">{$data.agents.source|wash}</span> <code class="a4-code">{$data.agents.path_shown|wash}</code></dd></div>
        <div><dt>{'Precedence'|i18n( 'extension/tron' )}</dt><dd>{'MASTER.md supersedes AGENTS.md: where the two conflict, MASTER.md wins.'|i18n( 'extension/tron' )}</dd></div>
        <div><dt>{'Prompt directory'|i18n( 'extension/tron' )}</dt><dd><code class="a4-code">{$data.dir_shown|wash}</code></dd></div>
    </dl>
    <p class="a4-help">{'Agents read them as tron://rules/agents, tron://rules/master and tron://rules/combined, through the prompt tron_project_rules and the tool tron_rules, and at the end of the instructions of every new session.'|i18n( 'extension/tron' )}</p>
    </div>
</section>

<section class="a4-card a4-tron-cards" aria-labelledby="tron-rules-switches">
    <div class="a4-card-head"><h2 class="a4-card-title" id="tron-rules-switches">{'Switching MASTER.md off'|i18n( 'extension/tron' )}</h2></div>
    <div class="a4-card-body">
    <p class="a4-help">{"In precedence order: the first of the site switches that is set decides; a user's preference and a session's flag can then only switch it off for themselves."|i18n( 'extension/tron' )}</p>
    </div>
    <div class="a4-table-wrap">
    <table class="a4-table">
    <thead><tr><th scope="col">{'Switch'|i18n( 'extension/tron' )}</th><th scope="col">{'Can'|i18n( 'extension/tron' )}</th><th scope="col">{'Value'|i18n( 'extension/tron' )}</th><th scope="col"><span class="a4-sr-only">{'Decides'|i18n( 'extension/tron' )}</span></th></tr></thead>
    <tbody>
    {foreach $data.switches as $tron_s}
    <tr>
        <th scope="row">{$tron_s.label|wash}</th>
        <td>{$tron_s.can|wash}</td>
        <td>{if eq( $tron_s.value, 'disabled' )}<span class="a4-badge a4-tron-state is-incompatible">{'disabled'|i18n( 'extension/tron' )}</span>{elseif eq( $tron_s.value, 'enabled' )}<span class="a4-badge a4-tron-state is-loaded">{'enabled'|i18n( 'extension/tron' )}</span>{else}<span class="a4-muted">{'not set'|i18n( 'extension/tron' )}</span>{/if}</td>
        <td>{if $tron_s.decides}<span class="a4-badge a4-tron-kind is-write" data-tron-rules="decides">{'decides'|i18n( 'extension/tron' )}</span>{/if}</td>
    </tr>
    {/foreach}
    </tbody>
    </table>
    </div>
    <div class="a4-card-body a4-tron-rules-switchforms">
        <form method="post" action={'/tron/rules'|ezurl} class="a4-field">
            <label class="a4-label" for="tron-admin-master">{'Admin toggle'|i18n( 'extension/tron' )}</label>
            <span class="a4-tron-userfilter-row">
            <select class="a4-input" id="tron-admin-master" name="TronAdminMaster">
                <option value=""{if eq( $data.admin_toggle, '' )} selected="selected"{/if}>{'Not set: the INI decides'|i18n( 'extension/tron' )}</option>
                <option value="enabled"{if eq( $data.admin_toggle, 'enabled' )} selected="selected"{/if}>{'MASTER.md on'|i18n( 'extension/tron' )}</option>
                <option value="disabled"{if eq( $data.admin_toggle, 'disabled' )} selected="selected"{/if}>{'MASTER.md off'|i18n( 'extension/tron' )}</option>
            </select>
            <button type="submit" class="a4-btn a4-btn-small" name="AdminToggleButton" value="1">{'Set'|i18n( 'extension/tron' )}</button>
            </span>
        </form>
        <form method="post" action={'/tron/rules'|ezurl} class="a4-field">
            <span class="a4-label">{'File marker'|i18n( 'extension/tron' )} <code class="a4-code">{$data.marker_shown|wash}</code></span>
            <input type="hidden" name="TronMarker" value="{if $data.marker}0{else}1{/if}" />
            <span><button type="submit" class="a4-btn a4-btn-small" name="MarkerButton" value="1">{if $data.marker}{'Remove the marker'|i18n( 'extension/tron' )}{else}{'Place the marker'|i18n( 'extension/tron' )}{/if}</button></span>
        </form>
        <form method="post" action={'/tron/rules'|ezurl} class="a4-field">
            <span class="a4-label">{'My own sessions'|i18n( 'extension/tron' )}</span>
            <input type="hidden" name="TronPreference" value="{if eq( $data.preference, 'disabled' )}{else}disabled{/if}" />
            <span><button type="submit" class="a4-btn a4-btn-small" name="PreferenceButton" value="1">{if eq( $data.preference, 'disabled' )}{'Let the site decide for me'|i18n( 'extension/tron' )}{else}{'Switch MASTER.md off for me'|i18n( 'extension/tron' )}{/if}</button></span>
        </form>
    </div>
</section>

<section class="a4-card a4-tron-cards" aria-labelledby="tron-rules-questions">
    <div class="a4-card-head"><h2 class="a4-card-title" id="tron-rules-questions">{'Write an AGENTS.md: questionnaire'|i18n( 'extension/tron' )}</h2></div>
    <div class="a4-card-body">
    <p class="a4-help">{'Answer what you can; a question left empty gets a sensible default. The starter lands in the editor below and is saved only when you save it.'|i18n( 'extension/tron' )}</p>
    <form method="post" action={'/tron/rules'|ezurl} class="a4-form is-wide a4-tron-rules-questions">
    {foreach $data.questions as $tron_q}
        <div class="a4-field{if eq( $tron_q.kind, 'lines' )} a4-field-wide{/if}">
            <label for="tron-q-{$tron_q.name|wash}">{$tron_q.label|i18n( 'extension/tron' )|wash}</label>
            {if eq( $tron_q.kind, 'choice' )}
            <select class="a4-input" id="tron-q-{$tron_q.name|wash}" name="TronQ[{$tron_q.name|wash}]">
                <option value="">{'Choose'|i18n( 'extension/tron' )}</option>
                {foreach $tron_q.choices as $tron_c}<option value="{$tron_c.value|wash}">{$tron_c.label|i18n( 'extension/tron' )|wash}</option>{/foreach}
            </select>
            {elseif eq( $tron_q.kind, 'lines' )}
            <textarea class="a4-input" id="tron-q-{$tron_q.name|wash}" name="TronQ[{$tron_q.name|wash}]" rows="3"></textarea>
            {else}
            <input class="a4-input" type="text" id="tron-q-{$tron_q.name|wash}" name="TronQ[{$tron_q.name|wash}]" maxlength="400" />
            {/if}
            <p class="a4-help">{$tron_q.help|i18n( 'extension/tron' )|wash}</p>
        </div>
    {/foreach}
        <div class="a4-field a4-field-wide">
            <span>
            <button type="submit" class="a4-btn a4-btn-primary" name="GenerateButton" value="1">{'Write the starter'|i18n( 'extension/tron' )}</button>
            {if $data.expai}<button type="submit" class="a4-btn" name="ExpaiDraftButton" value="1">{'Draft with Exponential Intelligence'|i18n( 'extension/tron' )}</button>{/if}
            </span>
            {if $data.expai|not}<p class="a4-help">{'The guided draft through Exponential Intelligence is not available: %why Agents can still run the prompt tron_rules_wizard.'|i18n( 'extension/tron',, hash( '%why', $data.expai_unavailable ) )|wash}</p>{/if}
        </div>
    </form>
    </div>
</section>

<section class="a4-card a4-tron-cards" aria-labelledby="tron-rules-agents">
    <div class="a4-card-head"><h2 class="a4-card-title" id="tron-rules-agents">{'AGENTS.md of this site'|i18n( 'extension/tron' )}</h2></div>
    <div class="a4-card-body">
    <form method="post" action={'/tron/rules'|ezurl} class="a4-form is-wide">
        <div class="a4-field">
            <label for="tron-agents-text">{'AGENTS.md'|i18n( 'extension/tron' )}</label>
            <textarea class="a4-input a4-tron-editor" id="tron-agents-text" name="TronAgentsText" rows="24" spellcheck="false">{$data.editor|wash}</textarea>
            <p class="a4-help">{'Never write a password, token or key into it: every agent reads this file. Use placeholders such as <ADMIN_PASSWORD>.'|i18n( 'extension/tron' )|wash}</p>
        </div>
        <div class="a4-field"><span>
            <button type="submit" class="a4-btn a4-btn-primary" name="SaveAgentsButton" value="1">{'Save AGENTS.md'|i18n( 'extension/tron' )}</button>
            {if $data.agents.exists}<button type="submit" class="a4-btn a4-btn-outline-danger" name="RemoveAgentsButton" value="1">{'Remove AGENTS.md'|i18n( 'extension/tron' )}</button>{/if}
        </span></div>
    </form>
    <form method="post" action={'/tron/rules'|ezurl} enctype="multipart/form-data" class="a4-field a4-tron-rules-upload">
        <label class="a4-label" for="tron-agents-file">{'Upload an AGENTS.md'|i18n( 'extension/tron' )}</label>
        <span class="a4-tron-userfilter-row">
            <input class="a4-input" type="file" id="tron-agents-file" name="TronAgentsFile" accept=".md,text/markdown,text/plain" />
            <button type="submit" class="a4-btn a4-btn-small" name="UploadAgentsButton" value="1">{'Upload'|i18n( 'extension/tron' )}</button>
        </span>
    </form>
    </div>
</section>

<section class="a4-card a4-tron-cards" aria-labelledby="tron-rules-master">
    <div class="a4-card-head"><h2 class="a4-card-title" id="tron-rules-master">{'MASTER.md'|i18n( 'extension/tron' )}</h2></div>
    <div class="a4-card-body">
    <p class="a4-help">{'tron ships MASTER.md with the Exponential rules for agents. A site override replaces it completely; remove the override to return to the shipped file.'|i18n( 'extension/tron' )}</p>
    <details class="a4-fold a4-tron-fold"><summary>{'Read MASTER.md (%source)'|i18n( 'extension/tron',, hash( '%source', $data.master.file ) )|wash}</summary>
        <div class="a4-fold-body"><pre class="a4-pre a4-tron-rules-pre">{$data.master.text|wash}</pre></div></details>
    <details class="a4-fold a4-tron-fold"><summary>{'Edit or upload a site override'|i18n( 'extension/tron' )}</summary>
        <div class="a4-fold-body">
        <form method="post" action={'/tron/rules'|ezurl} class="a4-form is-wide">
            <div class="a4-field">
                <label for="tron-master-text">{'MASTER.md override'|i18n( 'extension/tron' )}</label>
                <textarea class="a4-input a4-tron-editor" id="tron-master-text" name="TronMasterText" rows="20" spellcheck="false">{$data.master.text|wash}</textarea>
            </div>
            <div class="a4-field"><span>
                <button type="submit" class="a4-btn a4-btn-primary" name="SaveMasterButton" value="1">{'Save as site override'|i18n( 'extension/tron' )}</button>
                {if eq( $data.master.file, 'override' )}<button type="submit" class="a4-btn a4-btn-outline-danger" name="RemoveMasterButton" value="1">{'Remove the override'|i18n( 'extension/tron' )}</button>{/if}
            </span></div>
        </form>
        <form method="post" action={'/tron/rules'|ezurl} enctype="multipart/form-data" class="a4-field a4-tron-rules-upload">
            <label class="a4-label" for="tron-master-file">{'Upload a MASTER.md override'|i18n( 'extension/tron' )}</label>
            <span class="a4-tron-userfilter-row">
                <input class="a4-input" type="file" id="tron-master-file" name="TronMasterFile" accept=".md,text/markdown,text/plain" />
                <button type="submit" class="a4-btn a4-btn-small" name="UploadMasterButton" value="1">{'Upload'|i18n( 'extension/tron' )}</button>
            </span>
        </form>
        </div>
    </details>
    </div>
</section>

<section class="a4-card a4-tron-cards" aria-labelledby="tron-rules-combined">
    <div class="a4-card-head"><h2 class="a4-card-title" id="tron-rules-combined">{'What an agent receives'|i18n( 'extension/tron' )}</h2></div>
    <div class="a4-card-body">
    {if $data.combined}
    <details class="a4-fold a4-tron-fold"><summary>{'The combined rules for your own sessions'|i18n( 'extension/tron' )}</summary>
        <div class="a4-fold-body"><pre class="a4-pre a4-tron-rules-pre" data-tron-rules="combined">{$data.combined|wash}</pre></div></details>
    {else}
    <p class="a4-empty">{'No rules: there is no AGENTS.md and MASTER.md is off.'|i18n( 'extension/tron' )}</p>
    {/if}
    </div>
</section>

{include uri='design:tron/parts/page_close.tpl'}
