# Skill pack: Media library

Images, files, videos, galleries, audio, logos and banners: the oldest items, where they live, and the image aliases and their settings.

- Pack: `media` 0.0.0.1 in `share/skills/media/` (tron's own packs)
- Prefix: `media.`
- Skills: 4
- Audience: Editors who keep the media library tidy, and developers who change image sizes.

## Skills

| Skill | What it answers | Parameters | Units | Wave |
|---|---|---|---|---|
| `media.oldest` | The media objects of one class (image, file, ng_video ...) changed longest ago, oldest first, with their age in days: candidates for clean-up. | `--class` `--node-id` `--limit` | node_find | A |
| `media.where` | In which parent nodes the media objects of one class (image, file, ng_video ...) sit, with how many in each (from the first 50). | `--class` `--node-id` | node_find | A |
| `media.image-aliases` | The image aliases this installation renders, from image.ini, with the file that sets the list. | `--siteaccess` | ini_resolve | A |
| `media.image-ini-blocks` | The blocks of image.ini (one per alias, plus the converters), as a siteaccess resolves them. | `--siteaccess` | ini_list | A |

## Examples

### Oldest images

The images changed longest ago, oldest first, with their age in days: candidates for clean-up.

```
xsh tron:skill:run media.oldest --class image
```

### Images per folder

In which parent nodes the images sit, with how many in each (from the first 50).

```
xsh tron:skill:run media.where --class image
```

### Oldest files

The files changed longest ago, oldest first, with their age in days: candidates for clean-up.

```
xsh tron:skill:run media.oldest --class file
```

### Files per folder

In which parent nodes the files sit, with how many in each (from the first 50).

```
xsh tron:skill:run media.where --class file
```

### Oldest videos

The videos changed longest ago, oldest first, with their age in days: candidates for clean-up.

```
xsh tron:skill:run media.oldest --class ng_video
```

Back to [skills.md](../skills.md) and the [conventions](../extension-conventions.md).

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
