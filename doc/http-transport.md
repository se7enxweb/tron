# The HTTP transport

tron serves MCP's **streamable HTTP** transport at the module view `tron/mcp`, in its **stateless** form: every
request is a POST carrying one JSON-RPC message, every answer is a single
JSON body. No session id, no event stream, nothing kept between requests. Any serving node can answer any request,
so a cluster behind a load balancer needs no stickiness.

Code: `classes/transport/tronhttptransport.php` (the checks), `modules/tron/mcp.php` (the view),
`classes/kernel/tronkernel.php::httpTransport()` (the settings). Hosting and the load balancer: [hosting.md](hosting.md).

## Switching it on

The endpoint answers 404 until both hold:

```ini
# settings/override/tron.ini.append.php (or settings/siteaccess/<sa>/tron.ini.append.php)
[HTTPSettings]
Enabled=true
SiteAccesses[]
SiteAccesses[]=<sa>
AllowedHosts[]
AllowedHosts[]=<host>
```

1. Choose a user-facing siteaccess with `RequireUserLogin=false`. An administration siteaccess sends anonymous
   requests to its login page before tron runs.
2. The siteaccess's `[SiteAccessRules]` must not close the module `tron`.
3. `tron/mcp` is already in `[RoleSettings] PolicyOmitList` (tron's `settings/site.ini.append.php`): the view signs
   callers in with tokens, not with sessions, and checks the token user's policies on every call.
4. Clear the INI cache (`php bin/php/ezcache.php --clear-tag=ini`); where Velocity serves, deploy.

### The short address /mcp

tron answers at `/tron/mcp` (or `/<sa>/tron/mcp` with URI-matched siteaccesses). For `https://<host>/mcp`, add a
global URL alias `mcp` with the destination `tron/mcp` (Setup, URL translator). Clients may use either address.

### Apache and the Authorization header

Apache with PHP-FPM drops `Authorization` unless told to pass it on. Add to the virtual host (in Plesk: the domain's
"Additional Apache directives", for HTTP and HTTPS):

```apache
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```

`CGIPassAuth On` does the same where the handler is a CGI one. tron reads `HTTP_AUTHORIZATION` and Apache's
`REDIRECT_HTTP_AUTHORIZATION`. Velocity passes the header as it is. nginx with PHP-FPM passes it by default through
`fastcgi_params`.

### A request filter in front

Where a request filter allows POST only to known form addresses (exp_request_shield's rule EXP-POST, for example), it
answers a POST to the endpoint with 405 before tron runs. tron ships the rules it needs in
`doc/request-shield/tron.rules`: POST allowed, no Origin check and no browser challenge, and only for `/mcp` and
`**/tron/mcp`. tron does those checks itself with the token. Include the file above the first site block of the main
rule file, then run the filter's `check` and `test` commands; [hosting.md](hosting.md) has the steps.

## What tron checks, in order

Each refusal is a JSON-RPC error object with `id: null` and the HTTP status below; the first failing check answers.

| # | Check | Refusal | Body message |
|---|---|---|---|
| 0 | `[HTTPSettings] Enabled=true` and the siteaccess listed | **404** | `Not found` |
| 1 | method is POST | **405** with `Allow: POST` (OPTIONS: **204**) | tron serves MCP over POST only; it offers no event stream. |
| 2 | no `Origin` header, or one listed in `AllowedOrigins[]` | **403** | Forbidden: this Origin is not allowed |
| 3 | `Host` listed in `AllowedHosts[]` (when the list is not empty; the port is ignored) | **421** | Misdirected request: this Host is not served here |
| 4 | `Content-Type: application/json` | **415** | Unsupported media type: send application/json |
| 5 | body at most `MaxBodyBytes` (1 MB) | **413** | Payload too large: the limit is ... bytes |
| 6 | `MCP-Protocol-Version`, when sent, is one tron speaks | **400** | Bad request: unsupported MCP-Protocol-Version ... |
| 7 | `Authorization: Bearer <tron token>`, valid, not expired or revoked, user enabled | **401** with `WWW-Authenticate: Bearer realm="tron"` | Unauthorized: send a tron token as a bearer token |
| 8 | the token's user holds `tron/use` | **403** | Forbidden: this user does not hold the policy tron/use |
| - | a notification (no `id`) | **202**, empty body | |
| - | anything else | **200**, `Content-Type: application/json; charset=utf-8` | the JSON-RPC answer |

Refusals at steps 7 and 8 are written to the audit log (method `auth`, outcome `unauthenticated` or `denied`) with
the remote address and only the token's public prefix. The reason (malformed, unknown, revoked, expired, user missing
or disabled) goes to the audit, never to the caller.

## Protocol versions over HTTP

| Request | Version used |
|---|---|
| `initialize` without the header | negotiated from `params.protocolVersion` (a version tron speaks, else the newest, 2025-11-25) |
| any other request without the header | 2025-03-26, as the specification says for a missing header |
| any request with `MCP-Protocol-Version: <v>` | `<v>` (400 when tron does not speak it) |

Send the header on every request after `initialize`, as current clients do. It decides two things: from 2025-06-18 on,
tool results carry `structuredContent` next to the JSON text. Batches are refused (the legacy layer on PHP 7.4 to 8.1
still answers them under 2025-03-26 and older). The message itself is handled by the official MCP PHP SDK's streamable
HTTP transport, without sessions ([protocol.md](protocol.md)).

## A session with curl

The token is read from a file so it never appears in the shell history.

```bash
URL=https://<host>/tron/mcp
AUTH="Authorization: Bearer $(cat ~/.config/tron/<name>.token)"
```

**initialize**

```bash
curl -s -X POST "$URL" -H "$AUTH" -H 'Content-Type: application/json' \
     -H 'Accept: application/json, text/event-stream' \
     -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"curl","version":"1"}}}'
```

```json
{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2025-11-25",
 "capabilities":{"tools":{"listChanged":false},"resources":{"listChanged":false,"subscribe":false},"prompts":{"listChanged":false}},
 "serverInfo":{"name":"tron","version":"0.0.0.1","title":"Exponential tron"},
 "instructions":"tron is the MCP server of this Exponential installation. You act as the Exponential user 'agent-claude' with that user's roles and policies; 13 tools are available to you. Every call is written to an audit log. ..."}}
```

**the initialized notification** (answered 202 with no body; it is optional for a stateless server, clients send it)

```bash
curl -s -o /dev/null -w '%{http_code}\n' -X POST "$URL" -H "$AUTH" -H 'Content-Type: application/json' \
     -H 'MCP-Protocol-Version: 2025-11-25' -d '{"jsonrpc":"2.0","method":"notifications/initialized"}'
# 202
```

**tools/list**

```bash
curl -s -X POST "$URL" -H "$AUTH" -H 'Content-Type: application/json' -H 'MCP-Protocol-Version: 2025-11-25' \
     -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' | jq -r '.result.tools[].name'
```

```
centre_board_view
centre_decision_review
centre_links_find
centre_ticket_close
...
tron_status
tron_whoami
```

Only the tools the token's user may call and the token's scopes cover are listed, sorted by name, `PageSize` (100)
per page; a further page is announced with `nextCursor`, which goes back as `params.cursor`.

**tools/call**

```bash
curl -s -X POST "$URL" -H "$AUTH" -H 'Content-Type: application/json' -H 'MCP-Protocol-Version: 2025-11-25' \
     -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"centre_ticket_list","arguments":{"lane":"inbox","limit":5}}}'
```

The result holds `content` (one text item with the JSON answer), `isError` and, from 2025-06-18 on,
`structuredContent` (the same answer as an object).

**the refusals**

```bash
curl -s -o /dev/null -w '%{http_code}\n' -X POST "$URL" -H 'Content-Type: application/json' -d '{}'            # 401
curl -s -o /dev/null -w '%{http_code}\n' "$URL" -H "$AUTH"                                                    # 405
curl -s -o /dev/null -w '%{http_code}\n' -X POST "$URL" -H "$AUTH" -H 'Content-Type: text/plain' -d '{}'     # 415
curl -s -o /dev/null -w '%{http_code}\n' -X POST "$URL" -H "$AUTH" -H 'Content-Type: application/json' \
     -H 'Origin: https://elsewhere.example' -d '{}'                                                          # 403
```

`tests/kernel/http_smoke.php` runs all of these and more against a real endpoint (`--resolve=<host>:<port>:<address>`
sends them to one node past the load balancer).

## Errors inside a 200 answer

Once a request is past the checks, problems are JSON-RPC errors or tool results, as on stdio:

| What | Comes back as |
|---|---|
| malformed JSON | error -32700 without an id |
| not a JSON-RPC request | error -32600 |
| unknown method | error -32600 (the legacy layer: -32601) |
| unknown tool, tool outside the token's scope, bad params | error -32602 |
| unknown resource | error -32002 with `data.uri` |
| missing policy, invalid arguments, the tool's own refusal, internal failure | a result with `isError: true` and a readable message (invalid arguments carry the input schema) |
| a batch | error -32600 (the legacy layer answers one under 2025-03-26 and older) |

## Browsers

A request with an `Origin` header is refused unless the origin is listed in full (`https://app.example.com`, no path,
no trailing slash) in `[HTTPSettings] AllowedOrigins[]`. The list is empty by default, which closes the endpoint to
every browser page: that is the defense against DNS rebinding and cross-site requests. List an origin only for a
browser-based MCP client you trust, and always together with `AllowedHosts[]`.

## Limits and timing

- Body: `[HTTPSettings] MaxBodyBytes` (1048576). Answers: `[ServerSettings] ResponseMaxBytes` (200000), cut with a
  note asking to page.
- Each request builds the server anew (programs registered, settings read): about 10 to 45 ms on a large installation,
  before the tool's own work. Medians measured through Velocity were about 120 ms per request, through the public load
  balancer and PHP-FPM about 300 ms ([performance.md](performance.md)).
- tron holds no connection open, so proxy timeouts of a minute or two are ample.

## Not offered (yet)

| Feature | Status |
|---|---|
| GET with an SSE stream, server-to-client requests, `Mcp-Session-Id` | not in the stateless design; streaming for long tools is planned |
| DELETE to end a session | 405: there is no session |
| OAuth 2.1 sign-in and protected resource metadata | planned ([roadmap.md](roadmap.md)) |
| Rate limits | planned; until then use the load balancer's or request filter's limits |

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
