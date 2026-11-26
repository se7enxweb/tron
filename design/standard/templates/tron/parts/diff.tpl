{* A side-by-side diff, the reusable component of the diff program (doc/diff.md). The same table, classes and screen
   reader text as tronDiffRender::html(); colours come from stylesheets/modules/tron.css (.a4-tron-diff-*, tokens with
   light and dark values).
   Input: $rows   rows of tronDiffRender::rows() with left_html and right_html already escaped by tronDiffRender
                  (tronDiffRender::e(), or pairHtml() for a changed pair, which marks the words with <del> and <ins>)
          $left_label, $right_label (optional), $caption (optional, the table's accessible name)
   Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version). *}
{def $tron_d_left = cond( is_set( $left_label ), $left_label, 'Left'|i18n( 'extension/tron' ) )
     $tron_d_right = cond( is_set( $right_label ), $right_label, 'Right'|i18n( 'extension/tron' ) )
     $tron_d_sr = hash( 'del', 'removed'|i18n( 'extension/tron' ), 'add', 'added'|i18n( 'extension/tron' ), 'change', 'changed'|i18n( 'extension/tron' ), 'same', '' )}
<div class="a4-table-wrap a4-tron-diff-wrap">
<table class="a4-table a4-tron-diff">
<caption class="a4-sr-only">{cond( is_set( $caption ), $caption, 'Differences'|i18n( 'extension/tron' ) )|wash}</caption>
<thead><tr>
    <th scope="col" class="a4-tron-diff-num"><span class="a4-sr-only">{$tron_d_left|wash} #</span></th>
    <th scope="col">{$tron_d_left|wash}</th>
    <th scope="col" class="a4-tron-diff-num"><span class="a4-sr-only">{$tron_d_right|wash} #</span></th>
    <th scope="col">{$tron_d_right|wash}</th>
</tr></thead>
<tbody>
{foreach $rows as $tron_d_row}
{if eq( $tron_d_row.op, 'gap' )}
<tr class="is-gap"><td colspan="4" class="a4-tron-diff-gap">&#8943;</td></tr>
{else}
<tr class="is-{$tron_d_row.op|wash}">
    <td class="a4-tron-diff-num">{$tron_d_row.left_no|wash}</td>
    <td class="a4-tron-diff-left">{if and( $tron_d_sr[$tron_d_row.op], $tron_d_row.left_no )}<span class="a4-sr-only">{$tron_d_sr[$tron_d_row.op]|wash}: </span>{/if}<code>{$tron_d_row.left_html}</code></td>
    <td class="a4-tron-diff-num">{$tron_d_row.right_no|wash}</td>
    <td class="a4-tron-diff-right">{if and( $tron_d_sr[$tron_d_row.op], $tron_d_row.right_no )}<span class="a4-sr-only">{$tron_d_sr[$tron_d_row.op]|wash}: </span>{/if}<code>{$tron_d_row.right_html}</code></td>
</tr>
{/if}
{/foreach}
</tbody>
</table>
</div>
{undef $tron_d_left $tron_d_right $tron_d_sr}
