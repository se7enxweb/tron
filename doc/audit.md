# The audit log: what is kept and how to read it

Every `tools/call`, `resources/read`, `prompts/get`, every refused HTTP sign-in and every token and switch action
in the administration becomes an audit entry. A write tool is logged **before** it runs, and does not run when that entry
cannot be written. Code: `classes/core/tronserver.php` (entries), `classes/core/tronauditdb.php`,
`tronauditfile.php`, `tronauditmulti.php` (sinks), `classes/core/tronredactor.php` (masking).

## Sinks

```ini
[AuditSettings]
Sinks[]
Sinks[]=database          # table tron_audit (the default)
Sinks[]=file              # JSON lines
FilePath=var/log/tron-audit.log
```

| Sink | Good for | Notes |
|---|---|---|
| `database` | `audit:tail`, Setup > tron > Audit with filters and live tail | strings cut (target 255, detail 8000, others 150 bytes) |
| `file` | log shipping, fail2ban, `tail -f`, `jq` | one JSON object per line, appended under a lock |
| both | both | an entry counts as kept only when **both** took it |

tron refuses to start without a sink. The file sink creates its directory (0770) and file (0660) when missing; run as
root, it gives a new file the owner and group of its directory, so the web server can append to a file the command
line created first. If you create the file by hand, give it to the web server's user
(`chown --reference=var/log var/log/tron-audit.log`).

## Fields

| Field | Meaning |
|---|---|
| `created` | Unix time (UTC) |
| `user_id`, `login` | who; `0` and empty for a sign-in that named no valid user |
| `token_id` | the token's row id; `0` for stdio with `--user`, the command line and the administration |
| `transport` | `stdio`, `http`, `cli` (`tron.php call`) or `admin` (Setup > tron) |
| `remote` | the client address over HTTP (`REMOTE_ADDR`; behind a proxy, what the proxy or PROXY protocol hands PHP); `stdio`/`cli` otherwise |
| `method` | `tools/call`, `resources/read`, `prompts/get`, `auth` (an HTTP sign-in refused), `admin/token.create`, `admin/token.rotate`, `admin/token.revoke`, `admin/switch.on`, `admin/switch.off` |
| `target` | the tool, resource URI or prompt; for admin actions the token (`token 7`) or the switched program or tool |
| `program` | the program that owns the target |
| `outcome` | see below |
| `duration_ms` | time spent, measured from the start of the call |
| `detail` | JSON: `{"args": {...the arguments after redaction...}, "note": "..."}` |

### Outcomes

| Outcome | Meaning |
|---|---|
| `started` | a write tool is about to run (its arguments are in this row) |
| `ok` | the call succeeded |
| `error` | the tool refused (`tronToolError`, its message in `note`) or failed (internal error; details only in the server log) |
| `denied` | the user lacks the policy; or, for `auth`, a valid token of a user without `tron/use` |
| `invalid` | the arguments broke the tool's input schema |
| `unauthenticated` | an HTTP request without a valid token; `note` says why (malformed, unknown, revoked, expired, user missing or disabled) with the token's prefix only |

A successful write therefore leaves two rows, `started` then `ok`. A `started` row with no outcome row after it means
the process died inside the tool: look in the server log at that time.

## What is masked

- Values under keys that look secret (`pass`, `secret`, `token`, `api_key`, `private`, `credential`, `authorization`,
  `cookie`) become `[redacted]`.
- In free text, `password=...`, `secret: ...`, `api_key=...`, `token=...`, `credentials: ...` keep their name and lose
  their value.
- Anything shaped like a tron token or a bearer header is masked wherever it appears.
- Strings are cut at 200 characters, arrays at 50 entries, nesting at 6 levels.

What tools **return** is not written to the audit: the arguments, the outcome and the time are.

## Reading it

### Command line

```bash
php extension/tron/bin/php/tron.php audit:tail --siteaccess=<sa> --limit=50
php extension/tron/bin/php/tron.php audit:tail --siteaccess=<sa> --user=agent-claude --limit=200
```

