# tron documentation

tron brings the ordinary MCP connector up to what a content platform needs, and hands control back to the people who
run it: every call an agent makes runs as a real Exponential user under that user's roles and policies, every tool
can be switched on or off, every token expires and can be revoked, and every call is audited. This is the map of its
documentation. Every page lives in this directory; the README at the top of the extension is the overview.

## Start here

| Page | For |
|---|---|
| [quickstart.md](quickstart.md) | Five minutes from installation to the first tool call |
| [getting-started.md](getting-started.md) | The same path with the reasons, about fifteen minutes |
| [clients.md](clients.md) | Claude Code, Claude Desktop, Cursor, VS Code and any other MCP client, over stdio, SSH and HTTP |
| [use-cases.md](use-cases.md) | Worked end-to-end examples: tickets, triage, decisions, links, reports, reviews |
| [prompt-cookbook.md](prompt-cookbook.md) | Prompts that work well with tron's tools, and the ones to avoid |
| [faq.md](faq.md) | Short answers |

## Guides by role

| Page | For |
|---|---|
| [guides/agent-operator.md](guides/agent-operator.md) | People who run agents: connect, keep the power small, see what they did |
| [guides/site-admin.md](guides/site-admin.md) | People who run the installation: roles, tokens, endpoint, audit, Setup > tron |
| [guides/program-author.md](guides/program-author.md) | Extension authors: a program step by step |

## Running tron

| Page | Covers |
|---|---|
| [configuration.md](configuration.md) | Every tron.ini setting, the settings tron adds elsewhere, recipes |
| [cli.md](cli.md) | `tron.php` and `tron-schema.php`, every command and option, the test and smoke scripts |
| [admin-views.md](admin-views.md) | Setup > tron: dashboard, tokens, programs and tools with switches, audit with live tail, settings |
| [agent-rules.md](agent-rules.md) | The agent rules layer: the site's AGENTS.md, the shipped MASTER.md that supersedes it, overrides, every off-switch, the questionnaire and the expai wizard |
| [tokens.md](tokens.md) | Token anatomy and lifecycle: create, hand out, scope, rotate, revoke, expire, leak response |
| [permissions.md](permissions.md) | Policies, the tool-by-policy and role-by-tool matrices, limitations, scopes |
| [audit.md](audit.md) | Sinks, fields, outcomes, masking, reading with the CLI, the admin view, `jq`, SQL, fail2ban, retention |
| [activity-journal.md](activity-journal.md) | What tron did, as a story: sessions, intent, steps, objects, diffs, background jobs, hash chain, Setup > tron > Activity |
| [http-transport.md](http-transport.md) | The HTTP endpoint: switching it on, the checks and statuses, a curl session, browsers |
| [hosting.md](hosting.md) | stdio over SSH on a private mesh, public HTTPS behind a load balancer, Apache, request filter |
| [multi-site.md](multi-site.md) | Several siteaccesses, separate databases, several installations, clusters |
| [runbook.md](runbook.md) | Install, verify, operate, roll back, each step with its undo; publishing |
| [upgrading.md](upgrading.md) | Version numbers, the upgrade procedure, rolling back, notes per version |
| [troubleshooting.md](troubleshooting.md) | Symptoms, causes and fixes for starting, HTTP, calls and data |
| [performance.md](performance.md) | Measured costs, what drives them, tuning, growth |
| [compatibility.md](compatibility.md) | Exponential, PHP 7.4 to 8.5, databases, web servers, Velocity, clients, optional extensions |
| [security.md](security.md) | Fail-closed design, tokens, policies, transports, audit, data that leaves, threat notes |

## Reference

| Page | Covers |
|---|---|
| [tools.md](tools.md) | Every tool, resource and prompt with arguments and answers; error forms |
| [root-programs.md](root-programs.md) | The root programs web, browser, validate, diff, api: every tool with its input and output, the SSRF guard, install-then-use for browsers |
| [web.md](web.md) | web: fetching public addresses under the SSRF guard and robots.txt, extraction, search |
| [browser.md](browser.md) | browser: installing engines first, queued headless jobs on the runner node, screenshots, DOM, console, accessibility, flows |
| [validate.md](validate.md) | validate: twelve validators with one result format and stable rule ids |
| [diff.md](diff.md) | diff: Myers and patience, structured diffs, three-way merge, renderers, the admin HTML component |
| [api.md](api.md) | api: REST v1, REST v2, GraphQL with named secrets, HTTP requests to allowlisted hosts, the HTTP inspector |
| [units.md](units.md) | The units: small read tools with typed input and output, the type catalog, pipes with tron_pipe |
| [dry-run.md](dry-run.md) | Dry runs: the exact change a write would make, then tron_apply makes exactly that change or refuses |
| [policy-simulator.md](policy-simulator.md) | The policy simulator: tron_policy_check answers "could this user do that?" from the real roles, with the reason |
| [contract-testing.md](contract-testing.md) | Contract testing: input and output schemas of every tool, the fuzzer, its worlds and checks, what it found |
| [redaction.md](redaction.md) | Redaction: secrets and personal data masked in every answer, counted in the audit, settings |
| [units-roadmap.md](units-roadmap.md) | The waves of units: write units with a dry run, data movers, recipes, planning from expai |
| [system-adapters.md](system-adapters.md) | The system adapters: any fetch function, operator, module view, reviewed command and the queue through a few generic tools |
| [api-coverage.md](api-coverage.md) | Every API point of the installation (generated), its risk class and the tool that covers it |
| [programs.md](programs.md) | The program convention: manifest, class, environment services, testing |
| [skills.md](skills.md) | Skills: reviewed chains of units as data; lifecycle, tools, wiring, ops, the harness, packs, remixing |
| [extension-conventions.md](extension-conventions.md) | The standard for skill packs and programs shipped from extensions: layout, settings, naming, manifests, versions, testing, security, remix; a worked example |
| [skill-packs/](skill-packs/) | The starter skill packs, one page each |
| [architecture.md](architecture.md) | Core, programs, transports, the request path, data, kernel binding |
| [protocol.md](protocol.md) | The protocol layer: the official MCP PHP SDK, the frozen legacy layer, the official conformance suite |
| [theory.md](theory.md) | What tron guarantees, each claim tied to the test that proves it |
| [testing.md](testing.md) | The suite, the PHP matrix, kernel probe and smoke tests, proving runs, defects found and fixed |
| [cookbook.md](cookbook.md) | Eight runnable examples whose output the suite checks byte for byte |
| [glossary.md](glossary.md) | The terms used across these pages |

## Project

| Page | Covers |
|---|---|
| [roadmap.md](roadmap.md) | Waves 1 to 4: what is built and what is planned |
| [contributing.md](contributing.md) | Ground rules, setup, a change step by step, commit messages, versions, security reports |
| [changelog/0.0.0.1.md](changelog/0.0.0.1.md) | The first version |
| [changelog/0.0.0.12.md](changelog/0.0.0.12.md) | The first public release |
| [changelog/0.0.0.13.md](changelog/0.0.0.13.md) | Private names end where a word ends |
| [changelog/0.0.0.15.md](changelog/0.0.0.15.md) | the official MCP PHP SDK, the search program, 202 skills parameterised, command lines need a token |
| [changelog/0.0.0.14.md](changelog/0.0.0.14.md) | `serve --user` disabled by default; stdio needs a token |

## Files that are not pages

| File | What it is |
|---|---|
| [request-shield/tron.rules](request-shield/tron.rules) | Rules for exp_request_shield that let MCP POSTs reach `/mcp` and `**/tron/mcp` ([hosting.md](hosting.md)) |

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
