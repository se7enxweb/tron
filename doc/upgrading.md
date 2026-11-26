# Upgrading

How to move an installation from one tron version to the next, and what each version changed that an upgrade must
know about.

## Version numbers

tron uses four positions, `X.Y.Z.N`, and **only the last position moves for a change**: 0.0.0.1, 0.0.0.2, ...
0.0.0.9, 0.0.0.10, 0.0.0.11. A `.9` is followed by `.10`, never by `0.0.1.0`; moving any higher position is a
deliberate decision about what a release means, not a consequence of reaching nine. Compare versions numerically, never
as text: `0.0.0.10` is newer than `0.0.0.9` (`tronVersion::compare()` does this; in shell use `sort -V` or
`git tag --sort=version:refname`).

The same number is in `VERSION`, `tronVersion::VERSION`, `ezinfo.php`, `extension.xml`, `composer.json` and the
manifests of the built-in programs; the test suite fails when they disagree. A published version is never changed:
a fix is the next version.

## The procedure

On the master tree of the installation, then on every serving node after the tree has synced:

1. **Read the changelog** of every version between yours and the new one (`doc/changelog/<version>.md`). It names table
   changes, new policies and changed settings.
2. **Run the suite** on the checkout you will install: `php tests/run.php`, and `php tests/matrix.php` where several
   PHP versions are present.
3. **Replace the code**: `composer update se7enxweb/tron`, or update the checkout in `extension/tron`.
4. **Autoloads**: `php bin/php/ezpgenerateautoloads.php -e` (new classes in a release are found only after this).
5. **Caches and workers**: `php bin/php/ezcache.php --clear-tag=ini` and reload PHP-FPM; where Velocity serves,
   `./console exp:velocity deploy` so its workers load the new classes.
6. **Tables**: `php extension/tron/bin/php/tron.php install --siteaccess=<sa>`. It only adds what is missing and prints
   `created` or `present` per table; `--dry-run` first shows the SQL.
7. **Check**: `tron.php status --siteaccess=<sa>` (PASS), the read-only probe
   (`php bin/php/ezexec.php extension/tron/tests/kernel/kernel_probe.php`), and the stdio smoke against a throwaway user.
8. **New policies**: grant any new function the changelog names to the roles that need it.
9. **Agents**: running stdio servers keep the old code until their client restarts them. Ask sessions to restart.

Tokens, audit rows, switches and tickets are kept across upgrades. tron never changes a kernel table.

## Rolling back

1. Put the previous code back (`composer require se7enxweb/tron:<previous>` or check out the previous tag).
2. Autoloads, caches, workers as above.
3. Tables a newer version added stay; an older version ignores them.

To switch tron off entirely, see [runbook.md](runbook.md), "Roll back": close HTTP, remove `ActiveExtensions[]=tron`,
deploy. The tables can stay; they hold the audit and tokens.

## Notes per version

### 0.0.0.14

No schema change. `serve --user=<login>` is disabled by default: stdio needs a token. Anything that starts
`tron.php serve --user=...` must switch to `--token-file` or `TRON_TOKEN` before the upgrade, or the installation sets
`[StdioSettings] AllowLoginOption=enabled` in `settings/override` ([changelog/0.0.0.14.md](changelog/0.0.0.14.md)).

### 0.0.0.13

No schema change. Fixes the word boundary of `[PromptRules] PrivateNames[]` in 0.0.0.12
([changelog/0.0.0.13.md](changelog/0.0.0.13.md)).

### 0.0.0.12 (first public release)

No schema change. An installation whose generated MASTER.md must leave out private server names, internal domains
or a private network now sets them in `settings/override/tron.ini.append.php`: `[PromptRules] PrivateNames[]`,
`PrivateDomains[]` and `[WebSettings] MeshNetworks[]`; the names the system catalog and tools leave out go in
`[SystemAdapterSettings] PrivateNames[]` (see [changelog/0.0.0.12.md](changelog/0.0.0.12.md)). Without them the
SSRF guard refuses those addresses as "private" instead of "mesh", and nothing is left out of the system catalog.

### 0.0.0.1 (first version)

Nothing to upgrade from. A fresh install creates seven tables: `tron_token`, `tron_audit`, `tron_switch` and the four
`tron_centre_*` tables. An installation that ran an early preview without `tron_switch` gets it from `tron.php install`;
until then the switches in Setup > tron have no effect and tron serves every program.

Policies to grant: `tron/use` for anyone who connects, the program functions (`centre_read`, `centre_write`,
`centre_decide`, `katalyst_read`, `katalyst_detail`), and for the people who run tron `tron/admin`, `tron/tokens`,
`tron/audit`.

## Upgrading the installation around tron

- **A new PHP for the installation**: tron runs on 7.4 to 8.5; update the PHP in every MCP client entry
  (`command`) and SSH command at the same time, or they start the old binary.
- **A kernel upgrade**: run `tron.php status` and the probe afterwards; tron uses only long-standing kernel interfaces.
- **A database move**: tron's tables move with the database. Tokens keep working (they are hashes of themselves, not
  bound to a host).

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
