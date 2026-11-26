# Skill pack: Content inventory

What the site holds: the latest items of every content type, recently changed and long unchanged content, a subtree map, one node with its fields, empty fields, versions and open drafts, counts per class.

- Pack: `content-inventory` 0.0.0.1 in `share/skills/content-inventory/` (tron's own packs)
- Prefix: `content.`
- Skills: 41
- Audience: Editors, content managers and anyone planning a migration or a clean-up.

## Skills

| Skill | What it answers | Parameters | Units | Wave |
|---|---|---|---|---|
| `content.latest.ng-article` | The articles most recently published below a node (the whole site by default), newest first, with how many there are in all. | `--node-id` `--limit` | node_find | A |
| `content.latest.article` | The classic articles most recently published below a node (the whole site by default), newest first, with how many there are in all. | `--node-id` `--limit` | node_find | A |
| `content.latest.ng-news` | The news items most recently published below a node (the whole site by default), newest first, with how many there are in all. | `--node-id` `--limit` | node_find | A |
| `content.latest.ng-blog-post` | The blog posts most recently published below a node (the whole site by default), newest first, with how many there are in all. | `--node-id` `--limit` | node_find | A |
| `content.latest.folder` | The folders most recently published below a node (the whole site by default), newest first, with how many there are in all. | `--node-id` `--limit` | node_find | A |
| `content.latest.image` | The images most recently published below a node (the whole site by default), newest first, with how many there are in all. | `--node-id` `--limit` | node_find | A |
| `content.latest.file` | The files most recently published below a node (the whole site by default), newest first, with how many there are in all. | `--node-id` `--limit` | node_find | A |
| `content.latest.ng-landing-page` | The landing pages most recently published below a node (the whole site by default), newest first, with how many there are in all. | `--node-id` `--limit` | node_find | A |
| `content.latest.ng-recipe` | The recipes most recently published below a node (the whole site by default), newest first, with how many there are in all. | `--node-id` `--limit` | node_find | A |
| `content.latest.ng-video` | The videos most recently published below a node (the whole site by default), newest first, with how many there are in all. | `--node-id` `--limit` | node_find | A |
| `content.latest.ng-gallery` | The galleries most recently published below a node (the whole site by default), newest first, with how many there are in all. | `--node-id` `--limit` | node_find | A |
| `content.latest.ng-banner` | The banners most recently published below a node (the whole site by default), newest first, with how many there are in all. | `--node-id` `--limit` | node_find | A |
| `content.latest.ng-person` | The people most recently published below a node (the whole site by default), newest first, with how many there are in all. | `--node-id` `--limit` | node_find | A |
| `content.latest.ng-job-position` | The job positions most recently published below a node (the whole site by default), newest first, with how many there are in all. | `--node-id` `--limit` | node_find | A |
| `content.latest.ng-category` | The categories most recently published below a node (the whole site by default), newest first, with how many there are in all. | `--node-id` `--limit` | node_find | A |
| `content.latest.ng-topic` | The topics most recently published below a node (the whole site by default), newest first, with how many there are in all. | `--node-id` `--limit` | node_find | A |
| `content.latest.ng-container` | The containers most recently published below a node (the whole site by default), newest first, with how many there are in all. | `--node-id` `--limit` | node_find | A |
| `content.latest.ng-shortcut` | The shortcuts most recently published below a node (the whole site by default), newest first, with how many there are in all. | `--node-id` `--limit` | node_find | A |
| `content.latest.ng-frontpage` | The front pages most recently published below a node (the whole site by default), newest first, with how many there are in all. | `--node-id` `--limit` | node_find | A |
| `content.latest.product` | The products most recently published below a node (the whole site by default), newest first, with how many there are in all. | `--node-id` `--limit` | node_find | A |
| `content.latest.help-manual` | The help manuals most recently published below a node (the whole site by default), newest first, with how many there are in all. | `--node-id` `--limit` | node_find | A |
| `content.latest.help-task` | The help tasks most recently published below a node (the whole site by default), newest first, with how many there are in all. | `--node-id` `--limit` | node_find | A |
| `content.latest.help-reference` | The help reference pages most recently published below a node (the whole site by default), newest first, with how many there are in all. | `--node-id` `--limit` | node_find | A |
| `content.latest.cjw-newsletter-article` | The newsletter articles most recently published below a node (the whole site by default), newest first, with how many there are in all. | `--node-id` `--limit` | node_find | A |
| `content.latest.ng-htmlbox` | The HTML boxes most recently published below a node (the whole site by default), newest first, with how many there are in all. | `--node-id` `--limit` | node_find | A |
| `content.latest.ng-logo` | The logos most recently published below a node (the whole site by default), newest first, with how many there are in all. | `--node-id` `--limit` | node_find | A |
| `content.recently-modified` | The nodes changed most recently below a node, of any class, newest first. | `--node-id` `--limit` | node_find | A |
| `content.oldest-unchanged` | The nodes below a node that were changed longest ago, oldest first: candidates for review or archiving. | `--node-id` `--limit` | node_find | A |
| `content.find-by-name` | Finds nodes whose name holds the given text, below a node. | `--node-id` `--text` `--limit` | node_find | A |
| `content.find-by-class` | Lists the nodes of one or more classes below a node, sorted as asked. | `--node-id` `--class` `--limit` `--sort` | node_find | A |
| `content.section` | Lists the nodes of a section (by section id) below a node. | `--node-id` `--section` `--limit` | node_find | A |
| `content.missing-url-alias` | Lists the nodes below a node that have no URL alias, so they only open as content/view/full/<id>. | `--node-id` `--class` | node_find | A |
| `content.tree.children` | The children of a node with their class, and how many of each class there are. | `--node-id` `--limit` | node_tree | A |
| `content.tree.depth-2` | The subtree of a node 2 levels deep, with the number of nodes per class and per depth. | `--node-id` `--limit` | node_tree | A |
| `content.tree.depth-3` | The subtree of a node 3 levels deep, with the number of nodes per class and per depth. | `--node-id` `--limit` | node_tree | A |
| `content.node.summary` | One node in short: name, class, section, language, URL, dates, version, children and whether it is hidden, without its attribute values. | `--node-id` | node_read | A |
| `content.node.attributes` | The attributes of one node with their datatype and plain value (long text cut), and which ones are empty. | `--node-id` `--max-text` | node_read | A |
| `content.node.empty-fields` | Which attributes of a node are empty, with their datatype, so an editor can complete the page. | `--node-id` | node_read | A |
| `content.node.versions` | The versions of a node newest first, with their status, and how many are drafts, published and archived. | `--node-id` `--limit` | node_versions | A |
| `content.node.open-drafts` | The drafts of a node that were never published, newest first, with their language and age in days. | `--node-id` | node_versions | A |
| `content.class.count` | How many nodes of one or more classes there are below a node. | `--node-id` `--class` | node_find | A |

## Examples

### Latest articles

The articles most recently published below a node (the whole site by default), newest first, with how many there are in all.

```
xsh tron:skill:run content.latest.ng-article
```

### Latest classic articles

The classic articles most recently published below a node (the whole site by default), newest first, with how many there are in all.

```
xsh tron:skill:run content.latest.article
```

### Latest news items

The news items most recently published below a node (the whole site by default), newest first, with how many there are in all.

```
xsh tron:skill:run content.latest.ng-news
```

### Latest blog posts

The blog posts most recently published below a node (the whole site by default), newest first, with how many there are in all.

```
xsh tron:skill:run content.latest.ng-blog-post
```

### Latest folders

The folders most recently published below a node (the whole site by default), newest first, with how many there are in all.

```
xsh tron:skill:run content.latest.folder
```

Back to [skills.md](../skills.md) and the [conventions](../extension-conventions.md).

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
