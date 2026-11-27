# Testing

## The suite

```bash
php tests/run.php [filter]
```

`TRON_PROTOCOL=sdk` or `legacy` runs the suite on one protocol layer ([protocol.md](protocol.md)); `auto` (the
default) takes the SDK where it can run. Run it on both before a release; `TRON_SDK_AUTOLOAD` names the SDK's
Composer autoload.php when the checkout has none. The official MCP conformance suite runs with
`tests/conformance/official_run.php` (see protocol.md, "Conformance").

No installation, no network: SQLite in memory, a fixed clock (2026-11-24 12:00 UTC), ten users with the
roles below and made-up Katalyst rows (example.* domains). Scratch files go to `$TRON_TEST_TMP`, by default
`var/tmp/tron-tests` in the checkout (inside an installation set `TRON_TEST_TMP` to a directory outside the
extension). `TRON_TEST_LOG=1` prints the server log.

| File | Proves |
|---|---|
| `tests/unit/CoreUnitsTest.php` | versions (four positions, numeric), files agree (VERSION, ezinfo.php, extension.xml, composer.json, manifests), protocol negotiation, the schema validator, tool/resource/prompt declarations, registry naming and staging, the redactor, eZDB parameter interpolation, schema install (idempotent) and the generated SQL files, PDO round trips |
| `tests/unit/ProgramLoaderTest.php` | the built-in programs; both extension layouts; ten kinds of broken program each failing alone (throwing register, parse error, no interface, no class, file outside, bad JSON, bad name, too new, name clash, foreign prefix) with a healthy one after them; Disabled[]; the core cannot be disabled; ExtensionScan; discovery of 300 extensions with 50 programs under 250 ms (about 3 ms measured) |
| `tests/unit/TokenServiceTest.php` | token form, hash-only storage, scopes, expiry, revocation, disabled users, throttled last_used, listing without hashes |
| `tests/unit/ServerAuditTest.php` | initialize, stdio initialize rule, every JSON-RPC error (each layer's own), batches per layer and version, pagination, results and structuredContent per version, cut answers, internal errors not leaked, every call audited with outcome, write tools refused without audit, database and file sinks |
| `tests/unit/CentreToolsTest.php` | every centre tool, resource and prompt on Exponential Tron's tables, with their validation errors |
| `tests/unit/CentreBridgeTest.php` | the 29 contract tools in front of a stand-in expCentreApi: pass-through as the session user, error mapping, tron's gate before centre, the Backend setting |
| `tests/unit/KatalystCoreToolsTest.php` | the three Katalyst tools: column allowlist, masking, detail policy, filters; left out without a source; tron_whoami, tron_status and their resources |
| `tests/unit/PermissionMatrixTest.php` | per role (admin, viewer, editor, decider, operator, detail, useonly, limited, norole, disabled): the exact tools/list, and every tool called by every role (allowed or "Permission denied" plus a `denied` audit row); resources and prompts; token scopes; limited policies; disabled users |
| `tests/unit/HttpTransportTest.php` | the HTTP transport in process: 200, 202, 204, 400, 401, 403 (Origin, tron/use), 405, 413, 415, 421; stateless versions; revocation; scopes; headers from `$_SERVER` including Apache's REDIRECT_HTTP_AUTHORIZATION |
| `tests/conformance/McpConformanceTest.php` | MCP over a real stdio child process (lifecycle, every method, every error code, batch refusal, oversized line, notifications, stdout purity, clean exit, each protocol version) and over HTTP through PHP's built-in server (initialize, notification 202, tools/list, tools/call writing to the database, 401, 405, the audit rows the server process wrote) |

## Every PHP

```bash
php tests/matrix.php                                  # every PHP 7.4+ under /opt/plesk/php and /opt/remi
php tests/matrix.php /opt/plesk/php/7.4/bin/php ...   # or the ones given
```

Lints every PHP file and runs the suite with each binary, on the protocol layer each one picks (the SDK where PHP is
8.2 or newer and `mcp/sdk` is installed or named by `TRON_SDK_AUTOLOAD`). Result of 2026-11-26 for 0.0.0.15: the legacy
layer PASS on 7.4.33, 8.0.30, 8.1.34, 8.2.36, 8.3.37, 8.4.28, 8.5.13 and 8.6.0-dev (`TRON_PROTOCOL=legacy` from 8.2
on); the SDK layer (mcp/sdk 0.10.0) PASS on 8.2.36, 8.3.37, 8.4.28, 8.5.13 and 8.6.0-dev; 244 tests and the contract
fuzzer (24,200 inputs) each time. The official conformance runner passes its generic scenarios on both layers.

## The protocol layer on an installation

```bash
php <tron>/tests/kernel/protocol_live.php --deny=<write tool the user lacks> -- \
    <php> extension/tron/bin/php/tron.php serve --siteaccess=<sa> --token-file=<0600 file>
php <tron>/tests/kernel/protocol_live.php --deny=<write tool the user lacks> --url=https://<host>/tron/mcp --token-file=<0600 file>
```

initialize, every tools/list page, a read tool, a write tool the user's policies refuse (audited `denied`) and a write
tool they allow (`skill_draft` with a manifest the skill standard refuses: audited `started`, then `error`, nothing
stored), and the protocol layer the server reports. Use a short-lived token and revoke it afterwards. Result of
2026-11-26 on the development installation (an editor's token, `--deny=centre_decision_record`): PASS over stdio and over HTTP on the SDK
layer, PASS over stdio on the legacy layer.

## In an installation (read only)

```bash
<php> bin/php/ezexec.php <tron>/tests/kernel/kernel_probe.php --allow-root-user
```

Works before Exponential Tron is installed (it loads tron from the checkout) and writes nothing: eZDB statements with quoting,
limit and errors; users; the administrator holds tron/use, anonymous does not; the convention scan over the active
extensions; tools/list and tools/call through the real policy check. Result on alpha (PostgreSQL, 67 active
extensions) on 2026-11-24: PASS, server built in 43 ms.

After installation, `tests/kernel/stdio_smoke.php` speaks MCP to the real `tron.php serve`,
`tests/kernel/http_smoke.php` to the real HTTP endpoint (statuses, token, write), and
`tests/kernel/kernel_tools_run.php` runs every tool as users with real roles (doc/runbook.md).
`tests/kernel/search_live_run.php` proves the search units with a short-lived token for an editor-level and one for
an anonymous-level user (scope `search`, revoked at the end).

## Proving run on a real installation: alpha, 2026-11-24

The development installation (Exponential 6.0.15, PostgreSQL over the mesh, 68 active extensions, PHP 8.5, served by
PHP-FPM and by Velocity), installed by doc/runbook.md: files changed on the master node, commands run on the serving
nodes after the sync, deploy without Velocity restart. Throwaway users: one administrator and seven users with real
roles made for the run (viewer, editor, decider, operator, detail, use only, none), removed afterwards.

| Step | Result |
|---|---|
| Deploy, serving node 1 | PASS, 18 steps in 359 s (autoloads 61 s, help index 277 s) |
| Deploy, serving node 2 | PASS, 18 steps in 189 s |
| `tron.php install --dry-run` | the CREATE statements for PostgreSQL printed |
| `tron.php install` on PostgreSQL | 6 tables and 11 indexes created in 1.2 s; second run "present" for all six (after defect 1 was fixed) |
| `tron.php status` | 3 programs loaded (centre 22.7 ms, katalyst 3.0 ms, tron 0.3 ms), 17 tools, 4 resources, 2 prompts, tables present: PASS |
| `kernel_probe.php` (installed) | 26 checks PASS; server built in 11.1 ms over 68 extensions |
| `stdio_smoke.php` read / `--write` | 12 / 16 checks PASS; 1.1 s / 1.5 s including PHP start |
| `kernel_tools_run.php` | **86 checks PASS, 0 failed, 17.9 s**: every centre tool on PostgreSQL (including quotes, backslashes, literal `%` and `_`, an SQL injection attempt), 16 failure cases, tools/list and all 17 tools called by each of the 8 users (allowed or denied exactly by the real role, 74 denials each audited), Katalyst on the installation's tables (no unmasked domain, only listed columns), tokens (hash only, scope, revocation), the HTTP transport in process (403 without tron/use), audit (246 rows in the table and 246 lines in the file sink, every write with its started row, no token secret) |
| Site after the install | front page 200; login pages unchanged (the request shield's browser check, as before) |
| Request shield with `tron.rules` included (owner's go) | on each of the three nodes: check ok, 231 of 231 examples |
| `http_smoke.php` through Velocity (serving node 1, port 8080), administrator / viewer | **17 / 13 checks PASS** (after defects 9 and 10 were fixed): initialize 200, notification 202, 17 / 7 tools, whoami over http with the token, create and close (administrator), write refused (viewer), 401 without or with an unknown token, 405 GET, 403 foreign Origin, 415 text/plain, 400 unknown protocol version; median 122 / 135 ms. Velocity's workers had the classes without a restart |
| `http_smoke.php` through the public front (load balancer, PHP-FPM), administrator / viewer | **17 / 13 checks PASS**: the same checks; the Authorization header reaches PHP; median 308 / 523 ms |
| Other POSTs after the shield change | still refused: `/news/...` and `/tron/other` 405 by EXP-POST, `/content/view/full/2` 404 by EXP-SYSVIEW; a token of a user without tron/use: 403 from Exponential Tron |

Median time per tool in the run (each call includes two audit writes, to PostgreSQL over the mesh and to the file):
list, review, katalyst lists, whoami 19 to 23 ms; get, board, status 30 to 38 ms; writes 47 to 97 ms (a write
carries its started row, the change and its history rows).

### Administration views (Setup > tron), 2026-11-24

Deployed to serving node 1 with `--packer` (19 steps PASS, 185 s); the table `tron_switch` created on PostgreSQL. Browser
check (a Playwright script of the development tree, light and dark, 1440 px and 960 px at scale 2) as a
throwaway administrator, and the role test as a throwaway user who signs in to the administration without any Exponential Tron
policy; both removed afterwards.

| Check | Result |
|---|---|
| Six pages (dashboard, tokens, programs, audit, audit filtered, settings) x light/dark x 1440 at 100 % and 960 at 200 %: HTTP 200, the Exponential Tron frame, no console or page error, no failed request, no horizontal overflow, the theme asked for, no white box in dark mode, no inline style | **24 of 24 PASS** through the public front (PHP-FPM) |
| Setup menu entry; a token made through the form, shown once, not shown on the next visit, revoked through the form; a tool switched on through the form; the live tail shows a new audit row within its 5 s poll | **6 of 6 PASS** (the "switch off" step of this run found the tool already off from the run before, which is why one check of that run failed; the run before had switched it off successfully) |
| Role test: all six pages answer 403 and show no tron frame; no tron entry in the user's Setup menu | **8 of 8 PASS** |
| "Verify it's you": the kernel's matcher with the installation's settings asks for `tron/tokens` CreateButton and RevokeButton and `tron/programs` SwitchButton, and not for any page view | **7 of 7 PASS** (a check of the matcher against the administration posts); in the browser the fresh sign-in counted as a recent confirmation, as the kernel intends |
| AdminStylingRulesTest (DB-less suite `design`) | PASS; the suite's 5 other failures are in kernel and theme templates, none in tron |
| tron's own checks of its templates and stylesheet | no `<style>`, no `style=""`; only `--a4-*` tokens, no hard-coded colour, pixel size or font; no new token |
| Translations | 97 strings of `extension/tron`, eng-GB and ger-DE, none missing, both files valid XML |

Through Velocity (port 8080) the same check found the pages served with stylesheet and script bundle names that the
`--packer` deploy had removed (404), and a worker still running the `tronKernel` class from before switches existed.
Both come from deploying with `--no-velocity`: Velocity's workers keep what they loaded at their last start. A
Velocity restart was not done (it needs the owner's go); until then Velocity on that node serves the administration
without its packed stylesheets, and Exponential Tron's switches take effect there only after a restart. tron's own endpoint
through Velocity is not affected by the packed files (its HTTP proofs above passed).

### Administration views, second round, 2026-11-24 (evening)

Token rotation and renewal, user filter forms, the administrators' actions in the audit (transport `admin`), counts
and limited roles on Programs and tools; the views' data split per view (R7). Deployed with `--packer --no-velocity`
on both serving nodes (19 of 19 steps PASS on each); the master tree's own deploy was stopped at its Velocity step
(Velocity does not run on that node; the server it started was stopped again).

| Check | Result |
|---|---|
| `php tests/run.php` (new: AdminDataTest, 8 tests on SQLite for every view's data) | 86 tests, 1408 assertions, PASS |
| `php tests/matrix.php` (lint + suite) | PASS on PHP 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5 (and 8.6) |
| Browser check through the public front (PHP-FPM) as a throwaway administrator: six pages x light/dark x 1440 at 100 % and 960 at 200 %; make, rotate, filter, revoke a token; switch a tool off and on; admin actions listed in the audit; audit user filter; live tail | **63 of 63 PASS** (with the role test below) |
| Role test: no Exponential Tron policy (all six pages 403, no Setup entry); `tron/audit` only (only Audit, only its tab); `tron/tokens` + `tron/audit` (Tokens and Audit only, makes and revokes a token); administrator (everything) | PASS (in the 63) |
| AdminStylingRulesTest | 29 tests, 3119 assertions, OK |
| stdio smoke on a serving node as the throwaway administrator and with the two permanent session tokens | 12 of 12 PASS each (17 tools; 13 for the editor account) |

"Verify it's you" was not asked in the browser run: the fresh sign-in counts as a recent confirmation, as before; the
rule list now has `tron/tokens RotateButton` too.

### Defects found and fixed

| # | Defect | Found by | Fix |
|---|---|---|---|
| 1 | `tronDbKernel::tableExists()` used the kernel's table list, which on PostgreSQL holds only tables named `ez*`: a second `install` failed with "relation already exists", and `serve` would have refused to start ("missing tables") | the second `install` run | ask the engine's catalog (`pg_class` with `pg_table_is_visible`, `information_schema`, `sqlite_master`), shared by both adapters; the probe now checks that `tableExists()` agrees with a real query on every Exponential Tron table and is false for a missing one |
| 2 | `stdio_smoke.php` reported only timeouts when the server exited at once, hiding the server's own message | the first smoke run | it stops at the first missing answer and prints the server's stderr |
| 3 | As root the kernel refuses every command without `--allow-root-user`; the mesh configuration, README and runbook did not say so | the first smoke run | added to every command shown, with a note in the runbook |
| 4 | The runbook said to run commands on the node with the master tree; on a synced set they run on each serving node | the install | corrected |
| 5 | Where exp_request_shield is active, every POST to `/tron/mcp` is answered 405 by its rule EXP-POST, through PHP-FPM and through Velocity | `http_smoke.php` against Velocity | `doc/request-shield/tron.rules` (three rules limited to the endpoint), proven on a copy of the site's rules: check ok, 231 of 231 examples pass; doc/hosting.md says how to include it |
| 6 | The proving harness itself expected the old title after renaming a ticket | the first harness run (85/86) | harness corrected; not a tron defect |
| 7 | A write tool's `started` audit row went to the sinks without its arguments (its detail was empty) | cookbook example 6 | the started row is built like every other row; a unit test checks its detail |
| 8 | A secret written into free text ("password=hunter2" in a ticket body) went into the audit as written; only values under secret-looking keys were masked | cookbook example 6 | `tronRedactor::maskSecretsInText()` masks `name=value` and `name: value` for password, secret, api key, private key, token, credentials; unit test |
| 9 | The audit file, first created by the command line as root, could not be appended to by the web server: every HTTP write tool was refused (as designed: no audit entry, no run) | `http_smoke.php` through Velocity, the server log | the file sink gives a file it creates mode 0660 and, as root, its directory's owner and group; the existing file was given to the site user |
| 10 | The endpoint view closed every output buffer before answering; under Velocity the outermost buffer is the server's capture of the response, so the client waited until its timeout | `http_smoke.php` through Velocity | the view echoes and ends with cleanExit() like the kernel's other raw views, leaving the buffers alone |

## Dry runs, policy simulator, contract fuzzing, redaction: proofs, 2026-11-25

The tests of this wave ran on a tree of the repository's HEAD plus this wave only. On the development installation
(PostgreSQL over the mesh, PHP 8.5), installed by the runbook (files on the master node, `exp:velocity deploy
--no-velocity --packer` on both serving nodes, `tron.php install` added `tron_plan`), with throwaway users and one
scratch folder made for the run and removed afterwards (nothing left).

| Proof | Result |
|---|---|
| `php tests/run.php` (DryRunTest, PolicySimulatorTest, RedactionTest, ContractTest and every earlier test) | all PASS |
| `tests/matrix.php` with `TRON_MATRIX_FUZZ=200` on PHP 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5 | lint, suite (204 tests) and 12 600 fuzz inputs PASS on each |
| `tests/fuzz.php --per-tool=2000` (SQLite, both worlds, 59 tool entries) | 118 400 inputs, 0 problems |
| `tests/fuzz.php --dsn=pgsql:... --per-tool=500` (PostgreSQL 19, rolled back) | 29 900 inputs, 0 problems (after the fixes below) |
| `dry_run_live.php`: a folder edited twice as an editor limited to its subtree | **22 of 22 PASS**: each dry run left the row checksums of `ezcontentobject`, `ezcontentobject_version`, `ezcontentobject_attribute`, `ezcontentobject_name` unchanged; each apply gave exactly the planned value and version (1 -> 2 -> 3); a plan whose target changed in between was refused with no row changed |
| `policy_simulator_live.php`: 20 user x tool cases (administrator, editor limited to a subtree, viewer, use only, no role), simulated in process, live over HTTPS with a token per user | **20 of 20** match: 4 HTTP 403 (no tron/use), 6 refusals by policy or object check, 10 allowed; 5 tokens made and revoked |
| `redaction_canary_live.php`: canaries in a content attribute, a ticket and a comment, the tron.ini instruction (in memory) and a user's e-mail address; 57 tools (228 calls, write tools as 32 dry runs), 4 resources, 4 prompts, the instructions | **no canary** in 239 answers (142 with data, 578 masked values), none in 248 audit rows; the audit counted dsn 35, email 24, key 43, phone 37, secret 32, token 52 |

Defects the fuzzer found and that were fixed in the wave:

1. Ids and positions above 2^31-1 reached PostgreSQL's integer columns as internal errors (centre tools): the integer
   arguments of the centre, content and Katalyst tools are bounded.
2. Strings with invalid UTF-8 from an in-process caller failed in PostgreSQL as internal errors: the schema
   validator refuses them for every tool.
3. A dry run sent through `_meta` was checked against the tool's own output schema (a harness defect, fixed in the
   harness).
4. The redactor left the JSON text of an answer deeper than 64 levels unmasked while it cut the structured copy:
   both are cut now.

Found in other programs' tools and handed to their authors: integer ids above 2^31-1 and invalid UTF-8 on
PostgreSQL in the skill tools, and tools without an output schema or a write without a dry run.

## Search units: proof, 2026-11-26

On the development installation (built-in search engine, immediate indexing), with two throwaway users made for the run and removed
afterwards: an editor-level user (role Editor assigned without a subtree limit, plus tron/use) and an
anonymous-level user (role Anonymous plus a role with tron/use only). `tests/kernel/search_live_run.php` issued each a
token for one day with the scope `search` and revoked both at the end.

| Check | Result |
|---|---|
| `php tests/run.php` | all PASS (SearchUnitsTest 14 tests: restricted content, filters, sort, paging, facets with and without engine support, redaction of excerpts, private engine names, token scopes, audit, a pipe) |
| `search_live_run.php` | **29 of 29 PASS** in 42 s |
| "archives" as each user | editor 1 hit (the folder of the section Restricted), anonymous-level 4 hits (public help pages the editor role may not read); the folder never reaches the anonymous-level user, neither in the items nor in the total, also not with `section` + `class` filters; `search_index` on it answers "not found" for that user |
| every hit of both users | passes the kernel's own `checkAccess( 'read' )` for that user; the totals equal the hits paged through |
| "exponential" (8 hits) | offset/limit pages, sort modified desc, sort name asc equal to name desc reversed, language eng-GB, subtree |
| facets on the built-in engine | `facets_supported` false and a note; no invented counts |
| token scope | tools/list of a `search` token shows the two search tools and not `node_read`; a `node_read` call is refused |
| audit | 15 rows under the two tokens |


## Skills dedupe: proof, 2026-11-26

202 skills that differed from another only by a built-in value (a label, lane, kind, day window, priority, class,
siteaccess, INI file or setting, template, view, module) were replaced by 27 parameterised skills (358 skills before,
183 after; 26 new names, `class.containers` kept its name and took `class.leaves` in). Field and sort-key parameters
(`centre.count.by --by lane`) use the new `$param` support of the ops `field` and `by`.

| Check | Result |
|---|---|
| structural | for every old skill, its value put into the new manifest's parameters gives exactly the old steps, output, guards and requires (202 of 202) |
| runtime, fixtures | every example of every old skill run with the old manifest and with the new one plus the old value: 365 of 365 runs equal (268 answered, 97 the same error where the test world lacks the data) |
| runtime, the development installation (read only, throwaway administrator, removed afterwards) | 365 of 365 runs equal, all 365 answered |
| harness | the 12 changed packs proven on the fixtures and live: 151 skills pass, none failed or parked |
| `php tests/run.php` (a test node, PHP 8.5) | 237 tests PASS |


---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
