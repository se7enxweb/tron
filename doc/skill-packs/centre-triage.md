# Skill pack: Centre triage (writes)

Skills that change tickets: move between lanes, close what is done, nudge, raise priority, assign, date, file, decide, link. They wait for wave B (a dry run of the whole chain, applied after approval) and are parked until then.

- Pack: `centre-triage` 0.0.0.1 in `share/skills/centre-triage/` (Exponential Tron's own packs)
- Prefix: `centre.`
- Skills: 19
- Works on: `expcentre`
- Audience: Teams that triage in bulk, once wave B lands.

## Skills

| Skill | What it answers | Parameters | Units | Wave |
|---|---|---|---|---|
| `centre.triage.move-inbox-to-next` | Moves every open ticket of a board from the lane inbox to the lane next, at most a given number per run. | `--board` `--limit` | centre_ticket_list, centre_ticket_move | B |
| `centre.triage.move-next-to-doing` | Moves every open ticket of a board from the lane next to the lane doing, at most a given number per run. | `--board` `--limit` | centre_ticket_list, centre_ticket_move | B |
| `centre.triage.move-doing-to-waiting` | Moves every open ticket of a board from the lane doing to the lane waiting, at most a given number per run. | `--board` `--limit` | centre_ticket_list, centre_ticket_move | B |
| `centre.triage.move-waiting-to-doing` | Moves every open ticket of a board from the lane waiting to the lane doing, at most a given number per run. | `--board` `--limit` | centre_ticket_list, centre_ticket_move | B |
| `centre.triage.move-doing-to-done` | Moves every open ticket of a board from the lane doing to the lane done, at most a given number per run. | `--board` `--limit` | centre_ticket_list, centre_ticket_move | B |
| `centre.triage.move-waiting-to-next` | Moves every open ticket of a board from the lane waiting to the lane next, at most a given number per run. | `--board` `--limit` | centre_ticket_list, centre_ticket_move | B |
| `centre.triage.urgent-to-next` | Moves the urgent tickets of the inbox of a board to the lane next so they are planned first. | `--board` | centre_ticket_list, centre_ticket_move | B |
| `centre.triage.close-done` | Closes every open ticket of a board that sits in the lane done, with the reason done. | `--board` | centre_ticket_list, centre_ticket_close | B |
| `centre.triage.nudge-overdue` | Adds a comment to every overdue open ticket of a board saying how late it is, so its assignee sees it. | `--board` | centre_ticket_list, centre_ticket_comment | B |
| `centre.triage.nudge-waiting` | Adds a comment to every ticket in the waiting lane of a board asking what it still waits for. | `--board` | centre_ticket_list, centre_ticket_comment | B |
| `centre.triage.raise-overdue-urgent` | Raises the priority of the overdue open urgent tickets of a board by one step. | `--board` | centre_ticket_list, centre_ticket_update | B |
| `centre.triage.raise-overdue-high` | Raises the priority of the overdue open high tickets of a board by one step. | `--board` | centre_ticket_list, centre_ticket_update | B |
| `centre.triage.raise-overdue-normal` | Raises the priority of the overdue open normal tickets of a board by one step. | `--board` | centre_ticket_list, centre_ticket_update | B |
| `centre.triage.raise-overdue-low` | Raises the priority of the overdue open low tickets of a board by one step. | `--board` | centre_ticket_list, centre_ticket_update | B |
| `centre.triage.assign-unassigned` | Assigns the unassigned open tickets of a board to a given login, highest priority first, at most a given number. | `--board` `--limit` `--to` | centre_ticket_list, centre_ticket_update | B |
| `centre.triage.date-undated` | Gives every open ticket of a board without a due date the due date a number of days from today. | `--board` `--days` | centre_ticket_list, centre_ticket_update | B |
| `centre.triage.file-ticket` | Creates a ticket on a board with a title, priority and labels, in the inbox. | `--board` `--title` `--priority` | centre_ticket_create | B |
| `centre.triage.decide` | Records the decision of an open decision ticket and closes it. | `--id` `--decision` | centre_decision_record | B |
| `centre.triage.link-commit` | Links a commit (repo@sha) to a ticket. | `--id` `--ref` | centre_ticket_link | B |

## Examples

### Move inbox to next

Moves every open ticket of a board from the lane inbox to the lane next, at most a given number per run.

```
xsh tron:skill:run centre.triage.move-inbox-to-next --board main
```

### Move next to doing

Moves every open ticket of a board from the lane next to the lane doing, at most a given number per run.

```
xsh tron:skill:run centre.triage.move-next-to-doing --board main
```

### Move doing to waiting

Moves every open ticket of a board from the lane doing to the lane waiting, at most a given number per run.

```
xsh tron:skill:run centre.triage.move-doing-to-waiting --board main
```

### Move waiting to doing

Moves every open ticket of a board from the lane waiting to the lane doing, at most a given number per run.

```
xsh tron:skill:run centre.triage.move-waiting-to-doing --board main
```

### Move doing to done

Moves every open ticket of a board from the lane doing to the lane done, at most a given number per run.

```
xsh tron:skill:run centre.triage.move-doing-to-done --board main
```

Back to [skills.md](../skills.md) and the [conventions](../extension-conventions.md).

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
