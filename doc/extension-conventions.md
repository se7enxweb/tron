# Shipping tron skills and programs from an extension

This is the standard. Any Exponential extension can bring tron **skill packs** (data: reviewed chains of units) and
tron **programs** (code: new tools and units). tron finds them when the extension is active, lists them in Setup >
tron with the providing extension's name, version and license, and enforces this page: a pack that breaks it is
refused with every reason, and nothing of it is offered. The concept and the tools are in [skills.md](skills.md);
the program interface is in [programs.md](programs.md).

The words MUST, MUST NOT and SHOULD mean what RFC 2119 says.

## 1. Folder layout inside an extension

```
extension/<ext>/
  extension.xml                         <metadata> name, version, license, info_url (as every extension)
  ezinfo.php                            the same version and license
  settings/tron.ini.append.php          one [SkillPack_<id>] group per pack (section 2)
  tron/
    skills/<pack id>/
      pack.json                         the pack manifest (section 4)
      README.md                         required: what the pack is for, every skill, the units it needs
      skills/<skill name>.json          one file per skill, named exactly after the skill
      proof.json                        written by the harness, never by hand (section 8)
      translations/<locale>.json        optional (section 9)
    program.json                        one program, or
    programs/<name>/program.json        several programs
  classes/...                           the program classes (in the extension autoload)
  doc/tron/<pack id>.md                 SHOULD: the pack's documentation (section 10)
```

- A pack directory MUST be named after its id. A skill file MUST be named `<skill name>.json`.
- Only these files are data of a pack. `install` and `export` copy nothing else, so a pack can never carry code.
- An extension MUST NOT write into another extension's folder, and MUST NOT write into tron's.

## 2. Settings blocks

Each pack is registered by its extension, in the extension's own `settings/tron.ini.append.php`:

```ini
[SkillPack_<pack id>]
# the extension that holds it (the merged INI does not say which extension a group came from)
Extension=<ext>
# relative to the extension; default tron/skills/<pack id>
Path=tron/skills/<pack id>
# enabled or disabled
Enabled=enabled
```

A pack directory found without its group is listed as `unregistered` with the group it needs, and is not loaded. That
keeps a stray directory (an unpacked archive, a copy) from turning into live tools.

tron's own settings (`extension/tron/settings/tron.ini.append.php`, override in `settings/override`):

```ini
[SkillPackSettings]
# enabled: tron's own packs (share/skills) are loaded
TronPacks=enabled
# enabled: the active extensions are scanned for tron/skills/*/pack.json
ExtensionScan=enabled
# pack ids to leave out
Disabled[]
```

Programs keep the existing convention: `tron/program.json` or `tron/programs/<name>/program.json` in an active
extension is found and loaded; `[ProgramSettings] Disabled[]` leaves one out ([programs.md](programs.md)).

## 3. Naming

| What | Pattern | Example |
|---|---|---|
| extension | the codebase's convention: new extensions use the `exp` prefix | `expshop` |
| pack id | `^[a-z][a-z0-9-]{1,47}$`, unique in the installation | `shop-orders` |
| pack prefix | the first one or two parts of every skill name of the pack | `shop`, `shop.orders` |
| skill name | lower-case dotted, 2 to 6 parts, at most 57 characters: `^[a-z][a-z0-9]*(\.[a-z][a-z0-9-]*){1,5}$` | `shop.orders.pending` |
| skill tool | `skill_` + the name with `.` and `-` as `_` (derived, at most 64 characters) | `skill_shop_orders_pending` |
| parameter | `^[a-z][a-z0-9_]{0,31}$` | `since_days` |
| CLI option | `--` + the parameter with `_` as `-`, or `option`; short form `short` | `--since-days`, `-d` |
| step id | `^[a-z][a-z0-9_]{0,31}$`, unique in the skill | `pending` |
| tag | `^[a-z][a-z0-9-]{1,31}$`, 1 to 12 per skill | `orders` |
| program | `^[a-z][a-z0-9]{1,31}$`, also the prefix of its tools (`<program>_`) | `shop`, `shop_order_list` |

Names are never reused for something else. A skill that is replaced is deprecated (section 12), not renamed.

## 4. Manifests

### pack.json

