# Skill pack: Centre tickets

Lists over the centre's tickets: overdue, due soon, by priority, lane, kind, label and person, stale and waiting work, search.

- Pack: `centre-tickets` 0.0.0.1 in `share/skills/centre-tickets/` (Exponential Tron's own packs)
- Prefix: `centre.`
- Skills: 20
- Works on: `expcentre`
- Audience: Anyone who works from the centre boards: team leads at the stand-up, people planning their week, whoever runs triage.

## Skills

| Skill | What it answers | Parameters | Units | Wave |
|---|---|---|---|---|
| `centre.tickets.overdue` | Lists the open tickets of a board whose due date has passed, most overdue first, with how many days late each one is. | `--board` `--limit` | centre_ticket_list | A |
| `centre.tickets.due-today` | Lists the open tickets of a board that are due today, highest priority first. | `--board` `--limit` | centre_ticket_list | A |
| `centre.tickets.due-within` | Lists the open tickets of a board that fall due from today through the next given number of days, soonest first. | `--days` `--board` `--limit` | centre_ticket_list | A |
| `centre.tickets.no-due-date` | Lists the open tickets of a board that have no due date, oldest change first, so they can be dated or dropped. | `--board` `--limit` | centre_ticket_list | A |
| `centre.tickets.priority` | Lists the open tickets of a board with one priority (low, normal, high, urgent), soonest due first. | `--priority` `--board` `--limit` | centre_ticket_list | A |
| `centre.lane` | Lists the open tickets of a board in one lane (inbox, next, doing, waiting, done), by priority and due date. | `--lane` `--board` `--limit` | centre_ticket_list | A |
| `centre.kind` | Lists the open tickets of a board of one kind (ticket, card or decision), newest change first. | `--kind` `--board` `--limit` | centre_ticket_list | A |
| `centre.tickets.unassigned` | Lists the open tickets of a board that nobody is assigned to, highest priority first. | `--board` `--limit` | centre_ticket_list | A |
| `centre.tickets.mine` | Lists the open tickets of a board assigned to you (the user the skill runs as), soonest due first. | `--board` `--limit` | centre_ticket_list | A |
| `centre.tickets.mine-overdue` | Lists the open tickets of a board assigned to you whose due date has passed, most overdue first. | `--board` `--limit` | centre_ticket_list | A |
| `centre.tickets.assignee` | Lists the open tickets of a board assigned to a given login, soonest due first. | `--board` `--limit` `--who` | centre_ticket_list | A |
| `centre.tickets.stale` | Lists the open tickets of a board that nobody changed for longer than a number of days, the longest untouched first. | `--board` `--limit` `--days` | centre_ticket_list | A |
| `centre.tickets.recent` | Lists the open tickets of a board changed most recently, newest first. | `--board` `--limit` | centre_ticket_list | A |
| `centre.tickets.new` | Lists the tickets in the inbox lane of a board, newest first, for triage. | `--board` `--limit` | centre_ticket_list | A |
| `centre.tickets.urgent-unassigned` | Lists the urgent or high priority open tickets of a board that nobody is assigned to. | `--board` `--limit` | centre_ticket_list | A |
| `centre.tickets.urgent-overdue` | Lists the urgent or high priority open tickets of a board whose due date has passed. | `--board` `--limit` | centre_ticket_list | A |
| `centre.tickets.waiting-long` | Lists the tickets in the waiting lane of a board, the longest untouched first, to chase whoever they wait for. | `--board` `--limit` | centre_ticket_list | A |
| `centre.tickets.search` | Finds the open tickets of a board whose title holds the given words. | `--board` `--limit` `--query` | centre_ticket_list | A |
| `centre.tickets.closed-recent` | Lists the tickets of a board closed most recently, with the reason they were closed. | `--board` `--limit` | centre_ticket_list | A |
| `centre.label` | Lists the open tickets of a board that carry a label (admin, backup, db, docs, ops, security ...), highest priority first. | `--label` `--board` `--limit` | centre_ticket_list | A |

## Examples

### Overdue tickets

Lists the open tickets of a board whose due date has passed, most overdue first, with how many days late each one is.

```
xsh tron:skill:run centre.tickets.overdue --board main
```

### Due today

Lists the open tickets of a board that are due today, highest priority first.

```
xsh tron:skill:run centre.tickets.due-today --board main
```

### Due this week

Lists the open tickets of a board that fall due from today through the next 7 days, soonest first.

```
xsh tron:skill:run centre.tickets.due-within --days 7 --board main
```

### Due in two weeks

Lists the open tickets of a board that fall due from today through the next 14 days, soonest first.

```
xsh tron:skill:run centre.tickets.due-within --days 14 --board main
```

### Due this month

Lists the open tickets of a board that fall due from today through the next 30 days, soonest first.

```
xsh tron:skill:run centre.tickets.due-within --days 30 --board main
```

Back to [skills.md](../skills.md) and the [conventions](../extension-conventions.md).

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
