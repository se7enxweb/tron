{* Setup > tron > Tokens (tron/tokens): make a token (the secret shown once), list with filters as view parameters,
   rotate (renew) and revoke. Variables: tokens, filter_user, filter_state, scope_choices, max_days, form, created, error, notice, view.
   Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version). *}
{include uri='design:tron/parts/page_open.tpl' view=$view title='Tokens'|i18n( 'extension/tron' )
         intro='A token lets an agent act as one user, with that user\'s roles, optionally only in some programs. Only a hash is stored; the token is shown once, when it is made.'|i18n( 'extension/tron' )}

{if $created}
<section class="a4-card a4-tron-secret" aria-labelledby="tron-secret-title">
    <div class="a4-card-body">
    <h2 class="a4-card-title" id="tron-secret-title">{'The new token for %login'|i18n( 'extension/tron',, hash( '%login', $created.login ) )|wash}</h2>
    {if is_set( $created.replaced )}<p class="a4-help">{'It replaces token %id, which is revoked now. Put the new token where the old one was.'|i18n( 'extension/tron',, hash( '%id', $created.replaced ) )|wash}</p>{/if}
    <p class="a4-notice is-warn">{'Copy it now into a file only the agent\'s user can read (mode 0600). It is not shown again, and it cannot be recovered: a lost token is revoked and replaced.'|i18n( 'extension/tron' )}</p>
    <label class="a4-label" for="tron-secret-value">{'Token'|i18n( 'extension/tron' )}</label>
    <input class="a4-input a4-tron-secret-value" type="text" id="tron-secret-value" value="{$created.token|wash}" readonly="readonly" autocomplete="off" spellcheck="false" />
    <p class="a4-help">{'Prefix %prefix, scopes %scopes, expires %date.'|i18n( 'extension/tron',, hash( '%prefix', $created.prefix, '%scopes', $created.scopes|implode( ', ' ), '%date', $created.expires|l10n( 'shortdate' ) ) )|wash}</p>
    </div>
</section>
{/if}
{if $error}<div class="a4-notice is-bad" role="alert"><p>{$error|wash}</p></div>{/if}
{if $notice}<div class="a4-notice is-ok" role="status"><p>{$notice|wash}</p></div>{/if}

<section class="a4-card" aria-labelledby="tron-new-title">
    <div class="a4-card-head"><h2 class="a4-card-title" id="tron-new-title">{'Make a token'|i18n( 'extension/tron' )}</h2></div>
    <div class="a4-card-body">
    <form method="post" action={'/tron/tokens'|ezurl} class="a4-form a4-tron-form">
        <div class="a4-field">
            <label for="tron-login">{'User (login)'|i18n( 'extension/tron' )}</label>
            <input class="a4-input" type="text" id="tron-login" name="TronLogin" value="{$form.login|wash}" required="required" maxlength="150" autocomplete="off" />
        </div>
        <div class="a4-field">
            <label for="tron-name">{'What it is for'|i18n( 'extension/tron' )}</label>
            <input class="a4-input" type="text" id="tron-name" name="TronName" value="{$form.name|wash}" required="required" maxlength="100" placeholder="claude@cluster" />
        </div>
        <div class="a4-field">
            <label for="tron-days">{'Days until it expires'|i18n( 'extension/tron' )}</label>
            <input class="a4-input" type="number" id="tron-days" name="TronDays" value="{$form.days|wash}" min="1" max="{$max_days}" required="required" />
        </div>
        <fieldset class="a4-field a4-field-wide">
            <legend class="a4-label">{'Programs (none ticked: all)'|i18n( 'extension/tron' )}</legend>
            {foreach $scope_choices as $tron_s}
            <label class="a4-check"><input type="checkbox" name="TronScopes[]" value="{$tron_s|wash}"{if $form.scopes|contains( $tron_s )} checked="checked"{/if} /> <code class="a4-code">{$tron_s|wash}</code></label>
            {/foreach}
        </fieldset>
        <div class="a4-actions">
            <button type="submit" class="a4-btn a4-btn-primary" name="CreateButton" value="1">{'Make token'|i18n( 'extension/tron' )}</button>
        </div>
    </form>
    </div>
</section>

