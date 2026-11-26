# Skill pack: Users and roles

Access reviews: policies of a role per module, unlimited policies, who has a role, finding users with their roles, disabled users and administrators.

- Pack: `users-and-roles` 0.0.0.1 in `share/skills/users-and-roles/` (tron's own packs)
- Prefix: `role.`
- Skills: 15
- Audience: Administrators who review access, and support answering "why can this person (not) do that?".

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

## Examples

### Policies of a role

The policies of a role (module, function, limitations), and how many per module.

```
xsh tron:skill:run role.policies --role Anonymous
```

### Who has a role

The users and groups a role is assigned to, with any subtree or section limitation of the assignment.

```
xsh tron:skill:run role.assignments --role Anonymous
```

### Unlimited policies

The policies of a role that have no limitation at all (they hold for every section, subtree and class).

```
xsh tron:skill:run role.unlimited --role Anonymous
```

### Content policies of a role

The policies of a role for the module content, with their limitations.

```
xsh tron:skill:run role.module.content --role Anonymous
```

### User policies of a role

The policies of a role for the module user, with their limitations.

```
xsh tron:skill:run role.module.user --role Anonymous
```

Back to [skills.md](../skills.md) and the [conventions](../extension-conventions.md).

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
