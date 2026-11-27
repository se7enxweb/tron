# Layouts and blocks

Exponential Layouts: layout inventory, shared layouts, drafts, zones and their links, blocks and broken blocks, which layout a node or URL gets and why, rules, block definitions by category.

Pack `layouts-and-blocks` 0.0.0.1, GPL-2.0-or-later, by 7x. Skill names start with `layout.`.
It works on the data of the extension `explayouts`.

## Who it is for

Site builders and editors who work with Exponential Layouts.

## Skills

| Skill | What it answers | Parameters | Units | Wave |
|---|---|---|---|---|
| `layout.inventory` | Every published layout with its type, whether it is shared and how many zones it has. | (none) | layout_list | A |
| `layout.shared` | The shared layouts (header, footer, prefooter ...) whose zones other layouts link to. | (none) | layout_list | A |
| `layout.drafts` | The layouts that have an unpublished draft, oldest change first. | (none) | layout_list | A |
| `layout.recent` | The layouts changed most recently, newest first. | (none) | layout_list | A |
| `layout.types` | How many layouts use each layout type. | (none) | layout_list | A |
| `layout.zones` | The zones of a layout: which are linked to a shared layout, where their blocks come from, and how many blocks each has. | `--layout-id` | layout_read | A |
| `layout.blocks` | Every block of a layout with its definition and view type, and how many of each definition it uses. | `--layout-id` | layout_read | A |
| `layout.empty-blocks` | The blocks of a layout that have no block definition (left over or broken), which render nothing. | `--layout-id` | layout_read | A |
| `layout.for-node` | Which layout a node resolves to, through which rule (or the default), and which rules were tried on the way. | `--node-id` | layout_rule_resolve | A |
| `layout.for-path` | Which layout a URL path resolves to and through which rule. | `--path` | layout_rule_resolve | A |
| `layout.rules` | Every layout rule by priority, with its targets and conditions. | (none) | layout_rule_list | A |
| `layout.rules-disabled` | The layout rules that are switched off. | (none) | layout_rule_list | A |
| `layout.rules-of-layout` | The rules that send pages to one layout. | `--layout-id` | layout_rule_list | A |
| `layout.rules-unconditional` | The layout rules that apply to every request of their targets (no siteaccess, class or other condition). | (none) | layout_rule_list | A |
| `layout.rules-per-layout` | How many layout rules point to each layout. | (none) | layout_rule_list | A |
| `layout.block-definitions` | Every block definition editors can place, with its category and view types. | (none) | layout_block_definitions | A |
| `layout.block-definitions-collections` | The block definitions that hold a collection of content (lists, grids, sliders). | (none) | layout_block_definitions | A |
| `layout.block-categories` | How many block definitions each category has. | (none) | layout_block_definitions | A |
| `layout.blocks.category` | The block definitions of one category (text, media, listing, navigation, form, container). | `--category` | layout_block_definitions | A |

## Units it needs

`layout_block_definitions`, `layout_list`, `layout_read`, `layout_rule_list`, `layout_rule_resolve`

A skill whose unit is not offered here is listed as parked with the reason; it runs nothing. Every skill runs as the
calling user, each unit checked against that user's policies and written to the audit log.

## Running one

```
xsh tron:skill:run layout.inventory
```

Over MCP a live skill is the tool `skill_layout_inventory` and a prompt of the same name.

See tron's doc/skills.md and doc/extension-conventions.md.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
