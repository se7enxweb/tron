# Quick start in 5 minutes

The shortest path from an Exponential installation to an AI agent calling tron's tools. Every command runs from the
installation root. `<sa>` is an administration siteaccess (for example `admin`), `<php>` the PHP binary the
installation runs (it may need a newer PHP than tron does). Run as root, every command needs `--allow-root-user`.

For the long form with the reasons behind each step, read [getting-started.md](getting-started.md); for every step
with its undo, [runbook.md](runbook.md).

## 1. Install and activate (1 minute)

```bash
composer require se7enxweb/tron        # or: git clone <tron repository> extension/tron
```

`settings/override/site.ini.append.php`:

```ini
[ExtensionSettings]
ActiveExtensions[]=tron
```

```bash
<php> bin/php/ezpgenerateautoloads.php -e
<php> bin/php/ezcache.php --clear-tag=ini
<php> extension/tron/bin/php/tron.php install --siteaccess=<sa> --dry-run    # prints the CREATE statements
<php> extension/tron/bin/php/tron.php install --siteaccess=<sa>              # creates the seven tron_* tables
<php> extension/tron/bin/php/tron.php status  --siteaccess=<sa>              # ends in PASS
```

Where Velocity serves the installation, deploy with `./console exp:velocity deploy` instead of the cache line, so its
workers load the new classes.

## 2. Make a user for the agent (1 minute)

In the administration: **Users**, a new user (for example `agent-claude`), never a person's account and never the
shared administrator. Then **Roles and policies**, a new role `tron Editor`:

| Module | Function | Limitation |
|---|---|---|
| tron | use | none |
| tron | centre_read | none |
| tron | centre_write | none |

Assign the role to the new user. Grant the functions **without** limitations: tron treats a limited policy as not
granted ([permissions.md](permissions.md)).

## 3. Make a token (30 seconds)

```bash
mkdir -p ~/.config/tron && chmod 700 ~/.config/tron
<php> extension/tron/bin/php/tron.php token:create --siteaccess=<sa> --user=agent-claude \
      --name="claude@laptop" --days=30 --scopes=centre --out=$HOME/.config/tron/agent-claude.token
```

The token is written with mode 0600 and never printed. Only its SHA-256 hash is kept in the database.
[tokens.md](tokens.md) covers rotation, revocation and expiry.

## 4. Register tron with your MCP client (1 minute)

**Claude Code** (on the server, stdio):

```bash
claude mcp add tron -- <php> <installation root>/extension/tron/bin/php/tron.php serve \
    --siteaccess=<sa> --token-file=$HOME/.config/tron/agent-claude.token
claude mcp list          # tron: ... - Connected
```

**Claude Desktop** (`claude_desktop_config.json`), **Cursor** (`.cursor/mcp.json`) and other clients take the same
command in a JSON entry:

```json
{
  "mcpServers": {
    "tron": {
      "command": "<php>",
      "args": ["<installation root>/extension/tron/bin/php/tron.php", "serve",
               "--siteaccess=<sa>", "--token-file=/home/<you>/.config/tron/agent-claude.token"]
    }
  }
}
```

From another machine, put `ssh` in front of it, or use the HTTP endpoint. Every client, both transports:
[clients.md](clients.md).

## 5. The first calls (1 minute)

Ask the agent:

> Call tron_whoami and tron_status and tell me what you can do here.

`tron_whoami` answers with the user it acts as:

```json
{"user_id": 3, "login": "agent-claude", "token_id": 1, "scopes": ["centre"], "transport": "stdio", "protocol_version": "2025-11-25"}
```

`tron_status` lists the programs (`tron`, `centre`, `katalyst` and any of your own), their state and the tools left
out with the reason. Then:

> Make a ticket "Renew the certificate of www", high priority, labels certs and ops, due Friday.

The agent calls `centre_ticket_create`. Check what happened:

```bash
<php> extension/tron/bin/php/tron.php audit:tail --siteaccess=<sa> --limit=10
```

```
2026-11-24 12:00:01 agent-claude stdio  tools/call      tron_whoami                      ok                   21 ms
2026-11-24 12:00:02 agent-claude stdio  tools/call      tron_status                      ok                   33 ms
2026-11-24 12:00:09 agent-claude stdio  tools/call      centre_ticket_create             started               0 ms
2026-11-24 12:00:09 agent-claude stdio  tools/call      centre_ticket_create             ok                   58 ms
```

A write tool leaves two rows: `started` before it runs (no row, no run) and its outcome after.

## Where next

| Goal | Page |
|---|---|
| Agents outside the server, over HTTPS | [http-transport.md](http-transport.md), [hosting.md](hosting.md) |
| Real tasks, end to end | [use-cases.md](use-cases.md), [prompt-cookbook.md](prompt-cookbook.md) |
| Who may do what | [permissions.md](permissions.md), [security.md](security.md) |
| Several installations or siteaccesses | [multi-site.md](multi-site.md) |
| Something does not work | [troubleshooting.md](troubleshooting.md) |

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