```
2026-11-24 12:00:09 agent-claude stdio  tools/call      centre_ticket_create             started              0 ms
2026-11-24 12:00:09 agent-claude stdio  tools/call      centre_ticket_create             ok                  58 ms
2026-11-24 12:03:41 viewer       http   tools/call      centre_ticket_close              denied               4 ms
2026-11-24 12:05:02              http   auth                                             unauthenticated      0 ms
```

At most 500 rows per call, oldest first.

### Setup > tron > Audit

Newest first, filters by outcome, transport, program and user, paging, and **Live**: new rows appear as they come
(the page polls every 5 seconds). Every filter is a view parameter in the address, so a filtered view can be
bookmarked and shared:

```
/tron/audit/(outcome)/denied/(user)/agent-claude
```

Needs the policy `tron/audit`.

### The file sink

```bash
tail -f var/log/tron-audit.log | jq -c '{t: (.created|todate), login, transport, method, target, outcome, duration_ms}'
```

Common questions, answered with `jq`:

```bash
# every refused HTTP sign-in, by remote address
jq -r 'select(.method=="auth") | [.remote, .outcome, (.detail|fromjson|.note)] | @tsv' var/log/tron-audit.log | sort | uniq -c

# what one agent wrote today
jq -c --arg d "$(date -u +%F)" 'select(.login=="agent-claude" and .outcome=="ok" and (.created|todate|startswith($d)))
       | {target, args: (.detail|fromjson|.args)}' var/log/tron-audit.log

# the slowest calls
jq -r '[.duration_ms, .target, .login] | @tsv' var/log/tron-audit.log | sort -rn | head
```

### SQL

```sql
-- calls per tool and outcome in the last 24 hours
SELECT target, outcome, COUNT(*) AS calls, AVG(duration_ms) AS avg_ms
FROM tron_audit
WHERE created > <unix time 24 hours ago>
GROUP BY target, outcome
ORDER BY calls DESC;
```

## Reviewing an agent's session

1. Find the token: `token:list --user=<login>`; note its id.
2. Read the session in order: Setup > tron > Audit filtered by user, or the file sink filtered by `token_id`.
3. For each write, the `started` row has the arguments; the ticket's own history (`centre_ticket_get`) shows every
   field it changed, by whom and when.
4. `denied` and `invalid` rows show what the agent tried and was not allowed to do, or got wrong. A run of `denied`
   rows is the first sign of a prompt asking the agent for more than its role allows.

## fail2ban

Failed HTTP sign-ins in the file sink carry the remote address and outcome `unauthenticated`. A filter:

```ini
# /etc/fail2ban/filter.d/tron.conf
[Definition]
failregex = "remote":"<HOST>".*"method":"auth".*"outcome":"unauthenticated"
```

```ini
# /etc/fail2ban/jail.d/tron.conf
[tron]
enabled  = true
filter   = tron
logpath  = <root>/var/log/tron-audit.log
maxretry = 10
findtime = 600
bantime  = 3600
```

The order of keys in a line follows the entry (`created`, `user_id`, `login`, `token_id`, `transport`, `remote`,
`method`, ...); test the filter with `fail2ban-regex` against your own file before enabling the jail. Behind a load
balancer, ban on the node only if the node sees the real client address (PROXY protocol); otherwise ban at the front.

## Retention

The table grows with use; tron 0.0.0.1 has no retention job yet (planned, with shipping to a storage node). Until
then, archive and delete old rows with your database tools, for example monthly:

```sql
DELETE FROM tron_audit WHERE created < <unix time 180 days ago>;
```

Keep the file sink in your log rotation (`copytruncate`, or a rotation that recreates the file with the same owner
and mode 0660, so the web server can still append).

## The guarantee

- A write tool runs only after its `started` entry has been written to every sink. If the database is down or the
  file not writable, the tool answers "The audit log is unavailable, so this write tool was not run" and nothing
  changes.
- Read tools keep answering when the audit cannot be written; the lost entry is reported in the server log.
- Theory claims C4.1 to C4.5 and their tests: [theory.md](theory.md).

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
