# System adapters

The program `system` reaches the whole installation through a few generic tools instead of one tool per feature. Each
adapter takes a whole family of API points: any read fetch function, any read template operator, any read module view,
the reviewed console commands and scripts, the queue. `doc/api-coverage.md` lists every API point of the installation
and which adapter or unit covers it.

What every adapter does, without exception:

- **Runs as the calling Exponential user.** Fetches, operators and views run with the caller as the current user, so the
  kernel's own permission checks (content/read limitations, view functions) apply to the caller, not to the process.
  The session is not touched.
- **Changes nothing.** Code of the installation runs inside a database transaction that is rolled back. A write, admin
  or dangerous point is refused; it stays inactive until its dry-run twin exists (`doc/dry-run.md`), and the catalog
  names the unit planned for it. Commands run read-only or as their own dry run; `--apply`, `--force` and the like are
  refused.
- **Is redacted, audited and journaled** by the server like every tool: the answer passes the redactor
  (`doc/redaction.md`), the call is a row of the audit log, and commands run as jobs of the caller's activity journal
  (`doc/activity-journal.md`) with progress every 10 seconds.
- **Declares its input and output** (`inputSchema`, `outputSchema`), so the contract tests check every answer.

Units already planned elsewhere are reused, not duplicated: INI settings are the ini units (`ini_resolve`, `ini_list`,
`ini_diff`); nodes, classes, roles, templates and layouts are their units (`doc/units.md`).

## The adapters

| Unit | Tool | Policy | In | Out |
|---|---|---|---|---|
| `catalog.search` | `system_catalog` | tron/use | area, q, risk, covered, origin, by, limit, offset | generated, total, items |
| `catalog.coverage` | `system_coverage` | tron/use | - | generated, totals, areas, adapters |
| `fetch.describe` | `system_fetch_describe` | tron/use | module, function? | module, functions[name, risk, params, callable] |
| `fetch.call` | `system_fetch_call` | tron/use + the module's read | module, function, params{} | module, function, ran_as, params, result, truncated |
| `operator.eval` | `system_operator_eval` | tron/use | operator, input, params[] | operator, input, params, result, truncated |
| `module.view.describe` | `system_module_view_describe` | tron/use | module?, view? | modules, module, origin, views[], functions{} |
| `module.view.read` | `system_module_view_read` | tron/use + the view's functions | module, view, params[], unordered{}, max_text? | module, view, ran_as, title, path, text, links, status, redirect, truncated |
| `console.command` | `system_console_command` | setup/setup | command, args[] | command, mode, exit_code, ms, timed_out, stdout, stderr, truncated, job |
| `cli.script` | `system_cli_script` | setup/setup | script, args[] | script, mode, exit_code, ms, timed_out, stdout, stderr, truncated, job |
| `queue.jobs` | `system_queue_jobs` | expqueue/view | state?, type?, class?, limit, offset | installed, total, counts, items |
| `queue.job` | `system_queue_job` | expqueue/view | job (id or uuid) | job |

None of them is a write tool. Without an installation (the test suite, a bare checkout) only `system_catalog` and
`system_coverage` are offered; `tron_status` lists the others as unavailable with the reason.

## system_catalog and system_coverage

The catalog is generated, never written by hand: `bin/php/tron-inventory.php` walks the installation (see
`doc/api-coverage.md`, "Regenerating"). Start here to find the tool for a task.

```json
{ "name": "system_catalog", "arguments": { "area": "fetch_function", "q": "tag", "limit": 5 } }
```

```json
{ "generated": "2026-11-25T06:10:00Z", "total": 9, "items": [
  { "id": "fetch_function:tags/tag", "area": "fetch_function", "name": "tags/tag", "origin": "eztags", "risk": "read",
    "summary": "params: tag_id*", "covered": true, "by": "system_fetch_call", "mode": "read", "planned": "" } ] }
```

