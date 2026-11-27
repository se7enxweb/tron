# Skill pack: SEO

URL aliases per content type, content buried deep in the tree, site description, meta data and the canonical site URL.

- Pack: `seo` 0.0.0.1 in `share/skills/seo/` (Exponential Tron's own packs)
- Prefix: `seo.`
- Skills: 4
- Audience: Whoever looks after search engine visibility.

## Skills

| Skill | What it answers | Parameters | Units | Wave |
|---|---|---|---|---|
| `seo.urls` | The URL aliases of the nodes of one class below a node, to check them for readable, stable addresses. | `--class` `--node-id` `--limit` | node_find | A |
| `seo.deep` | The nodes of one class that sit deepest in the tree (more clicks from the front page), deepest first. | `--class` `--node-id` | node_find | A |
| `seo.site-setting` | The site description or the meta tags (author, keywords, description) a siteaccess writes into every page, and the files that set them. | `--setting` `--siteaccess` | ini_resolve | A |
| `seo.site-url-https` | Checks that a siteaccess has a canonical site URL for links in feeds, sitemaps and mails. | `--siteaccess` | ini_resolve | A |

## Examples

### URLs of articles

The URL aliases of the articles below a node, to check them for readable, stable addresses.

```
xsh tron:skill:run seo.urls --class ng_article
```

### Deep articles

The articles that sit deepest in the tree (more clicks from the front page), deepest first.

```
xsh tron:skill:run seo.deep --class ng_article
```

### URLs of news

The URL aliases of the news below a node, to check them for readable, stable addresses.

```
xsh tron:skill:run seo.urls --class ng_news
```

### Deep news

The news that sit deepest in the tree (more clicks from the front page), deepest first.

```
xsh tron:skill:run seo.deep --class ng_news
```

### URLs of blog posts

The URL aliases of the blog posts below a node, to check them for readable, stable addresses.

```
xsh tron:skill:run seo.urls --class ng_blog_post
```

Back to [skills.md](../skills.md) and the [conventions](../extension-conventions.md).

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
