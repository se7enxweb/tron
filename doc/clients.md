# Connecting MCP clients

tron speaks standard MCP over two transports, so any MCP client works. This page gives the configuration for the
common clients, for each of the three ways in:

| Way in | When | Identity | Network |
|---|---|---|---|
| **stdio on the server** | the agent runs on a node of the installation | `--token-file`, `TRON_TOKEN` or `--user=<login>` | nothing listens |
| **stdio over SSH** | the agent runs on another machine that may SSH to a node | a token file that stays on the node | SSH only |
| **HTTP** | the agent runs anywhere else | `Authorization: Bearer <token>` | the endpoint `/tron/mcp` over HTTPS |

Prefer stdio when you can: nothing is exposed, and the token never leaves the server. Use HTTP for agents that cannot
SSH to a node. Client configuration formats change between client releases; when a key below is refused, check the
client's own documentation for the current form.

Placeholders: `<php>` the installation's PHP binary, `<root>` the installation root, `<sa>` the siteaccess,
`<host>` the public host name, `<node>` an SSH-reachable node.

## The server command

Every stdio configuration runs this command:

```bash
<php> <root>/extension/tron/bin/php/tron.php serve --siteaccess=<sa> --token-file=<file> [--allow-root-user]
```

| Identity option | Read from | Notes |
|---|---|---|
| `TRON_TOKEN` | the environment | checked first |
| `--token-file=<file>` | a file holding the token (one line) | the usual choice; keep the file 0600 |
| `--user=<login>` | the command line | no token at all; allowed only while `[StdioSettings] AllowLoginOption=enabled` |

The server refuses to start when the user is missing or disabled, when the user lacks `tron/use`, or when tron's tables
are missing; the reason is printed on stderr (MCP clients show it in their server log). `tron.php` changes into the
installation root itself, so an absolute path from any working directory works. stdout carries only MCP messages.

## Claude Code

### stdio on the server

```bash
claude mcp add tron -- <php> <root>/extension/tron/bin/php/tron.php serve \
    --siteaccess=<sa> --token-file=/root/.config/tron/<name>.token --allow-root-user
```

Add `--scope project` to write it into the project's `.mcp.json` (shared with the team; never put a token in it), or
`--scope user` for every project of your account. The JSON form, for `.mcp.json` or `~/.claude.json`:

```json
{
  "mcpServers": {
    "tron": {
      "type": "stdio",
      "command": "<php>",
      "args": ["<root>/extension/tron/bin/php/tron.php", "serve", "--siteaccess=<sa>",
               "--allow-root-user", "--token-file=/root/.config/tron/<name>.token"]
    }
  }
}
```

### stdio over SSH

```bash
claude mcp add tron -- ssh -p <port> -o BatchMode=yes root@<node> \
    "<php> <root>/extension/tron/bin/php/tron.php serve --siteaccess=<sa> --allow-root-user --token-file=/root/.config/tron/<name>.token"
```

The token file lives on the node; the client machine only needs its SSH key. `BatchMode=yes` makes SSH fail at once
instead of waiting for a password prompt the client cannot answer.

### HTTP

```bash
export TRON_TOKEN="$(cat ~/.config/tron/<name>.token)"
claude mcp add --transport http tron https://<host>/tron/mcp --header "Authorization: Bearer $TRON_TOKEN"
```

or in JSON, with the token taken from the environment when the session starts:

```json
{
  "mcpServers": {
    "tron": { "type": "http", "url": "https://<host>/tron/mcp",
              "headers": { "Authorization": "Bearer ${TRON_TOKEN}" } }
  }
}
```

### Check it

```bash
claude mcp list                  # tron ... Connected
claude mcp get tron
```

In a session, `/mcp` shows the server and its tools. Claude Code names tron's tools `mcp__tron__<tool>` (for example
`mcp__tron__centre_ticket_list`); with several installations, the server name keeps them apart
([multi-site.md](multi-site.md)). A session loads its MCP servers when it starts: after changing the configuration,
start a new session.

## Claude Desktop

`claude_desktop_config.json` (Settings, Developer, Edit Config). Desktop starts local stdio servers:

```json
{
  "mcpServers": {
    "tron": {
      "command": "ssh",
      "args": ["-p", "<port>", "-o", "BatchMode=yes", "root@<node>",
               "<php> <root>/extension/tron/bin/php/tron.php serve --siteaccess=<sa> --allow-root-user --token-file=/root/.config/tron/<name>.token"]
    }
  }
}
```

On a machine that holds the installation itself, use the `<php>` command directly instead of `ssh`. Restart Desktop
after editing the file.

For the HTTP endpoint, Desktop's remote connectors expect OAuth sign-in, which tron does not offer yet (planned, see
[roadmap.md](roadmap.md)). Until then use stdio over SSH, or a local stdio-to-HTTP bridge that adds the
`Authorization` header (for example the community package `mcp-remote` with its `--header` option) if your policy
allows third-party bridges.

## Cursor

`.cursor/mcp.json` in the project, or `~/.cursor/mcp.json` for every project.

stdio:

```json
{
  "mcpServers": {
    "tron": {
      "command": "<php>",
      "args": ["<root>/extension/tron/bin/php/tron.php", "serve", "--siteaccess=<sa>", "--token-file=<file>"]
    }
  }
}
```

HTTP:

```json
{
  "mcpServers": {
    "tron": {
      "url": "https://<host>/tron/mcp",
      "headers": { "Authorization": "Bearer ${env:TRON_TOKEN}" }
    }
  }
}
```

## VS Code (GitHub Copilot agent mode)

`.vscode/mcp.json`; the `inputs` block asks for the token once and stores it in VS Code's secret storage, so it is
never written into the file:

```json
{
  "inputs": [
    { "type": "promptString", "id": "tron-token", "description": "tron token", "password": true }
  ],
  "servers": {
    "tron": {
      "type": "http",
      "url": "https://<host>/tron/mcp",
      "headers": { "Authorization": "Bearer ${input:tron-token}" }
    }
  }
}
```

## Any other client

| The client needs | tron gives |
|---|---|
| a stdio command | `tron.php serve ...` as above; newline-delimited JSON-RPC 2.0 on stdin/stdout |
| a "streamable HTTP" URL | `https://<host>/tron/mcp` (or `/mcp` with the URL alias); POST only, JSON answers |
| an "SSE" URL (the 2024-11-05 HTTP+SSE transport) | not offered; use streamable HTTP or stdio |
| authentication | a static bearer header; OAuth is planned |
| a protocol version | 2025-11-25, 2025-06-18, 2025-03-26 or 2024-11-05 |

Raw protocol examples for building or debugging a client: [http-transport.md](http-transport.md) and the stdio client
of the cookbook (`examples/scripts/08-stdio-client.php`).

## Checking an entry before a session uses it

The smoke clients run the exact command or URL a client would and print PASS or FAIL per check:

```bash
<php> extension/tron/tests/kernel/stdio_smoke.php -- <php> <root>/extension/tron/bin/php/tron.php serve \
      --siteaccess=<sa> --allow-root-user --token-file=<file>
<php> extension/tron/tests/kernel/http_smoke.php --url=https://<host>/tron/mcp --token-file=<file>
```

Both are read-only unless given `--write` (which creates and closes one ticket). They never print the token.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
