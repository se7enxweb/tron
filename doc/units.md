# Units

Units are small read tools with one job each. Every unit takes a declared input type and gives a declared output
type, so units compose into pipes (`tron_pipe`) the way Unix tools compose with `|`, and later into recipes (design
patterns by use case, see [units-roadmap.md](units-roadmap.md)).

A unit is an ordinary Exponential Tron tool of an ordinary program. What makes it a unit is its line in the program's manifest
(`programs/<area>/program.json`, key `"units"`): the unit id (`node.read`), the MCP tool name (`node_read`), the input
types (`in`) and the output type (`out`). Every unit:

- is a tool of its area's program (`ini`, `tpl`, `node`, `class`, `layout`, `role`, `search`), so a program or a single tool can
  be switched off in Setup > tron > Programs and tools;
- checks the policies of the calling Exponential user (the session's user, never a service account), and node units
  read only what that user's `content/read` limitations allow;
- is written to the audit log like any other tool call, and every step of a pipe is audited on its own;
- is redaction-aware: values under secret-looking names (Password, SecretKey, ApiKey, Token ...) come out as
  `[redacted]`, and text has tokens, `password=...` pairs and e-mail addresses masked;
- declares its output schema (`outputSchema`, the schema of its output type), which the contract tests check.

Wave A (this release) has read units only. Nothing a unit does changes the installation.

## Catalog (wave A: read)

| Unit | MCP tool | Policy | In | Out | Arguments |
|---|---|---|---|---|---|
| `ini.resolve` | `ini_resolve` | setup/setup | none | `ini-value` | `file`*, `block`*, `setting`*, `siteaccess` |
| `ini.diff` | `ini_diff` | setup/setup | none | `diff` | `file`*, `a`, `b` (two siteaccesses) or `other_file` (+ `siteaccess`), `block`, `limit` (100, max 500) |
| `ini.list` | `ini_list` | setup/setup | none | `ini-block[]` | `file`*, `siteaccess`, `block`, `values` (false) |
| `tpl.resolve` | `tpl_resolve` | setup/setup | `node-ref`, `template-ref` | `template-chain` | `node_id` or `template`, `view` (full), `siteaccess`, `keys` (extra match keys) |
| `tpl.lint` | `tpl_lint` | setup/setup | `template-file` | `lint-report` | `path` or `source` (template text) |
| `tpl.source` | `tpl_source` | setup/setup | `template-file` | `template-source` | `path`*, `from_line`, `to_line` |
| `node.read` | `node_read` | content/read | `node-ref` | `node` | `node_id`*, `language`, `attributes` (comma list), `max_text` (2000) |
| `node.tree` | `node_tree` | content/read | `node-ref` | `node[]` | `node_id`*, `depth` (1, max 5), `class`, `limit` (25, max 100), `offset`, `sort`, `order` |
| `node.find` | `node_find` | content/read | none, `node-ref` | `node[]` | `class`, `node_id` (subtree, default 1), `path`, `section`, `name`, `attribute` + `value`, `depth` (10), `limit` (10, max 50), `offset`, `sort`, `order` |
| `node.versions` | `node_versions` | content/versionread | `node-ref` | `version[]` | `node_id`*, `limit` (20) |
| `class.describe` | `class_describe` | class/* | `class-ref` | `class` | `identifier`* |
| `class.list` | `class_list` | class/* | none | `class[]` | `group`, `limit` (100), `offset` |
| `class.diff` | `class_diff` | class/* | `class-ref` | `diff` | `identifier`*, `other` (a second class) or `other_definition` (a `class` value from another installation) |
| `layout.list` | `layout_list` | explayouts/read | none | `layout[]` | `shared`, `drafts` (false), `limit` (100), `offset` |
| `layout.read` | `layout_read` | explayouts/read | `layout-ref` | `layout` | `layout_id` or `identifier`, `draft` (false) |
| `rule.list` | `layout_rule_list` | explayouts/read | none, `layout-ref` | `rule[]` | `layout_id`, `enabled`, `limit` (100), `offset` |
| `rule.resolve` | `layout_rule_resolve` | explayouts/read | `path-ref`, `node-ref` | `rule-trace` | `path` or `node_id` |
| `block.definitions` | `layout_block_definitions` | explayouts/read | none | `block-definition[]` | (none) |
| `role.describe` | `role_describe` | role/* | `role-ref` | `role` | `role`* (name or id) |
| `user.find` | `role_user_find` | role/* | none | `user[]` | `text`* (2+ characters, login or name), `limit` (20, max 50), `offset` |
| `search.query` | `search_query` | content/read | none, `node-ref` | `search-hit[]` | `text`*, `node_id` (below this node), `subtree` (node ids), `class`, `section`, `language`, `sort` (relevance), `order` (desc), `facets` (class, section, language), `include_hidden` (false), `limit` (10, max 50), `offset` |
| `search.index` | `search_index` | content/read | none, `node-ref` | `search-index` | `node_id` or `object_id` |

`*` required. `class` filters take one identifier or a comma list (`article,blog_post`). `sort` is one of published,
modified, name, priority, path; `order` asc or desc.

## Types

Ref types (what a unit takes) name a thing by a few fields; value types (what a unit gives) say which refs they can be
turned into ("provides"); list types are `{ total, offset, items }` whose items are of the item type.

| Type | Kind | Fields / required | Provides |
|---|---|---|---|
| `none` | ref | (nothing: the unit only starts a pipe) | |
| `node-ref` | ref | `node_id` | |
| `path-ref` | ref | `path` | |
| `class-ref` | ref | `identifier` | |
| `template-ref` | ref | `template` (node/view/full.tpl) | |
| `template-file` | ref | `path` (from the installation root, inside a design directory) | |
| `layout-ref` | ref | `layout_id` | |
| `role-ref` | ref | `role` | |
| `node` | value | `node_id`, `object_id`, `name`, `class_identifier`, `url_alias`, `attributes` (list of `identifier`, `name`, `datatype`, `value`), and `class_name`, `parent_node_id`, `depth`, `section_id`, `language`, `published`, `modified`, `current_version`, `hidden`, `remote_id` | node-ref, class-ref, path-ref |
| `node-summary` | value | `node_id`, `object_id`, `name`, `class_identifier`, `url_alias`, `parent_node_id`, `depth`, `published`, `modified` | node-ref, class-ref, path-ref |
| `version` | value | `version`, `status`, `language`, `creator_id`, `creator`, `created`, `modified` | |
| `ini-value` | value | `file`, `block`, `setting`, `siteaccess`, `found`, `value`, `winner`, `files` (path, scope, value), `not_loaded` | |
| `ini-block` | value | `block`, `settings` (name, kind, winner, value when asked) | |
| `diff` | value | `a`, `b` (what was compared), `counts` (changed, only_a, only_b), `items` (key, change, a, b) | |
| `template-chain` | value | `template`, `siteaccess`, `node_id`, `keys`, `designs`, `base`, `overrides` (name, match_file, conditions, result, failed), `winner` (path, by, reason) | template-file (winner.path), template-ref, node-ref |
| `template-source` | value | `path`, `lines`, `from`, `to`, `text` | template-file |
| `lint-report` | value | `path`, `ok`, `errors`, `warnings`, `functions`, `operators`, `unknown` | template-file |
| `class` | value | `id`, `identifier`, `name`, `groups`, `is_container`, `always_available`, `object_name_pattern`, `url_alias_pattern`, `attributes` (id, identifier, name, datatype, required, searchable, information_collector, translatable, category, placement) | class-ref |
| `class-summary` | value | `id`, `identifier`, `name`, `groups`, `is_container`, `object_count` | class-ref |
| `layout` | value | `id`, `identifier`, `name`, `layout_type`, `shared`, `status`, `zones` (identifier, linked, source, blocks) | layout-ref |
| `layout-summary` | value | `id`, `identifier`, `name`, `layout_type`, `shared`, `status` | layout-ref |
| `rule` | value | `id`, `priority`, `enabled`, `layout_id`, `layout_identifier`, `targets`, `conditions` | layout-ref |
| `rule-trace` | value | `path`, `layout_id`, `layout_identifier`, `source` (rule, default, none), `rule_id`, `tried` | layout-ref |
| `block-definition` | value | `identifier`, `name`, and the block type's settings | |
| `role` | value | `id`, `name`, `policies` (module, function, limitations), `assignments` (kind, object_id, name, limit_identifier, limit_value) | role-ref |
| `user` | value | `user_id`, `login`, `name`, `enabled`, `roles` | |
| `search-hit` | value | `node_id`, `object_id`, `name`, `class_identifier`, `url_alias`, `section_id`, `language`, `published`, `modified`, `excerpt` | node-ref, class-ref, path-ref |
| `search-index` | value | `engine` (name, class, facets, delayed_indexing), `pending`, `object` (object_id, node_id, name, indexed, queued; null without one) | |
| `node[]` `version[]` `ini-block[]` `class[]` `layout[]` `rule[]` `block-definition[]` `user[]` `search-hit[]` | list | `total`, `offset`, `items` (`search-hit[]` also `engine`, `sort`, `facets_supported`, `facets`, `stop_words`) | (per item) |

### Search

`search.query` and `search.index` use the installation's own search engine, the one its search page uses
(`[SearchSettings] SearchEngine` in site.ini: the built-in engine, eZ Find, or another plugin), with the parameters
the kernel's `fetch( 'content', 'search' )` passes. The calling user's `content/read` policies go into the search as
its limitation and the user is the current user for the call, so a hit the user may not read never comes back and
`total` counts only what the user may read; each hit is also checked with the kernel's own read check.

- `sort`: `relevance` (default), `published`, `modified`, `name`, `path`, `class_identifier`, `section`, with `order`.
  The built-in engine does not rank by relevance; it then sorts by publication date and `sort` in the answer says so.
- `facets`: `class`, `section` and `language` counts where the engine has facets (eZ Find); otherwise
  `facets_supported` is false, `facets` is empty and a `note` says why.
- `language`: hits available in that translation (the built-in engine also returns always-available objects).
- `excerpt`: the engine's highlight when it gives one, else about 240 characters of plain text around the first
  search word in the hit's text attributes. Names and excerpts are redacted like every unit answer (e-mail addresses,
  tokens and secrets masked).
- `search.index`: the engine (named `private` when it is in `[SystemAdapterSettings] PrivateNames[]`), its indexing
  mode (`DelayedIndexing`), the objects waiting in the indexing queue (`ezpending_actions`), and for one node or object
  whether the engine holds it (`indexed`: the built-in engine's word links; `null` for engines that do not say) and
  whether it is queued. A node the user may not read is "not found".

```
node.find class=landing_page | search.query text=breakfast
    a full-text search below every landing page (node-summary -> node-ref as the subtree)
```

The type catalog in code is `tronUnitTypes`; the units are read from the manifests by `tronUnitCatalog`, whose
`contracts()` gives every unit's tool, types and output schema.

## Pipes

`tron_pipe` (policy tron/use; offered where the units are) runs units one after another. What a step gives is turned
into what the next step takes by type: a `node-summary` becomes a `node-ref` from its `node_id`, a `template-chain` a
`template-file` from `winner.path`. A list feeds the next step item by item (fan out), at most `max_items` (default 10,
max 25) items per step; the same arguments are called once. A pipe has at most 8 steps and 100 calls, may hold read
units only, and each step is checked against the caller's policies and audited as its own tool call.

Arguments: `pipe` (text) or `steps` (list of `{ "unit": "node.read", "args": { ... } }`), `max_items`, `explain`
(true: the plan with the types per step, nothing run).

```
node.find class=article limit=3 | node.read | tpl.resolve siteaccess=site
```

In the text form a step is the unit (id or tool name) followed by `key=value` pairs (`key value` works too); values
are read by the tool's input schema (numbers, true/false, comma lists).

### Examples

```
node.find class=article limit=3 | node.read | tpl.resolve siteaccess=site
    which template renders each of three articles, and why (node-summary -> node-ref -> node -> node-ref)
node.find path=Fit-Healthy depth=1 | rule.resolve | layout.read
    the layouts the pages below a landing page get, with their zones and blocks (node -> path-ref, rule-trace -> layout-ref)
tpl.resolve node_id=60 | tpl.source | tpl.lint
    the template that wins for a node, its text, and its syntax check (template-chain -> template-file)
node.find class=article | class.describe
    the article class, described once (identical arguments are called once)
layout.list shared=true | layout.read
    every shared layout (header and footer, prefooter) with the blocks other layouts link to
```

`explain=true` returns the plan only: per step the unit, its tool, the input type it is fed with, its output type and
whether it fans out. The answer of a run lists per step the inputs, calls, reused calls, dropped items (beyond
`max_items`) and errors, then the results of the last step.

## How the units read

- **ini.\*** use the kernel's own settings chain (`expIniLocator`, `expSettingsChain`) on an uncached eZINI of the
  siteaccess: the same load order eZINI uses, so `winner` is the file whose value is in effect. `not_loaded` lists
  files of extensions not active in that siteaccess that would set the value.
- **tpl.resolve** reads the override list of the siteaccess (`eZTemplateDesignResource::overrideArray()`, in Priority
  order) and replays the kernel's match: an override with conditions wins when every condition matches the node's
  keys (`url_alias` as a prefix, list keys such as `class_group` by membership) and the search stops; an override
  without conditions is taken and the search goes on; without any, the plain template of the most preferred design
  wins. The node's keys are the ones a node view sets (object, node, parent_node, class, class_identifier, viewmode,
  depth, url_alias, class_group, state, section, parent_class_identifier ...). `keys` adds or replaces keys, to ask
  "which template would win for a node of class x".
- **tpl.lint** runs `eZTemplate::validateTemplateFile()`, lists the functions and operators of the parsed tree, names
  those the installation does not register, and adds what the parser does not report: a block function (`{if}`,
  `{foreach}`, `{section}` ...) opened more often than closed.
- **tpl.source / tpl.lint** read only `.tpl` files under `design/<d>/templates`, `design/<d>/override/templates`, or the
  same under `extension/<ext>/`; `..` is refused and the real path must stay inside the installation or that extension.
- **node.\*** check a single node with `checkAccess( 'read' )` for the calling user and pass the user's content/read
  policies as the Limitation of list fetches. Attributes come out as plain values per datatype (rich text without
  markup, images as alias data, relations as ids and names, a user account as its login only, authors as names).
- **layout.\*** read the layout tables through `expLayoutsLayout`, `expLayoutsZone` and `expLayoutsBlock`; a linked
  zone shows the blocks of the zone it links to (`expLayoutsZone::resolveSource()`). **rule.resolve** tries the
  enabled rules in the resolver's order with `expLayoutsResolver::targetsMatch()` and `conditionMatches()`, without
  the resolver cache (a read never writes a cache entry), and falls back to `[ResolverSettings] DefaultLayout`.
- **block.definitions** reads the `[BlockDefinition_*]` groups of explayouts.ini.
- **role.describe / user.find** read published roles (`eZRole`) and accounts; of an account only id, login, name,
  enabled and role names leave the server.

## Writing a unit

A program declares its units in its manifest (`"units"`, see above) and registers each one's tool as usual; the
`tronUnitProgram` base class adds the unit line to the description and the output schema from the output type. The
manifest is checked when the catalog reads it: the id is `area.name`, the tool belongs to the program, every input type
is a ref type, the output type a value or list type. An output's required fields are listed per type in `tronUnitTypes`.
Tests: `tests/unit/UnitsTest.php` (fixtures in `tests/fixtures/units_sources.php`); in an installation
`tests/kernel/units_live_run.php` and `tests/kernel/units_stdio_smoke.php`.

## Planned (not in wave A)

Write units with a dry run (wave B), data movers (wave C), recipes (wave D) and planning from expai (wave E) are
described in [units-roadmap.md](units-roadmap.md). Their names are not fixed yet.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