<div class="a4-filterbar">
    <div class="a4-field a4-field-wide">
        <span class="a4-label">{'State'|i18n( 'extension/tron' )}</span>
        <ul class="a4-chips">
            <li><a class="a4-chip{if $filter_state|not} is-current{/if}" href={concat( '/tron/tokens', cond( $filter_user, concat( '/(user)/', $filter_user ), '' ) )|ezurl}{if $filter_state|not} aria-current="true"{/if}><span>{'All'|i18n( 'extension/tron' )}</span></a></li>
            {foreach array( 'active', 'expired', 'revoked' ) as $tron_st}
            <li><a class="a4-chip{if eq( $filter_state, $tron_st )} is-current{/if}" href={concat( '/tron/tokens', cond( $filter_user, concat( '/(user)/', $filter_user ), '' ), '/(state)/', $tron_st )|ezurl}{if eq( $filter_state, $tron_st )} aria-current="true"{/if}><span>{$tron_st|wash}</span></a></li>
            {/foreach}
        </ul>
    </div>
    <form method="post" action={concat( '/tron/tokens', cond( $filter_state, concat( '/(state)/', $filter_state ), '' ) )|ezurl} class="a4-field a4-tron-userfilter">
        <label class="a4-label" for="tron-filter-user">{'User (login)'|i18n( 'extension/tron' )}</label>
        <span class="a4-tron-userfilter-row">
            <input class="a4-input" type="text" id="tron-filter-user" name="TronFilterUser" value="{$filter_user|wash}" maxlength="150" autocomplete="off" />
            <button type="submit" class="a4-btn a4-btn-small" name="FilterButton" value="1">{'Filter'|i18n( 'extension/tron' )}</button>
        </span>
    </form>
    {if $filter_user}<p class="a4-help">{'User %login.'|i18n( 'extension/tron',, hash( '%login', $filter_user ) )|wash} <a href={concat( '/tron/tokens', cond( $filter_state, concat( '/(state)/', $filter_state ), '' ) )|ezurl}>{'All users'|i18n( 'extension/tron' )}</a></p>{/if}
</div>

{if $tokens}
<div class="a4-table-wrap">
<table class="a4-table">
<thead><tr>
    <th scope="col">{'ID'|i18n( 'extension/tron' )}</th><th scope="col">{'Prefix'|i18n( 'extension/tron' )}</th>
    <th scope="col">{'User'|i18n( 'extension/tron' )}</th><th scope="col">{'For'|i18n( 'extension/tron' )}</th>
    <th scope="col">{'Scopes'|i18n( 'extension/tron' )}</th><th scope="col">{'Expires'|i18n( 'extension/tron' )}</th>
    <th scope="col">{'Last used'|i18n( 'extension/tron' )}</th><th scope="col">{'State'|i18n( 'extension/tron' )}</th>
    <th scope="col"><span class="a4-sr-only">{'Actions'|i18n( 'extension/tron' )}</span></th>
</tr></thead>
<tbody>
{foreach $tokens as $tron_t}
<tr>
    <td>{$tron_t.id}</td>
    <td><code class="a4-code">{$tron_t.token_prefix|wash}</code></td>
    <td><a href={concat( '/tron/tokens/(user)/', $tron_t.login|view_parameter )|ezurl}>{$tron_t.login|wash}</a></td>
    <td>{$tron_t.name|wash}</td>
    <td>{$tron_t.scopes|wash}</td>
    <td>{$tron_t.expires|l10n( 'shortdate' )}</td>
    <td>{if $tron_t.last_used|gt( 0 )}{$tron_t.last_used|l10n( 'shortdatetime' )}{else}<span class="a4-muted">{'never'|i18n( 'extension/tron' )}</span>{/if}</td>
    <td><span class="a4-badge a4-tron-token is-{$tron_t.state|wash}">{$tron_t.state|wash}</span></td>
    <td class="a4-tron-actions">{if ne( $tron_t.state, 'revoked' )}
        <form method="post" action={'/tron/tokens'|ezurl} class="a4-tron-inline">
            <input type="hidden" name="TronTokenID" value="{$tron_t.id}" />
            <button type="submit" class="a4-btn a4-btn-small" name="RotateButton" value="1" aria-label="{'Rotate token %id'|i18n( 'extension/tron',, hash( '%id', $tron_t.id ) )|wash}" title="{'Make a new token like this one and revoke this one'|i18n( 'extension/tron' )|wash}">{if eq( $tron_t.state, 'expired' )}{'Renew'|i18n( 'extension/tron' )}{else}{'Rotate'|i18n( 'extension/tron' )}{/if}</button>
            {if eq( $tron_t.state, 'active' )}<button type="submit" class="a4-btn a4-btn-small a4-btn-outline-danger" name="RevokeButton" value="1" aria-label="{'Revoke token %id'|i18n( 'extension/tron',, hash( '%id', $tron_t.id ) )|wash}">{'Revoke'|i18n( 'extension/tron' )}</button>{/if}
        </form>{/if}</td>
</tr>
{/foreach}
</tbody>
</table>
</div>
{else}
<p class="a4-empty">{'No tokens.'|i18n( 'extension/tron' )}</p>
{/if}

{include uri='design:tron/parts/page_close.tpl'}
