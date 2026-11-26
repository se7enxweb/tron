# Policy simulator: could this user do that?

`tron_policy_check` answers whether a user could call a tool, read a resource or get a prompt, from the installation's
real roles and policies, without calling anything. It walks the same gates a call passes, in the same order, and
says which one decided and why.

```
tron_policy_check { "user": "jane", "tool": "content_node_update", "target": "43" }
-> { "user": { "id": 3, "login": "jane" }, "scopes": ["*"], "allowed": 1, "denied": 0,
     "checks": [ { "kind": "tool", "name": "content_node_update", "program": "content", "allowed": true,
                   "decided_by": "target", "policy": "content/edit", "access": "limited", "limited_ok": true,
                   "matches": [ { "role": "Editor", "policy": "content/edit", "policy_id": 412,
                                  "limitations": { "Subtree": ["/1/2/43/"] } } ],
                   "target": "43", "reason": "the object check of node 43 allows edit for this user" } ] }
```

## Arguments

| Argument | |
|---|---|
| `tool` | a tool name, a resource URI (`centre://ticket/1`), a prompt name, a program name (every tool, resource and prompt of it), or `*` (everything) |
| `user` | the login to check; without it you check yourself. Checking someone else needs `tron/admin` |
| `target` | the object the tool would act on, e.g. a node id; the tool's own object check runs (where the tool has one) |
| `scopes` | simulate a token limited to these programs. Checking yourself, the simulation is never wider than your own token |

## The gates, in order

| `decided_by` | Gate | Answer comes from |
|---|---|---|
| `use` | the user does not hold `tron/use`, which every session needs (HTTP answers 403, stdio does not start) | the access checker |
| `unknown` / `unavailable` | the name is no tool, resource, prompt or program here, or the tool is left out (with the reason) | the registry |
| `scope` | the token scope does not cover the program | the session's or the simulated scopes |
| `policy` | the user does not hold the tool's module/function (or only with limitations where the tool needs it without) | the access checker's `check()`, the same call every real call makes |
| `target` | the object check of the tool refuses the target (content: the kernel's `checkAccess( 'edit', ..., $userId )`) | the tool's `targetCheck` |

`access` is the word of the policy gate: `yes`, `limited` or `no`. `matches` lists every role and policy of the user
that grants the module/function, with its limitations and the limitation of the role assignment (`User_Subtree`,
`User_Section`). In an installation they come from `eZRole::fetchByUser()` and the policies' limitation lists; the
answer word always comes from `eZUser::hasAccessTo()`.

A limited policy on a tool that accepts limited (`limited_ok`, e.g. `content_node_update`) is allowed at the policy
gate with the note "give a target to check the object itself".

## Who may ask

The tool needs `tron/use`. Asking about another user needs `tron/admin`: what a user may do is itself information.
Nothing is called, nothing is written but the audit entry of the check itself.

## For program authors

A tool that checks objects declares how, so the simulator can answer for a target:

```php
'targetCheck' => function ( string $target, tronPrincipal $p, tronEnvironment $env ) {
    return array( 'allowed' => ..., 'reason' => 'one sentence' );
},
```

## Proof

- `tests/unit/PolicySimulatorTest.php`: every user x every tool of the test world (280 cases) answered exactly as the
  real call is met; reasons, matches, limitations, target checks, programs, resources, prompts, scopes; only an
  administrator checks someone else; a session's scope cannot be widened.
- `tests/kernel/policy_simulator_live.php`: 20 user x tool cases on a real installation with users holding real
  roles, each simulated answer compared with the live call over HTTP (403 or a refusal, or 200), results in
  doc/testing.md.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
