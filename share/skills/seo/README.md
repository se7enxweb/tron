# SEO

URL aliases per content type, content buried deep in the tree, site description, meta data and the canonical site URL.

Pack `seo` 0.0.0.1, GPL-2.0-or-later, by 7x. Skill names start with `seo.`.

## Who it is for

Whoever looks after search engine visibility.

## Skills

| Skill | What it answers | Parameters | Units | Wave |
|---|---|---|---|---|
| `seo.urls` | The URL aliases of the nodes of one class below a node, to check them for readable, stable addresses. | `--class` `--node-id` `--limit` | node_find | A |
| `seo.deep` | The nodes of one class that sit deepest in the tree (more clicks from the front page), deepest first. | `--class` `--node-id` | node_find | A |
| `seo.site-setting` | The site description or the meta tags (author, keywords, description) a siteaccess writes into every page, and the files that set them. | `--setting` `--siteaccess` | ini_resolve | A |
| `seo.site-url-https` | Checks that a siteaccess has a canonical site URL for links in feeds, sitemaps and mails. | `--siteaccess` | ini_resolve | A |

## Units it needs

`ini_resolve`, `node_find`

A skill whose unit is not offered here is listed as parked with the reason; it runs nothing. Every skill runs as the
calling user, each unit checked against that user's policies and written to the audit log.

## Running one

```
xsh tron:skill:run seo.urls --class ng_article
```

Over MCP a live skill is the tool `skill_seo_urls` and a prompt of the same name.

See tron's doc/skills.md and doc/extension-conventions.md.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
