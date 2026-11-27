# Skill pack: Site audit

Settings checks with a verdict: debug and SQL output off, caches on, development mode off, mail and site basics set, security settings of the admin and public siteaccesses, and a production-readiness check in one call.

- Pack: `site-audit` 0.0.0.1 in `share/skills/site-audit/` (tron's own packs)
- Prefix: `audit.`
- Skills: 19
- Audience: Whoever signs off a launch or a deploy, and periodic audits.

## Skills

| Skill | What it answers | Parameters | Units | Wave |
|---|---|---|---|---|
| `audit.ini.debug-output-off` | Checks that debug output is switched off for a public siteaccess. Answers the value, where it is set and ok true or false. | `--siteaccess` | ini_resolve | A |
| `audit.ini.debug-redirect-off` | Checks that redirects are not held for debugging on a siteaccess. Answers the value, where it is set and ok true or false. | `--siteaccess` | ini_resolve | A |
| `audit.ini.template-cache-on` | Checks that compiled templates are cached on a siteaccess. Answers the value, where it is set and ok true or false. | `--siteaccess` | ini_resolve | A |
| `audit.ini.template-compile-on` | Checks that templates are compiled to PHP on a siteaccess. Answers the value, where it is set and ok true or false. | `--siteaccess` | ini_resolve | A |
| `audit.ini.development-mode-off` | Checks that the template development mode is off on a siteaccess. Answers the value, where it is set and ok true or false. | `--siteaccess` | ini_resolve | A |
| `audit.ini.view-caching-on` | Checks that full views are cached on a public siteaccess. Answers the value, where it is set and ok true or false. | `--siteaccess` | ini_resolve | A |
| `audit.ini.template-usage-off` | Checks that the list of used templates is not shown to visitors. Answers the value, where it is set and ok true or false. | `--siteaccess` | ini_resolve | A |
| `audit.ini.show-xhtml-off` | Checks that template debug markup is not written into pages. Answers the value, where it is set and ok true or false. | `--siteaccess` | ini_resolve | A |
| `audit.ini.sql-debug-off` | Checks that SQL statements are not written to the debug output. Answers the value, where it is set and ok true or false. | `--siteaccess` | ini_resolve | A |
| `audit.ini.debug-by-ip-off` | Checks that debug output is not limited to (and opened for) a list of IPs. Answers the value, where it is set and ok true or false. | `--siteaccess` | ini_resolve | A |
| `audit.ini.set` | Checks that a setting (file, block, setting) has a value for a siteaccess. Answers the value, where it is set and ok true or false. | `--file` `--block` `--setting` `--siteaccess` | ini_resolve | A |
| `audit.ini.text-translation-on` | Checks that interface texts are translated on a siteaccess. Answers the value, where it is set and ok true or false. | `--siteaccess` | ini_resolve | A |
| `audit.ini.require-user-login-admin` | Checks that the administration siteaccess asks every visitor to sign in. Answers the value, where it is set and ok true or false. | `--siteaccess` | ini_resolve | A |
| `audit.ini.show-hidden-off` | Checks that a public siteaccess does not show hidden nodes. Answers the value, where it is set and ok true or false. | `--siteaccess` | ini_resolve | A |
| `audit.ini.static-cache-state` | Says whether static cache is used on a siteaccess (both are valid; it must be one of them). Answers the value, where it is set and ok true or false. | `--siteaccess` | ini_resolve | A |
| `audit.ini.image-magick-state` | Says whether ImageMagick is enabled for image aliases. Answers the value, where it is set and ok true or false. | `--siteaccess` | ini_resolve | A |
| `audit.ini.debug-timing-off` | Checks that debug timing is not written inline into pages. Answers the value, where it is set and ok true or false. | `--siteaccess` | ini_resolve | A |
| `audit.ini.database-charset-utf8` | Checks that the database connection uses UTF-8 (or the driver default). Answers the value, where it is set and ok true or false. | `--siteaccess` | ini_resolve | A |
| `audit.site.production-ready` | Six settings a public siteaccess must have in production (debug off, template cache on, view caching on, development mode off, no SQL output, no template list), each ok or not, with the number that failed. | `--siteaccess` | ini_resolve | A |

## Examples

### Debug output is off

Checks that debug output is switched off for a public siteaccess. Answers the value, where it is set and ok true or false.

```
xsh tron:skill:run audit.ini.debug-output-off --siteaccess site
```

### Debug redirection is off

Checks that redirects are not held for debugging on a siteaccess. Answers the value, where it is set and ok true or false.

```
xsh tron:skill:run audit.ini.debug-redirect-off --siteaccess site
```

### Template cache is on

Checks that compiled templates are cached on a siteaccess. Answers the value, where it is set and ok true or false.

```
xsh tron:skill:run audit.ini.template-cache-on --siteaccess site
```

### Template compilation is on

Checks that templates are compiled to PHP on a siteaccess. Answers the value, where it is set and ok true or false.

```
xsh tron:skill:run audit.ini.template-compile-on --siteaccess site
```

### Development mode is off

Checks that the template development mode is off on a siteaccess. Answers the value, where it is set and ok true or false.

```
xsh tron:skill:run audit.ini.development-mode-off --siteaccess site
```

Back to [skills.md](../skills.md) and the [conventions](../extension-conventions.md).

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
