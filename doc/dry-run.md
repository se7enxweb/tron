# Dry runs: see the exact change, then apply exactly that

Every write tool of tron can run as a dry run. Nothing changes; the answer is the exact change the call would make,
and a plan id. A second call with that plan id applies the change, but only if it is still exactly the planned one.

```
tron_plan   { "tool": "centre_ticket_update", "arguments": { "id": 12, "title": "Ship 0.0.0.4" } }
  -> { "plan_id": "3f2a...", "mode": "transaction", "expires": "2026-11-25T06:20:00Z",
       "changes": [ { "table": "tron_centre_ticket", "op": "update", "id": 12,
                      "columns": { "title": { "from": "Ship it", "to": "Ship 0.0.0.4" }, "modified": { ... } } },
                    { "table": "tron_centre_history", "op": "insert", "id": 31, "row": { ... } } ],
       "summary": { "insert": 1, "update": 1, "delete": 0 }, "effects": [], "preview": { the ticket as it would be } }

tron_apply  { "plan_id": "3f2a...", "tool": "centre_ticket_update", "arguments": { "id": 12, "title": "Ship 0.0.0.4" } }
  -> { "plan_id": "3f2a...", "tool": "centre_ticket_update", "applied": true, "result": { the ticket } }
```

Clients that can set `_meta` on `tools/call` can do the same without the two helper tools: `"_meta": {"tron/dryRun":
true}` makes the call a dry run, `"_meta": {"tron/planId": "<id>"}` applies a plan.

## Two kinds of dry run

| Mode | Used by | How the change is found | How apply checks it |
|---|---|---|---|
| `transaction` | the centre tools on tron's tables (`centre_ticket_*`, `centre_decision_record`) | the tool runs for real inside a database transaction; its tables are read before and after; the difference is the change; the transaction is rolled back | the tool runs again inside a transaction; the change, normalised, must equal the planned one byte for byte, else the transaction is rolled back and the apply refused |
| `computed` | `content_node_update`, the centre tools in front of centre's API | the tool computes the change from the current state without writing: attribute by attribute from/to, the version the publish will make, and the effects outside the tables | the plan is computed again; its state (object, versions, modified, every editable value; or the card as centre returns it) and its changes must be unchanged, then the tool runs |

Normalising (transaction mode) leaves out values that legitimately differ between the dry run and the apply:
timestamps (`created`, `modified`, `closed`) and the ids a new row gets (with the columns that refer to them). Every
other byte counts: a ticket that someone else moved to the same lane in between changes the planned position, and the
apply is refused.

A computed plan lists `effects`: what the change does outside what it shows, e.g. for a content publish "the object
name is computed again from the class name pattern", "the view caches of the node are cleared and the search index is
updated".

## What a plan is bound to

A plan id is 32 hex characters. tron keeps, in the table `tron_plan`, only hashes: of the arguments (after
defaults), of the state and of the change, plus who made it, with which token, for which tool, when it expires and
whether it was used. Never the arguments or the change themselves.

An apply is refused, and changes nothing, when:

| Refusal (`reason` in the error details) | When |
|---|---|
| `unknown` | no such plan, or the plan of another user (both answer the same) |
| `token` | the plan was made with another token |
| `tool` | the plan is for another tool |
| `arguments` | the arguments are not exactly the planned ones |
| `expired` | older than `[PlanSettings] LifetimeSeconds` (default 300) |
| `used` | applied already: a plan is used once |
| `changed` | the target changed since the dry run, so the change would not be the planned one |

Every dry run is audited with the outcome `dry-run` (and no `started` row: nothing runs for real), every apply as a
write (`started`, then `ok`) with the note `applied plan <first 8 characters>`, every refusal with the outcome
`refused`.

A dry run passes the same gates as a call: the token scope, the tool's policy, the argument check. A dry run of a call
that would fail fails the same way and leaves no plan. A read tool has no dry run (`tron_plan` says so).

## For program authors

`tronTool::define()` takes one of:

```php
// tron's own tables, changed inside one transaction
'dryRun' => array(
    'tables'   => array( 'myprog_item', 'myprog_history' ),
    'volatile' => array( 'created', 'modified' ),                       // values left out of the comparison
    'refs'     => array( 'myprog_history.item_id' => 'myprog_item' ),   // columns holding a new row's id
),

// anything else: compute the change without making it
'plan' => function ( array $args, tronCallContext $ctx ) {
    return array(
        'changes' => array( array( 'kind' => 'attribute', 'target' => 'object 41', 'field' => 'title', 'from' => 'a', 'to' => 'b' ) ),
        'state'   => array( /* everything the change depends on; the apply compares its hash */ ),
        'effects' => array( 'what happens besides, in words' ),
    );
},
```

The transaction mode needs the tool's writes to go through the `db` service (tronDb nests transactions as savepoints,
so the tool may open and commit its own). It reads whole tables, and refuses tables larger than 50 000 rows. A write
whose effects leave the database (mail, files, another system) must use a computed plan. The contract test fails any
write tool without a dry run (doc/contract-testing.md).

## Proof

- `tests/unit/DryRunTest.php`: the dry run leaves a checksum of every row unchanged; apply makes exactly the planned
  change (normalised diff equal); a create applied after another create still matches; a changed target is refused
  and changes nothing; every binding (user, token, tool, arguments, expiry, once); the `_meta` route; the content edit
  twice; the centre API plan; savepoints nest.
- `tests/kernel/dry_run_live.php`: on a real installation, a content node edited twice: each dry run with row
  checksums of `ezcontentobject`, `ezcontentobject_version`, `ezcontentobject_attribute` and `ezcontentobject_name`
  unchanged, each apply giving the planned attribute values and version (doc/testing.md has the results).

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
