# INI and settings

Where a setting is set and what wins: key settings of site.ini, image.ini, content.ini and more with every file that sets them, siteaccess comparisons per INI file, block inventories and who overrides what.

Pack `ini-and-settings` 0.0.0.1, GPL-2.0-or-later, by 7x. Skill names start with `ini.`.

## Who it is for

Developers and site administrators who change settings, and anyone asking why two siteaccesses behave differently.

## Skills

| Skill | What it answers | Parameters | Units | Wave |
|---|---|---|---|---|
| `ini.setting` | The value of one setting (file, block, setting) as a siteaccess resolves it, and every file that sets it. | `--file` `--block` `--setting` `--siteaccess` | ini_resolve | A |
| `ini.diff` | Compares an INI file as two siteaccesses resolve it: which settings differ, and which exist in only one of them. | `--file` `--a` `--b` `--limit` | ini_diff | A |
| `ini.blocks` | Lists the blocks of an INI file as a siteaccess resolves it, with the number of settings in each. | `--file` `--siteaccess` | ini_list | A |
| `ini.block.values` | One block of an INI file as a siteaccess resolves it: every setting with its value and the file that sets it. | `--file` `--siteaccess` `--block` | ini_list | A |
| `ini.block.overrides` | For one block of an INI file, how many of its settings each file wins, so you see which file really decides the block. | `--file` `--siteaccess` `--block` | ini_list | A |
| `ini.file.overrides` | For a whole INI file, how many settings each settings file wins (default, extension, override, siteaccess), so changes go where they count. | `--file` `--siteaccess` | ini_list | A |

## Units it needs

`ini_diff`, `ini_list`, `ini_resolve`

A skill whose unit is not offered here is listed as parked with the reason; it runs nothing. Every skill runs as the
calling user, each unit checked against that user's policies and written to the audit log.

## Running one

```
xsh tron:skill:run ini.setting --file site.ini --block SiteSettings --setting SiteName --siteaccess site
```

Over MCP a live skill is the tool `skill_ini_setting` and a prompt of the same name.

See tron's doc/skills.md and doc/extension-conventions.md.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
