# Guide for site administrators

You are responsible for an installation and decide who may use tron, how, and what it may do.

## Install, upgrade, remove

[../runbook.md](../runbook.md) has every step with its undo. The short version: activate the extension, regenerate
autoloads, deploy, `tron.php install`, `tron.php status`. tron changes no kernel table; it adds seven tables named
`tron_*` and nothing else.

## Roles

tron has no permission model of its own; it uses yours. Module **tron**, functions:

| Function | What it opens |
|---|---|
| use | connecting at all; tron_whoami, tron_status |
| admin | program origins and load times in tron_status |
| centre_read | centre: list, get, board, decision review, link lookup, resources, prompts |
| centre_write | centre: create, update, comment, move, close, link |
| centre_decide | centre: record a decision |
| katalyst_read | Katalyst: services, plans, orders (domains masked) |
| katalyst_detail | Katalyst: full domains and search by domain |

Suggested roles: **tron Viewer** (use, centre_read), **tron Editor** (+ centre_write), **tron Decider**
(+ centre_decide), **tron Operator** (use, katalyst_read). Grant functions without limitations: tron treats a policy
with limitations as not granted (a tool must declare that it checks limitations itself; none of the built-in tools
does).

Programs of other extensions name their own policies (any module/function); grant them the same way.

## Setup > tron

The administration has five pages under Setup > tron (policy `tron/admin`; Tokens needs `tron/tokens`, Audit
`tron/audit`). They exist in every admin design and follow light and dark mode.

| Page | What it does |
|---|---|
| Dashboard | version, programs and their state, tools, active tokens, the last day's calls by outcome, the administrators' actions, the ways in (stdio, HTTP endpoint, audit sinks) |
| Tokens | make a token for any user (shown once, on the answer page), list with filters by user and state, rotate (a new token for the same user, purpose, scopes and lifetime, the old one revoked; on an expired token: renew), revoke |
| Programs and tools | counts (tools on, write tools, tools no role grants), every program and tool, the policy each needs and the roles that grant it (links to the roles, "limited" where the role's policy has limitations), and a switch per program and tool |
| Audit | the audit log newest first; filters by outcome, transport (also `admin`: the administrators' own actions), program and user, paging; "Live" shows new entries as they come |
| Settings | tron.ini as the siteaccess reads it, with links into the INI editor |

Making, rotating or revoking a token and switching a program or tool are written to the audit log as the
administrator's actions (transport `admin`, method `admin/token.create`, `admin/token.rotate`, `admin/token.revoke`,
`admin/switch.on`, `admin/switch.off`; the token's id and prefix, never the token).

Making, rotating or revoking a token and switching a program or tool ask "Verify it's you" (the reauth group `tron`, on by
default; Setup > Verify it's you). Changing tron.ini goes through the INI editor, which asks too. Every filter and
page is a view parameter in the address, so a view can be bookmarked and shared; the user filter forms post and are
answered with the address of the filtered view.

The views are thin: each reads its view parameters and calls one data class in `classes/admin/` (tronAdminContext
plus one class per page), which the test suite checks on SQLite (`tests/unit/AdminDataTest.php`).

## Tokens

| Task | Command |
|---|---|
| make | `tron.php token:create --siteaccess=<sa> --user=<login> --name=<what for> --days=<n> [--scopes=a,b] --out=<file>` |
| list | `tron.php token:list --siteaccess=<sa> [--user=<login>]` |
| revoke | `tron.php token:revoke --siteaccess=<sa> <id or prefix>` |

Lifetimes: `[TokenSettings] DefaultLifetimeDays` (90) and `MaxLifetimeDays` (365). Only a hash is stored. Disabling
a user stops all of that user's tokens.

## The HTTP endpoint

Off until you open it: `[HTTPSettings] Enabled=true`, the siteaccess in `SiteAccesses[]` (a user-facing one with
`RequireUserLogin=false`), your host names in `AllowedHosts[]`. Where exp_request_shield runs, include
`doc/request-shield/tron.rules`. Behind Apache with PHP-FPM, pass the Authorization header. Details:
[../hosting.md](../hosting.md).

## The audit

`[AuditSettings] Sinks[]`: `database` (table `tron_audit`), `file` (JSON lines), or both. A write tool does not run
when its first audit entry cannot be written. Read it with `tron.php audit:tail`; the file sink suits log shipping
and fail2ban (failed HTTP sign-ins carry the remote address and the token's public prefix only).

The table grows with use; tron 0.0.0.1 has no retention job yet (planned for the next wave). Until then, archive and
delete old rows with your usual database tools if needed.

## Programs

`tron.php status` lists every program with its state. To leave one out: `[ProgramSettings] Disabled[]=<name>`. To
stop scanning extensions for programs: `ExtensionScan=disabled`. A program is code in your installation: review a
new one like any extension before activating it.

## Health checks after a change

```bash
php extension/tron/bin/php/tron.php status --siteaccess=<sa>                                        # PASS
php bin/php/ezexec.php extension/tron/tests/kernel/kernel_probe.php                                # read only, PASS
php extension/tron/tests/kernel/stdio_smoke.php -- php extension/tron/bin/php/tron.php serve --siteaccess=<sa> --user=<test login>
```

Use a throwaway test user for the smoke tests and remove it afterwards.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
