# Configuration (tron.ini)

The defaults are in `extension/tron/settings/tron.ini.append.php`. Override in `settings/override/tron.ini.append.php`
or a siteaccess's `tron.ini.append.php`; start an array with an empty `Name[]` line to replace the inherited list
instead of adding to it. After a change: `php bin/php/ezcache.php --clear-tag=ini`, and where Velocity serves,
`exp:velocity deploy` so the workers see it.

| Group | Setting | Default | Meaning |
|---|---|---|---|
| ServerSettings | Title | Exponential tron | serverInfo.title |
| | Instructions | (empty) | text appended to the initialize instructions |
| | PageSize | 100 | items per tools/list (and resources, prompts) page |
| | ResponseMaxBytes | 200000 | answers above this are cut with a note; structuredContent is left out |
| ProgramSettings | ExtensionScan | enabled | look for programs in active extensions; `disabled`: tron's own only |
| | Disabled[] | (none) | program names to leave out; the core `tron` cannot be |
| AuditSettings | Sinks[] | database | `database` and/or `file`; at least one |
| | FilePath | var/log/tron-audit.log | the file sink, JSON lines |
| TokenSettings | DefaultLifetimeDays | 90 | token:create without --days |
| | MaxLifetimeDays | 365 | upper limit |
| StdioSettings | AllowLoginOption | disabled | stdio needs a token (`TRON_TOKEN`, `--token-file`); `enabled` allows `serve --user=<login>` without one |
| HTTPSettings | Enabled | false | the endpoint /tron/mcp answers 404 until `true` |
| | SiteAccesses[] | (none) | siteaccesses that serve the endpoint |
| | AllowedOrigins[] | (none) | browser origins (scheme://host[:port]); empty refuses any request with an Origin header |
| | AllowedHosts[] | (none) | Host names served (empty: any); set it on a public endpoint |
| | MaxBodyBytes | 1048576 | larger bodies get 413 |
| CentreSettings | Backend | auto | `auto`: expcentre's API where it exists, else tron's tables; `expcentre`; `tron` |
| | Lanes[] | inbox, next, doing, waiting, done | tron's tables: lanes in board order |
| | Boards[] | (none) | tron's tables: boards that exist; empty: any name |
| KatalystSettings | Source | kernel | `kernel`: sevenx_katalyst in this installation; `none` |
| SkillPackSettings | TronPacks | enabled | `enabled`: tron's own skill packs in `share/skills` are loaded |
| | ExtensionScan | enabled | `enabled`: active extensions are scanned for `tron/skills/<pack>/pack.json` (each pack needs its `[SkillPack_<id>]` group) |
| | Disabled[] | (none) | skill pack ids to leave out ([skills.md](skills.md)) |
| SkillPack_<id> | Extension, Path, Enabled | (none) | in an extension's own settings: registers its pack ([extension-conventions.md](extension-conventions.md)) |

Example: a public endpoint for the centre siteaccess.

```ini
[HTTPSettings]
Enabled=true
SiteAccesses[]
SiteAccesses[]=centre
AllowedHosts[]
AllowedHosts[]=centre.se7enx.com
```

## Where each setting is read

| Setting | Read | A change takes effect |
|---|---|---|
| everything in tron.ini | when the server is built: once per stdio process, once per HTTP request | HTTP: next request after the INI cache is cleared; stdio: next start; Velocity: after its workers restart |
| `HTTPSettings` | by the endpoint view before anything else | as above; `Enabled=false` closes the endpoint at once (404) |
| `TokenSettings` | when a token is made | tokens already made keep their expiry |
| switches (Setup > tron) | table `tron_switch`, when the server is built | as for tron.ini, without an INI cache clear |

## Settings tron adds to other INI files

tron ships these in its own `settings/`; they need no change, but they explain what happens.

| File | Group | Setting | Why |
|---|---|---|---|
| site.ini | `[RoleSettings]` | `PolicyOmitList[]=tron/mcp` | the endpoint signs callers in with tokens, not sessions; each call checks the token user's policies |
| site.ini | `[RegionalSettings]` | `TranslationExtensions[]=tron` | the administration views' translations (eng-GB, ger-DE) |
| site.ini | `[ReauthSettings]`, `[ReauthGroup_tron]` | `Groups[]=tron`, `Rules[]=tron/tokens CreateButton`, `RotateButton`, `RevokeButton`, `tron/programs SwitchButton` | "Verify it's you" before token and switch actions |
| module.ini | `[ModuleSettings]` | `ExtensionRepositories[]=tron`, `ModuleList[]=tron` | the module `tron` (endpoint, administration views, policy functions) |
| menu.ini | `[Leftmenu_setup]` | `Links[tron]=tron/dashboard`, `PolicyList_tron[]=tron/admin`, `GroupLinks_system[]=tron` | the Setup menu entry, for users with `tron/admin` |
| design.ini | `[ExtensionSettings]`, `[StylesheetSettings]` | `DesignExtensions[]=tron`, `BackendCSSFileList[]=modules/tron.css` | the administration views' templates and stylesheet, loaded through the packer |

## Recipes

**Only tron's own programs, never scan extensions:**

```ini
[ProgramSettings]
ExtensionScan=disabled
```

**Leave Katalyst out on an installation that has the extension:**

```ini
[ProgramSettings]
Disabled[]
Disabled[]=katalyst
```

**`serve --user=<login>` without a token** (off by default since 0.0.0.14). Whoever can start `tron.php` could then
act as any user, an administrator included, so enable it only where every account that can run it may do that anyway:

```ini
[StdioSettings]
AllowLoginOption=enabled
```

**Shorter tokens:**

```ini
[TokenSettings]
DefaultLifetimeDays=30
MaxLifetimeDays=90
```

**Audit to both sinks, the file in its own directory:**

```ini
[AuditSettings]
Sinks[]
Sinks[]=database
Sinks[]=file
FilePath=var/log/tron/audit.log
```

**Tell every agent something about this installation:**

```ini
[ServerSettings]
Instructions=This is the staging installation. Nothing here is customer-facing; tickets are for the operations team.
```

**A browser-based client you trust:**

```ini
[HTTPSettings]
AllowedOrigins[]
AllowedOrigins[]=https://agent.example.com
```

Per-siteaccess settings and several installations: [multi-site.md](multi-site.md).

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
