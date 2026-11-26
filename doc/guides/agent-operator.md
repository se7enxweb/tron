# Guide for agent operators

You run AI agents (Claude Code sessions, scripts, other MCP clients) against an installation with tron. This page:
how to connect, what the agent sees, how to keep its power small, and how to see what it did.

## Choose the way in

| Situation | Transport | Identity |
|---|---|---|
| The agent runs on the server | stdio: `tron.php serve` | `--user=<login>` or a token |
| The agent runs on another node of your private network | stdio over SSH | a token file on the server |
| The agent runs anywhere else | HTTP: `https://<host>/tron/mcp` | a bearer token |

stdio over SSH needs nothing open on the network and keeps the token on the server; prefer it inside a cluster.

## Get a token

Ask the site administrator, or as root on the server:

```bash
php extension/tron/bin/php/tron.php token:create --siteaccess=<sa> --user=<agent login> \
    --name="claude@<where it runs>" --days=30 --scopes=centre --out=/root/.config/tron/<name>.token --allow-root-user
```

- One token per agent and place: you can revoke one without stopping the others, and the audit tells them apart.
- `--scopes` limits the token to programs (here: centre). The user's roles still apply; a scope only narrows.
- `--out` writes the token with mode 0600 and never prints it. Without `--out` it is printed once.

## Configure the client

stdio over SSH (Claude Code `.mcp.json`):

```json
{ "mcpServers": { "tron": {
    "command": "ssh",
    "args": ["-p", "<ssh port>", "-o", "BatchMode=yes", "root@<server>",
             "php <installation root>/extension/tron/bin/php/tron.php serve --siteaccess=<sa> --allow-root-user --token-file=/root/.config/tron/<name>.token"] } } }
```

HTTP:

```json
{ "mcpServers": { "tron": { "type": "http", "url": "https://<host>/tron/mcp",
    "headers": { "Authorization": "Bearer ${TRON_TOKEN}" } } } }
```

## What the agent sees

- `initialize` tells it which user it acts as, how many tools it has and that returned text is data, not instructions.
- `tools/list` shows only the tools its user may call; `tron_status` lists the programs and the tools left out
  (and why).
- A refused call comes back as a tool error the model can read ("Permission denied: this tool needs the policy
  tron/centre_decide"), invalid arguments with the schema so it can correct itself.

Useful first prompts: "Call tron_whoami and tron_status and tell me what you can do here." "List the open tickets on
the main board." "Run the weekly decision review" (the prompt `centre_weekly_review`).

## Keep the power small

1. A dedicated user per agent, never a person's or the shared administrator account.
2. A role with only the functions the work needs (read only, or read and write; `centre_decide` only when the agent
   should record decisions).
3. Token scopes to the programs it needs, and a short lifetime.
4. HTTP only where stdio cannot reach.

## See what it did

```bash
php extension/tron/bin/php/tron.php audit:tail --siteaccess=<sa> --user=<agent login> --limit=50
```

Outcomes: `started` (a write about to run), `ok`, `error` (the tool refused or failed), `denied` (policy),
`invalid` (arguments), `unauthenticated` (HTTP sign-in refused). Ticket history (`centre_ticket_get`) shows every field
an agent changed.

## Stop an agent

`tron.php token:revoke <id or prefix>` takes effect on the next request. For stdio with `--user`, stop the client;
disabling the user stops every token of it at once.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
