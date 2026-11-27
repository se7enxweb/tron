# Runbook: install, verify, operate, roll back

Every step prints PASS or FAIL. Change files only where the master tree lives; run the commands from the installation
root on each serving node after the tree has synced to it (on the 7x alpha set: edit on cloud, run on cluster and web), with
the load gate open (`xsh node:load`). `<sa>` is an administration siteaccess, `<php>` the PHP the installation runs
(alpha and se7enx.com: 8.5). Run as root, every `tron.php`, `ezexec.php` and `serve` command needs `--allow-root-user`
(the kernel refuses root otherwise and says so on stderr).

## Before

1. Load gate open on the node.
2. The test suite green on the checkout that will be installed: `<php> <tron>/tests/run.php`, and the PHP matrix
   (`php <tron>/tests/matrix.php`).
3. The read-only probe against the installation, before anything changes:
   `<php> bin/php/ezexec.php <tron>/tests/kernel/kernel_probe.php --allow-root-user` must end in PASS.
4. A note of the current `ActiveExtensions[]` list (the settings file is backed up in step 2 below).

## Install

| # | Step | Command | Undo |
|---|---|---|---|
| 1 | Code | `git clone <tron repository> extension/tron` (or Composer: `composer require se7enxweb/tron`) | move `extension/tron` aside |
| 2 | Activate | back up `settings/override/site.ini.append.php` to `var/tmp/`, then add `ActiveExtensions[]=tron` | remove the line |
| 3 | Autoloads | `<php> bin/php/ezpgenerateautoloads.php -e` | the same after the undo of 2 |
| 4 | Caches and workers | `./console exp:velocity deploy --allow-root-user` where Velocity serves; else `--clear-tag=ini` and `systemctl reload <the site's FPM>` | the same |
| 5 | Tables | `<php> extension/tron/bin/php/tron.php install --siteaccess=<sa> --dry-run`, read the SQL, then without `--dry-run` | see "Remove the tables" |
| 6 | Status | `<php> extension/tron/bin/php/tron.php status --siteaccess=<sa>`: programs loaded, tables present, PASS | |
| 7 | Roles | in the admin, give `tron/use` and the program functions to the roles that need them (doc/security.md) | remove the policies |

Nothing is enabled over HTTP by these steps: the endpoint answers 404 until `[HTTPSettings]` says otherwise.

## Verify

```bash
<php> bin/php/ezexec.php extension/tron/tests/kernel/kernel_probe.php --allow-root-user          # PASS
TRON_TOKEN=<test admin token> <php> extension/tron/tests/kernel/stdio_smoke.php -- <php> extension/tron/bin/php/tron.php serve --siteaccess=<sa> --allow-root-user
<php> extension/tron/tests/kernel/stdio_smoke.php --write -- <same server command>                 # one ticket, created and closed
<php> extension/tron/bin/php/tron.php audit:tail --siteaccess=<sa> --limit=20
```

Use a throwaway test administrator for the smoke (and remove it afterwards, as the test-throwaways standard says),
never the shared admin account. Then the site's front page and admin still answer 200.

## Operate

| Task | Command |
|---|---|
| Hand a token to a session | `tron.php token:create --siteaccess=<sa> --token-file=<issuer token> --user=<login> --name="claude@<node>" --days=90 [--scopes=centre] --out=/root/.config/tron/<name>.token` |
| List tokens | `tron.php token:list --siteaccess=<sa> [--user=<login>]` |
| Revoke | `tron.php token:revoke --siteaccess=<sa> <id or prefix>` (immediate) |
| Audit | `tron.php audit:tail --siteaccess=<sa> [--limit=100] [--user=<login>]`, or the file sink with `tail -f var/log/tron-audit.log` |
| Status | `tron.php status --siteaccess=<sa>` (exit 1 when a table is missing or a program failed) |
| Call one tool | `tron.php call <tool> --siteaccess=<sa> --token-file=<token file> --json='{"limit":5}'` |
| Leave a program out | `[ProgramSettings] Disabled[]=<name>`, clear the INI cache, redeploy |
| Open HTTP | doc/hosting.md |

