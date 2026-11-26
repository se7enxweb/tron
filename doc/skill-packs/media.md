# Skill pack: Media library

Images, files, videos, galleries, audio, logos and banners: the oldest items, where they live, and the image aliases and their settings.

- Pack: `media` 0.0.0.1 in `share/skills/media/` (tron's own packs)
- Prefix: `media.`
- Skills: 16
- Audience: Editors who keep the media library tidy, and developers who change image sizes.

## Skills

| Skill | What it answers | Parameters | Units | Wave |
|---|---|---|---|---|
| `media.image.oldest` | The images changed longest ago, oldest first, with their age in days: candidates for clean-up. | `--node-id` `--limit` | node_find | A |
| `media.image.where` | In which parent nodes the images sit, with how many in each (from the first 50). | `--node-id` | node_find | A |
| `media.file.oldest` | The files changed longest ago, oldest first, with their age in days: candidates for clean-up. | `--node-id` `--limit` | node_find | A |
| `media.file.where` | In which parent nodes the files sit, with how many in each (from the first 50). | `--node-id` | node_find | A |
| `media.ng-video.oldest` | The videos changed longest ago, oldest first, with their age in days: candidates for clean-up. | `--node-id` `--limit` | node_find | A |
| `media.ng-video.where` | In which parent nodes the videos sit, with how many in each (from the first 50). | `--node-id` | node_find | A |
| `media.ng-gallery.oldest` | The galleries changed longest ago, oldest first, with their age in days: candidates for clean-up. | `--node-id` `--limit` | node_find | A |
| `media.ng-gallery.where` | In which parent nodes the galleries sit, with how many in each (from the first 50). | `--node-id` | node_find | A |
| `media.ng-audio.oldest` | The audio items changed longest ago, oldest first, with their age in days: candidates for clean-up. | `--node-id` `--limit` | node_find | A |
| `media.ng-audio.where` | In which parent nodes the audio items sit, with how many in each (from the first 50). | `--node-id` | node_find | A |
| `media.ng-logo.oldest` | The logos changed longest ago, oldest first, with their age in days: candidates for clean-up. | `--node-id` `--limit` | node_find | A |
| `media.ng-logo.where` | In which parent nodes the logos sit, with how many in each (from the first 50). | `--node-id` | node_find | A |
| `media.ng-banner.oldest` | The banners changed longest ago, oldest first, with their age in days: candidates for clean-up. | `--node-id` `--limit` | node_find | A |
| `media.ng-banner.where` | In which parent nodes the banners sit, with how many in each (from the first 50). | `--node-id` | node_find | A |
| `media.image-aliases` | The image aliases this installation renders, from image.ini, with the file that sets the list. | `--siteaccess` | ini_resolve | A |
| `media.image-ini-blocks` | The blocks of image.ini (one per alias, plus the converters), as a siteaccess resolves them. | `--siteaccess` | ini_list | A |

## Examples

### Oldest images

The images changed longest ago, oldest first, with their age in days: candidates for clean-up.

```
xsh tron:skill:run media.image.oldest
```

### Images per folder

In which parent nodes the images sit, with how many in each (from the first 50).

```
xsh tron:skill:run media.image.where
```

### Oldest files

The files changed longest ago, oldest first, with their age in days: candidates for clean-up.

```
xsh tron:skill:run media.file.oldest
```

### Files per folder

In which parent nodes the files sit, with how many in each (from the first 50).

```
xsh tron:skill:run media.file.where
```

### Oldest videos

The videos changed longest ago, oldest first, with their age in days: candidates for clean-up.

```
xsh tron:skill:run media.ng-video.oldest
```

Back to [skills.md](../skills.md) and the [conventions](../extension-conventions.md).

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
