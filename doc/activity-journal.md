# The activity journal

The audit log answers *which call, by whom, with what outcome*. The activity journal answers the question a person
asks afterwards: **what did the agent do, and why?** It turns every MCP session and every background job into a story
that can be read weeks later: who connected with which client, what the agent said it was doing, each step with what
it touched and what came back, the diff of every write, and what the jobs it started did in the background. Each
session is chained by a hash, so a changed, removed or inserted step is detected.

It builds on the audit and does not replace it: the journal is fed by the same entries (it is one more audit sink),
and an audit entry names the journal session it belongs to.

## Contents

- [What it records](#what-it-records)
- [Reading it](#reading-it)
- [Telling it why: tron_journal_note](#telling-it-why-tron_journal_note)
- [Background jobs](#background-jobs)
- [Writes, dry runs and diffs](#writes-dry-runs-and-diffs)
- [Integrity: the hash chain](#integrity-the-hash-chain)
- [Redaction](#redaction)
- [Settings](#settings)
- [Retention and rotation](#retention-and-rotation)
- [For program authors](#for-program-authors)
- [Tables](#tables)
- [Tests](#tests)

## What it records

| | |
|---|---|
| **Session** | one per stdio process, one per HTTP client and token within the idle window (`IdleMinutes`), one per command-line call, one per background job. Facts: user, token id, transport, client name and version (from `initialize`), protocol, node (host name), remote, start, end and why it ended, counts of steps, writes, refusals and errors. |
| **Intent** | a title and a purpose the agent gives with `tron_journal_note` (kind `intent`); shown on top of the session. |
| **Notes and milestones** | in the agent's own words, in order with the calls. |
| **Steps** | every `tools/call`, `resources/read` and `prompts/get`: tool, arguments (redacted), a one-line result summary, duration, outcome (`ok`, `started`, `dry-run`, `refused`, `error`) with a code (`policy`, `invalid_arguments`, `plan`, `tool_error`, `internal`, `not_found`), the objects it touched, and for writes the diff or a pointer to it. A write tool's step announced before it runs (`started`) and its outcome are linked (`ref_seq`). |
| **Objects** | read from arguments and answers by convention, so tools declare nothing: `node_id` → `node:123`, `object_id` → `object:57`, `layout_id` → `layout:4`, an INI file with a group and key → `ini:site.ini[SiteSettings]IndexPage`, `path`/`file` → `file:...`, any other `<thing>_id` → `<thing>:<n>`, and a bare `id` takes the kind of the tool's name (`centre_ticket_get` id 5 → `ticket:5`). Each is marked `read` or `write`. |
| **Jobs** | progress, checkpoints and the final result of background work, linked to the session that started it. |

## Reading it

**Setup > tron > Activity** (policy `tron/audit`, the same as the Audit page):

- the session list, newest first, with filters as view parameters: `(user)`, `(outcome)`, `(transport)`, `(kind)` (sessions or background jobs), `(tool)`, `(from)`, `(to)`;
- a session (`tron/activity/(session)/<id>`): its facts, the intent, the hash chain check, the **story** in plain sentences, the jobs it started and the **timeline** in the order it happened, with each step's objects (links to their history), arguments and diff;
- an object's history (`tron/activity/(object)/node:123`): everything Exponential Tron did to that object, across sessions;
- exports of a session as text, Markdown or JSON (`(format)/text|markdown|json`).

A story reads like this:

> On 2026-11-24 at 21:04:02 Claude Code 2.1.0 (as claude-cluster, token #3) started a session over stdio on
> node1.example.org. At 21:04:05 it said what it is doing: "Fix the front page teaser". At 21:04:09 it used Get
> a node (node_get) on node 2, then used Find a layout (layout_find) on node 2. At 21:05:11 it changed node 2 with
> Set a field (node_field_set) (184 ms). Its diff is recorded (plan:9f3c…). The session ended at 21:06:40 (the
> client closed stdin) after 9 steps: 4 calls, 1 writes, 0 refused, 0 errors. The hash chain of its 9 steps is intact.

**Tools**, for agents (every user reads their own sessions; with `tron/audit` everyone's):

| Tool | |
|---|---|
| `tron_journal_list` | sessions by `user`, `from`/`to` (YYYY-MM-DD), `tool`, `outcome`, `object`, `kind` (`mcp`, `job`), `transport` |
| `tron_journal_show` | one `session` (or `current: true`) as `narrative` (default), `timeline`, `markdown` or `json`, with `verify` |
| `tron_journal_search` | steps across sessions: the filters above plus `text`; "everything Exponential Tron did to node 123" is `object: "node:123"` |
| `tron_journal_note` | see below |

**Command line** (`bin/php/tron-journal.php`, from the installation root, `--siteaccess=<sa>`):

```
tron-journal.php list   [--user=..] [--from=YYYY-MM-DD] [--to=..] [--tool=..] [--outcome=..] [--object=node:123] [--kind=mcp|job]
tron-journal.php show <id> [--format=narrative|markdown|json]
tron-journal.php search [the filters] [--text=..]
tron-journal.php verify [<id>]          the hash chain of one or every session (exit 1 when one is broken)
tron-journal.php rotate [--dry-run]     retention
tron-journal.php job:run --name=.. --command=.. [--user=..] [--parent=<session>]
```

## Telling it why: tron_journal_note

A call log shows what happened; only the agent knows why. `tron_journal_note` writes into the journal of the current
session:

| kind | |
|---|---|
| `intent` | what this session is for: `title` (short) and `text` (the purpose). Set it first; the title names the session in lists. |
| `note` | why the next step is taken ("the teaser is cut because the layout block has max 120 characters") |
| `milestone` | a part of the task is done |

Good agent instructions ask for one `intent` at the start and a `milestone` after each part of the task. Notes are
redacted like everything else; secrets do not belong in them.

## Background jobs

A job writes its own session (kind `job`) linked to the session that started it:

```php
$job = $ctx->session->journal ? $ctx->session->journal->job( 'reindex the media site' ) : null;   // in a tool
$job = $journal->job( 'nightly import', array( 'login' => 'importer' ) );                          // anywhere else
$job->progress( 40, '400 of 1000 nodes' );
$job->checkpoint( 'batch 4 done', array( 'last_node' => 1234 ), array( 'node:1234' ) );
$job->finish( 'ok', '1000 nodes reindexed', array( 'nodes' => 1000 ) );       // or $job->fail( 'why' )
```

Any command can run as a journaled job without changing it: `tron-journal.php job:run --name=.. --command=..` runs it,
turns each output line `progress: 40% text` and `checkpoint: label` into a step, and records the exit code and the
last lines as the result. "What did it do in the background" is then the job's session in Setup > tron > Activity.

## Writes, dry runs and diffs

For a write the journal keeps the change itself, not just the call:

- a **dry run** (`_meta` `tron/dryRun`) is a step with outcome `dry-run`, its planned changes as the diff and
  `plan:<id>` as the reference;
- the **apply** of that plan (`_meta` `tron/planId`) is a write step with the same reference and a copy of the planned diff;
- a tool may attach its own before/after (`$ctx->session->journal->diff( $diff, 'ref' )`), or answer with `diff` or
  `changes`, which are taken as the diff;
- `StoreDiffs=disabled` keeps only the reference; `MaxDiffBytes` cuts long diffs.

## Integrity: the hash chain

Every step stores `hash = sha256( previous hash, the step's fields )`; the first step starts from a value derived
from the session's random key and carries the session's facts. `verify` (on every `tron_journal_show`, on the session
page, and `tron-journal.php verify`) recomputes the chain and reports the first step that was changed, removed or
inserted, a removed last step (the session row keeps the count and the last hash), and session facts that no longer
match the start step.

The chain makes tampering *detectable*, not impossible: someone with write access to the database who recomputes
every hash after a change is not caught by the chain alone. For that, copy the session's last hash (shown on the
session page and in exports) somewhere the database cannot reach, such as the audit file (`[AuditSettings] Sinks[]=file`
carries the session key on every line) or an archive.

## Redaction

Nothing reaches a journal row unredacted: arguments are stored as the audit stores them (secret-looking keys
replaced, Exponential Tron tokens and bearer headers masked, `password=...` in text masked, long strings cut), result summaries,
notes, diffs, client names and job texts go through the same redactor, and tool answers are redacted before the
journal sees them. The test suite plants canary secrets in arguments, notes, diffs and job results and checks that
none of them appears in any journal table or export.

## Settings

tron.ini `[JournalSettings]`:

| Setting | Default | |
|---|---|---|
| `Enabled` | `enabled` | `disabled` stops recording; what was recorded stays readable |
| `IdleMinutes` | `30` | HTTP requests within this gap are one session; open sessions idle longer are closed by `rotate` |
| `RetentionDays` | `180` | sessions idle longer are removed by `rotate` (`0` keeps everything) |
| `Archive` | `enabled` | a removed session is first appended to `<ArchiveDir>/tron-journal-<YYYY-MM>.jsonl` |
| `ArchiveDir` | `var/log/tron-journal` | |
| `StoreDiffs` | `enabled` | keep diffs, or only their references |
| `MaxDiffBytes` | `20000` | |
| `MaxResultChars` | `300` | the result summary of a step |
| `MaxInputBytes` | `8000` | the arguments of a step |
| `Required` | `disabled` | `enabled`: a write tool does not run when its `started` step cannot be journaled, like the audit |

A bare settings array (a program's own tests) leaves the journal off; tron.ini switches it on.

## Retention and rotation

`tron-journal.php rotate` removes sessions whose last activity is older than `RetentionDays`, after archiving each one
(with its steps and its chain check) as one JSON line, and closes open sessions idle longer than `IdleMinutes`. Run it
daily from a systemd timer or cron; `--dry-run` only counts.

## For program authors

Nothing is required: the journal reads arguments and answers by convention. To tell it more, use the handle on the
session (it is `null` when the journal is off):

```php
'handler' => function ( array $args, tronCallContext $ctx ) {
    // ... the change ...
    if ( $ctx->session->journal )
    {
        $ctx->session->journal->objects( array( 'node:' . $nodeId, 'ini:site.ini[SiteSettings]IndexPage' ) );
        $ctx->session->journal->diff( array( 'before' => $old, 'after' => $new ) );
    }
    return array( 'node_id' => $nodeId );
},
```

## Tables

| Table | |
|---|---|
| `tron_journal_session` | one row per session or job: facts, title and intent, counts, status, last hash |
| `tron_journal_step` | the steps in order (`session_id`, `seq` unique), each with `prev_hash` and `hash` |
| `tron_journal_object` | which step touched which object: the index behind object histories; derived from the steps |

`tron.php install` creates them; the CREATE statements are in `sql/<engine>/schema.sql`.

## Tests

`tests/unit/JournalTest.php` (SQLite): sessions and steps from real calls, the audit entry naming its session, the
narrative, the hash chain against a changed step, a removed middle and last step and a rewritten session row, canary
secrets, a background job, HTTP sessions within the idle window, search and object history, the tools and who may
read what, dry run and apply keeping the diff, retention with archive, the journal off without its setting, object
extraction, and the data of Setup > tron > Activity.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
