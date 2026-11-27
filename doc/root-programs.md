# Root programs: web, browser, validate, diff, api

Five programs that every Exponential Tron installation carries, the base that skills are built on. Each tool below is
schema-declared (input and output, so the contract tests and the fuzzer cover it), runs as the calling Exponential
user under a policy of its own, is redacted on the way out, audited and journaled like every other tron tool, and a
tool that changes something outside tron has a dry run (`tron_plan` / `tron_apply`).

Tool names follow Exponential Tron's rule `<program>_<name>`: what a skill may call `web.fetch` is the tool `web_fetch`.

| Program | Policy (module tron) | Tools | Page |
|---|---|---|---|
| web | `web` | 4 | [web.md](web.md) |
| browser | `browser`, `browser_install` | 9 | [browser.md](browser.md) |
| validate | `validate` | 12 | [validate.md](validate.md) |
| diff | `diff` | 9 | [diff.md](diff.md) |
| api | `api_read`, `api_write`, `api_http` | 7 | [api.md](api.md) |

## Order of use: install a browser engine first

**A browser engine must be installed before any `browser_*` tool uses it.** Installing is its own explicit step, never
an implicit download inside a call:

1. `browser_engines` lists the engines (`chromium`, `chrome`, `firefox`, `webkit`) and which are installed, with
   their versions and paths.
2. `browser_install {"engine": "firefox"}` installs one (or `xsh tron:browser:install firefox` on the runner node).
   It runs on the runner node only (CLUSTER), into Exponential Tron's fixed path, at the Playwright version pinned in
   `[BrowserSettings]`, load-gated and journaled. It adopts a build that is already on the box before downloading.
3. Every other `browser_*` call (and `validate_a11y`) takes `engine`; an engine that is not installed is refused with
   `engine '<e>' is not installed: run browser_install {"engine":"<e>"} first`.

## The SSRF guard (web, browser, validate_links, api)

Every outbound request goes through `tronWebGuard`, the same for every program:

- http and https only; no user name or password in the URL; ports 80 and 443 (plus `[WebSettings] AllowedPorts[]`).
- The host is resolved once and **every** address must be public. Refused: loopback, private (10/8, 172.16/12,
  192.168/16), the installation's mesh (`[WebSettings] MeshNetworks[]`, named separately), carrier-grade NAT 100.64/10, link-local 169.254/16 and
  fe80::/10, cloud metadata addresses (169.254.169.254, 169.254.170.2, 100.100.100.200, fd00:ec2::254), unspecified,
  multicast, reserved, documentation and benchmark ranges, unique-local fc00::/7, and IPv4 inside IPv6 (mapped, NAT64,
  6to4) by the IPv4 address it carries.
- Names that are never public are refused before resolving: `localhost`, `*.localhost`, `*.local`, `*.internal`,
  `*.lan`, `*.home.arpa`, numeric host forms other than a canonical IP (`0x7f.1`, `2130706433`, `127.1`).
- The connection is pinned to the checked address (no second DNS answer is used: DNS rebinding), and the address
  curl actually connected to is compared with it afterwards.
