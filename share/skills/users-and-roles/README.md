# Users and roles

Access reviews: policies of a role per module, unlimited policies, who has a role, finding users with their roles, disabled users and administrators.

Pack `users-and-roles` 0.0.0.1, GPL-2.0-or-later, by 7x. Skill names start with `role.`.

## Who it is for

Administrators who review access, and support answering "why can this person (not) do that?".

## Skills

| Skill | What it answers | Parameters | Units | Wave |
|---|---|---|---|---|
| `role.policies` | The policies of a role (module, function, limitations), and how many per module. | `--role` | role_describe | A |
| `role.assignments` | The users and groups a role is assigned to, with any subtree or section limitation of the assignment. | `--role` | role_describe | A |
| `role.unlimited` | The policies of a role that have no limitation at all (they hold for every section, subtree and class). | `--role` | role_describe | A |
| `role.module.content` | The policies of a role for the module content, with their limitations. | `--role` | role_describe | A |
| `role.module.user` | The policies of a role for the module user, with their limitations. | `--role` | role_describe | A |
| `role.module.setup` | The policies of a role for the module setup, with their limitations. | `--role` | role_describe | A |
| `role.module.tron` | The policies of a role for the module tron, with their limitations. | `--role` | role_describe | A |
| `role.module.explayouts` | The policies of a role for the module explayouts, with their limitations. | `--role` | role_describe | A |
| `role.module.class` | The policies of a role for the module class, with their limitations. | `--role` | role_describe | A |
| `role.module.role` | The policies of a role for the module role, with their limitations. | `--role` | role_describe | A |
| `role.module.section` | The policies of a role for the module section, with their limitations. | `--role` | role_describe | A |
| `role.module.state` | The policies of a role for the module state, with their limitations. | `--role` | role_describe | A |
| `role.user.find` | Finds users by login or name, with whether they are enabled and their roles. | `--text` | role_user_find | A |
| `role.user.disabled` | Among the users matching a text, the ones that are disabled. | `--text` | role_user_find | A |
| `role.user.administrators` | Among the users matching a text, the ones that hold the Administrator role. | `--text` | role_user_find | A |

## Units it needs

`role_describe`, `role_user_find`

A skill whose unit is not offered here is listed as parked with the reason; it runs nothing. Every skill runs as the
calling user, each unit checked against that user's policies and written to the audit log.

## Running one

```
xsh tron:skill:run role.policies --role Anonymous
```

Over MCP a live skill is the tool `skill_role_policies` and a prompt of the same name.

See tron's doc/skills.md and doc/extension-conventions.md.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
