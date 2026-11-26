# tron operations

The state of tron itself: who a session acts as, the programs and their state, failed programs, tools left out and the protocol versions.

Pack `ops-reports` 0.0.0.1, GPL-2.0-or-later, by 7x. Skill names start with `ops.`.

## Who it is for

Operators of tron and anyone connecting a new client.

## Skills

| Skill | What it answers | Parameters | Units | Wave |
|---|---|---|---|---|
| `ops.tron.whoami` | The user, token and transport this tron session acts as. | (none) | tron_whoami | A |
| `ops.tron.programs` | The programs tron loaded, with their version and state. | (none) | tron_status | A |
| `ops.tron.failed-programs` | The programs that failed, conflict or need a newer tron, with the reason. | (none) | tron_status | A |
| `ops.tron.left-out` | The tools a program left out on purpose here, with the reason (a missing extension or source). | (none) | tron_status | A |
| `ops.tron.protocol` | The tron version and the MCP protocol versions it speaks. | (none) | tron_status | A |

## Units it needs

`tron_status`, `tron_whoami`

A skill whose unit is not offered here is listed as parked with the reason; it runs nothing. Every skill runs as the
calling user, each unit checked against that user's policies and written to the audit log.

## Running one

```
xsh tron:skill:run ops.tron.whoami
```

Over MCP a live skill is the tool `skill_ops_tron_whoami` and a prompt of the same name.

See tron's doc/skills.md and doc/extension-conventions.md.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
