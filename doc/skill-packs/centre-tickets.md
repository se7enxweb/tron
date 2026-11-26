# Skill pack: Centre tickets

Lists over the centre's tickets: overdue, due soon, by priority, lane, kind, label and person, stale and waiting work, search.

- Pack: `centre-tickets` 0.0.0.1 in `share/skills/centre-tickets/` (tron's own packs)
- Prefix: `centre.`
- Skills: 44
- Works on: `expcentre`
- Audience: Anyone who works from the centre boards: team leads at the stand-up, people planning their week, whoever runs triage.

## Skills

| Skill | What it answers | Parameters | Units | Wave |
|---|---|---|---|---|
| `centre.tickets.overdue` | Lists the open tickets of a board whose due date has passed, most overdue first, with how many days late each one is. | `--board` `--limit` | centre_ticket_list | A |
| `centre.tickets.due-today` | Lists the open tickets of a board that are due today, highest priority first. | `--board` `--limit` | centre_ticket_list | A |
| `centre.tickets.due-7d` | Lists the open tickets of a board that fall due from today through the next 7 days, soonest first. | `--board` `--limit` | centre_ticket_list | A |
| `centre.tickets.due-14d` | Lists the open tickets of a board that fall due from today through the next 14 days, soonest first. | `--board` `--limit` | centre_ticket_list | A |
| `centre.tickets.due-30d` | Lists the open tickets of a board that fall due from today through the next 30 days, soonest first. | `--board` `--limit` | centre_ticket_list | A |
| `centre.tickets.no-due-date` | Lists the open tickets of a board that have no due date, oldest change first, so they can be dated or dropped. | `--board` `--limit` | centre_ticket_list | A |
| `centre.tickets.priority-urgent` | Lists the open tickets of a board with the priority urgent, the ones due first at the top. | `--board` `--limit` | centre_ticket_list | A |
| `centre.tickets.priority-high` | Lists the open tickets of a board with the priority high, the ones due first at the top. | `--board` `--limit` | centre_ticket_list | A |
| `centre.tickets.priority-normal` | Lists the open tickets of a board with the priority normal, the ones due first at the top. | `--board` `--limit` | centre_ticket_list | A |
| `centre.tickets.priority-low` | Lists the open tickets of a board with the priority low, the ones due first at the top. | `--board` `--limit` | centre_ticket_list | A |
| `centre.lane.inbox` | Lists the open tickets of a board in the lane inbox (not looked at yet), by priority and due date. | `--board` `--limit` | centre_ticket_list | A |
| `centre.lane.next` | Lists the open tickets of a board in the lane next (planned next), by priority and due date. | `--board` `--limit` | centre_ticket_list | A |
| `centre.lane.doing` | Lists the open tickets of a board in the lane doing (in progress), by priority and due date. | `--board` `--limit` | centre_ticket_list | A |
| `centre.lane.waiting` | Lists the open tickets of a board in the lane waiting (waiting on someone), by priority and due date. | `--board` `--limit` | centre_ticket_list | A |
| `centre.lane.done` | Lists the open tickets of a board in the lane done (done but still open), by priority and due date. | `--board` `--limit` | centre_ticket_list | A |
| `centre.kind.ticket` | Lists the open tickets of a board, newest change first. | `--board` `--limit` | centre_ticket_list | A |
| `centre.kind.card` | Lists the open cards of a board, newest change first. | `--board` `--limit` | centre_ticket_list | A |
| `centre.kind.decision` | Lists the open decisions of a board, newest change first. | `--board` `--limit` | centre_ticket_list | A |
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
| `centre.label.security` | Lists the open tickets of a board labelled security (security work), highest priority first. | `--board` `--limit` | centre_ticket_list | A |
| `centre.label.perf` | Lists the open tickets of a board labelled perf (performance work), highest priority first. | `--board` `--limit` | centre_ticket_list | A |
| `centre.label.seo` | Lists the open tickets of a board labelled seo (search engine work), highest priority first. | `--board` `--limit` | centre_ticket_list | A |
| `centre.label.i18n` | Lists the open tickets of a board labelled i18n (translation work), highest priority first. | `--board` `--limit` | centre_ticket_list | A |
| `centre.label.docs` | Lists the open tickets of a board labelled docs (documentation work), highest priority first. | `--board` `--limit` | centre_ticket_list | A |
| `centre.label.ops` | Lists the open tickets of a board labelled ops (operations work), highest priority first. | `--board` `--limit` | centre_ticket_list | A |
| `centre.label.site` | Lists the open tickets of a board labelled site (public site work), highest priority first. | `--board` `--limit` | centre_ticket_list | A |
| `centre.label.layouts` | Lists the open tickets of a board labelled layouts (Exponential Layouts work), highest priority first. | `--board` `--limit` | centre_ticket_list | A |
| `centre.label.media` | Lists the open tickets of a board labelled media (media and images work), highest priority first. | `--board` `--limit` | centre_ticket_list | A |
| `centre.label.db` | Lists the open tickets of a board labelled db (database work), highest priority first. | `--board` `--limit` | centre_ticket_list | A |
| `centre.label.backup` | Lists the open tickets of a board labelled backup (backup work), highest priority first. | `--board` `--limit` | centre_ticket_list | A |
| `centre.label.admin` | Lists the open tickets of a board labelled admin (administration work), highest priority first. | `--board` `--limit` | centre_ticket_list | A |
| `centre.label.php` | Lists the open tickets of a board labelled php (PHP work), highest priority first. | `--board` `--limit` | centre_ticket_list | A |
| `centre.label.search` | Lists the open tickets of a board labelled search (search work), highest priority first. | `--board` `--limit` | centre_ticket_list | A |

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
xsh tron:skill:run centre.tickets.due-7d --board main
```

### Due in two weeks

Lists the open tickets of a board that fall due from today through the next 14 days, soonest first.

```
xsh tron:skill:run centre.tickets.due-14d --board main
```

### Due this month

Lists the open tickets of a board that fall due from today through the next 30 days, soonest first.

```
xsh tron:skill:run centre.tickets.due-30d --board main
```

Back to [skills.md](../skills.md) and the [conventions](../extension-conventions.md).

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
