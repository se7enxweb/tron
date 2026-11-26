# Katalyst reports

Hosting and shop reports from Katalyst by ids and counts only: services per status, plan, server and cycle, due and overdue services, disk use, plans and prices, orders per state, pending orders, revenue and fraud checks.

Pack `katalyst-reports` 0.0.0.1, GPL-2.0-or-later, by 7x. Skill names start with `katalyst.`.
It works on the data of the extension `sevenx_katalyst`.

## Who it is for

Whoever runs the hosting business: billing, capacity and catalog reviews.

## Skills

| Skill | What it answers | Parameters | Units | Wave |
|---|---|---|---|---|
| `katalyst.services.by-status` | How many hosting services are active, suspended, pending or cancelled. | (none) | katalyst_services_list | A |
| `katalyst.services.by-plan` | How many hosting services each plan has. | (none) | katalyst_services_list | A |
| `katalyst.services.by-server` | How many hosting services each server carries. | (none) | katalyst_services_list | A |
| `katalyst.services.by-cycle` | How many hosting services bill monthly, yearly and so on. | (none) | katalyst_services_list | A |
| `katalyst.services.by-kind` | How many services of each kind (hosting, domain ...) there are. | (none) | katalyst_services_list | A |
| `katalyst.services.suspended` | The suspended hosting services by id, plan and server (no domains, no customer data). | (none) | katalyst_services_list | A |
| `katalyst.services.due-soon` | The services whose next due date falls within a number of days, soonest first (ids only). | `--days` | katalyst_services_list | A |
| `katalyst.services.overdue` | The services whose next due date has passed (ids only). | (none) | katalyst_services_list | A |
| `katalyst.services.disk-top` | The services using the most disk, largest first (ids, plan and server only). | (none) | katalyst_services_list | A |
| `katalyst.services.gone` | How many services Katalyst still remembers that no longer exist upstream. | (none) | katalyst_services_list | A |
| `katalyst.plans.list` | The plans and products on offer with their monthly price, cheapest first. | (none) | katalyst_plans_list | A |
| `katalyst.plans.by-source` | How many plans come from Plesk, the shop and the old billing system. | (none) | katalyst_plans_list | A |
| `katalyst.plans.hidden` | The plans that exist but are not offered. | (none) | katalyst_plans_list | A |
| `katalyst.plans.archived` | The plans of the old billing system that are gone. | (none) | katalyst_plans_list | A |
| `katalyst.orders.by-state` | How many shop orders are paid, pending, cancelled and so on. | (none) | katalyst_orders_list | A |
| `katalyst.orders.pending` | The orders waiting for payment, oldest first (number, total, age). | (none) | katalyst_orders_list | A |
| `katalyst.orders.revenue` | The number of paid orders and their total without VAT since a date. | `--days` | katalyst_orders_list | A |
| `katalyst.orders.fraud` | How many orders passed, failed or skipped the fraud check. | (none) | katalyst_orders_list | A |
| `katalyst.orders.recent` | The newest orders with number, state, total and item count (no customer data). | (none) | katalyst_orders_list | A |

## Units it needs

`katalyst_orders_list`, `katalyst_plans_list`, `katalyst_services_list`

A skill whose unit is not offered here is listed as parked with the reason; it runs nothing. Every skill runs as the
calling user, each unit checked against that user's policies and written to the audit log.

## Running one

```
xsh tron:skill:run katalyst.services.by-status
```

Over MCP a live skill is the tool `skill_katalyst_services_by_status` and a prompt of the same name.

See tron's doc/skills.md and doc/extension-conventions.md.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
