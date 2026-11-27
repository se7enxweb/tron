# Hosting the MCP endpoint

Two ways in, both wanted on the 7x network:

1. **Mesh, for 7x sessions**: stdio over SSH on the WireGuard mesh. The agent's MCP client starts Exponential Tron on the node
   through SSH; nothing listens, nothing leaves the mesh, and both the SSH key and the tron token must be right.
2. **Public HTTPS with tokens**: `https://centre.se7enx.com/tron/mcp` (and `/mcp` with a URL alias) through the load
   balancer front, for agents outside the mesh.

## 1. Mesh: stdio over SSH

On the node, once per session user:

```bash
mkdir -p /root/.config/tron && chmod 700 /root/.config/tron
php extension/tron/bin/php/tron.php token:create --siteaccess=<sa> --token-file=<issuer token> --user=<session login> \
    --name="claude@<node>" --days=90 --scopes=centre --out=/root/.config/tron/claude-<node>.token
```

In the session's MCP configuration (Claude Code `.mcp.json`):

```json
{
  "mcpServers": {
    "tron": {
      "command": "ssh",
      "args": ["-p", "<ssh port>", "-o", "BatchMode=yes", "root@<node private address>",
               "/opt/plesk/php/8.5/bin/php <installation root>/extension/tron/bin/php/tron.php serve --siteaccess=<sa> --allow-root-user --token-file=/root/.config/tron/claude-<node>.token"]
    }
  }
}
```

- Use the PHP the installation needs (an installation whose Composer platform check asks for 8.4 or later refuses
  8.3).
- The token file stays on the node; the token is never typed, never in the config, never in a URL.
- On the node itself, drop the `ssh` part and run the same command.
- stdout carries only MCP; Exponential Tron's own messages go to stderr (the MCP client shows them in its log).

A mesh HTTP listener (the endpoint on the node's WireGuard address, without the PROXY protocol that the public entry
expects) is planned for wave 2; until then the mesh way is stdio over SSH.

## 2. Public HTTPS

### In the installation

`settings/override/tron.ini.append.php` (or `settings/siteaccess/centre/tron.ini.append.php`):

```ini
[HTTPSettings]
Enabled=true
SiteAccesses[]
SiteAccesses[]=centre
AllowedHosts[]
AllowedHosts[]=centre.se7enx.com
```

