# Skill pack: SEO

URL aliases per content type, content buried deep in the tree, site description, meta data and the canonical site URL.

- Pack: `seo` 0.0.0.1 in `share/skills/seo/` (tron's own packs)
- Prefix: `seo.`
- Skills: 15
- Audience: Whoever looks after search engine visibility.

## Skills

| Skill | What it answers | Parameters | Units | Wave |
|---|---|---|---|---|
| `seo.ng-article.urls` | The URL aliases of the articles below a node, to check them for readable, stable addresses. | `--node-id` `--limit` | node_find | A |
| `seo.ng-article.deep` | The articles that sit deepest in the tree (more clicks from the front page), deepest first. | `--node-id` | node_find | A |
| `seo.ng-news.urls` | The URL aliases of the news below a node, to check them for readable, stable addresses. | `--node-id` `--limit` | node_find | A |
| `seo.ng-news.deep` | The news that sit deepest in the tree (more clicks from the front page), deepest first. | `--node-id` | node_find | A |
| `seo.ng-blog-post.urls` | The URL aliases of the blog posts below a node, to check them for readable, stable addresses. | `--node-id` `--limit` | node_find | A |
| `seo.ng-blog-post.deep` | The blog posts that sit deepest in the tree (more clicks from the front page), deepest first. | `--node-id` | node_find | A |
| `seo.ng-landing-page.urls` | The URL aliases of the landing pages below a node, to check them for readable, stable addresses. | `--node-id` `--limit` | node_find | A |
| `seo.ng-landing-page.deep` | The landing pages that sit deepest in the tree (more clicks from the front page), deepest first. | `--node-id` | node_find | A |
| `seo.ng-recipe.urls` | The URL aliases of the recipes below a node, to check them for readable, stable addresses. | `--node-id` `--limit` | node_find | A |
| `seo.ng-recipe.deep` | The recipes that sit deepest in the tree (more clicks from the front page), deepest first. | `--node-id` | node_find | A |
| `seo.folder.urls` | The URL aliases of the folders below a node, to check them for readable, stable addresses. | `--node-id` `--limit` | node_find | A |
| `seo.folder.deep` | The folders that sit deepest in the tree (more clicks from the front page), deepest first. | `--node-id` | node_find | A |
| `seo.site-description` | The description a siteaccess gives search engines, and where it is set. | `--siteaccess` | ini_resolve | A |
| `seo.meta-data` | The meta tags (author, keywords, description) a siteaccess writes into every page. | `--siteaccess` | ini_resolve | A |
| `seo.site-url-https` | Checks that a siteaccess has a canonical site URL for links in feeds, sitemaps and mails. | `--siteaccess` | ini_resolve | A |

## Examples

### URLs of articles

The URL aliases of the articles below a node, to check them for readable, stable addresses.

```
xsh tron:skill:run seo.ng-article.urls
```

### Deep articles

The articles that sit deepest in the tree (more clicks from the front page), deepest first.

```
xsh tron:skill:run seo.ng-article.deep
```

### URLs of news

The URL aliases of the news below a node, to check them for readable, stable addresses.

```
xsh tron:skill:run seo.ng-news.urls
```

### Deep news

The news that sit deepest in the tree (more clicks from the front page), deepest first.

```
xsh tron:skill:run seo.ng-news.deep
```

### URLs of blog posts

The URL aliases of the blog posts below a node, to check them for readable, stable addresses.

```
xsh tron:skill:run seo.ng-blog-post.urls
```

Back to [skills.md](../skills.md) and the [conventions](../extension-conventions.md).

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
