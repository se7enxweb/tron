# Tools, resources and prompts

Every list below is what an administrator sees. A user sees only what the token's scope and the user's policies
allow. Every tool's exact input schema is in its `tools/list` entry; arguments outside the schema are refused.

## Program tron

| Tool | Policy | Arguments | Answer |
|---|---|---|---|
| `tron_whoami` | tron/use | none | user_id, login, token_id, scopes, transport, protocol_version |
| `tron_status` | tron/use | none | tron version, protocol versions, programs (name, version, state, reason, counts; with tron/admin also origin and ms), tool count, `not_registered` (tool => reason) |

Resources: `tron://status` (as tron_status), `tron://programs` (the programs part).

## Program centre, tron's own tables

Used where the extension expcentre is not in the installation (`[CentreSettings] Backend=auto` or `tron`).

| Tool | Policy | Write | Arguments |
|---|---|---|---|
| `centre_ticket_create` | centre_write | yes | **title**, body, kind (ticket, card, decision), board (main), lane (first lane), priority (low, normal, high, urgent), labels[], assignee (login), due (YYYY-MM-DD), decision_week (2026-W48) |
| `centre_ticket_update` | centre_write | yes, idempotent | **id**, title, body, kind, priority, labels[], assignee ('' clears), due ('' clears), decision_week ('' clears), status (only "open": reopen) |
| `centre_ticket_comment` | centre_write | yes | **id**, **body** |
| `centre_ticket_move` | centre_write | yes, idempotent | **id**, **lane**, board, position (0 = top; default: end of lane) |
| `centre_ticket_close` | centre_write | yes, idempotent | **id**, reason (done) |
| `centre_ticket_list` | centre_read | | status (open, closed, any), kind, board, lane, assignee, label, query (title words), decision_week, limit (50, at most 200), offset |
| `centre_ticket_get` | centre_read | | **id**: the ticket with body, decision, comments, links, history |
| `centre_board_view` | centre_read | | board (main), per_lane (25): lanes in order with count and items by position |
| `centre_decision_review` | centre_read | | week (this week): overdue, due, undated, later, decided this week |
| `centre_decision_record` | centre_decide | yes, idempotent | **id**, **decision**, close (true) |
| `centre_ticket_link` | centre_write | yes, idempotent | **id**, **kind**, **ref**, note. Kinds: `gnubus` (gb-20261124T101500Z-cloud-1a2b), `todo` (claude@cloud#12), `url`, `ticket` (an id), `commit` (se7enxweb/tron@1a2b3c4) |
| `centre_links_find` | centre_read | | **kind**, **ref**: the tickets linked to that message, to-do, URL, ticket or commit |

Rules: a decision gets this ISO week when none is given; closing a closed ticket changes nothing; every change is a
history row (field, from, to, who, when); labels are lower case, at most 8; lanes come from `[CentreSettings] Lanes[]`
(inbox, next, doing, waiting, done), boards from `Boards[]` (empty: any name). Lists carry no bodies.

Resources: `centre://ticket/{id}` (as centre_ticket_get), `centre://board/{board}` (as centre_board_view).
Prompts: `centre_weekly_review` (week), `centre_triage` (board).

## Program centre, with expcentre (centre.se7enx.com)

Where expCentreApi exists (`Backend=auto` or `expcentre`), the centre program offers the tools of centre's API
contract instead, passes each call to `expCentreApi::call( $tool, $args, $user )` as the session's user, and turns
centre's `{"ok":false,"error":{"code","message"}}` into a tool error `<code>: <message>`. tron checks `centre/api`
first; centre checks its own policies per card.

| Tool | Write | Arguments |
|---|---|---|
| `centre_whoami` | | none |
| `centre_list_projects` | | state (active, archived) |
| `centre_get_project` | | **prefix** (EXP) |
| `centre_get_board` | | **prefix** |
| `centre_search_cards` | | **query**, prefix |
| `centre_get_card` | | **number** (EXP-123, or an adopted help desk ticket's number such as 7X-2026-00012), include[] (comments, subtasks, links, time) |
| `centre_create_card` | yes | **prefix**, **title**, body, lane, priority (low, medium, high, urgent), source (ts, card), decision_needed, decision_question, assignee_id, due (YYYY-MM-DD), tags (comma separated), idempotency_key |
| `centre_update_card` | yes, idempotent | **number**, **fields** |
| `centre_move_card` | yes, idempotent | **number**, **lane**, position |
| `centre_comment` | yes | **number**, **body** |
| `centre_add_subtask` | yes | **number**, **title** |
| `centre_update_subtask` | yes | **number**, **id**, status (0, 1, 2), title |
| `centre_set_flags` | yes, idempotent | **number**, decision_needed, decision_question, public, public_title, public_summary |
| `centre_link` | yes | **number**, **kind** (card, ticket, commit, pr, gnubus, url), **target**, label |
| `centre_log_time` | yes | **number**, **minutes** (1-1440), billable, note |
| `centre_decisions` | | prefix, since (YYYY-MM-DD) |
| `centre_record_decision` | yes | **number**, **decision** |
| `centre_list_assignable_users` | | **prefix** |
| `centre_set_tags` | yes, idempotent | **number**, **tags** |
| `centre_close_card` | yes, idempotent | **number**, resolution (why: a key of centre's resolutions, e.g. not_needed, duplicate, wont_fix, released; empty = "Closed"), ref (what the resolution names: a card, a link or a version), comment (mailed to the customer of a help desk ticket card) (moves it to Done; a help desk ticket card is resolved and its reply names the resolution) |
| `centre_open_card` | yes, idempotent | **number** (back to Ready; a help desk ticket card is reopened) |
| `centre_activity` | | prefix, number, limit (50, at most 200) |
| `centre_overview` | | none: what needs you now, and your projects with lane counts |
| `centre_workload` | | none: who holds which open cards in the projects you lead |
| `centre_notifications` | | limit |
| `centre_desk` | | none (staff only): open help desk tickets with SLA timers, decisions waiting, unbilled time |
| `centre_customer` | | customer_id (staff, or the customer's own users) |
| `centre_sync_status` | | none (staff only) |
| `centre_status` | | none: the public status page |

29 tools since centre 0.0.0.2 (17 before).

## Program katalyst

Registered only where sevenx_katalyst is active (`[KatalystSettings] Source=kernel`). Read only.

| Tool | Policy | Arguments | Columns returned |
|---|---|---|---|
| `katalyst_plans_list` | katalyst_read | source, hidden, gone (false), limit, offset | id, source, server_id, external_id, name, group_name, type, pay_type, price_monthly, currency, hidden, gone, created, modified |
| `katalyst_services_list` | katalyst_read | status, server_id, plan_id, customer_id, domain (needs katalyst_detail), gone (false), limit, offset | id, customer_id, server_id, domain (masked without katalyst_detail), status, hosting_type, plan_id, plesk_plan_id, service_kind, billing_cycle, next_due_date, disk_total_bytes, traffic_bytes, gone, created, modified |
| `katalyst_orders_list` | katalyst_read | state, customer_id, since (YYYY-MM-DD), limit, offset | id, source, shop_order_nr, customer_id, state, currency, total_ex_vat, total_inc_vat, item_count, fraud_state, created, modified (newest first) |

## Program search

The installation's search, as the calling user (units `search.query` and `search.index`, see [units.md](units.md#search)).
Registered wherever tron runs inside an installation. Read only.

| Tool | Policy | Arguments | Answer |
|---|---|---|---|
| `search_query` | content/read (limited is fine) | **text**, node_id (below this node), subtree (node ids, at most 10), class (identifier or comma list), section (id), language (eng-GB), sort (relevance, published, modified, name, path, class_identifier, section), order (desc), facets (class, section, language), include_hidden (false), limit (10, max 50), offset | total, offset, items (node_id, object_id, name, class_identifier, url_alias, section_id, language, published, modified, excerpt), engine, sort (what the engine sorted by), facets_supported, facets (field => value, name, count), stop_words |
| `search_index` | content/read (limited is fine) | node_id or object_id (both optional) | engine (name, class, facets, delayed_indexing), pending (objects in the indexing queue), object (object_id, node_id, name, indexed: true, false or null, queued) |

Only content the user may read is found, and `total` counts only that: the user's content/read policies are the
search's limitation, as on the site's own search page. A node the user may not read is "not found" in `search_index`.

## Errors

| What | How it comes back |
|---|---|
| Unknown or out-of-scope tool | JSON-RPC error -32602 "Unknown tool: ..." |
| Policy missing | tool result, isError, "Permission denied: this tool needs the policy ..." |
| Arguments outside the schema | tool result, isError, "Invalid arguments: ..." plus the inputSchema |
| The tool's own refusal | tool result, isError, the tool's message |
| Anything else | tool result, isError, "internal error"; details in the server log |
| Unknown resource | JSON-RPC error -32002 with data.uri |
| Unknown method | -32600 (the legacy layer: -32601); malformed JSON -32700; malformed request -32600 |

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
