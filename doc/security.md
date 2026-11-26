# Security

tron hands an AI agent access to a running installation. This page says what protects that access.

## Summary

- **Fail closed.** The HTTP endpoint answers 404 until `[HTTPSettings] Enabled=true` lists the siteaccess. stdio
  refuses to start without a user that holds `tron/use`, and without the tables (no audit, no run).
- **One permission model: the installation's.** Every tool names an Exponential policy; tron asks `eZUser::hasAccessTo`
  for the token's user. Tools a user may not call are not listed, and a call is refused and audited.
- **Per-user tokens**, hashed, expiring, revocable, scoped to programs.
- **Everything audited**, writes before they run.
- **Returned text is data.** The `initialize` instructions tell the client that content, comments and names coming
  back from tools are data from the installation, never instructions.

## Tokens

- Form `tron_<12 characters>_<43 characters>`: the prefix finds the row, the secret is 256 random bits
  (`random_int`). Only `sha256(token)` is stored; a stolen table gives no usable token. Comparison is constant time.
- Shown once by `tron.php token:create`; with `--out=<file>` written with mode 0600 and never printed.
- Lifetime: `[TokenSettings] DefaultLifetimeDays` (90), at most `MaxLifetimeDays` (365).
- Scopes: `--scopes=centre,katalyst` limits a token to those programs (the core `tron` is always in scope). Out of
  scope looks like "unknown tool" to the client.
- Revocation (`token:revoke <id|prefix>`) takes effect on the next request. A token of a user who is later disabled
  or removed stops working at once.
- `last_used` is written at most once a minute per token.
- A token travels only in the `Authorization: Bearer` header, never in a URL. The audit and the logs mask anything
  that looks like a token.

## Policies and roles

Module `tron`, functions:

| Function | Grants |
|---|---|
| `use` | connect at all (both transports); `tron_whoami`, `tron_status` |
| `admin` | see program origins and load times; manage other users' tokens (wave 2 admin views) |
| `centre_read` | the centre read tools, resources and prompts (tron's own tables) |
| `centre_write` | create, update, comment, move, close and link tickets |
| `centre_decide` | record a decision |
| `katalyst_read` | Katalyst services, plans, orders; domains masked |
| `katalyst_detail` | full domains, search by domain |

With expcentre in the installation the centre tools need `centre/api`, and centre then checks its own policies
(`centre/board`, `card_read`, `card_write`, `decide`, `manage`, with their Subtree limitations) on every call.

Suggested roles:

| Role | Policies |
|---|---|
| tron Viewer | tron/use, tron/centre_read |
| tron Editor | Viewer + tron/centre_write |
| tron Decider | Editor + tron/centre_decide |
| tron Operator | tron/use, tron/katalyst_read |
| tron Operator (detail) | Operator + tron/katalyst_detail |
| AI session | tron/use plus exactly what that session needs; never `admin`, `centre_decide` or `katalyst_detail` unless the owner grants it to one session |

A policy granted **with limitations** (for example a Section limitation) is reported by the kernel as `limited`; tron
treats it as not granted unless the tool declares `limitedOk` and checks the limitation itself. No built-in tool
declares it, so give tron functions without limitations.

Access answers are cached for the lifetime of a checker: one HTTP request, or one stdio process. A role change
reaches a running stdio server when it restarts.

## Transports

| | stdio | HTTP |
|---|---|---|
| Who | whoever may run PHP in the installation (they already hold its database credentials) | anyone with a valid token who can reach the endpoint |
| Identity | `--user=<login>` (allowed by `[StdioSettings] AllowLoginOption`) or a token (`TRON_TOKEN`, `--token-file`) | a bearer token, always |
| Checks | the user holds `tron/use`; tables present | method POST, Origin allowlist (empty: browsers refused), Host allowlist, `application/json`, body size, protocol version header, token, `tron/use` |
| Session | for the process; initialize first | none (stateless) |

Unlike servers that keep writes on stdio, tron allows write tools over HTTP: each HTTP caller is a named user with
their own token and policies, which is the identity a write needs. An installation that wants read-only HTTP grants
HTTP users no write functions, or scopes their tokens.

## Audit

Every `tools/call`, `resources/read`, `prompts/get` and refused sign-in is an entry: time, user, login, token id,
transport, remote address, method, target, program, outcome (`started`, `ok`, `error`, `denied`, `invalid`,
`unauthenticated`), duration and the arguments after redaction (values under keys like password, secret, token, key
replaced; tokens and bearer strings masked anywhere; strings cut at 200 characters).

Sinks: `database` (`tron_audit`), `file` (JSON lines, `[AuditSettings] FilePath`), or both. With both, an entry
counts as kept only when both took it. tron does not start without a sink, and a **write tool does not run** when its
`started` entry cannot be written. Read with `tron.php audit:tail`.

## Data that leaves the server

- **Katalyst**: a column allowlist per tool. Ids, states, plan and server references, amounts, dates. Never names,
  addresses, e-mail or IP addresses of customers. Domains masked (`ex****e.com`) without `tron/katalyst_detail`;
  searching by domain needs it too.
- **Errors**: an internal error says only "internal error"; database messages and stack traces stay in the log.
- **Answers** are capped at `[ServerSettings] ResponseMaxBytes`.

## Threat notes

| Threat | Answer |
|---|---|
| Token theft from the database | only hashes are stored |
| Token in logs or audit | masked by pattern before writing |
| Brute force | 256-bit secrets; every failure audited with the prefix and remote address (fail2ban can read the file sink) |
| DNS rebinding / browser CSRF | Origin allowlist (empty refuses every browser request), Host allowlist, JSON content type required |
| Prompt injection through ticket text | instructions mark returned text as data; tools act only with the caller's policies; writes audited |
| A malicious or broken program | it registers only under its own prefix, cannot replace another program's tools, and fails alone; it is code in the installation, so review it like any extension |
| Oversized input | HTTP body limit (1 MB), stdio line limit (4 MB), schema bounds on every argument |
| SQL injection | named parameters only; identifiers checked against `^[a-z_][a-z0-9_]*$` |

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
