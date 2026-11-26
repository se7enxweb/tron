# Languages

Languages per siteaccess, a node in one translation with its empty fields, regional settings of two siteaccesses compared, translation extensions.

Pack `i18n` 0.0.0.1, GPL-2.0-or-later, by 7x. Skill names start with `i18n.`.

## Who it is for

Teams running translated sites.

## Skills

| Skill | What it answers | Parameters | Units | Wave |
|---|---|---|---|---|
| `i18n.siteaccess.site` | The locale, content language and site languages of the siteaccess site, with the files that set them. | (none) | ini_resolve | A |
| `i18n.siteaccess.bold` | The locale, content language and site languages of the siteaccess bold, with the files that set them. | (none) | ini_resolve | A |
| `i18n.siteaccess.bold-ger` | The locale, content language and site languages of the siteaccess bold_ger, with the files that set them. | (none) | ini_resolve | A |
| `i18n.siteaccess.starter` | The locale, content language and site languages of the siteaccess starter, with the files that set them. | (none) | ini_resolve | A |
| `i18n.siteaccess.starter-ger` | The locale, content language and site languages of the siteaccess starter_ger, with the files that set them. | (none) | ini_resolve | A |
| `i18n.siteaccess.admin` | The locale, content language and site languages of the siteaccess admin, with the files that set them. | (none) | ini_resolve | A |
| `i18n.node.translation` | One node read in a given language, with the attributes that are empty in that translation. | `--node-id` `--language` | node_read | A |
| `i18n.compare-siteaccesses` | Compares the regional settings (locale, languages, translation) of two siteaccesses. | `--a` `--b` | ini_diff | A |
| `i18n.translation-extensions` | The extensions that bring interface translations. | `--siteaccess` | ini_resolve | A |

## Units it needs

`ini_diff`, `ini_resolve`, `node_read`

A skill whose unit is not offered here is listed as parked with the reason; it runs nothing. Every skill runs as the
calling user, each unit checked against that user's policies and written to the audit log.

## Running one

```
xsh tron:skill:run i18n.siteaccess.site
```

Over MCP a live skill is the tool `skill_i18n_siteaccess_site` and a prompt of the same name.

See tron's doc/skills.md and doc/extension-conventions.md.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
