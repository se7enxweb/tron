# Contract testing: every tool keeps its word

Every tool declares what it takes and what it gives: an input schema and an output schema (`outputSchema`, listed in
`tools/list` to clients of MCP 2025-06-18 and later). Every write tool declares its dry run (doc/dry-run.md). A fuzzer
then holds every tool to that contract with thousands of inputs.

## The contract

| Rule | Checked by |
|---|---|
| an input schema of type object | `tronTool::define()`, ContractTest |
| an output schema of type object | ContractTest |
| a write tool has a dry run (`dryRun` or `plan`); the exception is `tron_apply`, which applies one | ContractTest, DryRunTest |
| the output schema is listed from 2025-06-18 on, and left out for older clients | ContractTest |

## The fuzzer

`tests/lib/fuzz.php` makes inputs from a tool's input schema, in seven categories:

| Category | What |
|---|---|
| valid | made to fit the schema: enums, ranges, lengths, strings generated from the schema's `pattern`, values that make sense for names like `tool`, `user`, `node_id` |
| boundary | lengths and numbers at, under and over their limits, `PHP_INT_MAX`, 2^31, 2^53+1, `1e20`, multi-byte strings at the maximum length |
| malformed | wrong types, missing required or unknown arguments, a list instead of an object, a scalar instead of arguments |
| injection | SQL, markup, paths, template and format strings, NUL bytes, line breaks, prompt injection text |
| unicode | scripts, emoji sequences, combining marks, direction marks, zero-width characters, invalid UTF-8 byte sequences |
| huge | a 1 MB string, 10 000 items, 300 levels of nesting |
| canary | the planted secrets (doc/redaction.md) as arguments |

Each input is sent by a user of the test world (half the time one who holds most policies, so valid inputs get past
the gates), over a random protocol version, sometimes as a dry run or an apply through `_meta`, every tenth call as
JSON text through `handleRaw()`, to two identical worlds A and B.

A call fails when:

- the answer is an internal error, or holds a PHP message (`Fatal error`, `Warning:`, a stack trace, `SQLSTATE`, a
  `.php:` path), or the tool "failed with an internal error", or the server logged an error;
- a successful answer does not fit the tool's output schema (or `tron_plan`'s for a dry run), or its
  `structuredContent` differs from its text;
- a canary secret, or another user's e-mail address or phone number, is anywhere in the answer;
- `tron_whoami` answers for another user than the caller;
- A and B answer differently with an error (an error must depend on nothing but the call and the state; successful
  answers may carry timings).

After the tools: 200 raw protocol messages (broken JSON, wrong types, deep nesting, invalid bytes) must get a clean
JSON-RPC error, and the whole audit log is searched for canaries.

The worlds (`tests/lib/fuzzworld.php`): the ten test users with their roles plus content grants (one editor limited
to a subtree), centre tickets by several users with canaries in titles, bodies, comments and links, Katalyst rows,
three content nodes (one a user's profile with an e-mail address and phone number), an INI instruction with a
password; `tables` has centre on Exponential Tron's tables, `bridge` centre through a stand-in of centre's API.

## Running it

```bash
php tests/run.php Contract                              # in the suite: 40 inputs per tool, both worlds
php tests/fuzz.php --per-tool=2000                      # the long run, both worlds, SQLite in memory
php tests/fuzz.php --per-tool=500 --programs=tron,centre --world=tables --show=centre_ticket_move
php tests/fuzz.php --dsn='pgsql:dbname=postgres' --peer-user=postgres --per-tool=500
TRON_MATRIX_FUZZ=200 php tests/matrix.php               # lint, suite and fuzzer on every PHP from 7.4 to 8.5
```

`--dsn` runs on PostgreSQL: each world in a schema of its own inside one transaction that is rolled back at the end
(PostgreSQL's DDL is transactional, so nothing remains), each call inside a savepoint. `--peer-user` connects as that
system user (local peer authentication) and carries on as before. MySQL is not offered: its DDL commits.

Same seed, same inputs, on every PHP version: the fuzzer has its own random generator.

## What it found (0.0.0.4)

| Defect | Found by | Fixed |
|---|---|---|
| ids and positions above 2^31-1 reached PostgreSQL's `integer` columns and failed as internal errors | boundary inputs on PostgreSQL | the integer arguments of the centre, content and Katalyst tools carry `maximum` 2147483647 |
| strings with invalid UTF-8 (possible from in-process callers) failed in PostgreSQL as internal errors | unicode inputs on PostgreSQL | the schema validator refuses a string that is not UTF-8 |
| answers deeper than 64 levels went through the JSON text unmasked while the structured copy was cut | huge inputs | the redactor marks cut parts and re-encodes the text |

Results per run are in doc/testing.md.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
