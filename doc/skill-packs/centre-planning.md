# Skill pack: Centre planning

Counts and reports over the centre boards: work per lane, priority, person and label, board snapshot and health, work-in-progress check, ticket digest, history and links, the weekly report.

- Pack: `centre-planning` 0.0.0.2 in `share/skills/centre-planning/` (Exponential Tron's own packs)
- Prefix: `centre.`
- Skills: 14
- Works on: `expcentre`
- Audience: Team leads and project managers who plan and report from the centre.

## Skills

| Skill | What it answers | Parameters | Units | Wave |
|---|---|---|---|---|
| `centre.count.by` | Counts the open or closed tickets of a board per assignee, kind, label, lane, priority or close reason, largest first. | `--by` `--status` `--board` | centre_ticket_list | A |
| `centre.count.overdue-by` | Counts the overdue open tickets of a board per assignee or per lane, largest first. | `--by` `--board` | centre_ticket_list | A |
| `centre.count.urgent-by-lane` | Counts the urgent and high priority open tickets of a board per lane. | `--board` | centre_ticket_list | A |
| `centre.count.labels-unassigned` | Counts the unassigned open tickets of a board per label, to see which areas lack an owner. | `--board` | centre_ticket_list | A |
| `centre.count.decisions-by-week` | Counts the open decisions of a board per decision week (empty: not planned). | `--board` | centre_ticket_list | A |
| `centre.board.snapshot` | The open tickets of a board lane by lane with the count per lane and the first items of each, in board order. | `--board` `--per-lane` | centre_board_view | A |
| `centre.board.wip-check` | Counts the tickets in the doing lane of a board and says whether that is within a work-in-progress limit. | `--board` `--wip-limit` | centre_ticket_list | A |
| `centre.board.health` | Five numbers about a board: open tickets, overdue, unassigned, urgent, and waiting. | `--board` | centre_ticket_list | A |
| `centre.ticket.digest` | One ticket in short: its fields, the number of comments and links, the last comment and the last change. | `--id` | centre_ticket_get | A |
| `centre.ticket.history` | The change history of one ticket, newest first, with who changed which field from what to what. | `--id` | centre_ticket_get | A |
| `centre.ticket.links` | The links of one ticket (commits, URLs, gnubus messages, to-dos, other tickets), grouped by kind. | `--id` | centre_ticket_get | A |
| `centre.links.commit` | Finds the tickets linked to a commit, with their status and title. | `--ref` | centre_links_find | A |
| `centre.links.url` | Finds the tickets linked to a url, with their status and title. | `--ref` | centre_links_find | A |
| `centre.report.weekly` | A weekly report of a board: open and overdue counts, what is in progress, what waits, the urgent items and a one-line summary. | `--board` | centre_ticket_list | A |

## Examples

### Tickets per lane

Counts the open tickets of a board per lane, to see where work piles up.

```
xsh tron:skill:run centre.count.by --by lane --status open --board main
```

### Tickets per priority

Counts the open tickets of a board per priority.

```
xsh tron:skill:run centre.count.by --by priority --status open --board main
```

### Workload per person

Counts the open tickets of a board per assignee (empty: unassigned), most loaded first.

```
xsh tron:skill:run centre.count.by --by assignee --status open --board main
```

### Tickets per label

Counts the open tickets of a board per label, most used first.

```
xsh tron:skill:run centre.count.by --by labels --status open --board main
```

### Tickets, cards and decisions

Counts the open tickets, cards and decisions of a board.

```
xsh tron:skill:run centre.count.by --by kind --status open --board main
```

Back to [skills.md](../skills.md) and the [conventions](../extension-conventions.md).

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
