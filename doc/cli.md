# Command line reference

Exponential Tron's commands are in `extension/tron/bin/php/tron.php` (from the installation) and `bin/php/tron-schema.php`
(needs no installation). The test and smoke scripts are listed at the end.

## tron.php

```bash
php extension/tron/bin/php/tron.php <command> [argument] --siteaccess=<sa> [options] [--allow-root-user]
```

- Run from the installation root, or from anywhere with an absolute path: `tron.php` changes into the installation
  root (four levels above `extension/tron/bin/php`) when it is not started there.
- `--siteaccess=<sa>` decides which settings and which database the command uses. Use the siteaccess whose database
  holds Exponential Tron's tables; for most installations every siteaccess shares one database ([multi-site.md](multi-site.md)).
- As root the kernel refuses to run without `--allow-root-user` and says so on stderr.
- **Exit codes:** `0` PASS, `1` FAIL, `2` usage error. Results go to stdout, messages (`PASS: ...`, `FAIL: ...`) to
  stderr, so the output can be piped.
- Errors are printed with any token masked.

### serve

Runs the stdio MCP server: newline-delimited JSON-RPC on stdin and stdout, until stdin closes (exit 0).

```bash
tron.php serve --siteaccess=<sa> --token-file=<file>
TRON_TOKEN=<token> tron.php serve --siteaccess=<sa>
```

| Option | Meaning |
|---|---|
| `TRON_TOKEN` (environment) | the token; checked first |
| `--token-file=<file>` | read the token from a file (whitespace trimmed) |
| `--user=<login>` | act as this user without a token; refused (exit 1) unless an override sets `[StdioSettings] AllowLoginOption=enabled`. A token wins over it |

Refuses to start (exit 1, reason on stderr) when the token is refused, `--user` is given without a token while
it is disabled (the default), the user is missing or disabled, the user
lacks `tron/use`, or an Exponential Tron table is missing. On start it prints
`tron 0.0.0.1 serving stdio as <login>, <n> tools` to stderr. The session must begin with `initialize`. A line
longer than 4 MB is refused (dropped and logged by the SDK, answered with a parse error by the legacy layer) and the
server carries on. Role changes and switches
are read once at start: restart the client to pick them up.

### install

```bash
tron.php install --siteaccess=<sa> --dry-run     # print the CREATE statements of the missing tables, change nothing
tron.php install --siteaccess=<sa>               # create what is missing
```

Prints one line per table, `created` or `present`. Idempotent: run it again after every upgrade. Supported engines:
MySQL/MariaDB, PostgreSQL, SQLite; any other engine is refused with a clear message ([compatibility.md](compatibility.md)).

```
tron_token             created
tron_audit             created
tron_switch            created
tron_centre_ticket     created
tron_centre_comment    created
tron_centre_link       created
tron_centre_history    created
```

### status

```bash
tron.php status --siteaccess=<sa>
```

Builds the server as it would serve (every program, every switch) with all permissions, and prints the programs,
counts, tools left out and the tables. Exit 1 when a table is missing or a program failed.

```
tron 0.0.0.1, protocol 2025-11-25 2025-06-18 2025-03-26 2024-11-05
  centre     builtin      0.0.0.1  loaded      22.70 ms
  katalyst   builtin      0.0.0.1  loaded       3.00 ms
  tron       builtin      0.0.0.1  loaded       0.30 ms
  tools: 17, resources: 4, prompts: 2
  tables: all present
```

A program's state is `loaded`, `failed` (with the reason: class not found, an exception in `register()`, a bad
manifest), `disabled` (by `[ProgramSettings] Disabled[]` or a switch), `conflict` (name taken) or `incompatible`
(needs a newer Exponential Tron). Tools a program declared unavailable are listed as `not registered: <tool> (<reason>)`.

### token:create

```bash
TRON_TOKEN=<token> tron.php token:create --siteaccess=<sa> --user=<login> --name=<what for> [--days=<n>] [--scopes=<p1,p2>] [--out=<file>]
```

The token given (`TRON_TOKEN` or `--token-file`) is the issuer: a user may create tokens for themselves, within their
own token's scopes; for another user, or wider scopes, the issuer needs `tron/admin`. Without a token the command is
refused (exit 1) unless an override sets `[StdioSettings] AllowLoginOption=enabled`, because then anyone who can run
it could mint a token for any user. The first token of an installation comes from Setup > tron > Tokens.

| Option | Default | Meaning |
|---|---|---|
| `--user` | required | the user the new token acts as (must exist and be enabled) |
| `--token-file` | `TRON_TOKEN` | the issuer's token |
| `--name` | required | 1 to 100 characters: where and what for (`claude@laptop`, `ci nightly`) |
| `--days` | `[TokenSettings] DefaultLifetimeDays` (90) | lifetime; more than `MaxLifetimeDays` (365) is refused |
| `--scopes` | all programs | comma-separated program names; the core program `tron` is always in scope |
| `--out` | none | write the token to this file with mode 0600 and never print it |

