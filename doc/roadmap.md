# Roadmap

Exponential Tron grows in four waves. Each wave refactors what the one before showed needs it, adds features, and ships with
worked examples run by the test suite, proofs (tests and theory claims, plus a proving run on a real installation) and
usage guides. A wave is done only when its exit gate is met. Throughout: PHP 7.4 to 8.5, no new Composer dependency,
everything new off by default where it widens access.

| Wave | Theme | State |
|---|---|---|
| 1 | MCP core, tokens, permissions, audit, programs, centre and Katalyst tools, administration views | built and proven on a development installation (0.0.0.1) |
| 2 | foundation refactor; programs for the installation's core (system, content, templates, classes, users, search ...) | planned |
| 3 | platform for scale, identity and operations | planned |
| 4 | advanced features | planned |

## Every wave's exit gate

1. `php tests/run.php` and `php tests/matrix.php` green on PHP 7.4 to 8.5; the cookbook and theory tests green.
2. Every new tool in the per-role permission matrix; every new claim in [theory.md](theory.md) with its test.
3. A proving run on a real installation, recorded in [testing.md](testing.md); the probe and the stdio and HTTP smoke
   tests pass through PHP-FPM and Velocity.
4. Docs updated: guides, cookbook, tools, troubleshooting, FAQ, changelog.
5. Administration views (if any) checked in light and dark mode, at desktop width and at 200 % zoom.

## Wave 1: the core (done)

MCP 2024-11-05 to 2025-11-25 over stdio and stateless streamable HTTP; per-user tokens; Exponential roles and
policies; the audit with its write guarantee; programs by convention; the programs `tron`, `centre` and `katalyst`;
seven tables on MySQL/MariaDB, PostgreSQL and SQLite; Setup > tron. Details: [changelog/0.0.0.1.md](changelog/0.0.0.1.md).

## Wave 2: the installation's core as tools

**Refactor:** the server split into a dispatcher and handlers per method family; a typed tool result; answers cut by
rows (a large list keeps the rows that fit and says how many were left out, instead of a byte cut); an `outputSchema`
per tool with validated `structuredContent`; one request context passed through; audit files kept writable for the web
server after rotation; the administration views' data tested on SQLite.

**New programs:**

| Program | Tools for |
|---|---|
| `system` | system info, settings resolved across siteaccesses and overrides, siteaccesses, modules, extensions, caches, a system check, log tail, database schema, cronjobs, workflows |
| `content` | query, get, versions, relations, metadata, fetch, render, URLs, trash, media, collected information, catalog |
| `template` | resolve which template renders a node, inspect a template |
| `classes` | list and describe content classes |
| `users` | get a user, describe a role, check access ("may this user do that?") |
| `search` | search, index check (and the search engine's own query where present): **landed** as the units `search.query` and `search.index` ([units.md](units.md#search)) |
| `commerce` | orders and inventory |
| `jobs` | content jobs (read) |
| `services` | a tool per service of the installation's service layer |
| `dataset` | anonymized slices of an installation with a leak scan (off by default) |
| write tools | content (rich text from HTML and Markdown, files), classes, users, cache, autoloads, trash, locations, metadata, URLs, roles, sections, states, index, services: each in its program, each audited before it runs |

**Also:** profiles (named tool selections for clients with a tool cap, per installation and per token);
`[Security] AllowWrite` per program and a per-token read-only flag; personal-data redaction in answers; a siteaccess
allowlist for tools that read other siteaccesses; an in-process bridge so other extensions can call tools as the
signed-in user; `./console tron:*` commands; a systemd timer for the self-test and audit retention.

**Worked examples:** find and read content as an editor; render a page and inspect its template; explain who may do
what; write an article from Markdown with an image, preview, publish; export an anonymized dataset and read its leak
report; a client with a tool cap using a profile.

## Wave 3: scale, identity and operations

| Feature | What it brings |
|---|---|
| OAuth 2.1 sign-in | MCP authorization with protected resource metadata, code + PKCE, dynamic client registration; bearer tokens stay |
| Self-service tokens | users make and revoke their own tokens on their account page, within limits the administrator sets |
| Rate limits | per token and per user; 429 with `Retry-After`; audited |
| Streaming | event-stream answers for long tools, progress notifications, cancellation |
| Change notifications | `listChanged`, resource subscriptions (a ticket changed) |
| Completion, logging, elicitation | argument completion, `logging/setLevel`, confirmation of destructive tools |
| Private-network listener | the HTTP endpoint on a node's private address for the mesh |
| More programs | the message bus (read, send notes, link) and the operations docs (read only) |
| Remote centre | Exponential Tron in one installation calls another installation's centre API |
| Engines | Oracle and MongoDB in the schema and the database layer; schema files for the setup wizard |
| Default install | tron in Exponential's default install, activated by the setup wizard with suggested roles |
| Velocity native | the endpoint served by Velocity workers without a per-request bootstrap |
| Observability | calls, errors and latency per tool; audit retention and shipping |
| Program packaging | a Composer type for programs and `tron.php program:new <name>` with generated tests |

## Wave 4: advanced

| Feature | What it does |
|---|---|
| Process isolation | a program can run in its own worker with only the capabilities its manifest asks for; a hanging program is stopped while the others answer |
| Capability tokens | attenuated, delegable tokens: a session hands a sub-agent a token for one board for one hour, which cannot be widened |
| Plan and apply | every write tool offers a dry run returning the exact change; apply takes the plan's hash, so a tampered plan is refused |
| Undo journal | writes recorded with compensating actions; a whole agent session rolled back step by step |
| Two-person rule | destructive tools wait for a second user's approval |
| Policy simulator | "what could this token do?" across every tool, without calling anything |
| Tools from content classes | typed tools generated per content class |
| Federation | several installations route a call to the one that owns the data |
| Gateway | Exponential Tron in front of other MCP servers, with the installation's users, policies and audit |
| Semantic layer | permission-filtered embeddings of content and tickets for search and duplicates |
| Anomaly watch | the audit stream scored per token (volume, new tools, odd hours), with alerts |
| Program store | signed programs, verified before load |
| Session replay | an agent session reconstructed from the audit for review |
| Hash-chained audit | each row carries the hash of the one before, so an edited or removed row is detected |

Every advanced feature is off by default and switchable per installation.

## Release

Exponential Tron is a preview until it is proven on real installations; the repository and the Composer package are published
after that. Versions then follow [upgrading.md](upgrading.md), "Version numbers".

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
