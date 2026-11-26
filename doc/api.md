# The api program

The installation's own APIs as a client uses them, and two generic HTTP tools. Policies `tron/api_read`,
`tron/api_write`, `tron/api_http`. Tool table: [root-programs.md](root-programs.md#api-policies-tronapi_read-tronapi_write-tronapi_http).

## What is there

`api_endpoints` reports, from the classes and `rest.ini` of the installation:

| API | found by | on alpha (2026-11-24) |
|---|---|---|
| REST v1 `/api/ezp/v1` (reads) | the kernel REST framework and ezprestapi's `ezp7xRestApiProvider` | available |
| REST v2 `/api/ezp/v2` (reads and writes) | the same provider (writes answer at v2 only) | available |
| GraphQL `/api/graphql` | `expGraphQLRestProvider` registered in `rest.ini [ApiProvider] ProviderClass[graphql]` | available |

An API that is not there is reported as `available: false` with the reason, and its tools are left out (named in
`tron_status`). `[ApiSettings] BaseUrl` must name the installation's address; without it the REST and GraphQL tools
are left out.

## Two permission checks

tron checks the caller's tron policy (`api_read` or `api_write`). The request itself carries a personal API key or
OAuth token read from the secret store, so the REST layer then applies the **key owner's** content policies
(`content/read`, `create`, `edit`, `remove`) on top. Give each agent user a key of their own (My account > API
access), store it in Setup > Secrets under a name listed in `[ApiSettings] SecretNames[]`, and pass that name as
`key_secret`. Without `key_secret` the request is anonymous.

## Secrets are names, never values

- A call names a secret; tron reads the value inside the call (`expSecrets::get()`), puts it into the header, and
  replaces it with `[redacted:secret]` wherever it appears in the answer (a body that echoes it included).
- Only names on `[ApiSettings] SecretNames[]` can be used (`secret.not-allowed` otherwise).
- `api_http_request` refuses a credential-looking header with an inline value (`Authorization`, `Cookie`,
  `X-Api-Key`, anything with token, secret, password or api-key in its name): `secret.inline`. Use
  `secrets: [{"name": "<secret>", "header": "Authorization", "format": "Bearer {secret}"}]`.
- The audit, the journal and the dry run see only the names.

## The tools

- `api_rest_v1`, `api_rest_v2`: `GET` below the prefix, e.g. `{"path": "/content/node/2/list/offset/0/limit/20",
  "query": {"ResponseGroups": "Fields"}}`. JSON answers come back decoded.
- `api_rest_v2_write` (write, destructive): `POST /content/node/create`, `POST|PUT /content/node/<id>`,
  `DELETE /content/node/delete/<id>` with a JSON `body`. `tron_plan` shows the method, URL, body fields and the key
  name; nothing is sent.
- `api_graphql`: queries only; a document with a `mutation` or `subscription` operation is refused
  (`graphql.mutation`).
- `api_http_request` (write): any method to a host on `[ApiSettings] AllowedHosts[]` (exact or `*.domain`), through
  the SSRF guard (every hop also on the allowlist), `json` or `body`, `timeout`, `max_bytes`. Its dry run shows the
  request without sending it.
- `api_http_inspect`: one `GET` or `HEAD` without credentials to any public address: status, HTTP version, timings
  (DNS, connect, TLS, first byte, total in ms), response headers (cookies left out), TLS version, cipher,
  certificate subject, issuer and expiry, the redirect chain and the address connected to.

## Settings

`[ApiSettings]`: `BaseUrl`, `SecretNames[]`, `AllowedHosts[]`. The requests use `[WebSettings]` (network switch,
guard, limits).

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
