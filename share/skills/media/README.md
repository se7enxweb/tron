# Media library

Images, files, videos, galleries, audio, logos and banners: the oldest items, where they live, and the image aliases and their settings.

Pack `media` 0.0.0.1, GPL-2.0-or-later, by 7x. Skill names start with `media.`.

## Who it is for

Editors who keep the media library tidy, and developers who change image sizes.

## Skills

| Skill | What it answers | Parameters | Units | Wave |
|---|---|---|---|---|
| `media.oldest` | The media objects of one class (image, file, ng_video ...) changed longest ago, oldest first, with their age in days: candidates for clean-up. | `--class` `--node-id` `--limit` | node_find | A |
| `media.where` | In which parent nodes the media objects of one class (image, file, ng_video ...) sit, with how many in each (from the first 50). | `--class` `--node-id` | node_find | A |
| `media.image-aliases` | The image aliases this installation renders, from image.ini, with the file that sets the list. | `--siteaccess` | ini_resolve | A |
| `media.image-ini-blocks` | The blocks of image.ini (one per alias, plus the converters), as a siteaccess resolves them. | `--siteaccess` | ini_list | A |

## Units it needs

`ini_list`, `ini_resolve`, `node_find`

A skill whose unit is not offered here is listed as parked with the reason; it runs nothing. Every skill runs as the
calling user, each unit checked against that user's policies and written to the audit log.

## Running one

```
xsh tron:skill:run media.oldest --class image
```

Over MCP a live skill is the tool `skill_media_oldest` and a prompt of the same name.

See tron's doc/skills.md and doc/extension-conventions.md.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
