# Skill pack: Katalyst reports

Hosting and shop reports from Katalyst by ids and counts only: services per status, plan, server and cycle, due and overdue services, disk use, plans and prices, orders per state, pending orders, revenue and fraud checks.

- Pack: `katalyst-reports` 0.0.0.1 in `share/skills/katalyst-reports/` (Exponential Tron's own packs)
- Prefix: `katalyst.`
- Skills: 19
- Works on: `sevenx_katalyst`
- Audience: Whoever runs the hosting business: billing, capacity and catalog reviews.

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

## Examples

### Services per status

How many hosting services are active, suspended, pending or cancelled.

```
xsh tron:skill:run katalyst.services.by-status
```

### Services per plan

How many hosting services each plan has.

```
xsh tron:skill:run katalyst.services.by-plan
```

### Services per server

How many hosting services each server carries.

```
xsh tron:skill:run katalyst.services.by-server
```

### Services per billing cycle

How many hosting services bill monthly, yearly and so on.

```
xsh tron:skill:run katalyst.services.by-cycle
```

### Services per kind

How many services of each kind (hosting, domain ...) there are.

```
xsh tron:skill:run katalyst.services.by-kind
```

Back to [skills.md](../skills.md) and the [conventions](../extension-conventions.md).

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
