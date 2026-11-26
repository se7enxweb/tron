# Architecture

tron is built like a microkernel with its services outside it: a small core that speaks MCP, authenticates, checks
policies and audits, and **programs** that bring the tools. The core knows no tool of its own beyond `tron_whoami`
and `tron_status`, and those come from a program too (the built-in program `tron`).

```
 agent (Claude Code, any MCP client)
   |  stdio: one JSON-RPC message per line        |  HTTP: POST /tron/mcp, Bearer token, JSON answer
   v                                              v
 bin/php/tron.php serve                       modules/tron/mcp.php
   tronStdioTransport                           tronHttpTransport   (method, Origin, Host, type, size,
   (principal: --user or token)                                      version header, token, tron/use)
            \                                     /
             +------------> tronServer <---------+      JSON-RPC 2.0 + MCP: initialize, ping, tools/*, resources/*, prompts/*
                               |
              +----------------+-----------------+--------------------+
              |                |                 |                    |
        tronRegistry     tronAccessChecker   tronAuditSink       tronEnvironment
        tools, resources  eZUser roles and    tron_audit table    settings (tron.ini), db (tronDb),
        prompts, by       policies (kernel)   and/or a JSON-lines  users, katalyst.source, centre.api,
        program                               file                 a clock
              ^
              |  register() inside begin()/commit()
        tronProgramLoader --- programs/<name>/program.json (built in: tron, centre, katalyst)
                          \-- extension/<ext>/tron/program.json, extension/<ext>/tron/programs/<name>/program.json
```

## The request path

1. **Transport.** stdio reads a line; HTTP checks method, Origin, Host, content type, size and the
   `MCP-Protocol-Version` header, then the bearer token (`tronTokenService::authenticate`) and the policy `tron/use`.
   A refused sign-in is audited (method `auth`) with the token's prefix only.
2. **Session.** A `tronSession` holds the principal (user id, login, token id, scopes), the transport and the
   protocol version. stdio sessions last for the process and must start with `initialize`; HTTP sessions are one
   request each (stateless), their version from the header (2025-03-26 when absent, as the specification says).
3. **Dispatch.** `tronServer` validates the JSON-RPC envelope, answers notifications with nothing, refuses batches
   from 2025-06-18 on, and routes the method.
4. **Visibility.** `tools/list`, `resources/list`, `resources/templates/list` and `prompts/list` show only what the
   token's scope covers and the user's policies allow, sorted by name, 100 per page (opaque cursor).
5. **A call.** `tools/call` finds the tool (an unknown or out-of-scope tool is -32602), checks the policy (a refusal
   is a tool error, audited `denied`), validates the arguments against the input schema (a tool error with the
   schema, audited `invalid`, so the model can correct itself), fills defaults, writes the `started` audit entry for
   a write tool (no entry, no run), runs the handler, and answers with `content` (JSON text) and, from 2025-06-18
   on, `structuredContent`. A `tronToolError` from the handler is a tool error with its message; any other exception
   is "internal error" to the client and the details go to the log.
6. **Audit.** The outcome (`ok`, `error`, `denied`, `invalid`) with duration and redacted arguments.

## Programs

A program is a class implementing `tronProgram::register( tronRegistry, tronEnvironment )`. The loader:

1. **discovers** manifests: tron's own `programs/*/program.json`, then each active extension's `tron/program.json`
   and `tron/programs/*/program.json`, in extension order;
2. **reads** each manifest without running code (name, class, file, version, requires);
3. **skips** it when `[ProgramSettings] Disabled[]` names it (never the core `tron`), when its name is already taken
   (`conflict`), or when it needs a newer tron (`incompatible`);
4. **loads** the class through the autoload, else the manifest's file (which must lie inside its extension);
5. **registers** it in a registry transaction: the registry enforces the name prefix (`<program>_*`,
   `<program>://`), refuses duplicates, and keeps everything staged until `commit()`. Any `Throwable` (a parse
   error included) rolls the program back and records `failed` with the reason.

Discovery and registration of 300 extensions with 50 programs takes about 3 ms (`tests/unit/ProgramLoaderTest.php`
measures it on every run); building a whole server in the alpha installation over 67 active extensions took 43 ms.

Each program gets the **environment**: tron.ini settings and named services. A service given as a Closure is a
factory that runs on first use. Standard services: `db` (tronDb), `users` (tronUserDirectory), `access`,
`katalyst.source`, `centre.api` (only where expcentre is), `tron.status`.

## Data

| Table | Holds |
|---|---|
| `tron_token` | per-user tokens: prefix, SHA-256 hash, scopes, created, expires, last used (at most once a minute), revoked |
| `tron_audit` | one row per call and refused sign-in |
| `tron_centre_ticket`, `_comment`, `_link`, `_history` | the centre program's tickets when expcentre is not in the installation |

The schema is declared once in `tronSchema::tables()`; `sql/{mysql,postgresql,sqlite}/schema.sql` are generated from
it (`php bin/php/tron-schema.php --write`, a test keeps them equal) and `tron.php install` creates whatever is
missing. Statements go through `tronDb` with named parameters only: `tronDbKernel` on the installation's eZDB
connection (each value quoted by the engine's own escaping, exception error handling switched on per statement),
`tronDbPdo` on PDO (the tests use SQLite in memory).

## Kernel binding

`tronKernel` builds the parts from the installation (tron.ini, eZDB, eZUser, the active extensions) and hands them to
`tronFactory::build()`, the same function the tests use with SQLite and a role table. What the tests prove is what
the installation runs; only the parts differ. `tests/kernel/kernel_probe.php` runs the kernel parts read-only in a
real installation.

## Why no SDK

tron implements JSON-RPC and MCP itself (`tronServer` and the two transports, a few hundred lines). The official PHP SDK
needs PHP 8.1 or later and brings PSR, schema and UUID packages; tron must run on every PHP from 7.4 to 8.5 in a
default Exponential installation without extra Composer packages. The conformance test pins the behavior.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
