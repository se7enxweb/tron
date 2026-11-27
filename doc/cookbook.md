# Cookbook: worked examples

Eight examples you can run with plain PHP (7.4 to 8.5), without an installation: each starts the test world
(SQLite in memory, the clock fixed at Tuesday 2026-11-24 12:00 UTC, users with roles; `tests/lib/fixture.php`). The
outputs below are what they print. `tests/conformance/CookbookTest.php` runs every example on every test run and
compares its output with `examples/scripts/<name>.expected` byte for byte, so this page cannot drift from the code.

```bash
php examples/scripts/01-first-call.php
```

In an installation the same calls go to the real server: `tronKernel::server()` instead of `TronFixture::build()`,
or an MCP client over stdio (`tron.php serve`) or HTTP (`/tron/mcp`), see [getting-started.md](getting-started.md).

The test world's users: `admin` (all), `viewer` (tron/use, centre_read), `editor` (+ centre_write), `decider`
(+ centre_decide), `operator` (tron/use, katalyst_read), `detail` (+ katalyst_detail), `useonly`, `norole`.

## 1. The first call

`examples/scripts/01-first-call.php`: initialize, list tools, ask who you are.

```
protocol: 2025-11-25
server:   tron 0.0.0.1
tools:    13 (what the editor's role allows)
whoami:   editor (user 3) over stdio
```

The editor sees 13 of the 17 tools: the core two, the eleven centre tools of reading and writing, not
`centre_decision_record` (needs centre_decide) and not the three Katalyst tools.

## 2. A ticket's life

`examples/scripts/02-tickets.php`: create with labels, priority and due date; comment; move; close; list; history.

```
created #1 in main/inbox, priority high, labels certs,ops
moved to doing at position 0
closed #1: closed (done) at 2026-11-24T12:00:00Z
open now: 1 (#2)
history: created=ticket on main/inbox, lane=doing, status=closed, closed_reason=done
comments: 1
```

New tickets go to the first lane of `[CentreSettings] Lanes[]`; a move without position appends to the lane;
every change is a history row.

## 3. The weekly decision review

`examples/scripts/03-decision-review.php`: two decisions (one this week, one from week 46), the review, one decision
recorded, the review again, and an editor who may not decide.

```
week 2026-W48: 1 due, 1 overdue, 0 decided
#1 closed (decided): PostgreSQL 19; upgrade after the holidays.
week 2026-W48: 0 due, 1 overdue, 1 decided
editor: ERROR: Permission denied: this tool needs the policy tron/centre_decide and the user 'editor' does not hold it.
```

## 4. Linking bus messages, to-dos and commits

`examples/scripts/04-link-gnubus.php`: link a ticket to a gnubus message, a session to-do and a commit; find the
ticket from the message id; a malformed id is refused with what a valid one looks like.

```
linked gnubus gb-20261124T101500Z-cloud-1a2b
linked todo claude@cluster#301
linked commit se7enxweb/tron@f99e348
the message belongs to #1 "Valkey failover on web" (open)
refused: ERROR: The gnubus reference 'not-a-bus-id' is not valid: expected a gnubus message id like gb-20261124T101500Z-cloud-1a2b
```

## 5. Tokens and HTTP

`examples/scripts/05-tokens-and-http.php`: a token scoped to the centre program, a tools/list over the HTTP transport
(the code `/tron/mcp` runs), the refusals, revocation. The token is never printed.

```
token for admin, scopes centre, expires 2026-12-24
HTTP 200: 14 tools; katalyst tools listed: no (out of the token's scope)
no token:      HTTP 401
other origin:  HTTP 403
notification:  HTTP 202
after revoke:  HTTP 401
```

An administrator's token scoped to `centre` sees 14 tools (the core two and the twelve centre tools): a scope narrows
a user, it never widens one.

## 6. Roles decide, the audit remembers

`examples/scripts/06-permissions-and-audit.php`: the same calls as viewer and editor, then the audit entries.

```
viewer  list: ok | create: denied | katalyst: denied
editor  list: ok | create: ok #1 | katalyst: denied
audit:
  viewer  centre_ticket_list     ok
  viewer  centre_ticket_create   denied
  viewer  katalyst_plans_list    denied
  editor  centre_ticket_list     ok
  editor  centre_ticket_create   started
  editor  centre_ticket_create   ok
  editor  katalyst_plans_list    denied
the editor's create was logged before it ran, with body: "password=[redacted]"
```

A write tool leaves two rows: `started` before it runs (no row, no run) and its outcome after. Secrets in arguments
are masked, also inside free text.

## 7. A program of your own

`examples/scripts/07-your-own-program.php` loads `examples/hello/` (laid out like an extension:
`tron/program.json` and `classes/examplehellotronprogram.php`) and uses its read tool, write tool, resource template
and prompt.

```
program centre from builtin: loaded
program class from builtin: loaded
program ini from builtin: loaded
program katalyst from builtin: loaded
program layout from builtin: loaded
program node from builtin: loaded
program role from builtin: loaded
program search from builtin: loaded
program tpl from builtin: loaded
program tron from builtin: loaded
program hello from hello: loaded
{"greeting":"Hello, editor!"}
{"greeting":"Hello, Ada! Hello, Ada!"}
"ERROR: Invalid arguments: times: must be at most 3"
{"remembered":"plan","notes":1}
"ERROR: The name \"secret\" is reserved; pick another one."
"ERROR: Permission denied: this tool needs the policy tron\/centre_write and the user 'viewer' does not hold it."
resource: Ship the preview.
missing resource: -32002 Resource not found
prompt: Introduce yourself to the ops team in one sentence, as an assistant working for editor.
```

The schema's bounds are checked before the handler runs; the handler's own refusal is a `tronToolError`; the policy
comes from the installation's roles. [guides/program-author.md](guides/program-author.md) walks through the class.

## 8. A client over real stdio

`examples/scripts/08-stdio-client.php` starts a server process and speaks newline-delimited JSON-RPC to it, as Claude
Code does.

```
initialize -> tron, protocol 2025-06-18
tools/call -> ticket #1 "Made over stdio"
unknown tool -> -32602 Unknown tool: nope_nothing
server exit code: 0
```

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
