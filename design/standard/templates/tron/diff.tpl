{* Setup > tron > Diff (tron/diff): two texts compared with the diff program. Variables: left, right, options
   (granularity, algorithm, context, ignore_whitespace), diff (tronDiffProgram::text(); rows with left_html and
   right_html for line diffs, rendered for word and character diffs), error, view.
   Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version). *}
{include uri='design:tron/parts/page_open.tpl' view=$view title='Diff'|i18n( 'extension/tron' )
         intro='Compares two texts with the engines of the diff program, the same ones agents call as diff_text: by line, word or character, with Myers or patience diff. Nothing you enter here is stored.'|i18n( 'extension/tron' )}

{if $error}<div class="a4-notice is-bad" role="alert"><p>{$error|wash}</p></div>{/if}

<section class="a4-card" aria-labelledby="tron-diff-input">
<div class="a4-card-head"><h2 class="a4-card-title" id="tron-diff-input">{'Texts'|i18n( 'extension/tron' )}</h2></div>
<div class="a4-card-body">
<form method="post" action={'/tron/diff'|ezurl}>
    <div class="a4-tron-diff-form">
        <div class="a4-field">
            <label for="tron-diff-left">{'Left (before)'|i18n( 'extension/tron' )}</label>
            <textarea class="a4-input" id="tron-diff-left" name="TronDiffLeft" rows="12" spellcheck="false">{$left|wash}</textarea>
        </div>
        <div class="a4-field">
            <label for="tron-diff-right">{'Right (after)'|i18n( 'extension/tron' )}</label>
            <textarea class="a4-input" id="tron-diff-right" name="TronDiffRight" rows="12" spellcheck="false">{$right|wash}</textarea>
        </div>
    </div>
    <div class="a4-tron-diff-options">
        <div class="a4-field">
            <label for="tron-diff-granularity">{'Compare by'|i18n( 'extension/tron' )}</label>
            <select class="a4-input is-auto" id="tron-diff-granularity" name="TronDiffGranularity">
                {foreach hash( 'line', 'Line'|i18n( 'extension/tron' ), 'word', 'Word'|i18n( 'extension/tron' ), 'char', 'Character'|i18n( 'extension/tron' ) ) as $tron_k => $tron_l}
                <option value="{$tron_k}"{if eq( $options.granularity, $tron_k )} selected="selected"{/if}>{$tron_l|wash}</option>
                {/foreach}
            </select>
        </div>
        <div class="a4-field">
            <label for="tron-diff-algorithm">{'Algorithm'|i18n( 'extension/tron' )}</label>
            <select class="a4-input is-auto" id="tron-diff-algorithm" name="TronDiffAlgorithm">
                <option value="myers"{if eq( $options.algorithm, 'myers' )} selected="selected"{/if}>Myers</option>
                <option value="patience"{if eq( $options.algorithm, 'patience' )} selected="selected"{/if}>{'Patience'|i18n( 'extension/tron' )}</option>
            </select>
        </div>
        <div class="a4-field">
            <label for="tron-diff-context">{'Unchanged lines around a change'|i18n( 'extension/tron' )}</label>
            <input class="a4-input is-auto" type="number" min="0" max="100" id="tron-diff-context" name="TronDiffContext" value="{$options.context|wash}" />
        </div>
        <div class="a4-field">
            <label><input type="checkbox" name="TronDiffIgnoreWhitespace" value="1"{if $options.ignore_whitespace} checked="checked"{/if} /> {'Ignore whitespace'|i18n( 'extension/tron' )}</label>
        </div>
        <button type="submit" class="a4-btn a4-btn-primary" name="CompareButton" value="1">{'Compare'|i18n( 'extension/tron' )}</button>
    </div>
</form>
</div>
</section>

{if $diff}
<section class="a4-card" aria-labelledby="tron-diff-result">
<div class="a4-card-head"><h2 class="a4-card-title" id="tron-diff-result">{'Differences'|i18n( 'extension/tron' )}</h2></div>
<div class="a4-card-body">
<ul class="a4-figures">
    <li class="a4-figure"><strong>{$diff.summary.added}</strong><span>{'Added'|i18n( 'extension/tron' )}</span></li>
    <li class="a4-figure"><strong>{$diff.summary.removed}</strong><span>{'Removed'|i18n( 'extension/tron' )}</span></li>
    <li class="a4-figure"><strong>{$diff.summary.unchanged}</strong><span>{'Unchanged'|i18n( 'extension/tron' )}</span></li>
</ul>
{if $diff.identical}
<p class="a4-help">{'The texts are identical.'|i18n( 'extension/tron' )}</p>
{elseif is_set( $diff.rows )}
{include uri='design:tron/parts/diff.tpl' rows=$diff.rows left_label='Left'|i18n( 'extension/tron' ) right_label='Right'|i18n( 'extension/tron' ) caption='Differences'|i18n( 'extension/tron' )}
{else}
{$diff.rendered}
{/if}
</div>
</section>
{/if}

{include uri='design:tron/parts/page_close.tpl'}