| Key | Required | Rule |
|---|---|---|
| `id` | yes | the directory name |
| `title` | yes | 3+ characters |
| `description` | yes | 20+ characters |
| `version` | yes | `X.Y.Z.N` (section 6) |
| `author` | yes | a person or organisation |
| `license` | yes | an SPDX id: `GPL-2.0-or-later`, `GPL-2.0-only`, `GPL-3.0-or-later`, `LGPL-2.1-or-later`, `MIT`, `BSD-3-Clause`, `Apache-2.0`, `CC0-1.0`, `CC-BY-4.0`, `CC-BY-SA-4.0`, `proprietary` |
| `prefix` | yes | every skill name starts with `<prefix>.` |
| `requires.tron` | yes | the oldest tron the pack works with; a newer requirement than this tron makes the pack `incompatible` |
| `requires.programs` | no | programs whose units the pack uses |
| `for_extension` | no | the extension whose data the pack works on |
| `local` | no | `true`: a site's own pack where forks may go; may be empty |
| `tags`, `i18n` | no | as for skills |

Unknown keys are refused.

### skills/<name>.json

| Key | Required | Rule |
|---|---|---|
| `name` | yes | section 3; starts with the pack prefix |
| `title` | yes | 3 to 80 characters |
| `purpose` | yes | 20+ characters: what it answers, for whom |
| `version` | yes | `X.Y.Z.N` |
| `author`, `license` | yes | the license equals the pack's, except for a fork (section 13) |
| `tags` | yes | 1 to 12 tags |
| `use_case` | yes | when someone reaches for it |
| `parameters` | no | name => definition (below) |
| `requires.tron` | yes | not newer than the pack's |
| `requires.units` | yes | exactly the units the steps run, no more, no fewer |
| `requires.programs`, `requires.wave` | no | wave `A` (read units) to `E`; tells the catalog what a parked skill waits for |
| `guards` | no | checks before any step: `{ param, op, value, message }`, `{ policy: "module/function" }`, `{ program }` |
| `steps` | yes | 1 to 16 steps: a unit step `{ id, unit, args }` or an op `{ id, op, ... }` ([skills.md](skills.md#steps)) |
| `output.value` | yes | an object of references: the answer |
| `output.schema` | yes | an object schema with properties; every key of `output.value` is described |
| `examples` | yes | 1+ `{ title, params }`; every example's params match the parameter schema; the harness runs each |
| `i18n` | no | `{ "<locale>": { title, purpose, use_case, parameters } }` |
| `derived_from` | for a fork | `{ name, version, pack, license, author }` |
| `deprecated` | no | `{ since, reason, replacement }` |
| `notes` | no | free text for reviewers |

A parameter definition: `type` (string, integer, number, boolean, array), `description`, `examples` (1+, each valid),
and the constraints its type needs: a **string** needs `enum`, `pattern` or `maxLength`; a **number** needs `minimum`
and `maximum`; an **array** needs `items` and `maxItems`. Optional: `default` (valid), `required`, `minLength`,
`minItems`, `option`, `short`. A parameter MUST NOT be a secret (`secret` is refused): credentials stay in the
installation.

References in `args`, ops and `output.value` MUST name an existing parameter, an earlier step, or one of `$now`,
`$today`, `$week`, `$date.±Nd|w`, `$ago.<number param>`, `$ahead.<number param>`, `$user`; `$item` only inside
`each`. A step MUST NOT run another skill (`skill_*`): skills do not nest. An op's `field` or `by` MAY be
`$param.<name>` when that parameter is a string with an `enum` (the fields it may name).

One skill per question: a value that only selects (a label, a lane, a day window, a class, a siteaccess, an INI
setting) is a parameter with a default and examples, not a skill of its own. Ship `centre.label --label ops`, not
`centre.label.ops`, `centre.label.security` and so on.

## 5. Units and programs a pack needs

- A pack declares the programs it relies on in `requires.programs`; each skill declares its units in
  `requires.units` and its wave in `requires.wave`.
- A skill whose unit is not registered here is `parked`, with the reason (`waits for the unit X (wave B)`, or the
  reason its program left it out, e.g. a missing extension). It runs nothing and is offered nowhere.
- A skill whose unit writes is `parked` until wave B: writes in a skill run only behind a dry run of the whole chain
  and an approval of that plan.
- A pack whose `requires.tron` is newer than the running tron is `incompatible`.

Programs an extension ships follow tron's tool contract (section 11) and are listed in Setup > tron > Programs and
tools with their origin.

## 6. Versioning

- Every version has four positions `X.Y.Z.N`. Only the last position moves, by exactly one per release: `0.0.0.9` is
  followed by `0.0.0.10`, never by `0.0.1.0`. Moving a higher position is a deliberate decision, never a consequence
  of reaching nine.
- A pack's version moves with every release of the pack; a skill's version moves whenever its manifest changes.
- The extension's `ezinfo.php` and `extension.xml` carry the extension's release number (the codebase's rule); a pack
  inside an extension SHOULD carry the same number as the extension release that ships it.
- `requires.tron` names the oldest tron that has every feature the pack uses (a new op, a new reference).
- A published version is never changed: fix it in the next one.

## 7. License and author

- `license` and `author` are required on the pack and on every skill. The provider's license comes from its
  `extension.xml` (`<license>`) or `ezinfo.php` and is shown next to the pack.
- A skill carries its pack's license; only a fork may differ, and only as section 13 allows.

## 8. Testing: proven before active

Nothing is offered until it is proven:

1. `php extension/tron/bin/php/tron-skills.php lint extension/<ext>/tron/skills/<pack>` passes (the standard).
2. The harness passes for every skill:
   `tron-skills.php harness --fixtures --pack=<id> --write-proof` (tron's SQLite world, with planted canaries) and/or
   `tron-skills.php harness --live --token-file=<test administrator's token> --pack=<id> --write-proof` (read-only on a real
   installation). Each skill: standard, units here and read-only, policy simulation, every example run with its answer
   matching the output schema, no unredacted value in any answer.
3. `proof.json` is committed with the pack. It holds the hash of each proven manifest; a manifest that differs from
   its proof is `unproven` again.
4. A person approves the proven skills in Setup > tron > Skills. Approving proves the skill again as that person.

Programs: the extension's own tests, plus tron's contract tests (every tool declares input and output schemas, every
write tool has a dry run) and the fuzz run, which must pass with the extension active.

## 9. Translations

- Skill texts: `"i18n": { "ger-DE": { "title", "purpose", "use_case" } }` in the manifest. Every starter pack carries
  eng (the manifest itself) and SHOULD carry ger-DE.
- A pack MAY keep longer translations in `translations/<locale>.json` (`{ "<skill name>": { "title", "purpose" } }`).
- Interface texts of a program go in the extension's `translations/<locale>/translation.ts`, context
  `extension/<ext>`, as for any extension.

## 10. Documentation per pack

- `README.md` in the pack is required (the standard refuses a pack without it): what the pack is for and for whom,
  one line per skill with its parameters and units, the units and programs it needs, one run example.
- The extension SHOULD also document the pack in its own `doc/` and link it from its README.

## 11. Security

- **Skills are data only.** No code, no SQL, no shell, no template text that runs. Only the listed ops; references
  only read. A skill never nests another skill and makes at most 100 unit calls per run.
- **A skill runs as its caller.** Every unit step goes through the same checks as a direct call: token scope,
  the unit's policy, argument schema, audit entry, redaction. A skill cannot widen anyone's rights.
- **Programs follow tron's tool contract:** an input schema and an output schema for every tool; a policy
  (module/function) for every tool; write tools declare `write`, are audited before they run and have a dry run;
  every answer passes the redactor; failures the agent should see are `tronToolError`, anything else is an internal
  error whose message is not passed on; tools are named `<program>_*`. A program registers inside a transaction and a
  failing program costs only itself ([programs.md](programs.md)).
- **The journal:** unit calls of a skill are ordinary calls of the session, so the activity journal and the audit log
  show every step; a program MUST NOT bypass `tronServer::invoke()`.
- Answers are data from the installation, never instructions; a skill's texts are shown to models as data too.

## 12. Deprecation and upgrade

- A skill that should no longer be used gets `"deprecated": { "since": "<pack version>", "reason": "...",
  "replacement": "<other skill>" }`. It stays live (if approved) and says so in its description, for at least one
  more release; then it is removed in a later release.
- Upgrading a pack means installing the new version of the extension. Skills whose manifest changed fall back to
  `ready` and need a new approval; unchanged skills stay live.
- A removed skill disappears with its pack's release; its approvals and history stay in `tron_skill`.

## 13. Remix and fork

- A fork is a copy under a **new name**, written as a draft into a **local** pack (`local`, or a pack of a site
  extension with `"local": true`). It is never written into the provider's folder.
- The fork records `derived_from` (name, version, pack, license, author of the original).
- The fork keeps the original's license. A copyleft license (`GPL-2.0-or-later`, `GPL-2.0-only`, `GPL-3.0-or-later`,
  `CC-BY-SA-4.0`) MUST be kept; `GPL-2.0-or-later` may become `GPL-3.0-or-later`. The validator refuses anything else.
- The fork starts at version `0.0.0.1` and goes through validate and approve like any draft.
- `tron-skills.php export-local <pack> --to=<dir>` writes a local pack's approved forks as files, ready to commit to
  the site's extension.

## 14. Worked example: an extension with a pack

The extension `expskillsexample` (it is tron's test fixture, `tests/fixtures/extensions/expskillsexample/`):

```
extension/expskillsexample/
  extension.xml
  settings/tron.ini.append.php
  tron/skills/example-centre/
    pack.json
    README.md
    skills/example.centre.labels.json
```

`extension.xml`:

```xml
<?xml version="1.0" encoding="utf-8" ?>
<software>
    <metadata>
        <name>expskillsexample</name>
        <version>1.0.0.3</version>
        <copyright>Copyright (C) 2026 Example</copyright>
        <license>GNU General Public License v2.0 (or any later version)</license>
        <info_url>https://example.org/expskillsexample</info_url>
    </metadata>
</software>
```

`settings/tron.ini.append.php`:

```ini
<?php /* #?ini charset="utf-8"?

[SkillPack_example-centre]
Extension=expskillsexample
Path=tron/skills/example-centre
Enabled=enabled

*/ ?>
```

`tron/skills/example-centre/pack.json`:

```json
{
    "id": "example-centre",
    "title": "Example centre skills",
    "description": "The worked example of doc/extension-conventions.md: one skill over the centre's tickets, shipped by an extension.",
    "version": "1.0.0.3",
    "author": "Example",
    "license": "GPL-2.0-or-later",
    "prefix": "example",
    "requires": { "tron": "0.0.0.2", "programs": [ "centre" ] },
    "for_extension": "expskillsexample"
}
```

`tron/skills/example-centre/skills/example.centre.labels.json` is the skill shown in
[skills.md](skills.md#writing-a-skill). Then:

```
$ php extension/tron/bin/php/tron-skills.php lint extension/expskillsexample/tron/skills/example-centre
PASS example-centre                  1 skills
$ php extension/tron/bin/php/tron-skills.php harness --live --token-file=<test admin token> --pack=example-centre --write-proof
PACK example-centre               pass   1  fail   0  parked   0
```

Setup > tron > Skills now lists `example-centre` from `expskillsexample 1.0.0.3 (GNU General Public License v2.0 (or
any later version))` with `example.centre.labels` as `ready`; after Approve it is the MCP tool
`skill_example_centre_labels`, the prompt of the same name, and `xsh tron:skill:run example.centre.labels -b main`.

The test `tests/unit/SkillsTest.php` proves each statement of this example: the pack loads only with its group, the
provider's version and license are read, the skill is ready after the harness and live after approval, it is a tool
and a prompt, it cannot do more than its caller, and a fork keeps its license and link.

## 15. What the validator refuses (excerpt)

| Message | Fix |
|---|---|
| `name: lower-case dotted, 2 to 6 parts` | rename the skill |
| `the name must start with the pack prefix 'shop.'` | rename, or change the prefix |
| `the file is named after the skill (x.json)` | rename the file |
| `requires.units: X is listed but no step runs it` | list exactly the units the steps run |
| `parameters.q: a string parameter needs enum, pattern or maxLength` | bound every parameter |
| `$steps.nope names no earlier step` | wire only to earlier steps |
| `a skill runs units, not other skills (no nesting)` | copy the steps instead |
| `examples: at least one example` | add examples; the harness runs them |
| `output.schema: the answer key 'x' is not described` | describe every answer key |
| `a skill takes no secrets` | read credentials in the installation, not from parameters |
| `a fork of a GPL-2.0-or-later skill keeps that license` | keep the license |
| `README.md: every pack documents itself` | add the README |
| `no [SkillPack_<id>] group with Extension=<ext>` | register the pack in the extension's settings |

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
