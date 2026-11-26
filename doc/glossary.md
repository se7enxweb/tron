# Glossary

**Agent.** An AI model acting through an MCP client (Claude Code, Claude Desktop, Cursor, a script). To tron it is
always an Exponential user.

**Audit, audit entry.** The record of every call, resource read, prompt, refused sign-in and administration token
action: who, how, what, outcome, duration, redacted arguments. See [audit.md](audit.md).

**Audit sink.** Where entries go: `database` (table `tron_audit`) and/or `file` (JSON lines).

**Backend (centre).** Which ticket store the centre program uses: tron's own tables, or the centre extension's API
(`[CentreSettings] Backend`).

**Bearer token.** A token sent as `Authorization: Bearer <token>` over HTTP.

**Board, lane.** The centre program's ticket layout: boards hold lanes (columns, in order, from `Lanes[]`), lanes hold
tickets by position.

**Core program.** The built-in program `tron` (`tron_whoami`, `tron_status`). Always loaded, always in every token's
scope, cannot be switched off.

**Decision, decision week.** A ticket of kind `decision`, due in an ISO week (`2026-W48`); reviewed weekly and recorded
by a user with `centre_decide`.

**Exponential.** The content management system tron is an extension of (legacy kernel, 6.x).

**Fixture, test world.** The in-memory installation of the test suite: SQLite, fixed clock, ten users with roles.

**Initialize.** The first MCP request of a session: protocol version negotiation, capabilities, server info and
instructions.

**Instructions.** Text tron returns in `initialize`: the user the agent acts as, the number of tools, the audit, and
that returned text is data. `[ServerSettings] Instructions` adds to it.

**JSON-RPC.** The message format of MCP: requests with an `id`, notifications without, answers with `result` or
`error`.

**Limited policy.** A policy granted with a limitation (Section, Subtree ...). tron treats it as not granted unless a
tool declares it checks the limitation itself.

**Manifest.** A program's `program.json`: name, class, file, version, requires, title, description.

**MCP (Model Context Protocol).** The open protocol between AI clients and servers that offer tools, resources and
prompts.

**Policy, function.** An Exponential permission: module/function (`tron/centre_write`), granted through roles.

**Prefix (token).** The public, unique 12-character part of a token; safe to show, used to find, list and revoke it.

**Principal.** The identity of a session: user id, login, token id and scopes.

**Program.** A unit of tools, resources and prompts that tron loads by convention from `extension/<ext>/tron/program.json`.
Its name is the prefix of everything it registers.

**Prompt.** A named, parameterized message template a client can offer as a command (`centre_triage`).

**Protocol version.** The MCP revision a session speaks: 2025-11-25, 2025-06-18, 2025-03-26 or 2024-11-05.

**Redaction.** Masking secrets in audit entries: secret-looking keys, `name=value` secrets in text, tokens anywhere.

**Registry.** Where programs register their tools, resources and prompts, staged per program so a failure leaves
nothing behind.

**Resource, resource template.** Readable data addressed by URI (`tron://status`), or a URI pattern
(`centre://ticket/{id}`).

**Scope (token).** The programs a token may use; it narrows the user's roles, never widens them.

**Session.** One stdio process from `initialize` to end of input, or one HTTP request (stateless).

**Siteaccess.** An Exponential configuration context (settings, database, design) a command or request runs in.

**stdio transport.** MCP over a process's standard input and output, one JSON message per line (`tron.php serve`).

**Streamable HTTP.** MCP's HTTP transport; tron offers its stateless form: POST in, JSON out.

**Switch.** A per-installation on/off for a program or tool, set in Setup > tron (table `tron_switch`).

**Token.** `tron_<prefix>_<secret>`: a user's credential for tron, stored only as a hash, with expiry, scopes and
revocation. See [tokens.md](tokens.md).

**Tool.** A callable operation with a name, description, input schema, policy and annotations (read, write,
idempotent, destructive).

**Velocity.** Exponential's persistent application server; it serves tron's endpoint and administration with warm
workers.

**Write tool.** A tool that changes data. It is audited `started` before it runs and does not run if that fails.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
