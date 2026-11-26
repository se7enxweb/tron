# Agent rules: AGENTS.md and MASTER.md

Agents do better work when they know a project's rules before they start: how a change is verified, what must never
be committed, how versions are numbered, how templates are styled. tron's **agent rules layer** hands every agent
those rules with its session, from two files:

| File | What it is | Where it comes from |
|---|---|---|
| **AGENTS.md** | The site's own rules for agents, written by the team | The site's prompt directory; no shipped default |
| **MASTER.md** | The Exponential rules: the working rules of the Exponential project itself, made shippable | tron's `doc/MASTER.md`, or a site override |

**MASTER.md supersedes AGENTS.md.** While both are on, an agent receives MASTER.md first, then AGENTS.md, with the
sentence "MASTER.md (the Exponential rules) SUPERSEDES the site's AGENTS.md. Where the two conflict, follow MASTER.md;
where they do not, follow both." MASTER.md is there for teams whose AGENTS.md is missing, a skeleton, or short: it
brings verification checklists, release and versioning rules (the last position counts on: `x.y.z.9` is followed by
`x.y.z.10`), commit message rules, styling rules for the administration, database pitfalls and safety rules. A team
with strong rules of its own can replace MASTER.md or switch it off.

## Contents

- [Where the files are](#where-the-files-are)
- [How an agent receives the rules](#how-an-agent-receives-the-rules)
- [Which source is active](#which-source-is-active)
- [Switching MASTER.md off](#switching-mastermd-off)
- [Writing an AGENTS.md](#writing-an-agentsmd)
- [Setup > tron > Agent rules](#setup--tron--agent-rules)
- [Regenerating MASTER.md](#regenerating-mastermd)
- [Settings](#settings)
- [Security notes](#security-notes)
- [Examples](#examples)
- [Tests](#tests)

## Where the files are

Everything a site keeps lives in its **prompt directory**: `tron.ini [PromptRules] Directory`, by default
`<VarDir>/storage/claude/prompt` of the siteaccess. On a standard installation with `VarDir=var/site` that is:

```
var/site/storage/claude/prompt/
    AGENTS.md            the site's rules (optional)
    MASTER.md            a site override of MASTER.md (optional)
    MASTER.disabled      the file marker: MASTER.md is off while it exists (optional)
    rules-state.json     the admin toggle, written by Setup > tron > Agent rules (optional)
```

| Rules file | Used | Otherwise |
|---|---|---|
| MASTER.md | `<prompt dir>/MASTER.md` when it exists (**source: override**) | `extension/tron/doc/MASTER.md` (**source: default**), or `[PromptRules] DefaultMaster` when set |
| AGENTS.md | `<prompt dir>/AGENTS.md` when it exists and is not empty (**source: override**) | nothing (**source: none**) |

An override replaces the default completely; the default is then not read at all. Remove the override to return to the
shipped MASTER.md. A site with several siteaccesses on different `VarDir`s has one prompt directory per `VarDir`.

On a cluster of web nodes, the prompt directory is a plain directory on each node, like the rest of `var/`. Point
`[PromptRules] Directory` at a shared mount, or deploy the files to every node, so that every node gives agents the
same rules; the admin page writes on the node that answered it.

## How an agent receives the rules

| Way | What it gives |
|---|---|
| The `initialize` instructions | tron's own instructions, then the combined rules (cut at `InstructionsMaxBytes`, with a pointer to the resource) |
| Resource `tron://rules/combined` | `{ master: { source, decided_by }, agents: { source, strength }, precedence, text }` |
| Resource `tron://rules/master` | `{ active, source, file, decided_by, bytes, sha256, text }` |
| Resource `tron://rules/agents` | `{ active, source, strength, bytes, sha256, superseded_by_master, text }` |
| Resource `tron://rules/status` | Both without the texts, plus every switch in precedence order and the one that decided |
| Prompt `tron_project_rules` | The combined rules as a user message; argument `master=disabled` leaves MASTER.md out this once |
| Prompt `tron_rules_wizard` | An interview that writes a starter AGENTS.md with the user (see below) |
| Tool `tron_rules` | The status and the combined text; `master=disabled` switches MASTER.md off for the rest of the session |
| `tron_status` | A `rules` field: `{ master, master_decided_by, agents, agents_strength }` |

All of them need only `tron/use`. File paths appear only for a user holding `tron/admin` (the resources and
`tron_rules`) and on the admin page. Like every answer, the texts pass tron's redactor on the way out.

## Which source is active

Four places say which MASTER.md and AGENTS.md are in force, all from the same code (`tronRules::state()`):

- **Setup > tron > Agent rules**: the figures at the top, the card "Which rules agents receive", the switch table with
  the switch that decides.
- **`tron_status`**: `rules.master` is `default`, `override` or `off`; `rules.agents` is `override` or `none`.
- **The resources**: the field `source` of `tron://rules/master`, `tron://rules/agents` and `tron://rules/combined`.
- **The command line**: `php extension/tron/bin/php/tron-rules.php status --siteaccess=<sa> [--user=<login>]`.

`strength` rates the AGENTS.md: `missing`, `hollow` (a skeleton: headings and placeholders, hardly a rule), `weak` (a
few rules) or `solid`. It is advice for the administrator; it changes nothing on its own.

## Switching MASTER.md off

Seven switches, in **precedence order**. For the site, the first switch that is set decides; after that, a user's
preference and a session's flag can only switch MASTER.md **off** for themselves, never on.

| # | Switch | How | Can | Scope |
|---|---|---|---|---|
| 1 | **File marker** | A file `MASTER.disabled` in the prompt directory (any content) | off | the site (that `VarDir`) |
| 2 | **Environment variable** | `TRON_MASTER_RULES=disabled` (or `enabled`) in the environment of the PHP process | on / off | that process: one stdio server, one PHP-FPM pool |
| 3 | **Admin toggle** | Setup > tron > Agent rules, "Admin toggle": on, off, or not set | on / off | the site |
| 4 | **INI** | `tron.ini [PromptRules] Master=enabled|disabled`, in `settings/override/` or in a siteaccess's `tron.ini.append.php` | on / off | the site, or one siteaccess |
| 5 | **Default** | none of the above set | on | |
| 6 | **User preference** | Setup > tron > Agent rules, "Switch MASTER.md off for me" (preference `tron_master_rules=disabled`) | off | that user's sessions |
| 7 | **Session flag** | `initialize` with `_meta: { "tron/masterRules": "disabled" }`, or the tool `tron_rules` with `master=disabled`, or the prompt argument `master=disabled` (that one prompt only) | off | that session |

Notes:

- Values are read leniently: `enabled`, `on`, `true`, `yes`, `1` and `disabled`, `off`, `false`, `no`, `0`. Anything
  else counts as not set.
- The environment variable can switch MASTER.md back **on** over the admin toggle and the INI, which is useful for a
  one-off run; the file marker always wins.
- A per-siteaccess INI value is reported with its placement (for example "siteaccess, siteaccess site_admin") in the
  switch table and in `tron-rules.php status`.
- A switched-on MASTER.md whose file is missing is reported as off with `decided_by: missing`.
- Changes take effect with the next session (stdio) or request (HTTP). An open stdio session keeps the instructions it
  was given at `initialize`; the resources and `tron_rules` always read the current state.
- AGENTS.md has one switch: `[PromptRules] Agents=disabled` leaves it out everywhere. `Instructions=disabled` keeps
  both files out of the `initialize` instructions while the resources, prompts and tool still serve them.

## Writing an AGENTS.md

Four ways, all ending in the same file:

1. **Edit or upload** it in Setup > tron > Agent rules. Saving, uploading and removing ask "Verify it's you" first.
2. **The questionnaire** (no model involved): answer up to eleven questions in Setup > tron > Agent rules (project
   name and type, purpose, stack, conventions, always, never, verification, releases, commit messages, who decides)
   and press "Write the starter". A starter AGENTS.md with the sections Project, Stack, Conventions, Always, Never,
   Verification, and Commits and releases lands in the editor; a question left empty gets a safe default. Nothing is
   saved until you save it. The same generator runs on the command line:
   `php extension/tron/bin/php/tron-rules.php generate --answers=answers.json`.
3. **The expai wizard**, when Exponential Intelligence (expai) is active and your role grants `expai/write`: "Draft
   with Exponential Intelligence" sends your answers and the starter to your expai model, which writes a fuller
   AGENTS.md into the editor. The request goes through expai's usage recorder (action `tron:agents_md`), so it counts
   against expai's budgets, follows its switches and appears in its usage log. Read the draft before saving it.
4. **Any agent**, through the prompt `tron_rules_wizard`: it asks the user the same questions one or two at a time and
   writes the draft in the conversation. The agent does not save it: there is deliberately no MCP tool that writes
   AGENTS.md, because a file every future agent obeys is a person's decision. A person pastes or uploads the draft.

Or put the file in the prompt directory yourself (deploy tooling, configuration management).

## Setup > tron > Agent rules

`/tron/rules`, policy `tron/admin`. The page has:

- **Figures**: MASTER.md source, the switch that decided, AGENTS.md source and strength.
- **Which rules agents receive**: both files with their source, path and hash, the precedence, the prompt directory.
- **Switching MASTER.md off**: the seven switches with their current value and the one that decides; the admin
  toggle, the file marker and your own preference.
- **Questionnaire**: the eleven questions, "Write the starter", and "Draft with Exponential Intelligence" where expai
  can run (otherwise the reason it cannot).
- **AGENTS.md of this site**: the editor, save, remove, upload.
- **MASTER.md**: the active text to read; edit, save or upload a site override; remove it.
- **What an agent receives**: the combined text for your own sessions.

Saving, uploading or removing either file, the admin toggle and the file marker ask "Verify it's you"
(`[ReauthGroup_tron]` in tron's `settings/site.ini.append.php`); the questionnaire, the expai draft and your own
preference do not. Every change is written to the audit log as `admin/rules.agents.save`, `admin/rules.agents.remove`,
`admin/rules.master.save`, `admin/rules.master.remove`, `admin/rules.master.toggle` or `admin/rules.master.marker`,
with the size and a hash of the text, never the text. Saving warns, by line number and kind, when the text looks like
it holds a secret, and when it reads like a skeleton.

## Regenerating MASTER.md

MASTER.md is generated from the Exponential project's own AGENTS.md, which holds private material (credentials of
staging systems, internal host names, private paths and tools). The sanitizer (`tronRulesSanitizer`) makes it
shippable:

```bash
php extension/tron/bin/php/tron-rules.php sanitize --in=/path/to/AGENTS.md --out=extension/tron/doc/MASTER.md \
    --secrets-file=/path/outside/the/repository/secrets.txt
php extension/tron/bin/php/tron-rules.php check extension/tron/doc/MASTER.md
```

1. **An allow list of sections.** Only the sections named in `tronRulesSanitizer::SECTIONS` are kept: the release
   rules, source control, build and run, release versioning, extension versions, changelog and commit format, database
   gotchas, autoloading, conventions, administration styling, file removal, verification, security. A new section in
   the source stays out until someone adds it to the list on purpose.
2. **Private blocks.** A line naming a private tree, an internal tool, an internal host or a hosting panel path takes
   its whole list item or paragraph with it (inside code blocks only the line).
3. **Placeholders.** Credential pairs become `` `<ADMIN_USER>` / `<ADMIN_PASSWORD>` ``; tokens and keys `<TRON_TOKEN>`,
   `<API_KEY>`, `<JWT>`, `<SECRET>`; connection strings `mysql://<DB_USER>:<DB_PASSWORD>@db.example.org/<DATABASE>`;
   internal host names `www.example.org`, `admin.example.org`, `api.example.org`; addresses `192.0.2.x`; server paths
   `/path/to/site`; the product's former name Exponential. The `--secrets-file` lists literal secrets (one per line)
   that are replaced as well; keep it outside the repository, mode 0600.
5. **The installation's own private words.** What is private to one installation is not written in tron at all:
   `[PromptRules] PrivateNames[]` (a line naming one is left out with its item), `PrivateDomains[]` (replaced by
   example.org names) and `[WebSettings] MeshNetworks[]` (addresses replaced by `192.0.2.20`) come from the
   installation's `settings/override/tron.ini.append.php`, which is never shipped. The command line reads that file
   from the current directory (run it from the installation root); with the kernel loaded the INI is used.
4. **The check.** `sanitize` writes nothing when `check` would find anything, and `check` reports a finding by pattern
   name and line number only, never the matching text, so its output is safe to paste.

The test `TronRulesTest::testShippedMasterIsClean` fails when the shipped file has any finding, and
`testSanitizerLeavesNoCanary` plants a canary of every kind and proves none survives.

## Settings

`tron.ini [PromptRules]` (see also [configuration.md](configuration.md)):

| Setting | Default | |
|---|---|---|
| `Directory` | empty: `<VarDir>/storage/claude/prompt` | The prompt directory |
| `DefaultMaster` | empty: tron's `doc/MASTER.md` | The MASTER.md used when the prompt directory has none |
| `Master` | `enabled` | Switch 4; set per siteaccess in that siteaccess's `tron.ini.append.php` |
| `Agents` | `enabled` | `disabled` leaves the site's AGENTS.md out everywhere |
| `Instructions` | `enabled` | `disabled`: not in the `initialize` instructions, only resources, prompts and tool |
| `InstructionsMaxBytes` | `40000` | The rules in the instructions are cut here; `tron://rules/combined` has all |
| `MaxBytes` | `200000` | The largest rules file the admin page accepts and tron reads |
| `PrivateNames[]` | empty | The installation's private names (servers, hosts, projects); set in `settings/override` only |
| `PrivateDomains[]` | empty | The installation's internal domains; set in `settings/override` only |

## Security notes

- The rules are instructions to agents, so writing them is an administrator's act: `tron/admin`, "Verify it's you",
  audited. No MCP tool writes them.
- Never put a secret into AGENTS.md: every agent with `tron/use` can read it. The admin page warns when it sees one.
- The prompt directory is under the storage directory, which the web server does not serve as files on a standard
  installation; check your own web server's rules if you moved `VarDir`.
- Writes are atomic (a temporary file, then a rename): an agent never reads half a file.

## Examples

A first session with both files on (the end of the `initialize` instructions):

```
# Project rules for agents

Active: MASTER.md (default), AGENTS.md (override).
Precedence: MASTER.md (the Exponential rules) SUPERSEDES the site's AGENTS.md. Where the two conflict, follow MASTER.md; where they do not, follow both.

<!-- MASTER.md (default) -->
# MASTER.md: Exponential rules for agents
...
<!-- AGENTS.md (site rules, superseded by MASTER.md where they conflict) -->
# AGENTS.md: Acme shop
...
```

A client that wants only the site's own rules:

```json
{ "jsonrpc": "2.0", "id": 1, "method": "initialize",
  "params": { "protocolVersion": "2025-11-25", "capabilities": {}, "clientInfo": { "name": "my-agent", "version": "1" },
              "_meta": { "tron/masterRules": "disabled" } } }
```

Switching MASTER.md off for one siteaccess (`settings/siteaccess/<sa>/tron.ini.append.php`):

```ini
[PromptRules]
Master=disabled
```

One stdio run without MASTER.md, whatever the site says (short of the file marker):

```bash
TRON_MASTER_RULES=disabled php extension/tron/bin/php/tron.php serve --siteaccess=<sa> --token-file=<file>
```

Checking the state:

```bash
php extension/tron/bin/php/tron-rules.php status --siteaccess=<sa> --user=<login>
MASTER.md: default (decided by ini) extension/tron/doc/MASTER.md
AGENTS.md: override (strength weak) var/site/storage/claude/prompt/AGENTS.md
  file-marker  -         File marker MASTER.disabled
  environment  -         Environment variable TRON_MASTER_RULES
  admin        -         Admin toggle (Setup > tron > Agent rules)
  ini          enabled   tron.ini [PromptRules] Master (...)   <- decides
  ...
```

## Tests

`tests/unit/RulesTest.php` (run `php tests/run.php TronRulesTest`): the default and the override, the precedence of
MASTER.md over AGENTS.md, every switch and its rank, the session flag through `initialize`, `tron_rules` and the
prompt argument, the `source` of every resource and of `tron_status`, the admin page's actions with their audit
entries and warnings, the questionnaire, the sanitizer against planted canaries, and the shipped MASTER.md.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
