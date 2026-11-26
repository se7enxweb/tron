# Contributing

tron is GPL-2.0-or-later. Contributions are welcome as programs (in your own extension, see
[guides/program-author.md](guides/program-author.md)) and as changes to tron itself. The repository is
[github.com/se7enxweb/tron](https://github.com/se7enxweb/tron): open an issue or a pull request against `main`.

## Ground rules

| Rule | Why |
|---|---|
| PHP 7.4 to 8.5, every version | tron runs in default installations on every supported PHP; never drop one |
| No Composer dependencies | the MCP layer, JSON-RPC, schema validation and transports are tron's own few hundred lines |
| No kernel changes | tron is an extension; it reads kernel interfaces and adds `tron_*` tables only |
| Every behavior has a test | the suite runs without an installation in seconds; there is no reason to skip it |
| Every claim in the docs is checked | the cookbook's output and the theory page's proofs are verified by the suite |
| Write the product name as Exponential | in code, docs and commit messages |
| American English, no emojis | in docs, UI strings and messages |
| No secrets, no real tokens, no customer data | in code, tests, docs, examples, commit messages; use placeholders and example.* domains |

## Setting up

```bash
git clone <tron repository> tron && cd tron
php tests/run.php                      # PASS: about 86 tests, 1400 assertions, a few seconds
php tests/matrix.php                   # every PHP 7.4+ found under /opt/plesk/php and /opt/remi
php examples/scripts/01-first-call.php
```

No installation needed: the test world is SQLite in memory, a fixed clock (2026-11-24 12:00 UTC) and ten users with
roles (`tests/lib/fixture.php`). Scratch files go to `$TRON_TEST_TMP` (default `var/tmp/tron-tests` in the
checkout). `TRON_TEST_LOG=1` prints the server log. `php tests/run.php <filter>` runs matching tests only.

## Where things live

| Path | Holds |
|---|---|
| `classes/core/` | the server, protocol, registry, loader, tokens, access, audit sinks, redactor, schema validator |
| `classes/transport/` | stdio and HTTP |
| `classes/db/` | `tronDb`, the eZDB and PDO adapters, the schema |
| `classes/kernel/` | the binding to a real installation |
| `classes/programs/` | the built-in programs |
| `classes/admin/`, `modules/tron/`, `design/standard/` | Setup > tron |
| `tests/unit/`, `tests/conformance/` | the suite |
| `tests/kernel/` | probe and smoke clients for a real installation |
| `examples/` | the cookbook's scripts and the example program |
| `doc/` | this documentation |

## A change, step by step

1. **Test first.** Add or change a test in `tests/unit/` (or `tests/conformance/` for protocol behavior).
2. **Code.** Follow the existing style: `tron*` class names, one class per file named in lower case
   (`tronTokenService` in `trontokenservice.php`), named SQL parameters only, `tronToolError` for refusals the model
   should read.
3. **New tool?** Add it to the per-role permission matrix (`tests/unit/PermissionMatrixTest.php`) and to
   [tools.md](tools.md) and [permissions.md](permissions.md).
4. **New guarantee?** Add a claim to [theory.md](theory.md) naming the test that proves it (the theory test fails when
   a named test does not exist).
5. **Schema change?** Change `tronSchema::tables()`, then `php bin/php/tron-schema.php --write`; the suite checks the
   generated files. `tron.php install` must stay idempotent.
6. **Run** `php tests/run.php` and `php tests/matrix.php`.
7. **Docs.** Update the pages the change touches, and the changelog of the next version.
8. **On a real installation** (maintainers): the probe, the stdio and HTTP smoke tests, and a proving run for larger
   changes, recorded in [testing.md](testing.md).

## Commit messages

One kind of change per commit, with a prefix the changelog is assembled from:

```
Added: <short description>      new feature, file, class, tool, setting
Updated: <short description>    changed behavior, template, setting, class
Removed: <short description>    deleted feature, file, class, dependency
Renamed: <short description>    renamed file, class, tool, setting
```

The body says why and what, as written by the person who made the change.

## Versions and releases

Only the last position moves per change (0.0.0.1, 0.0.0.2, ... 0.0.0.10). A release puts the same number in
`VERSION`, `tronVersion::VERSION`, `ezinfo.php`, `extension.xml`, `composer.json` and the built-in program manifests
(the suite checks they agree), in one commit before the tag. A published tag is permanent: a mistake is fixed by the
next version, never by moving a tag. Details: [upgrading.md](upgrading.md).

## Reporting a security problem

Do not open a public issue. Write to the maintainers with the version, the steps and what you observed; you get an
answer before anything is published.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
