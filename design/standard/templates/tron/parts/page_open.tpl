{* Opening of every Setup > tron page: the title, the tabs and the intro. Input: $view, $title, $intro (optional).
   Closed by tron/parts/page_close.tpl. The body uses the administration's shared components (components.css, .a4-*)
   and stylesheets/modules/tron.css (.a4-tron-*), so it follows light and dark mode. Every admin design shares it.
   Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version). *}
<div class="context-block a4-page a4-tron-page">

<div class="box-header"><div class="box-ml">
<h1 class="context-title">{$title|wash}</h1>
</div></div>

<div class="box-bc"><div class="box-ml"><div class="box-content">

{include uri='design:tron/parts/tabs.tpl' view=$view}

{if is_set( $intro )}<p class="a4-intro">{$intro|wash}</p>{/if}
