# Hosting the MCP endpoint

Two ways in, both wanted on the 7x network:

1. **Mesh, for 7x sessions**: stdio over SSH on the WireGuard mesh. The agent's MCP client starts tron on the node
   through SSH; nothing listens, nothing leaves the mesh, and both the SSH key and the tron token must be right.
2. **Public HTTPS with tokens**: `https://centre.se7enx.com/tron/mcp` (and `/mcp` with a URL alias) through the load
   balancer front, for agents outside the mesh.

## 1. Mesh: stdio over SSH

On the node, once per session user:

```bash
mkdir -p /root/.config/tron && chmod 700 /root/.config/tron
php extension/tron/bin/php/tron.php token:create --siteaccess=<sa> --user=<session login> \
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
- stdout carries only MCP; tron's own messages go to stderr (the MCP client shows them in its log).

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

The view `tron/mcp` is in `[RoleSettings] PolicyOmitList` (shipped in tron's `site.ini.append.php`): callers sign in
with tokens, not sessions. The siteaccess's `[SiteAccessRules]` must not close the module `tron`. Serve the endpoint
from a user-facing siteaccess with `RequireUserLogin=false` (as `centre` has), not from an administration siteaccess
that sends every anonymous request to its login page; prove it with the smoke request below.

**The path /mcp**: tron answers at `/tron/mcp`. For the shorter `/mcp`, add a global URL alias `mcp` with the
destination `tron/mcp` (admin: Setup, URL translator, global aliases). Clients may use either.

**Authorization header**: Apache with PHP-FPM drops `Authorization` unless told to pass it. In Plesk, the domain's
"Additional Apache directives" for HTTP and HTTPS:

```apache
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```

(`CGIPassAuth On` does the same where the FPM handler is a CGI one.) tron reads `HTTP_AUTHORIZATION` and
`REDIRECT_HTTP_AUTHORIZATION`. Velocity passes the header as it is; check with the smoke request below.

**Request shield**: where the extension exp_request_shield filters requests (alpha, se7enx.com), its rule EXP-POST
answers every POST outside the known form addresses with 405, so `/tron/mcp` is refused before tron runs (found on
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

- a `timeout client` / `timeout server` of 120s is ample: tron answers each POST at once and holds no stream open;
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

## Using tron from Claude Code

A Claude Code session on a node of the installation uses tron over stdio, one MCP server per account and siteaccess it
should work as. Each account is a permanent user of the installation made for the session (never a person's account
or the shared administrator), with exactly the roles its work needs; each token is that user's, 90 days, in a 0600
file:

```bash
php extension/tron/bin/php/tron.php token:create --siteaccess=admin --user=<session admin login> \
    --name="claude@<node>" --days=90 --out=/root/.config/tron/<session admin login>.token --allow-root-user
php extension/tron/bin/php/tron.php token:create --siteaccess=editor --user=<session editor login> \
    --name="claude@<node> editor" --days=90 --out=/root/.config/tron/<session editor login>.token --allow-root-user
```

An editor account needs the tron policies besides its editing role (`tron/use`, and `tron/centre_read`,
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