## Upgrade

Pull the new release into `extension/tron`, regenerate autoloads, deploy, run `tron.php install` (it only adds what is
missing) and `tron.php status`, then the probe and the smoke. A release's changelog names any table change.

## Roll back

1. `[HTTPSettings] Enabled=false` (if it was opened), clear the INI cache.
2. Remove `ActiveExtensions[]=tron`, regenerate autoloads, deploy. Exponential Tron is now inert: no module, no tools.
3. Leave the tables: they hold the audit and tokens and do no harm. Move `extension/tron` aside if the code must go.

### Remove the tables (only on the owner's word)

The seven tables and nothing else; dump them first:

```sql
DROP TABLE tron_centre_history; DROP TABLE tron_centre_link; DROP TABLE tron_centre_comment;
DROP TABLE tron_centre_ticket; DROP TABLE tron_switch; DROP TABLE tron_audit; DROP TABLE tron_token;
```

## Publishing (only when the owner says so)

Nothing here is done before the owner approves the repository. A pushed tag is permanent; read the repository rules on
releases first.

1. Create the repository (private) and push `main`, no tag:

   ```bash
   gh repo create se7enxweb/tron --private --source=. --remote=origin
   git push -u origin main
   git ls-remote origin main          # the remote has the same commit as local main
   ```

2. Second step, after the repository exists: description, homepage (the Composer package) and topics. The description
   is 336 characters (GitHub allows about 350):

   ```bash
   gh repo edit se7enxweb/tron \
     --description 'tron: the open MCP server layer of Exponential CMS. AI agents work inside your site as real users, with their roles and policies, per-user tokens and a complete audit log. stdio and streamable HTTP (MCP 2024-11-05 to 2025-11-25), programs auto-loaded from extensions, Setup > tron admin views. PHP 7.4 to 8.5, no dependencies, GPL-2.0+.' \
     --homepage 'https://packagist.org/packages/se7enxweb/tron'
   gh repo edit se7enxweb/tron \
     --add-topic mcp --add-topic model-context-protocol --add-topic mcp-server --add-topic exponential \
     --add-topic exponential-cms --add-topic cms --add-topic php --add-topic ai-agents --add-topic llm \
     --add-topic claude --add-topic json-rpc --add-topic streamable-http --add-topic audit-log --add-topic rbac \
     --add-topic access-tokens --add-topic legacy-extension --add-topic composer-package --add-topic gpl
   gh repo view se7enxweb/tron --json description,homepageUrl,repositoryTopics     # check what GitHub kept
   ```

3. Packagist reads public repositories only: submit `https://github.com/se7enxweb/tron` there when the repository
   becomes public, add the GitHub webhook Packagist shows, and check that `https://packagist.org/packages/se7enxweb/tron`
   lists the branch. Until then the homepage link answers 404.

4. A release: the version chosen from the tags that exist (`git tag -l 'v*' --sort=version:refname`,
   `gh release list`), the same number in VERSION, `tronVersion::VERSION`, ezinfo.php, extension.xml, composer.json and
   the program manifests (the suite checks they agree), the suite and the PHP matrix green, then the tag.

## Troubleshooting

| Symptom | Cause |
|---|---|
| `serve` exits "does not hold the policy tron/use" | give the user's role `tron/use` |
| `serve` exits "missing tables" | `tron.php install` |
| HTTP 404 | `[HTTPSettings] Enabled` or `SiteAccesses[]`, or the node lacks Exponential Tron (code drift) |
| HTTP 401 with a token that works on stdio | the Authorization header is dropped before PHP (doc/hosting.md, Apache) |
| HTTP 403 "Origin" | a browser-based client: list its origin in `AllowedOrigins[]` |
| HTTP 421 | `AllowedHosts[]` does not list the host |
| A program shows `failed` | `tron.php status` gives the reason; regenerate autoloads after adding a program |
| A role change not seen | a running stdio server keeps its answers; restart the client |
| Composer platform error on start | use the PHP the installation needs (8.5 on alpha and se7enx.com) |

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