`covered: false` with `planned` says what will cover a point: `{"area": "module_view", "covered": false, "risk": "write"}`
lists the views that wait for a unit with a dry-run twin. `system_coverage` answers the totals per area and the adapter
table above.

## system_fetch_describe and system_fetch_call

Any template fetch, as `fetch( 'content', 'list', hash( 'parent_node_id', 2, 'limit', 5 ) )` in a template:

```json
{ "name": "system_fetch_call", "arguments": { "module": "content", "function": "list",
  "params": { "parent_node_id": 2, "limit": 5, "sort_by": ["published", false] } } }
```

```json
{ "module": "content", "function": "list", "ran_as": "editor1", "params": { "parent_node_id": 2, "limit": 5, "sort_by": ["published", false] },
  "result": [ { "_class": "eZContentObjectTreeNode", "node_id": 61, "contentobject_id": 59, "name": "News",
                "url_alias": "News", "class_identifier": "folder" } ], "truncated": false }
```

- The parameters are checked against the module's `function_definition.php`: an unknown name, a missing required one or
  a value of the wrong type is refused with the list of what the fetch takes. `system_fetch_describe` shows them.
- A list fetch (one with a `limit` parameter) gets `limit` 100 when none is given, and never more than 100.
- A fetch whose `operation_types` say it writes is refused ("inactive until its dry-run twin exists").
- When the module has a `read` policy function (content, tags ...), the caller needs it, with or without limitations;
  the limitations themselves are applied by the kernel, because the fetch runs as the caller.
- Objects come back as their stored fields plus `name`, `url_alias`, `class_identifier` where they have them, at most
  four levels deep and 100 items per list. Fields that look secret (password hashes, tokens, keys, sessions) are
  dropped; mail addresses, tokens and secret pairs in text are masked by the adapter itself, and the server's
  redactor runs on top. The same goes for the text and links of a rendered view (mailto: links are left out).

## system_operator_eval

`$input|operator( params )` with any read template operator:

```json
{ "name": "system_operator_eval", "arguments": { "operator": "shorten", "input": "A long headline about tron", "params": [12] } }
```

```json
{ "operator": "shorten", "input": "A long headline about tron", "params": [12], "result": "A long he...", "truncated": false }
```

The input and the parameters are handed to the template as variables, never as template text, so nothing the caller
sends is parsed. Refused: operators that read something other than the input (`ezini`, `ezhttp`, `ezsys`,
`ezpreference`), that run another API point past its adapter (`fetch` belongs to `system_fetch_call`), that print whole
object graphs (`attribute`, `dump`), and operators that write files (`texttoimage`, `image`). tron.ini
`[SystemAdapterSettings] OperatorDeny[]` adds more.

## system_module_view_describe and system_module_view_read

Describe any module: its views with their ordered and unordered parameters, the policy functions each needs, the POST
actions it handles, its risk class and whether it is readable here, and the module's policy functions with their
limitations.

```json
{ "name": "system_module_view_describe", "arguments": { "module": "content", "view": "view" } }
```

Read a view as a browser GET would, as the caller:

```json
{ "name": "system_module_view_read", "arguments": { "module": "content", "view": "view", "params": ["full", 2] } }
```

```json
{ "module": "content", "view": "view", "ran_as": "editor1", "title": "Home", "path": [ { "text": "Home", "url": "" } ],
  "text": "Home\nWelcome to the site ...", "links": [ { "href": "/News", "text": "News" } ], "status": 1, "redirect": "", "truncated": false }
```

Rendered only: read views (by the view's name and the policy functions it needs), outside the admin modules (setup,
role, workflow, class, section, package ...), and not on the deny list (`user/logout`, `content/download`,
`setup/info`, ... `system_module_view_describe` gives the reason). No form is submitted: `$_POST` is empty for the call.
The kernel's view access check (`hasAccessToView`) runs for the caller first. `max_text` cuts the text (default 20 000
characters).

## system_console_command and system_cli_script

Reviewed commands of `bin/php/console` and their bin/php scripts, as jobs:

