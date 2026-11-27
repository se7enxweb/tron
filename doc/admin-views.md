# Setup > tron: the administration views

Exponential Tron is controlled from the Exponential administration, next to the rest of the installation's settings. The entry
**tron (MCP)** sits in the Setup menu (in the grouped menu of admin4, under System) and is shown only to users with
`tron/admin`. The pages work in every admin design and follow its light and dark mode.

Code: `modules/tron/*.php` (views), `classes/admin/` (the data of each view), `design/standard/templates/tron/`,
`design/standard/stylesheets/modules/tron.css`, `design/standard/javascript/tron_audit_live.js`.
Translations: eng-GB and ger-DE.

| Page | Address | Policy | What it is for |
|---|---|---|---|
| Dashboard | `/tron/dashboard` | tron/admin | the state of Exponential Tron at a glance |
| Tokens | `/tron/tokens` | tron/tokens | make, rotate, list and revoke tokens for any user |
| Programs and tools | `/tron/programs` | tron/admin | every program and tool, who may call it, switches |
| Audit | `/tron/audit` | tron/audit | the audit log with filters and a live tail |
| Settings | `/tron/settings` | tron/admin | tron.ini as the siteaccess reads it, with links to the INI editor |

The three policies are separate so you can let a support person read the audit without letting them make tokens.

## Dashboard

- Exponential Tron's version and the protocol versions it speaks.
- The programs with their state (`loaded`, `failed` with the reason, `disabled`, `conflict`, `incompatible`) and their
  tools, resources and prompts.
- Active tokens.
- The last day's calls by outcome (`ok`, `error`, `denied`, `invalid`, `unauthenticated`).
- The ways in: stdio, the HTTP endpoint (on or off, for which siteaccesses), the audit sinks.

## Tokens

- **Make a token**: user, name (what it is for), lifetime in days (at most `[TokenSettings] MaxLifetimeDays`), scopes
  (programs). The token is shown once, on the answer page, and never again; copy it into the agent's token file.
- **Rotate**: a new token with the same user, name, scopes and lifetime, shown once; the old one is revoked in the same
  step. Rotating an expired token renews it the same way.
- **Revoke**: effective on the next HTTP request.
- **List**: filter by user and state (active, expired, revoked); prefix, name, scopes, expiry and last use per token.
  Never the hash, never the secret.

Making, rotating and revoking ask "Verify it's you" first and are written to the audit (`admin/token.create`,
`admin/token.rotate`, `admin/token.revoke`). Background: [tokens.md](tokens.md).

## Programs and tools

Every program and tool with:

- the policy it needs, and **the roles that grant that policy**, each linked to the role, so "who can call this?" is
  answered on one page;
- its annotations (read, write, idempotent, destructive);
- a **switch**. A program or tool switched off is not registered: no user sees or calls it, whatever their roles. The
  core program `tron` and its tools cannot be switched off.

Switches are stored in the table `tron_switch` and take effect on the next HTTP request and the next start of a stdio
server. Where Velocity serves, its workers read them only after a restart. Switching asks "Verify it's you" and is
written to the audit (`admin/switch.on`, `admin/switch.off`). The page also counts the tools that are on, the write
tools, and the tools no role grants, and marks roles whose policy has limitations as "limited" (Exponential Tron treats those as
not granted).

A switch is the quick, per-installation way. The INI way, `[ProgramSettings] Disabled[]=<program>`, does the same for
whole programs and is versioned with your settings; use it for decisions that should survive a database restore.

## Audit

The audit log, newest first:

- filters by outcome, transport, program and user, and paging;
- **Live**: new entries appear as they come (polled every 5 seconds);
- every filter and page is a view parameter in the address, so a view can be bookmarked and shared, for example
  `/tron/audit/(outcome)/denied`.

What each field means and how to read the file sink: [audit.md](audit.md).

## Settings

tron.ini as the current siteaccess reads it, group by group (lists joined by commas), with links into the
kernel's INI editor. Changing a value goes through the INI editor (which asks "Verify it's you" in the reauth group
`system`). Every setting: [configuration.md](configuration.md).

## Skills

`/tron/skills` (policy tron/admin). The packs with the extension that provides each (its version and license) and
their state (loaded, refused with every reason, unregistered, disabled, incompatible, switched off), with Switch off/on
and "Approve ready skills" per pack. Below them the skills with counts per state, a search field and state and tag
chips (all view parameters: `(pack)`, `(state)`, `(tag)`, `(q)`, `(offset)`), and the drafts and forks waiting for
approval. `/tron/skills/(skill)/<name>` shows one skill: facts (use case, pack, author and license, derived from, MCP
tool, units, tags, manifest hash), parameters with their CLI options, defaults, ranges and examples, the steps, the
examples as `xsh` lines and JSON, the harness proof, Fork into a local pack, recent runs from the audit log, every
stored version and decision, and the manifest itself. "Prove and approve" proves the skill again as the signed-in
administrator (their real policies) and approves exactly that manifest; Revoke takes it back. Every action asks
"Verify it's you" and is recorded in the audit log as transport admin. See [skills.md](skills.md).

## "Verify it's you"

Exponential Tron adds the reauth group `tron` (on by default) in its `site.ini.append.php`:

```ini
[ReauthGroup_tron]
Enabled=true
Rules[]
Rules[]=tron/tokens CreateButton
Rules[]=tron/tokens RotateButton
Rules[]=tron/tokens RevokeButton
Rules[]=tron/programs SwitchButton
Rules[]=tron/skills ApproveButton ApprovePackButton RevokeButton
Rules[]=tron/skills PackSwitchButton ForkButton
```

Seeing the pages never asks. Manage the group in Setup > Verify it's you.

## Who should have what

| Person | Policies |
|---|---|
| the installation's administrator | tron/admin, tron/tokens, tron/audit (the Administrator role holds them through `*/*`) |
| a support person who reviews agents | tron/audit |
| a team lead who hands out tokens | tron/tokens |
| an agent | **none of these**: tron/use plus its program functions ([permissions.md](permissions.md)) |

## After deploying a change to the views

Template and stylesheet changes reach the browser through the packer: deploy with `--packer` where the installation
uses it, then hard-reload the browser once.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