Without `--out` the token is printed once on stdout. stderr says `PASS: expires <date>, scopes <list>`.
[tokens.md](tokens.md) has the lifecycle.

### token:list

```bash
tron.php token:list --siteaccess=<sa> [--user=<login>]
```

Newest first; never the hash or the secret:

```
    7 Q2hxW0aZ1bKe agent-claude     active   claude@laptop                  expires 2026-12-24 last used 2026-11-24 12:00 scopes centre
    6 m9TtR4yUe0Pw agent-claude     revoked  claude@laptop (old)            expires 2026-12-01 last used never scopes *
```

Columns: id, prefix, login, state (`active`, `expired`, `revoked`), name, expiry, last use (written at most once a
minute), scopes.

### token:revoke

```bash
tron.php token:revoke --siteaccess=<sa> <id or prefix>
```

Takes effect on the next request (HTTP) or the next start (stdio: a running stdio server keeps its principal until
the client stops it). Exit 1 when there is no active token with that id or prefix.

### audit:tail

```bash
tron.php audit:tail --siteaccess=<sa> [--limit=50] [--user=<login>]
```

The newest entries of the table `tron_audit`, oldest first: time (UTC), login, transport, method, target, outcome,
duration. [audit.md](audit.md) explains every field and how to read the JSON-lines file sink.

### call

```bash
TRON_TOKEN=<token> tron.php call <tool> --siteaccess=<sa> [--json='{"limit":5}']
```

Calls one tool as the token's user (`TRON_TOKEN` or `--token-file`; `--user=<login>` only where an override sets
`[StdioSettings] AllowLoginOption=enabled`), through the same server, permission check, schema validation and audit as a client
(transport `cli` in the audit). Prints the JSON-RPC answer; exit 1 when it is an error. Useful to test a program or a
role without an MCP client.

```bash
tron.php call centre_board_view --siteaccess=<sa> --token-file=$HOME/.config/tron/agent-claude.token --json='{"board":"main","per_lane":5}'
```

## tron-skills.php

Skills from the command line; `xsh tron:skill:*` runs the same commands as the site user. Without an installation:
`lint [<pack dir>...]`, `harness --fixtures [--pack=<id>] [--write-proof] [--json]`, `try <name> --fixtures
[--<param> <value> ...]`, `export <pack> --to=<dir|file.tar.gz>`. Inside the installation (`--use-siteaccess=<sa>`
picks its siteaccess; `--siteaccess` is a skill parameter): `list [--state] [--pack] [--query] [--tag]`, `show <name>`,
`packs`, `counts`, `run <name> [--<param> <value> ...]` (a live skill), `validate <name>`, `try <name>`,
`unit <tool> --args=<json>` (a read unit), `harness --live [--pack=<id>] [--write-proof]`; these act as the token's user
(`TRON_TOKEN` or `--token-file`), `--user=<login>` only where an override sets `[StdioSettings] AllowLoginOption=enabled`; `install <dir|file.tar.gz> --extension=<ext>`, `export-local <pack>
--to=<dir>`. Exit codes 0 PASS, 1 FAIL, 2 usage. Approving has no command: it is a person's act in Setup > tron >
Skills. See [skills.md](skills.md).

## tron-schema.php

```bash
php extension/tron/bin/php/tron-schema.php mysql|pgsql|sqlite     # print one engine's schema
php extension/tron/bin/php/tron-schema.php --write                 # rewrite sql/{mysql,postgresql,sqlite}/schema.sql
php extension/tron/bin/php/tron-schema.php --check                 # PASS when the files equal tronSchema::tables()
```

Use it to hand the SQL to a database administrator who creates the tables by hand.

## Test and smoke scripts

| Script | Needs | What it does |
|---|---|---|
| `tests/run.php [filter]` | PHP only | the whole suite: unit, permission matrix, MCP conformance over real stdio and HTTP, cookbook, theory |
| `tests/matrix.php [php ...]` | several PHP binaries | lint plus suite on each PHP 7.4 or newer found (or given) |
| `tests/kernel/kernel_probe.php` | an installation (via `bin/php/ezexec.php`) | read-only checks of the kernel binding; works before Exponential Tron is installed |
| `tests/kernel/stdio_smoke.php [--write] -- <server command>` | a server command | MCP over stdio against the real `serve` |
| `tests/kernel/http_smoke.php --url=<u> --token-file=<f> [--resolve=h:p:a] [--insecure] [--write] [--expect-tools=<n>]` | ext-curl | MCP over HTTP against a real endpoint, every refusal status |
| `tests/kernel/kernel_tools_run.php` | an installation, throwaway users | every tool as users with real roles (the proving run) |
| `examples/scripts/0N-*.php` | PHP only | the cookbook's eight examples |

[testing.md](testing.md) describes each with its last results.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
