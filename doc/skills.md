# Skills

A skill is a reviewed chain of units, written down as data. It has a name, a purpose, typed parameters with ranges,
defaults, examples and CLI options, the units it runs and how their arguments are wired, a few declarative steps
between them (keep, sort, count, group ...), guards, and the schema of its answer. A skill holds no code, so anyone can
read exactly what it will do before it runs, and it can never do more than the person who runs it could do by hand:
every unit it calls is checked against that person's policies and written to the audit log on its own.

Skills come in packs, and packs are the loadable parts of the library: tron ships its own starter packs, and any
Exponential extension can ship packs of its own (and programs, which bring new units). People remix skills by forking
them into a pack of their own site.

The rules a skill and a pack must keep are in [extension-conventions.md](extension-conventions.md). This page explains
the concept, the lifecycle and the tools; [skill-packs/](skill-packs/) describes every starter pack.

## The lifecycle

```
 draft ──> validate ──> approve ──> live
 (a person, or the AI    (schema, units, policy     (a person, in Setup >     MCP tool skill_<name>,
  through skill_draft;    simulation, example runs,  tron > Skills, after       MCP prompt skill_<name>,
  or a pack's file)       redaction)                 "Verify it's you")         xsh tron:skill:run <name>
```

| State | Meaning | What moves it on |
|---|---|---|
| `refused` | its pack breaks the standard; the reasons are listed and nothing of the pack is offered | fix the pack |
| `parked` | a unit it runs is not here (it waits for that unit's wave), or it writes (skills that write wait for wave B) | the unit lands |
| `unproven` | the harness has not proven exactly this manifest | `tron-skills.php harness` |
| `failed` | the harness ran it and it failed (the reason is kept in proof.json) | fix the skill |
| `ready` | proven, waiting for a person to approve it | Approve in Setup > tron > Skills |
| `live` | proven and approved: offered as a tool, a prompt and an xsh command | Revoke |
| `disabled` | its pack is switched off (INI or Setup > tron > Skills) | switch it on |

An approval holds for exactly one manifest: it stores the manifest's SHA-256. Change one character of a live skill and
it falls back to `ready` until someone approves it again. Every approval, revocation, draft and fork is a row of the
table `tron_skill`, which is never cleared, so every version of every skill is kept.

## Running a skill

Over MCP, a live skill is a tool of its own and a prompt of the same name:

```json
{ "method": "tools/call", "params": { "name": "skill_centre_tickets_overdue", "arguments": { "board": "ops", "limit": 5 } } }
```

The tool's input schema is the skill's parameters; its output schema is the skill's output schema. The prompt
`skill_centre_tickets_overdue` renders a message that asks the model to call the tool and explain the answer.
`skill_run` runs a live skill by name and also answers the trace (one row per step with its outcome and timing).

From the shell, with the same parameters as long options (and their short forms):

```
xsh tron:skill:run centre.tickets.overdue --board ops --limit 5
xsh tron:skill:run centre.tickets.overdue -b ops -n 5 --json
```

Each step runs through the server exactly as a direct call would: scope, policy, argument check, audit entry,
redaction. The activity journal records the steps, because they are ordinary calls of the session.

## The tools of the program "skill"

| Tool | What it does |
|---|---|
| `skill_list` | the catalog: search by words, tags, state, pack; counts per state |
| `skill_show` | one skill: manifest, state and why, provider, proof, the CLI line, every stored version |
| `skill_packs` | the packs with the extension that provides each (name, version, license) and its state |
| `skill_run` | runs a live skill by name; answers output and trace |
| `skill_draft` | stores a manifest as a draft (refused with every reason when it breaks the standard); has a dry run |
| `skill_validate` | proves a draft or a skill as the caller, without changing anything |
| `skill_fork` | copies a skill into a local pack as a draft under a new name, keeping a "derived from" link and the license; has a dry run |

All of them need only `tron/use`; what a skill may read is decided by the policies of each unit it runs. Approving needs
`tron/admin` and happens only in the administration.

## Writing a skill

A minimal skill (the worked example of [extension-conventions.md](extension-conventions.md) has a whole extension):

```json
{
    "name": "example.centre.labels",
    "title": "Labels in use",
    "purpose": "Counts the labels of a board's open tickets, most used first, so a team sees where its work goes.",
    "version": "1.0.0.3",
    "author": "Example",
    "license": "GPL-2.0-or-later",
    "tags": [ "centre", "labels", "example" ],
    "use_case": "Planning: which areas take the work",
    "parameters": {
        "board": { "type": "string", "pattern": "^[a-z0-9][a-z0-9-]{0,63}$", "default": "main",
                   "description": "The board.", "examples": [ "main" ], "short": "-b" }
    },
    "requires": { "tron": "0.0.0.2", "units": [ "centre_ticket_list" ], "programs": [ "centre" ], "wave": "A" },
    "steps": [
        { "id": "list", "unit": "centre_ticket_list", "args": { "board": "$param.board", "limit": 200 } },
        { "id": "labels", "op": "group", "from": "$steps.list.items", "by": "labels" }
    ],
    "output": {
        "value": { "board": "$param.board", "tickets": "$steps.list.total", "labels": "$steps.labels" },
        "schema": { "type": "object", "required": [ "board", "labels" ], "properties": {
            "board": { "type": "string" }, "tickets": { "type": "integer" },
            "labels": { "type": "array", "items": { "type": "object", "properties": {
                "key": { "type": "string" }, "count": { "type": "integer" } } } } } }
    },
    "examples": [ { "title": "The main board", "params": { "board": "main" } } ]
}
```

### Wiring

| Reference | Is |
|---|---|
| `$param.board` | a parameter of the run (defaults applied) |
| `$steps.list.items` | what an earlier step gave, and a path into it |
| `$item.id` | inside an `each` step: the current item |
| `$now`, `$today`, `$week` | unix time, `YYYY-MM-DD` and the ISO week of the run (the server's clock) |
| `$date.-30d`, `$date.+2w` | a date relative to today |
| `$ago.days`, `$ahead.days` | today minus or plus as many days as the number parameter `days` says |
| `$user.login`, `$user.user_id` | the person the skill runs as |
| `$steps.list.items\|count` | a filter: `count first last keys join lower upper int bool not empty json sum` |
| `"{{$steps.n}} open"` | references inside text |
| `"$$5"` | a literal text that starts with one `$` |

A whole-value reference keeps its type (a list stays a list). A parameter left out is an argument left out: the unit
uses its own default.

### Steps

A step either runs a unit (`"unit"` plus `"args"`) or is one of the declarative ops, none of which reads or writes the
installation:

| Op | Takes | Gives |
|---|---|---|
| `where` | `from`, `field`, `cmp`, `value` | the items whose field compares (dates compare as dates) |
| `pick` | `from`, `fields[]` | the items with only these fields |
| `map` | `from`, `fields{ new: path }` | new items made of renamed fields |
| `sort` | `from`, `by`, `order` | the items in order (empty values last) |
| `limit` | `from`, `count` (number or `$param.x`), `offset` | a slice |
| `count` | `from` | the number of items |
| `group` | `from`, `by` | `[ { key, count } ]`, largest first; a list field counts each value |
| `sum`, `min`, `max` | `from`, `field` | one number |
| `distinct`, `pluck` | `from`, `field` | the (distinct, sorted) values of a field |
| `flatten` | `from`, `field` | the lists under `field` of every item, joined |
| `age` | `from`, `field`, `as` | each item with `as` = whole days since the field's date |
| `merge` | `values{}` | an object of resolved values |
| `text` | `template` | a text with `{{$...}}` references |
| `test` | `value`, `cmp`, `compare` | true or false (a check's verdict) |
| `assert` | `value`, `cmp`, `compare`, `message` | stops the run with `message` when the comparison fails |
| `each` | `from`, `unit`, `args`, `max` (at most 50) | the unit's answer for every item, `$item` in `args` |

Comparisons: `eq ne lt le gt ge in nin contains prefix empty notempty match`. Any step may have `"when"`: a reference
that must be true for the step to run. A run makes at most 100 unit calls and a skill has at most 16 steps.

### Trying it

```
php extension/tron/bin/php/tron-skills.php lint extension/myext/tron/skills/my-pack
php extension/tron/bin/php/tron-skills.php try example.centre.labels --fixtures --board main
php extension/tron/bin/php/tron-skills.php try content.class.count --user=<test admin> --class image
php extension/tron/bin/php/tron-skills.php unit node_find --user=<test admin> --args='{"class":"image","limit":2}'
```

Or over MCP: `skill_draft` with the manifest, then `skill_validate` with the draft id.

## Proving: the harness

`tron-skills.php harness` runs every example of every skill and records the result in the pack's `proof.json`
(name, version, the manifest hash, the result per mode and the checks):

```
php extension/tron/bin/php/tron-skills.php harness --fixtures --write-proof              # tron's SQLite test world
php extension/tron/bin/php/tron-skills.php harness --live --user=<test admin> --write-proof   # read-only on this installation
```

For each skill it checks, in order: the manifest keeps the standard; every unit is registered here and only reads;
the policy simulator allows every unit and guard for the user; every example runs and its answer matches the output
schema; and no answer still holds anything the redactor would mask. The fixtures plant canaries (a fake password, a
real-shaped tron token, an e-mail address) in ticket bodies and comments; they must never reach an answer. A skill
whose unit is not here is `parked`, not failed. A skill is proven when a mode passed and none failed. A skill that is
not proven is never offered, whatever its approval says.

## Packs

```
<pack id>/
  pack.json          id, title, description, version, author, license, prefix, requires { tron, programs }, for_extension, local
  README.md          what the pack is for, every skill, the units it needs (required)
  skills/<name>.json one file per skill, named after the skill
  proof.json         written by the harness
  translations/<locale>.json   optional
```

Where packs are found, and the INI group an extension adds for each, is in
[extension-conventions.md](extension-conventions.md). In Setup > tron > Skills a pack can be switched off (table
`tron_skill_pack`); `[SkillPackSettings] Disabled[]` does the same by INI.

```
php extension/tron/bin/php/tron-skills.php export centre-tickets --to=var/tmp/centre-tickets.tar.gz
php extension/tron/bin/php/tron-skills.php install var/tmp/centre-tickets.tar.gz --extension=mysite
```

`install` checks the pack against the standard first, copies only its data files into
`extension/<ext>/tron/skills/<id>/` and adds the `[SkillPack_<id>]` group to that extension's
`settings/tron.ini.append.php`. It never overwrites a pack.

## Remixing

`skill_fork` (or Fork in Setup > tron > Skills) copies a skill under a new name into a local pack, as a draft:

- the new manifest gets `"derived_from": { "name", "version", "pack", "license", "author" }` of the original;
- it keeps the original's license (a fork of a GPL skill stays GPL; the standard refuses anything else);
- it is written into a local pack (`local`, stored in the database, or a pack of a site extension marked
  `"local": true`), never into the provider's folder;
- it starts at version 0.0.0.1 and goes through validate and approve like any draft.

`tron-skills.php export-local <pack> --to=<dir>` writes the approved drafts and forks of a local pack as files, so a
site can commit them to its own extension and ship them as a pack.

## Translations

A skill carries its own translations in `"i18n": { "ger-DE": { "title": ..., "purpose": ..., "use_case": ... } }`; the
administration shows them for the user's locale. The interface texts of Setup > tron > Skills are in tron's
`translations/eng-GB` and `translations/ger-DE`.

## Starter library

tron ships these packs (counts per state are in Setup > tron > Skills and `tron-skills.php counts`):

| Pack | Prefix | About |
|---|---|---|
| [centre-tickets](skill-packs/centre-tickets.md) | `centre.` | lists over the centre's tickets |
| [centre-planning](skill-packs/centre-planning.md) | `centre.` | counts, board snapshot, health, digest, weekly report |
| [centre-decisions](skill-packs/centre-decisions.md) | `centre.` | the decision review in parts |
| [centre-triage](skill-packs/centre-triage.md) | `centre.` | bulk changes to tickets (parked until wave B) |
| [content-inventory](skill-packs/content-inventory.md) | `content.` | what the site holds, nodes, versions, drafts |
| [classes-and-schema](skill-packs/classes-and-schema.md) | `class.` | the content model and class comparisons |
| [media](skill-packs/media.md) | `media.` | the media library and image aliases |
| [seo](skill-packs/seo.md) | `seo.` | URLs, depth, description and meta data |
| [i18n](skill-packs/i18n.md) | `i18n.` | languages per siteaccess and translations |
| [ini-and-settings](skill-packs/ini-and-settings.md) | `ini.` | where a setting is set, siteaccess comparisons |
| [site-audit](skill-packs/site-audit.md) | `audit.` | settings checks with a verdict, production readiness |
| [layouts-and-blocks](skill-packs/layouts-and-blocks.md) | `layout.` | Exponential Layouts: layouts, zones, blocks, rules |
| [templates-and-overrides](skill-packs/templates-and-overrides.md) | `tpl.` | which template renders what, lint, source |
| [users-and-roles](skill-packs/users-and-roles.md) | `role.` | access reviews |
| [ops-reports](skill-packs/ops-reports.md) | `ops.` | tron itself |
| [katalyst-reports](skill-packs/katalyst-reports.md) | `katalyst.` | hosting and shop reports by ids and counts |

## Administration

Setup > tron > Skills lists the packs (with the providing extension, its version and license) and the skills with
search, tag and state filters. A skill's page shows its manifest, parameters, examples, proof and versions. Approve,
Revoke, switching a pack and Fork ask "Verify it's you" first (`[ReauthGroup_tron]`). Approving proves the skill again
as the approving administrator; a skill that does not pass is not approved. See [admin-views.md](admin-views.md).

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