```json
{ "name": "system_console_command", "arguments": { "command": "exp:expmigrationstatus", "args": ["--siteaccess=site"] } }
```

```json
{ "command": "exp:expmigrationstatus", "mode": "read", "exit_code": 0, "ms": 1840, "timed_out": false,
  "stdout": "Migration status ...", "stderr": "", "truncated": false, "job": 4711 }
```

- **The allowlist** is in `tronSystemAllow::CONSOLE`: status, list, show and check commands as they are (`mode: read`),
  and commands whose changes are a dry run unless `--apply` as their dry run (`mode: dry_run`: `exp:contentcheck`,
  `exp:installcrontab`, `exp:exphelpcontent`). Some run only their read sub-commands (`exp:audit tail|show|verify|channels`,
  `exp:configphp list|get`). tron.ini `ConsoleAllow[]=exp:foo` (or `exp:foo;dry_run`) adds a reviewed command;
  `ScriptAllow[]=extension/<ext>/bin/php/<x>.php` a script by its path. A script is allowed exactly when its console
  command is: `bin/php/<name>.php` is `exp:<name>`, `extension/<ext>/bin/php/<name>.php` is `ext:<ext>:<name>`.
  Commands that list customers, registrations or other personal data are left off the list on purpose.
- **Arguments**: letters, digits and `_ @ : . , = / + -` only, no `..`, and never one that applies, forces, confirms,
  fixes, deletes, clears or names an output file (`--apply`, `--force`, `--yes`, `--fix`, `--clear-all`, `-D`, `--out=`
  ...). The command runs without a shell, with `nice -n 15`, from the installation root.
- **As a job**: the call opens a job in the caller's activity journal (progress every 10 s, the exit code at the end).
  It stops after `CommandTimeout` seconds (default 120, at most 900; exit code 124). Output keeps its last 30 000
  characters (stderr 5 000), colours stripped.
- Under PHP-FPM the PHP command line binary next to the FPM binary is used; `PhpBinary` names another.

## system_queue_jobs and system_queue_job

expqueue's jobs, newest first, with the count per state; one job with its payload, result and checkpoint:

```json
{ "name": "system_queue_jobs", "arguments": { "state": "failed", "limit": 10 } }
{ "name": "system_queue_job", "arguments": { "job": "1234" } }
```

Needs `expqueue/view`. Where expqueue is not installed, `system_queue_jobs` answers `installed: false`. Payloads pass the
redactor like every answer. Cancelling, retrying and enqueueing are writes: planned with their dry-run twins.

## Settings

tron.ini `[SystemAdapterSettings]` (all optional):

```ini
[SystemAdapterSettings]
# The catalog file (default var/tron/api-coverage.json, else tron's doc/api-coverage.json)
CatalogFile=
# More operators operator.eval never evaluates
OperatorDeny[]
# More reviewed console commands: exp:foo (read) or exp:foo;dry_run
ConsoleAllow[]
# Scripts of extensions: extension/<ext>/bin/php/<x>.php
ScriptAllow[]
# module/view pairs module.view.read never renders
ViewDeny[]
# Seconds a command may run (5 to 900)
CommandTimeout=120
# The PHP command line binary (default: the one running, or the CLI next to PHP-FPM)
PhpBinary=
# Names left out of the catalog and of every system tool (extensions, modules, operators, commands): a whole word,
# or the beginning of a word with a trailing *. Set them in settings/override only; tron ships none.
PrivateNames[]
```

Nothing in the settings can make a write, admin or dangerous point run outside its dry run: the risk check comes
before the lists.

## Tests

`tests/unit/SystemAdaptersTest.php` runs the inventory on a fixture installation (`tests/fixtures/system/root`) and every
adapter against fixture sources (`tronSystemArraySource`): parameters checked, writes refused, permissions as the
caller, output against the output schema, redaction, commands read-only or dry run only. `tests/kernel/system_adapters_run.php`
is the proving run against a real installation.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
