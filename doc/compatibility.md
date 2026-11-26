# Compatibility

What tron 0.0.0.1 runs on, what was tested, and what is not supported yet.

## Summary

| Area | Supported | Tested |
|---|---|---|
| Exponential | 6.x (legacy kernel) | 6.0.15 on the development installation |
| PHP | 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5 | every one of them, plus 8.6-dev, by `tests/matrix.php` |
| Databases | MySQL, MariaDB, PostgreSQL, SQLite | PostgreSQL on a real installation; SQLite in the suite; MySQL DDL generated and checked |
| Oracle, MongoDB | not yet | `install` refuses with a clear message |
| Web servers | Apache with PHP-FPM, nginx with PHP-FPM, Velocity | Apache + PHP-FPM and Velocity |
| MCP protocol | 2025-11-25, 2025-06-18, 2025-03-26, 2024-11-05 | each version over real stdio; HTTP with and without the version header |
| Transports | stdio; streamable HTTP (stateless, JSON answers) | both, end to end |
| Composer packages | none beyond the kernel (`ext-json`) | |

## PHP

`composer.json`: `"php": ">=7.4 <8.6"`, `ext-json`. Suggested: `ext-mbstring` (cuts long text by characters, not
bytes), `ext-pdo_sqlite` (the test suite), `ext-curl` (the HTTP smoke client).

tron follows the rule of never dropping a PHP version: code is written for 7.4 (no enums, no readonly properties, no
first-class callable syntax, no `match`) and checked on every version by `php tests/matrix.php`, which lints every file
and runs the suite with each PHP binary it finds. Result of 2026-11-24: PASS on 7.4.33, 8.0.30, 8.1.34, 8.2.36,
8.3.37, 8.4.28, 8.5.13 and 8.6.0-dev.

**The installation may need a newer PHP than tron.** An Exponential 6.0.15 installation's Composer platform check
asks for PHP 8.4 or later; run `tron.php` with the PHP the installation runs, or it stops with "Composer detected
issues in your platform" before tron starts.

## Exponential

tron is an extension of the legacy kernel (a Composer legacy-extension package, installed by
`se7enxweb/exponential-legacy-installer`). It uses only long-standing kernel interfaces: `eZScript` and `eZCLI`,
`eZINI`, `eZDB`, `eZUser::hasAccessTo`, `eZSiteAccess`, module views and the extension autoloads. The administration
views use the admin designs' shared component classes (admin, admin4 and the others). Proven on 6.0.15; it is planned
to ship in the default install of a later 6.0.15 pre-release.

Older lines built on the same legacy kernel are expected to work for the MCP server itself where they run PHP 7.4 or
newer, but they are not tested; the administration views depend on the 6.x admin designs.

## Databases

The schema is declared once (`tronSchema::tables()`) and generated per engine:

| Engine | Driver | Schema file | Notes |
|---|---|---|---|
| MySQL / MariaDB | `mysql` | `sql/mysql/schema.sql` | InnoDB, utf8mb4 |
| PostgreSQL | `pgsql` | `sql/postgresql/schema.sql` | table checks through `pg_class` (defect 1 of the proving run) |
| SQLite | `sqlite` | `sql/sqlite/schema.sql` | the test suite's engine |
| Oracle | | none | planned |
| MongoDB | | none | planned |

All statements go through `tronDb` with named parameters (`tronDbKernel` on the installation's eZDB connection,
`tronDbPdo` in the tests); identifiers are checked against `^[a-z_][a-z0-9_]*$`. Tables: `tron_token`, `tron_audit`,
`tron_switch`, `tron_centre_ticket`, `tron_centre_comment`, `tron_centre_link`, `tron_centre_history`.

## Web servers and runtimes

| Runtime | Status | Notes |
|---|---|---|
| Apache + PHP-FPM | proven | pass the `Authorization` header (`SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` or `CGIPassAuth On`) |
| nginx + PHP-FPM | expected | `fastcgi_params` passes `HTTP_AUTHORIZATION`; not yet proven |
| Velocity | proven | the endpoint and admin views work; workers load new classes and switches only after a restart |
| PHP's built-in server | test suite only | the conformance test runs the HTTP transport through it |
| Load balancers | proven in TCP mode with SNI pass-through | stateless: no stickiness; tron on every node first |
| Request filters | proven with exp_request_shield | include `doc/request-shield/tron.rules` |

## MCP clients

Any client that speaks stdio or streamable HTTP with a static bearer header. Configurations for Claude Code, Claude
Desktop, Cursor and VS Code are in [clients.md](clients.md).

| Client capability | tron |
|---|---|
| tools, resources, resource templates, prompts, pagination | yes |
| `structuredContent` | from 2025-06-18 on |
| JSON-RPC batches | under 2025-03-26 and older only, as the specification says |
| HTTP+SSE (2024-11-05 HTTP transport) | no; use streamable HTTP |
| server-sent streams, progress, cancellation, subscriptions, `listChanged` | not yet (planned) |
| sampling, elicitation, completion, logging/setLevel | not yet (some planned) |
| OAuth 2.1 sign-in | not yet (planned) |

## Optional extensions

| Extension | Brings |
|---|---|
| Katalyst (`sevenx_katalyst`) | the `katalyst` program's three tools; without it they are left out and `tron_status` says why |
| the centre extension (`expcentre`) | the `centre` program switches to its 29 contract tools (`[CentreSettings] Backend=auto`) |
| exp_request_shield | needs the shipped `tron.rules` included for the HTTP endpoint |

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
