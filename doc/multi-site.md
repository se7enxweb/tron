# Several siteaccesses, several installations

An agent often works with more than one site. This page explains what a siteaccess means to tron, how to serve tron
from several siteaccesses of one installation, and how to give one agent several installations.

## What the siteaccess decides

Every tron command and every request runs in one siteaccess (`--siteaccess=<sa>` on the command line; the siteaccess
the request matched over HTTP). It decides:

| Decided by the siteaccess | Effect |
|---|---|
| the settings | `tron.ini` with that siteaccess's overrides: lanes, boards, page size, audit sinks, HTTP switch |
| the database | tokens, audit, switches and the centre tables live in the database of that siteaccess |
| the active extensions | access extensions of that siteaccess bring their programs |

What it does **not** decide: users and roles. They belong to the database, so a user's policies are the same on every
siteaccess of that database (a role assigned with a Subtree or Section limitation is "limited" and not granted by
tron, see [permissions.md](permissions.md)).

## One installation, several siteaccesses

Most installations have one database for all siteaccesses. Then:

- **Install once.** `tron.php install --siteaccess=<any>` creates the tables in the shared database.
- **Tokens work everywhere.** A token made on `admin` also signs in on `site` or `editor`, because the token table is
  shared.
- **One audit.** Every siteaccess writes to the same `tron_audit`. The siteaccess is not a field; if you need to tell
  them apart, give each a file sink of its own (below) or a separate agent user.

### Per-siteaccess settings

Put overrides in `settings/siteaccess/<sa>/tron.ini.append.php`:

```ini
# settings/siteaccess/support/tron.ini.append.php
[ServerSettings]
Title=Support desk tron
Instructions=This is the support desk. Tickets here are customer requests; never paste their text into other systems.

[CentreSettings]
Lanes[]
Lanes[]=new
Lanes[]=answered
Lanes[]=waiting
Lanes[]=closed
Boards[]
Boards[]=support

[AuditSettings]
Sinks[]
Sinks[]=database
Sinks[]=file
FilePath=var/log/tron-audit-support.log
```

`Instructions` is appended to the `initialize` instructions, so each siteaccess can tell the agent what it is.

### Serving HTTP from several siteaccesses

```ini
# settings/override/tron.ini.append.php
[HTTPSettings]
Enabled=true
SiteAccesses[]
SiteAccesses[]=site
SiteAccesses[]=support
AllowedHosts[]
AllowedHosts[]=www.example.com
AllowedHosts[]=support.example.com
```

The address follows the installation's siteaccess matching:

| Matching | Address |
|---|---|
| by host | `https://support.example.com/tron/mcp` |
| by URI | `https://www.example.com/support/tron/mcp` |
| short alias | `https://<host>/mcp`, with a global URL alias `mcp -> tron/mcp` |

The request filter rules shipped in `doc/request-shield/tron.rules` already cover `**/tron/mcp`, so URI siteaccesses
need nothing more.

### stdio per siteaccess

One MCP server entry per siteaccess, each with its own name:

```json
{
  "mcpServers": {
    "tron-site":    { "command": "<php>", "args": ["<root>/extension/tron/bin/php/tron.php", "serve", "--siteaccess=site",    "--token-file=<file>"] },
    "tron-support": { "command": "<php>", "args": ["<root>/extension/tron/bin/php/tron.php", "serve", "--siteaccess=support", "--token-file=<file>"] }
  }
}
```

### Separate users per siteaccess

An agent that edits the public site and an agent that answers support should be two users with two roles, and two
tokens, even on one database. The audit then tells them apart by login, and revoking one does not stop the other.

## Siteaccesses with their own databases

Some installations give a siteaccess its own database (a separate shop, an intranet). Then each database is its own
tron world:

- run `tron.php install --siteaccess=<sa>` once per database;
- make tokens per database: a token of one does not exist in the other;
- users and roles differ per database; give `tron/use` and the program functions in each.

## Several installations

Each installation runs its own tron, with its own users, tokens and audit. An agent that works across installations
gets one MCP server entry per installation, named so the tools stay apart:

```bash
claude mcp add tron-shop -- ssh -o BatchMode=yes root@shop-node \
    "<php> <shop root>/extension/tron/bin/php/tron.php serve --siteaccess=admin --allow-root-user --token-file=/root/.config/tron/agent-shop.token"
claude mcp add --transport http tron-docs https://docs.example.com/tron/mcp --header "Authorization: Bearer $TRON_DOCS_TOKEN"
```

Tool names are the same in every installation (`centre_ticket_list`); the client prefixes them with the server name
(`mcp__tron-shop__centre_ticket_list`, `mcp__tron-docs__centre_ticket_list`). Tell the agent which installation is
which in the project instructions:

```
tron-shop is the shop installation (orders, products). tron-docs is the documentation site. Never copy text from one
into the other without asking.
```

Keep a token per installation and agent, and the token files apart (`agent-shop.token`, `agent-docs.token`), so a
leak or a revoke touches one installation only.

Calls that route between installations on their own (federation) are planned ([roadmap.md](roadmap.md)); today the
agent is the one that combines them.

## Clusters

Several serving nodes for one installation are one installation to tron:

- the tables live in the shared database, so tokens and audit are shared;
- HTTP is stateless, so a load balancer may send any request to any node: no stickiness;
- **tron must be on every serving node before traffic reaches it**: a node without the extension answers `/tron/mcp`
  with 404;
- the file sink, if used, is per node (`var/log` is usually local); ship it or use the database sink for one view;
- deploy changes to every node, and where Velocity serves, restart its workers so they load the new classes.

[hosting.md](hosting.md) has the load-balancer details.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