The view `tron/mcp` is in `[RoleSettings] PolicyOmitList` (shipped in Exponential Tron's `site.ini.append.php`): callers sign in
with tokens, not sessions. The siteaccess's `[SiteAccessRules]` must not close the module `tron`. Serve the endpoint
from a user-facing siteaccess with `RequireUserLogin=false` (as `centre` has), not from an administration siteaccess
that sends every anonymous request to its login page; prove it with the smoke request below.

**The path /mcp**: Exponential Tron answers at `/tron/mcp`. For the shorter `/mcp`, add a global URL alias `mcp` with the
destination `tron/mcp` (admin: Setup, URL translator, global aliases). Clients may use either.

**Authorization header**: Apache with PHP-FPM drops `Authorization` unless told to pass it. In Plesk, the domain's
"Additional Apache directives" for HTTP and HTTPS:

```apache
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```

(`CGIPassAuth On` does the same where the FPM handler is a CGI one.) Exponential Tron reads `HTTP_AUTHORIZATION` and
`REDIRECT_HTTP_AUTHORIZATION`. Velocity passes the header as it is; check with the smoke request below.

**Request shield**: where the extension exp_request_shield filters requests (alpha, se7enx.com), its rule EXP-POST
answers every POST outside the known form addresses with 405, so `/tron/mcp` is refused before Exponential Tron runs (found on
alpha 2026-11-24, both through PHP-FPM and through Velocity). tron ships the three rules it needs in
`doc/request-shield/tron.rules` (POST allowed, no Origin check, no browser check; only for `/mcp` and
`**/tron/mcp`; tron does those checks itself with the token). Copy the file next to the site's main rule file and add
`include tron.rules` **above the first site block** of that file, on every node that has its own copy, then
`request-shield check` and `request-shield test` (all examples must pass). The shield reads the change within its
recheck time; no restart.

### A load balancer in front

A load balancer that routes HTTPS by SNI in TCP mode (TLS ends on the serving node, the PROXY protocol passes the
client address) never sees the path, so `/mcp` needs **no rule of its own**: the MCP host only has to reach the same
backends as the site. For example, in HAProxy:

```haproxy
# frontend fe_http
    use_backend be_site_http if { req.hdr(host),field(1,:),lower -m str mcp.example.org }

# frontend fe_https
    use_backend be_site_https if { req.ssl_sni,lower -m str mcp.example.org }
```

Nothing else changes for MCP:

- a `timeout client` / `timeout server` of 120s is ample: Exponential Tron answers each POST at once and holds no stream open;
- tron is stateless (no `Mcp-Session-Id`), so no stickiness is needed; any serving node with tron installed answers;
- the backend's health check stays the site's own. A node without tron would answer `/tron/mcp` with 404 (unknown
  module), so tron must reach every serving node before the rule sends traffic.

DNS and certificates: the MCP host name is one more name of the site (its records, its certificate).

### Smoke request

```bash
curl -s -X POST https://centre.se7enx.com/tron/mcp \
     -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
     -H "Authorization: Bearer $(cat /root/.config/tron/<name>.token)" \
     -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"1"}}}'
```

Expected: HTTP 200 and `"serverInfo":{"name":"tron",...}`. Without the header: 401 with `WWW-Authenticate: Bearer
realm="tron"`. A GET: 405. Then `tron.php audit:tail` shows both requests.

## A live install on a site served by several nodes

How centre.se7enx.com got Exponential Tron's public endpoint (2026-11-26): one Exponential installation, one shared database, two
serving nodes behind an SNI load balancer, each node with its own copy of the tree (Plesk, Apache with PHP-FPM, nginx
in front). The names below are placeholders: `node-a`, `node-b`, `<site address of node-a>`.

1. **The same code on every node.** Install the released version on each node (`composer update se7enxweb/tron`, or
   the tag's files in `extension/tron`; move the old directory out of `extension/` rather than deleting it). Prove it:
   the md5 of every file under `extension/tron` equals the tag's (`git archive v<version>`), and node-a equals node-b.
2. **Settings, the same file on every node**, in `settings/override/tron.ini.append.php`: `[HTTPSettings]`
   (`Enabled=true`, the siteaccess, `AllowedHosts[]=centre.se7enx.com`), and the private words of the installation
   (`[PromptRules] PrivateNames[]`/`PrivateDomains[]`, `[WebSettings] MeshNetworks[]`,
   `[SystemAdapterSettings] PrivateNames[]`, see [upgrading.md](upgrading.md), 0.0.0.12). The site's own public domain
   does not belong in `PrivateDomains[]`.
3. **PolicyOmitList.** Exponential Tron ships `PolicyOmitList[]=tron/mcp`, but a site whose `settings/override/site.ini.append.php`
   empties the list (`PolicyOmitList[]` on a line of its own) drops it: `/tron/mcp` then answers the kernel's HTML
   "access denied" page (403) instead of tron's JSON 401. Add `PolicyOmitList[]=tron/mcp` to that override.
4. **Request shield on every node**: `tron.rules` next to the main rule file, `include tron.rules` above the first
   site block, then `request-shield check` and `request-shield test` (the three TRON rules' examples must pass).
5. **Per node**: `php bin/php/ezpgenerateautoloads.php -e`, `php bin/php/ezcache.php --clear-tag=ini`, a graceful
   reload of the PHP-FPM master that serves the site (the Plesk one, e.g. `systemctl reload plesk-php85-fpm`, not the
   system `php-fpm`).
6. **Tables, once** (the database is shared): `tron.php install --siteaccess=<sa>`, then `tron.php status` on every
   node (PASS). Coming from a preview build, `install` creates the tables that build lacked and keeps tokens and audit.
7. **The Authorization header**: `CGIPassAuth On` (or the `SetEnvIf` line above) in the site's Apache directives on
   every node. Proof is a 200 with the token and tron's own JSON 401 (not a 403 page) without it.
8. **Caches in front**: the nginx front cache stores GET and HEAD only; MCP is POST, tron answers `Cache-Control:
   no-store` and a GET with 405, so nothing of it is kept. Check that no answer carries `X-Cache: HIT`.
9. **Proof per node, from outside**: pin the public name to each node's site address (the address the site's
   certificate is bound to; a node's main address may answer with the panel's default certificate):

   ```bash
   curl --resolve centre.se7enx.com:443:<site address of node-a> -X POST https://centre.se7enx.com/tron/mcp ...
   ```

   initialize and `tools/list` with the token (200), without it (401), a GET (405); then the same through the public
   name. Send the token to a remote `curl` on its standard input (`-H @-`), never as an argument.

**A half-done rollout looks intermittent.** The front on one node may hand a request to the other node over the
private mesh, so a pinned request is not always answered by the node it was pinned to. While one node still lacked
`tron.rules`, every second POST answered 405 (that node's shield, rule EXP-POST) and the other half reached Exponential Tron.
Look in both nodes' shield logs before suspecting tron, and finish every node before judging the endpoint.

## Using Exponential Tron from Claude Code

A Claude Code session on a node of the installation uses Exponential Tron over stdio, one MCP server per account and siteaccess it
should work as. Each account is a permanent user of the installation made for the session (never a person's account
or the shared administrator), with exactly the roles its work needs; each token is that user's, 90 days, in a 0600
file:

```bash
php extension/tron/bin/php/tron.php token:create --siteaccess=admin --token-file=<issuer token> --user=<session admin login> \
    --name="claude@<node>" --days=90 --out=/root/.config/tron/<session admin login>.token --allow-root-user
php extension/tron/bin/php/tron.php token:create --siteaccess=editor --token-file=<issuer token> --user=<session editor login> \
    --name="claude@<node> editor" --days=90 --out=/root/.config/tron/<session editor login>.token --allow-root-user
```

An editor account needs the Exponential Tron policies besides its editing role (`tron/use`, and `tron/centre_read`,
`tron/centre_write` for the centre tools): the kernel's Editor role holds none of them, and without `tron/use` the
server refuses to start.

In Claude Code's configuration (`mcpServers` of the project in `~/.claude.json`, or a project `.mcp.json`):

```json
{
  "tron": {
    "type": "stdio",
    "command": "/opt/plesk/php/8.5/bin/php",
    "args": ["<installation root>/extension/tron/bin/php/tron.php", "serve", "--siteaccess=admin",
             "--allow-root-user", "--token-file=/root/.config/tron/<session admin login>.token"]
  },
  "tron-editor": {
    "type": "stdio",
    "command": "/opt/plesk/php/8.5/bin/php",
    "args": ["<installation root>/extension/tron/bin/php/tron.php", "serve", "--siteaccess=editor",
             "--allow-root-user", "--token-file=/root/.config/tron/<session editor login>.token"]
  }
}
```

Check each entry before the session uses it, with the same command the client will run:

```bash
php extension/tron/tests/kernel/stdio_smoke.php -- /opt/plesk/php/8.5/bin/php \
    <installation root>/extension/tron/bin/php/tron.php serve --siteaccess=admin --allow-root-user \
    --token-file=/root/.config/tron/<session admin login>.token
```

A running session loads its MCP servers when it starts; a changed configuration takes effect in the next session.
`tron.php token:list --user=<login>` shows when each token was last used; renew before it expires, then revoke the
old one.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
