# Getting started

From nothing to an AI agent working in your Exponential installation, in five steps. About fifteen minutes.

**Preview.** tron 0.0.0.12 is the first public release, a preview proven on a development installation (see
[testing.md](testing.md)). Interfaces may still change between preview releases.

## 0. Try it without an installation

```bash
git clone <tron> tron && cd tron
php tests/run.php                        # about 86 tests: should end in PASS
php examples/scripts/01-first-call.php   # a server, a session, the tools, who you are
```

Every example in [cookbook.md](cookbook.md) runs like this.

## 1. Install

On the installation's master tree (as the site's user or with `--allow-root-user` as root):

```bash
composer require se7enxweb/tron                 # or: git clone <tron> extension/tron
```

Add to `settings/override/site.ini.append.php`, under `[ExtensionSettings]`:

```ini
ActiveExtensions[]=tron
```

Then, on every serving node (after your sync, if you have one):

```bash
php bin/php/ezpgenerateautoloads.php -e
php bin/php/ezcache.php --clear-tag=ini
php extension/tron/bin/php/tron.php install --siteaccess=<admin siteaccess> --dry-run    # read the SQL
php extension/tron/bin/php/tron.php install --siteaccess=<admin siteaccess>
php extension/tron/bin/php/tron.php status  --siteaccess=<admin siteaccess>              # PASS
```

Where Velocity serves, deploy with `./console exp:velocity deploy` instead of the cache line.

## 2. Give someone the right to use it

In the administration: Roles and policies, a new role "tron Editor" with the policies

| Module | Function |
|---|---|
| tron | use |
| tron | centre_read |
| tron | centre_write |

assigned to the user the agent will act as. [guides/site-admin.md](guides/site-admin.md) has the suggested roles.

## 3. Connect an agent over stdio

Make a token for the user the agent acts as, written to a file only that account can read:

```bash
php extension/tron/bin/php/tron.php token:create --siteaccess=<sa> --token-file=<issuer token> --user=<login> --name="agent on this node" --out=<token file>
```

On the server, Claude Code's `.mcp.json`:

```json
{
  "mcpServers": {
    "tron": {
      "command": "php",
      "args": ["<installation root>/extension/tron/bin/php/tron.php", "serve", "--siteaccess=<sa>", "--token-file=<token file>"]
    }
  }
}
```

Ask the agent: "Which tron tools do you have, and who are you?" It calls `tron_whoami`. Then: "Make a ticket to renew
the certificate, high priority." It calls `centre_ticket_create`.

From another machine on your network, run the same command over SSH; the token file stays on the node
([guides/agent-operator.md](guides/agent-operator.md)).

## 4. Check the audit

```bash
php extension/tron/bin/php/tron.php audit:tail --siteaccess=<sa> --limit=20
```

Every call, with who, what, the outcome and how long it took.

## 5. Optional: HTTP for remote agents

Make a token, open the endpoint for one siteaccess, connect with `Authorization: Bearer`.
[hosting.md](hosting.md) has every step, including the request shield and the Apache header.

## Where next

| You are | Read |
|---|---|
| running agents against tron | [guides/agent-operator.md](guides/agent-operator.md) |
| responsible for the installation | [guides/site-admin.md](guides/site-admin.md) |
| writing a program for your extension | [guides/program-author.md](guides/program-author.md) |
| wondering what tron guarantees | [theory.md](theory.md) |
| stuck | [troubleshooting.md](troubleshooting.md), [faq.md](faq.md) |

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