- Every redirect hop is checked again from the start; at most `[WebSettings] MaxRedirects` hops.
- An exception is only possible by naming a host in `[WebSettings] PrivateHosts[]` (an administrator's choice).

## Uniform validator result

Every `validate_*` tool answers in one shape:

```json
{ "validator": "html", "valid": false,
  "counts": { "error": 1, "warning": 2, "info": 0 },
  "findings": [ { "severity": "error", "rule": "html.duplicate-id", "message": "The id 'main' is used 2 times",
                  "location": { "line": 12, "column": 5, "path": null } } ],
  "meta": { } }
```

`severity` is `error`, `warning` or `info`; `valid` is true when there is no error. `rule` is stable: skills may match on it.

## Tools

### web (policy tron/web)

| Tool | Input | Output |
|---|---|---|
| `web_fetch` | **url**, method (`GET`, `HEAD`), extract (`auto`, `text`, `links`, `metadata`, `json`, `xml`, `feed`, `raw`), max_bytes, timeout, max_chars, cache (bool) | url, final_url, status, content_type, bytes, truncated, cached, redirects[], robots, and per extract: title, text, links[], metadata{}, json, xml{}, feed{}, raw |
| `web_url_check` | **url** | url, allowed, reason, rule, host, addresses[{address, kind}] (nothing is fetched but DNS) |
| `web_robots` | **url**, agent | url, robots_url, status, allowed, rule, crawl_delay |
| `web_search` | **query**, count | query, provider, results[{title, url, snippet}] (only where the expai search provider is configured) |

### browser (policy tron/browser; install: tron/browser_install)

Jobs run on the runner node (`[BrowserSettings] RunnerHosts[]`, CLUSTER), one at a time per browser pool, load-gated,
with a timeout. Every run tool takes `engine` (default `[BrowserSettings] DefaultEngine`) and `wait` (seconds to wait
for the result, 0 to 60): an answer with `state: "queued"` or `"running"` is picked up with `browser_job`.

| Tool | Input | Output |
|---|---|---|
| `browser_engines` | (none) | node, runner, playwright, path, engines[{engine, installed, version, build, path}], queue{queued, running} |
| `browser_install` (write) | **engine** | job_id, engine, state; the job adopts or downloads the engine |
| `browser_open` | **url**, engine, width, height, scale, color_scheme, wait | job: status, title, final_url, timings, console_errors |
| `browser_screenshot` | **url**, engine, full_page, selector, color_schemes[`light`,`dark`], widths[] (e.g. 1440, 960), scale (e.g. 2), wait | job: artifacts[{name, width, scale, color_scheme, path, bytes, sha256}] |
| `browser_dom` | **url**, engine, what (`text`, `links`, `forms`, `all`), selector, wait | job: text, links[], forms[] |
| `browser_console` | **url**, engine, wait | job: messages[], errors[], page_errors[], failed_requests[] |
| `browser_a11y` | **url**, engine, color_scheme, wait | job: the uniform validator result (validator `a11y`) |
| `browser_flow` (write) | **steps**[{action: `goto`, `click`, `fill`, `press`, `wait_for`, `expect_text`, `screenshot`; selector, value, url}], engine, wait | job: steps[{action, ok, ms, error}], artifacts[] |
| `browser_job` | **job_id**, wait | job_id, kind, state (`queued`, `running`, `done`, `failed`), result, error, artifacts[] |

### validate (policy tron/validate)

| Tool | Input |
|---|---|
| `validate_html` | html or url |
| `validate_css` | **css** |
| `validate_json` | **json**, schema (a JSON Schema as text or object) |
| `validate_xml` | **xml**, xsd |
| `validate_yaml` | **yaml** |
| `validate_ini` | **ini**, file_name (Exponential INI rules, `.ini.append.php` wrapper) |
| `validate_tpl` | template or path (through the `tpl` program's lint where installed) |
| `validate_php` | **php** (php -l in a sandbox directory, with a time limit) |
| `validate_links` | url or html + base_url, max_links, delay_ms, same_host_only |
| `validate_a11y` | **url**, engine, wait (a `browser_a11y` job) |
| `validate_format` | **kind** (`email`, `url`, `phone`), **values**[] |
| `validate_seo` | html or url |

### diff (policy tron/diff)

Renderers (`format`): `unified`, `side_by_side`, `ansi`, `html` (admin4 classes, see [diff.md](diff.md)), `ops`.

| Tool | Input | Output |
|---|---|---|
| `diff_text` | **left**, **right**, granularity (`line`, `word`, `char`), algorithm (`myers`, `patience`), context, ignore_whitespace, format | summary{added, removed, unchanged}, hunks[], rendered |
| `diff_json`, `diff_xml`, `diff_ini`, `diff_yaml` | **left**, **right**, format | changes[{op: `add`, `remove`, `change`, path, from, to}], summary, rendered |
| `diff_merge3` | **base**, **left**, **right**, labels | merged, clean, conflicts[], rendered |
| `diff_content_versions` | **object_id**, from_version, to_version, language | object, from, to, changes[], summary |
| `diff_class` | **left**, **right** (class identifiers) | changes[], summary |
| `diff_layout` | **left**, **right** (layout ids; `draft:<id>` for a draft) | changes[], summary |

### api (policies tron/api_read, tron/api_write, tron/api_http)

Secrets are never written into a call: a call names an expSecrets entry (`key_secret`, `secrets[].name`), the value is
read inside Exponential Tron, sent, and masked in every answer, audit entry and journal line. Only names on
`[ApiSettings] SecretNames[]` can be used.

| Tool | Policy | Input | Output |
|---|---|---|---|
| `api_endpoints` | api_read | (none) | base_url, rest_v1{available, ...}, rest_v2{available, ...}, graphql{available, ...} |
| `api_rest_v1` | api_read | **path** (below `/api/ezp/v1`), query{}, key_secret | status, headers, json, ms |
| `api_rest_v2` | api_read | **path** (below `/api/ezp/v2`), query{}, key_secret | status, headers, json, ms |
| `api_rest_v2_write` (write) | api_write | **method** (`POST`, `PUT`, `DELETE`), **path**, body{}, key_secret | status, headers, json, ms |
| `api_graphql` | api_read | **query**, variables{}, operation_name, key_secret (queries only) | status, data, errors |
| `api_http_request` (write) | api_http | **method**, **url**, headers{}, body or json, secrets[{name, header, format}], timeout | status, headers, body, json, ms, redirects |
| `api_http_inspect` | api_read | **url**, method (`GET`, `HEAD`) | status, http_version, timings{dns, connect, tls, ttfb, total}, headers, tls{version, subject, issuer, expires}, redirects[], primary_ip |

## Availability on alpha (2026-11-24)

- REST v1: available (ezprestapi, `/api/ezp/v1`).
- REST v2: available on this installation (ezprestapi answers reads at v1 and v2, writes at v2 only). On an
  installation without ezprestapi, `api_endpoints` reports it as not available and the v2 tools are left out.
- GraphQL: available (`/api/graphql`, kernel provider `expGraphQLRestProvider`).

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
