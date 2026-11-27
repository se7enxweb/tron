# Troubleshooting

Start every diagnosis with these two commands; they answer most questions:

```bash
php extension/tron/bin/php/tron.php status --siteaccess=<sa> [--allow-root-user]      # programs, tools, tables
php extension/tron/bin/php/tron.php audit:tail --siteaccess=<sa> --limit=20            # what happened, with outcomes
```

Every entry below was seen on a real installation (see [testing.md](testing.md)) or is guarded by a test.

## Starting and connecting

| Symptom | Cause | Fix |
|---|---|---|
| A CLI command prints "Running scripts as root may be dangerous" and does nothing | the kernel refuses root without the flag | add `--allow-root-user`, also in an MCP client's `serve` command |
| `serve` exits "does not hold the policy tron/use" | the user's roles lack `tron/use` | grant it (doc/guides/site-admin.md) |
| `serve` exits "missing tables" | Exponential Tron was never installed here, or not on this database | `tron.php install --siteaccess=<sa>` |
| "Composer detected issues in your platform" | the PHP is older than the installation needs | run the PHP the site runs (for 6.0.15 installations: 8.4 or later) |
| The MCP client shows the server "failed" and nothing else | the server exited before answering; its reason is on stderr | run the same command in a shell; `tests/kernel/stdio_smoke.php` prints the reason |
| A program shows `failed` in status | its reason is in the row: class not found, an exception in register(), a bad manifest | after adding a program: `php bin/php/ezpgenerateautoloads.php -e`; fix what the reason says |
| A tool is missing from tools/list | the user lacks its policy, the token's scope excludes its program, it is switched off, or its program left it out | `tron_status` lists `not_registered` tools with the reason; check roles and the token's scopes |

## HTTP

| Symptom | Cause | Fix |
|---|---|---|
| 404 from `/tron/mcp` | `[HTTPSettings] Enabled` is not `true`, the siteaccess is not in `SiteAccesses[]`, or this node lacks Exponential Tron | settings; deploy tron to every serving node before traffic reaches it |
| 405 `{"status":405,"error":"not allowed","reference":...}` | the request shield's rule EXP-POST, before tron | include `doc/request-shield/tron.rules` (doc/hosting.md) |
| 405 from tron itself (`Allow: POST`) | the client sent GET or DELETE | tron offers no event stream; clients POST |
| 401 although the token works on stdio | Apache with PHP-FPM dropped the Authorization header | `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` (doc/hosting.md) |
| 401 | no token, a wrong, expired or revoked token, or a disabled user | `audit:tail` shows the reason with the token's prefix |
| 403 "Origin" | a browser-based client | list its origin in `AllowedOrigins[]` |
| 403 "tron/use" | the token's user lacks `tron/use` | grant it |
| 421 | the Host is not in `AllowedHosts[]` | add the host |
| 415 | the body is not `application/json` | send JSON |
| 400 "unsupported MCP-Protocol-Version" | the client speaks a version tron does not | update tron or the client |
| The request hangs until the client gives up, only under Velocity | before 0.0.0.1's fix the view closed Velocity's output capture | use tron with that fix (testing.md, defect 10) |
| Login pages answer 429 "One moment, please" | the request shield's browser check on sign-in pages | normal; not tron |

## Calls

| Symptom | Cause | Fix |
|---|---|---|
| "Permission denied: this tool needs the policy ..." | the user's roles lack the policy | grant it, or use a user who has it |
| "Invalid arguments: ..." with the schema | the arguments break the tool's schema (type, bounds, pattern, unknown name) | correct them; the schema is in the answer |
| "The audit log is unavailable, so this write tool was not run" | no sink took the started entry: the table is missing, or the file is not writable by this process | `tron.php status`; give the file to the web server's user (`chown --reference=var/log var/log/tron-audit.log`) |
| "The tool ... failed with an internal error" | an exception; the details are in the server log (eZDebug / stderr) | read the log at that time; it names the class and message |
| An answer ends with "the answer was cut at ... bytes" | it exceeded `ResponseMaxBytes` | page with `limit` and `offset` |
| A role change does not show | a running stdio server keeps its permission answers | restart the client |

## Data and database

| Symptom | Cause | Fix |
|---|---|---|
| A second `install` fails with "relation already exists" | Exponential Tron before 0.0.0.1's fix asked the kernel's table list, which on PostgreSQL holds only `ez*` tables | use tron with the fix (testing.md, defect 1) |
| "tron's schema has no statements for the engine ..." | Oracle and MongoDB are not supported yet | planned; see the plan |
| Katalyst tools absent | sevenx_katalyst is not active in this installation, or `[KatalystSettings] Source=none` | expected outside the Katalyst installation |

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
