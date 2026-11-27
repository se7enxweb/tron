# Performance

Exponential Tron is small and does little per call besides the tool's own work: build the server, check the policy, validate the
arguments, write the audit. This page gives the measured costs, what drives them and what to tune. All figures are
from the proving runs recorded in [testing.md](testing.md) (a development installation with about 68 active
extensions, PostgreSQL over a private network, PHP 8.5, PHP-FPM and Velocity) unless stated otherwise.

## Measured

| What | Time |
|---|---|
| Program discovery and registration, 300 extensions with 50 programs (test suite, every run) | about 3 ms |
| Building the whole server in a real installation | 11 to 43 ms |
| Program registration on the installation: centre / katalyst / tron | 22.7 / 3.0 / 0.3 ms |
| Read tools (list, review, Katalyst lists, whoami), median, two audit writes each | 19 to 23 ms |
| Tools that read more (get, board, status), median | 30 to 38 ms |
| Write tools, median (started row, the change, history rows, outcome row) | 47 to 97 ms |
| stdio smoke run, read / write, including PHP start | 1.1 s / 1.5 s |
| HTTP request through Velocity, median | about 120 to 135 ms |
| HTTP request through the public load balancer and PHP-FPM, median | about 300 to 520 ms |
| The full per-role tool run (86 checks, 8 users, every tool) | 17.9 s |
| The test suite (86 tests, about 1400 assertions, SQLite in memory) | about 5 s |

## What costs what

**Transport.** A stdio server builds everything once and then answers calls with only the tool's cost: it is the
fastest way for a long agent session. HTTP is stateless, so each request boots the kernel, builds the server and
authenticates the token: that fixed cost is most of an HTTP request's time. Velocity keeps the kernel warm and cuts it
by more than half.

**Audit.** Every call writes one row (two for a write tool). With both sinks, each row is written twice. The database
sink costs a round trip to the database; on a remote database that is most of a read tool's time.

**Tokens.** One indexed lookup by prefix per request. `last_used` is written at most once a minute per token, so a busy
agent does not turn every request into a write.

**Permissions.** One `eZUser::hasAccessTo` per policy, cached for the life of the checker (one request or one stdio
process). `tools/list` checks each distinct policy once, not each tool.

**Answers.** Capped at `[ServerSettings] ResponseMaxBytes` (200000). Large answers cost the model more than the
server: page with `limit` and `offset`.

## Tuning

| Goal | Do |
|---|---|
| Fastest agent sessions | stdio (on the node or over SSH): one build per session |
| Faster HTTP | serve the endpoint through Velocity; keep the database close to the nodes |
| Less audit cost | one sink (`database` or `file`), not both; the file sink is cheaper than a remote database |
| Smaller answers | lower `ResponseMaxBytes`; teach agents to page (see the prompt cookbook) |
| Less registration | leave unused programs out (`[ProgramSettings] Disabled[]`, or switches in Setup > tron); `ExtensionScan=disabled` when only Exponential Tron's own programs are wanted |
| Lists that stay fast | the centre tables are indexed by board, lane and status, by status and change time, and by decision week; audit by time and by user |

Program authors: keep `register()` free of work (it runs on every HTTP request), page every list, select only the
columns you return. [programs.md](programs.md) has the rules.

## Growth

- `tron_audit` grows by one or two rows per call. At 10 000 calls a day that is about 4 million rows a year; plan
  retention ([audit.md](audit.md)).
- `tron_token`, `tron_switch` stay tiny. The centre tables grow with tickets and history rows.

## Planned

Discovery cached by extension list and file times, metrics per tool (calls, errors, latency) for status pages and
dashboards, rate limits, a load test of the HTTP endpoint with recorded requests per second and percentiles, and
Velocity serving the endpoint without a per-request bootstrap ([roadmap.md](roadmap.md)).

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
