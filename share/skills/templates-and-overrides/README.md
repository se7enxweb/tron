# Templates and overrides

Which template renders a node in each view mode and why, which file renders the page layout, edit, login and error pages, template lint and source, override rules.

Pack `templates-and-overrides` 0.0.0.1, GPL-2.0-or-later, by 7x. Skill names start with `tpl.`.

## Who it is for

Template developers and anyone debugging how a page renders.

## Skills

| Skill | What it answers | Parameters | Units | Wave |
|---|---|---|---|---|
| `tpl.node.view` | Which template renders a node in a view (full, line, embed, listitem ...) on a siteaccess, through which override rule, and which overrides were tried. | `--view` `--node-id` `--siteaccess` | tpl_resolve | A |
| `tpl.which` | Which file renders a design template (pagelayout.tpl, node/view/full.tpl, user/login.tpl ...) on a siteaccess, by design order. | `--template` `--siteaccess` | tpl_resolve | A |
| `tpl.lint` | Checks one template file: errors, warnings, the functions and operators it uses and any it uses that are unknown here. | `--path` | tpl_lint | A |
| `tpl.node.lint` | Finds the template that renders a node in its full view and lints it in one go. | `--node-id` `--siteaccess` | tpl_resolve, tpl_lint | A |
| `tpl.source` | Lines of a template file (the first 60 by default), with the total number of lines. | `--path` `--from-line` `--to-line` | tpl_source | A |
| `tpl.node.source` | Finds the template that renders a node in its full view and shows its first lines. | `--node-id` `--siteaccess` | tpl_resolve, tpl_source | A |
| `tpl.override-rules` | The override blocks of override.ini as a siteaccess resolves them. | `--siteaccess` | ini_list | A |

## Units it needs

`ini_list`, `tpl_lint`, `tpl_resolve`, `tpl_source`

A skill whose unit is not offered here is listed as parked with the reason; it runs nothing. Every skill runs as the
calling user, each unit checked against that user's policies and written to the audit log.

## Running one

```
xsh tron:skill:run tpl.node.view --view full --node-id 2
```

Over MCP a live skill is the tool `skill_tpl_node_view` and a prompt of the same name.

See Exponential Tron's doc/skills.md and doc/extension-conventions.md.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
