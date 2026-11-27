# Content inventory

What the site holds: the latest items of every content type, recently changed and long unchanged content, a subtree map, one node with its fields, empty fields, versions and open drafts, counts per class.

Pack `content-inventory` 0.0.0.1, GPL-2.0-or-later, by 7x. Skill names start with `content.`.

## Who it is for

Editors, content managers and anyone planning a migration or a clean-up.

## Skills

| Skill | What it answers | Parameters | Units | Wave |
|---|---|---|---|---|
| `content.latest` | The nodes of one class most recently published below a node (the whole site by default), newest first, with how many there are in all. | `--class` `--node-id` `--limit` | node_find | A |
| `content.recently-modified` | The nodes changed most recently below a node, of any class, newest first. | `--node-id` `--limit` | node_find | A |
| `content.oldest-unchanged` | The nodes below a node that were changed longest ago, oldest first: candidates for review or archiving. | `--node-id` `--limit` | node_find | A |
| `content.find-by-name` | Finds nodes whose name holds the given text, below a node. | `--node-id` `--text` `--limit` | node_find | A |
| `content.find-by-class` | Lists the nodes of one or more classes below a node, sorted as asked. | `--node-id` `--class` `--limit` `--sort` | node_find | A |
| `content.section` | Lists the nodes of a section (by section id) below a node. | `--node-id` `--section` `--limit` | node_find | A |
| `content.missing-url-alias` | Lists the nodes below a node that have no URL alias, so they only open as content/view/full/<id>. | `--node-id` `--class` | node_find | A |
| `content.tree.children` | The children of a node with their class, and how many of each class there are. | `--node-id` `--limit` | node_tree | A |
| `content.tree.depth` | The subtree of a node some levels deep, with the number of nodes per class and per depth. | `--depth` `--node-id` `--limit` | node_tree | A |
| `content.node.summary` | One node in short: name, class, section, language, URL, dates, version, children and whether it is hidden, without its attribute values. | `--node-id` | node_read | A |
| `content.node.attributes` | The attributes of one node with their datatype and plain value (long text cut), and which ones are empty. | `--node-id` `--max-text` | node_read | A |
| `content.node.empty-fields` | Which attributes of a node are empty, with their datatype, so an editor can complete the page. | `--node-id` | node_read | A |
| `content.node.versions` | The versions of a node newest first, with their status, and how many are drafts, published and archived. | `--node-id` `--limit` | node_versions | A |
| `content.node.open-drafts` | The drafts of a node that were never published, newest first, with their language and age in days. | `--node-id` | node_versions | A |
| `content.class.count` | How many nodes of one or more classes there are below a node. | `--node-id` `--class` | node_find | A |

## Units it needs

`node_find`, `node_read`, `node_tree`, `node_versions`

A skill whose unit is not offered here is listed as parked with the reason; it runs nothing. Every skill runs as the
calling user, each unit checked against that user's policies and written to the audit log.

## Running one

```
xsh tron:skill:run content.latest --class ng_article
```

Over MCP a live skill is the tool `skill_content_latest` and a prompt of the same name.

See tron's doc/skills.md and doc/extension-conventions.md.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
