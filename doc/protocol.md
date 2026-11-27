# The protocol layer

Exponential Tron does not implement the Model Context Protocol itself where it does not have to. On PHP 8.2 and newer, with the
package `mcp/sdk` installed, the **official MCP PHP SDK** ([modelcontextprotocol/php-sdk](https://github.com/modelcontextprotocol/php-sdk),
maintained by the MCP project with Symfony and the PHP Foundation) does the protocol:

- parsing and validating every JSON-RPC message, and the JSON-RPC errors
- the version negotiation of `initialize` and the lifecycle (nothing but `initialize` before it on stdio)
- `ping`
- the stdio transport (`StdioTransport`) for `tron.php serve`
- the streamable HTTP transport (`StreamableHttpTransport`, without sessions) behind `/tron/mcp`

Exponential Tron adds one request handler, `tronSdkHandler`, that answers `initialize`, `tools/list`, `tools/call`,
`resources/list`, `resources/templates/list`, `resources/read`, `prompts/list` and `prompts/get` with `tronServer`.
Everything that makes tron tron stays tron's: the tokens and the user behind each one, the token scope, the user's
roles and policies, the audit entry written before a write tool runs (no entry, no run), the dry runs and plans, the
redaction of every answer, the activity journal and the programs.

```
 client --stdio--> tron.php serve ----------> SDK StdioTransport ---------+
 client --HTTP---> tronHttpTransport -------> SDK StreamableHttpTransport -+--> SDK protocol --> tronSdkHandler --> tronServer
                   (Origin, Host, type, size,                                  (JSON-RPC, versions,
                    version, token, tron/use)                                    lifecycle, ping)
```

`tronHttpTransport` keeps the edge of the HTTP endpoint, because it is Exponential Tron's deployment policy rather than protocol:
POST only, the Origin and Host allowlists of `[HTTPSettings]`, JSON only, the body limit, a known
`MCP-Protocol-Version`, the bearer token and the policy `tron/use`, each refusal with its own status and failed sign-ins
audited. The request it lets through goes to the SDK unchanged.

## Which layer runs

`[ServerSettings] Protocol` in `tron.ini`, or the environment variable `TRON_PROTOCOL` (which wins):

| Value | Layer |
|---|---|
| `auto` (default) | the SDK where it can run (PHP 8.2 or newer, `mcp/sdk` and `nyholm/psr7` installed), else the legacy layer |
| `sdk` | the SDK; Exponential Tron refuses to start when it cannot run, and says why |
| `legacy` | the legacy layer |

`tron.php status`, the tool `tron_status` and the dashboard under Setup > tron name the layer in use and the versions it
speaks.

## Installing the SDK

Exponential Tron's `composer.json` **suggests** the SDK rather than requiring it, so an installation on PHP 7.4 to 8.1 still
installs. On PHP 8.2 and newer, from the installation root:

```bash
composer require mcp/sdk:^0.10 nyholm/psr7:^1.8
```

The SDK needs PHP 8.2 (version 0.10), brings PSR packages, `opis/json-schema`, `phpdocumentor/reflection-docblock`
and `symfony/uid`, and is licensed under the Apache License 2.0 (see the README's licence section). It is still
before 1.0: Exponential Tron pins `^0.10` and moves to a new minor version only after the suite and the official conformance suite
pass on it.

`TRON_SDK_AUTOLOAD=<path to a Composer autoload.php>` loads the SDK from somewhere else, for a checkout whose
installation does not have it (the test suite, a branch under review).

## The legacy layer (PHP 7.4 to 8.1)

The SDK cannot run below PHP 8.1, and its current version needs 8.2. It runs every handler in a PHP Fiber, which is a
runtime feature rather than syntax, so no downgraded build can bring it to older PHP. For those versions Exponential Tron keeps
its earlier JSON-RPC and MCP code as `tronLegacyProtocol`. That code is **frozen**: it takes security fixes and
nothing else, and it goes once tron's lowest PHP reaches the SDK's.

Both layers run the same test suite and the same conformance checks (`TRON_PROTOCOL=legacy|sdk php tests/run.php`,
see [testing.md](testing.md)). Above the wire they answer identically. Where the framing differs, the SDK's answer
is what the tests expect on the SDK layer:

| Situation | SDK layer | Legacy layer |
|---|---|---|
| a request before `initialize` on stdio | -32600, `ping` included | -32600, `ping` answered |
| an unknown method | -32600 | -32601 |
| `params` that are not an object | -32600 | -32602 |
| a JSON-RPC batch | -32600 in every version | answered under 2025-03-26 and older |
| an error with no request id (parse error) | no `id` member | `"id": null` |
| an stdio line over the limit | dropped and logged, no answer | -32700, and the server carries on |
| `initialize` without `capabilities` or `clientInfo` | -32602 | answered |

## Versions

2025-11-25, 2025-06-18, 2025-03-26 and 2024-11-05 on both layers. The SDK also speaks the stateless revision
2026-07-28 (no `initialize`; the version and the client travel in every request's `_meta`). Exponential Tron switches that era
off for now (`withoutModernEra()`) until its tools, dry runs and journal are proven on it.

## Conformance

`tests/conformance/official_run.php` runs the official conformance suite
([@modelcontextprotocol/conformance](https://www.npmjs.com/package/@modelcontextprotocol/conformance)) against the
streamable HTTP endpoint with the test world:

```bash
mkdir -p <dir> && (cd <dir> && npm install @modelcontextprotocol/conformance)
TRON_PROTOCOL=sdk php tests/conformance/official_run.php --conformance=<dir>
```

The generic scenarios (initialize, ping, the tools, resources and prompts lists, DNS rebinding protection) must pass
on both layers. The other scenarios call the conformance suite's own test tools and prompts (`test_simple_text`,
`test_simple_prompt`, ...) or need sessions (subscriptions, `logging/setLevel`), which Exponential Tron's stateless endpoint does
not keep; the runner lists them as not applicable.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
