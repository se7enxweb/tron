# The browser program

Headless Chromium, Chrome for Testing, Firefox and WebKit, as queued jobs on the runner node. Policies:
`tron/browser` for every job tool, `tron/browser_install` for installing an engine. Tool table:
[root-programs.md](root-programs.md#browser-policy-tronbrowser-install-tronbrowser_install).

## Install first, then use

**An engine is installed before it is used, as its own explicit step.** No browser tool downloads anything.

1. `browser_engines`: which engines are installed, their version, build, path and where they came from.
2. `browser_install {"engine": "firefox"}` (a write tool; `tron_plan` shows what it would do), or on the runner node
   `xsh tron:browser:install firefox`. It:
   - checks the load of the node first (it waits while the load per CPU is above the limit, and gives up after ten
     minutes);
   - asks Playwright (pinned: `[BrowserSettings] PlaywrightVersion`, the runner's version) which build it needs;
   - **adopts** that build when it is already on the node (another Playwright cache, e.g. root's, when run through
     xsh), else lets Playwright download it, into Exponential Tron's own directory `[BrowserSettings] BrowsersPath`
     (`/var/lib/tron/browsers`) only;
   - starts the engine once as proof and records its version and the sha256 of its executable in `engines.json`.
     The runner checks that checksum again before every job, so a changed binary is refused.
3. Every other browser tool, and `validate_a11y`, takes `engine`. An engine that is not installed is refused before
   anything is queued: `The browser engine 'webkit' is not installed: run browser_install {"engine":"webkit"} first`.

| engine | what Playwright installs | launched as |
|---|---|---|
| `chromium` (default) | `chromium-headless-shell` | headless shell |
| `chrome` | `chromium` (Chrome for Testing) | `channel: chromium`, the new headless mode of the full browser |
| `firefox` | `firefox` | Playwright's Firefox |
| `webkit` | `webkit` | Playwright's WebKit (needs its system libraries; the install's proof start says when they are missing) |

Google's branded Chrome is installed system-wide by its own packages, outside Exponential Tron's directory, so it is not one of
tron's engines; Chrome for Testing is the same browser built for automation.

## Where jobs run

Only on the nodes of `[BrowserSettings] RunnerHosts[]` (CLUSTER on the 7x set). On any other node every browser
tool is left out with the reason, so a load-balancer node never starts a browser. On the runner node:

- `xsh tron:browser:setup` creates the browsers directory and the queue (`var/tron/browser`) for the runner's user
  and installs two systemd units: `tron-browser.path` starts `tron-browser.service` when a job is queued; the
  service runs `bin/python/tron_browser_runner.py run --once` as the runner's user, niced, `CPUQuota=300%`,
  `MemoryMax=3G`.
- One browser pool: a lock file lets one runner work at a time; jobs run one after the other, oldest first.
- Load-gated: while the load per CPU is above `--max-load` (2.5) a job waits; after five minutes the runner leaves
  it queued and exits; systemd starts it again.
- Each job has a time limit (`timeout`, at most 300 s; the install 30 minutes).

A tool queues the job and waits up to `wait` seconds (0 to 60). An answer with `state` `queued` or `running` is
picked up later with `browser_job {"job_id": ...}`; a user sees their own jobs, `tron/admin` all of them.

## The guard in the browser

The URL of a job is checked by the SSRF guard before it is queued. In the browser every request the page makes
(documents, scripts, images, XHR, fetch) passes the runner's copy of the same rules and is aborted when its host is
not public; those requests are listed as `blocked_requests`. For Chromium engines the page's own host is also pinned
to the checked address (`--host-resolver-rules`). Service workers are blocked, downloads refused, TLS errors not
ignored. `[WebSettings] PrivateHosts[]` is passed to the runner as the only exception.

## Jobs

| tool | what it answers |
|---|---|
| `browser_open` | status, final address, title, navigation timings, number of console errors |
| `browser_screenshot` | one PNG per colour scheme and width (e.g. `widths: [1440, 960]`, `scale: 2`, `color_schemes: ["light", "dark"]`), whole page or one element (`selector`); artifacts with size and sha256 |
| `browser_dom` | the rendered text, links, forms (field names, types, labels, never values) |
| `browser_console` | console messages, errors, uncaught page errors, failed requests and HTTP errors of resources |
| `browser_a11y` | accessibility findings in the validators' format: language, title, main landmark, image alt, link, button and field names, duplicate ids, heading order, positive tabindex, focusable elements under aria-hidden, text contrast (WCAG AA), horizontal overflow |
| `browser_flow` | a scripted flow (`goto`, `click`, `fill`, `press`, `wait_for`, `expect_text`, `screenshot`), a write tool because a flow can submit forms; it starts with `goto` |

Artifacts stay on the runner node under the queue directory (`artifacts/<job id>/`); answers give their path
relative to it.

## Settings

`[BrowserSettings]`: `RunnerHosts[]`, `BrowsersPath`, `QueueDir`, `PlaywrightVersion`, `DefaultEngine`.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
