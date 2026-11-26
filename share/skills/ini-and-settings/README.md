# INI and settings

Where a setting is set and what wins: key settings of site.ini, image.ini, content.ini and more with every file that sets them, siteaccess comparisons per INI file, block inventories and who overrides what.

Pack `ini-and-settings` 0.0.0.1, GPL-2.0-or-later, by 7x. Skill names start with `ini.`.

## Who it is for

Developers and site administrators who change settings, and anyone asking why two siteaccesses behave differently.

## Skills

| Skill | What it answers | Parameters | Units | Wave |
|---|---|---|---|---|
| `ini.setting.site-name` | The name a siteaccess shows as its site name, and where it is set. Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.site-url` | The site URL a siteaccess uses in links and mails, and where it is set. Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.default-access` | Which siteaccess a request without a match lands on. Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.index-page` | The page the front page of a siteaccess opens. Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.siteaccess-list` | The siteaccesses this installation knows. Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.match-order` | How a request is matched to a siteaccess (uri, host, port ...). Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.related-siteaccesses` | The siteaccesses that share content with this one. Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.active-extensions` | The extensions active for every siteaccess. Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.access-extensions` | The extensions active only for a siteaccess. Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.site-design` | The main design of a siteaccess. Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.additional-designs` | The fallback designs of a siteaccess, in order. Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.database-implementation` | The database driver this installation uses. Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.database-server` | The database host this installation connects to. Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.locale` | The locale a siteaccess formats dates and numbers with. Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.content-language` | The language new content of a siteaccess is created in. Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.site-languages` | The languages a siteaccess shows content in, in order of preference. Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.text-translation` | Whether interface texts are translated for a siteaccess. Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.mail-transport` | How the installation sends mail (sendmail, SMTP, file). Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.admin-email` | Where the installation sends its own notices (the address is masked). Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.var-dir` | Where a siteaccess keeps its storage and caches. Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.session-handler` | How sessions are stored. Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.session-timeout` | How long a session lives without activity. Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.cache-dir` | The cache directory below the var directory. Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.tree-menu` | The classes the admin content tree shows. Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.image-aliases` | The image aliases (sizes) the installation renders. Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.image-converters` | Whether ImageMagick converts images here. Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.versions-kept` | How many versions of an object are kept before the oldest go. Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.view-cache-dir` | Whether full views are cached for a siteaccess. Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.static-cache` | Whether static HTML pages are written for a siteaccess. Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.cluster-handler` | How files are stored across nodes (plain, DFS, database). Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.search-engine` | The search engine the installation uses. Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.user-class` | The content class new users are made of. Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.default-user-group` | Where new users are placed. Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.anonymous-user` | The user id anonymous visitors act as. Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.setting.login-handlers` | How users sign in (standard, LDAP, text file ...). Shows the winning value and every file that sets it. | `--siteaccess` | ini_resolve | A |
| `ini.diff.site` | Compares the resolved site.ini of two siteaccesses: which settings differ, and which exist in only one of them. | `--a` `--b` `--limit` | ini_diff | A |
| `ini.blocks.site` | Lists the blocks of site.ini as a siteaccess resolves it, with the number of settings in each. | `--siteaccess` | ini_list | A |
| `ini.diff.design` | Compares the resolved design.ini of two siteaccesses: which settings differ, and which exist in only one of them. | `--a` `--b` `--limit` | ini_diff | A |
| `ini.blocks.design` | Lists the blocks of design.ini as a siteaccess resolves it, with the number of settings in each. | `--siteaccess` | ini_list | A |
| `ini.diff.content` | Compares the resolved content.ini of two siteaccesses: which settings differ, and which exist in only one of them. | `--a` `--b` `--limit` | ini_diff | A |
| `ini.blocks.content` | Lists the blocks of content.ini as a siteaccess resolves it, with the number of settings in each. | `--siteaccess` | ini_list | A |
| `ini.diff.image` | Compares the resolved image.ini of two siteaccesses: which settings differ, and which exist in only one of them. | `--a` `--b` `--limit` | ini_diff | A |
| `ini.blocks.image` | Lists the blocks of image.ini as a siteaccess resolves it, with the number of settings in each. | `--siteaccess` | ini_list | A |
| `ini.diff.override` | Compares the resolved override.ini of two siteaccesses: which settings differ, and which exist in only one of them. | `--a` `--b` `--limit` | ini_diff | A |
| `ini.blocks.override` | Lists the blocks of override.ini as a siteaccess resolves it, with the number of settings in each. | `--siteaccess` | ini_list | A |
| `ini.diff.menu` | Compares the resolved menu.ini of two siteaccesses: which settings differ, and which exist in only one of them. | `--a` `--b` `--limit` | ini_diff | A |
| `ini.blocks.menu` | Lists the blocks of menu.ini as a siteaccess resolves it, with the number of settings in each. | `--siteaccess` | ini_list | A |
| `ini.diff.toolbar` | Compares the resolved toolbar.ini of two siteaccesses: which settings differ, and which exist in only one of them. | `--a` `--b` `--limit` | ini_diff | A |
| `ini.blocks.toolbar` | Lists the blocks of toolbar.ini as a siteaccess resolves it, with the number of settings in each. | `--siteaccess` | ini_list | A |
| `ini.diff.explayouts` | Compares the resolved explayouts.ini of two siteaccesses: which settings differ, and which exist in only one of them. | `--a` `--b` `--limit` | ini_diff | A |
| `ini.blocks.explayouts` | Lists the blocks of explayouts.ini as a siteaccess resolves it, with the number of settings in each. | `--siteaccess` | ini_list | A |
| `ini.block.values` | One block of an INI file as a siteaccess resolves it: every setting with its value and the file that sets it. | `--file` `--siteaccess` `--block` | ini_list | A |
| `ini.block.overrides` | For one block of an INI file, how many of its settings each file wins, so you see which file really decides the block. | `--file` `--siteaccess` `--block` | ini_list | A |
| `ini.file.overrides` | For a whole INI file, how many settings each settings file wins (default, extension, override, siteaccess), so changes go where they count. | `--file` `--siteaccess` | ini_list | A |

## Units it needs

`ini_diff`, `ini_list`, `ini_resolve`

A skill whose unit is not offered here is listed as parked with the reason; it runs nothing. Every skill runs as the
calling user, each unit checked against that user's policies and written to the audit log.

## Running one

```
xsh tron:skill:run ini.setting.site-name --siteaccess site
```

Over MCP a live skill is the tool `skill_ini_setting_site_name` and a prompt of the same name.

See tron's doc/skills.md and doc/extension-conventions.md.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
