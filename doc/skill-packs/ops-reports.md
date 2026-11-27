# Skill pack: Exponential Tron operations

The state of Exponential Tron itself: who a session acts as, the programs and their state, failed programs, tools left out and the protocol versions.

- Pack: `ops-reports` 0.0.0.1 in `share/skills/ops-reports/` (Exponential Tron's own packs)
- Prefix: `ops.`
- Skills: 5
- Audience: Operators of tron and anyone connecting a new client.

## Skills

| Skill | What it answers | Parameters | Units | Wave |
|---|---|---|---|---|
| `ops.tron.whoami` | The user, token and transport this Exponential Tron session acts as. | (none) | tron_whoami | A |
| `ops.tron.programs` | The programs tron loaded, with their version and state. | (none) | tron_status | A |
| `ops.tron.failed-programs` | The programs that failed, conflict or need a newer tron, with the reason. | (none) | tron_status | A |
| `ops.tron.left-out` | The tools a program left out on purpose here, with the reason (a missing extension or source). | (none) | tron_status | A |
| `ops.tron.protocol` | The tron version and the MCP protocol versions it speaks. | (none) | tron_status | A |

## Examples

### Who Exponential Tron acts as

The user, token and transport this Exponential Tron session acts as.

```
xsh tron:skill:run ops.tron.whoami
```

### Exponential Tron programs

The programs Exponential Tron loaded, with their version and state.

```
xsh tron:skill:run ops.tron.programs
```

### Failed programs

The programs that failed, conflict or need a newer Exponential Tron, with the reason.

```
xsh tron:skill:run ops.tron.failed-programs
```

### Tools left out

The tools a program left out on purpose here, with the reason (a missing extension or source).

```
xsh tron:skill:run ops.tron.left-out
```

### Protocol versions

The Exponential Tron version and the MCP protocol versions it speaks.

```
xsh tron:skill:run ops.tron.protocol
```

Back to [skills.md](../skills.md) and the [conventions](../extension-conventions.md).

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
