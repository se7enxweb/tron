# Centre decisions

The weekly decision review in parts: overdue, due, undated, later and taken decisions, open and closed decisions, and whether a week's meeting can take its load.

Pack `centre-decisions` 0.0.0.1, GPL-2.0-or-later, by 7x. Skill names start with `centre.`.
It works on the data of the extension `expcentre`.

## Who it is for

Whoever prepares and minutes the weekly decision meeting.

## Skills

| Skill | What it answers | Parameters | Units | Wave |
|---|---|---|---|---|
| `centre.decisions.review` | The decision review of an ISO week: how many decisions are overdue, due, undated and planned later, and which were taken during the week. | `--week` | centre_decision_review | A |
| `centre.decisions.overdue` | The open decisions planned for an earlier week than the given one, which nobody took yet. | `--week` | centre_decision_review | A |
| `centre.decisions.due` | The open decisions planned for the given ISO week. | `--week` | centre_decision_review | A |
| `centre.decisions.undated` | The open decisions that have no decision week yet. | `--week` | centre_decision_review | A |
| `centre.decisions.later` | The open decisions planned for a later week than the given one. | `--week` | centre_decision_review | A |
| `centre.decisions.taken` | The decisions taken during the given ISO week. | `--week` | centre_decision_review | A |
| `centre.decisions.open` | Lists the open decisions of a board with their decision week, the earliest week first. | `--board` `--limit` | centre_ticket_list | A |
| `centre.decisions.closed` | Lists the closed decisions of a board, most recently closed first. | `--board` `--limit` | centre_ticket_list | A |
| `centre.decisions.week-load` | Says whether the decisions due in a week (with the overdue ones) stay within a number the meeting can take. | `--week` `--capacity` | centre_decision_review | A |

## Units it needs

`centre_decision_review`, `centre_ticket_list`

A skill whose unit is not offered here is listed as parked with the reason; it runs nothing. Every skill runs as the
calling user, each unit checked against that user's policies and written to the audit log.

## Running one

```
xsh tron:skill:run centre.decisions.review
```

Over MCP a live skill is the tool `skill_centre_decisions_review` and a prompt of the same name.

See Exponential Tron's doc/skills.md and doc/extension-conventions.md.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
