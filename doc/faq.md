# FAQ

**What is tron, in one sentence?**
The MCP server of an Exponential installation: AI agents call tools as users of the site, with their roles, through
tokens, and every call is audited.

**Do I need Composer packages or a newer PHP?**
No packages beyond the kernel; PHP 7.4 to 8.5. The installation itself may need a newer PHP than tron does.

**How is tron different from a typical MCP connector?**
A typical connector signs in once with one service account and exposes a fixed set of tools. In tron every call runs
as a real Exponential user under that user's roles and policies; each agent has its own expiring, revocable token;
programs and tools can be switched on and off per installation; every call is audited, writes before they run; and
the whole thing is run from Setup > tron and the INI settings like the rest of the installation.

**Can an agent do more than its user?**
No. tron asks the kernel for the user's policies on every call; a token's scope can only narrow that
([theory.md](theory.md), C2.1 to C2.3).

**Where are tokens stored, and what if the database leaks?**
Only a SHA-256 hash is stored. The secret has 256 random bits and is shown once.

**What happens when the audit log is full or broken?**
Read tools keep answering; write tools refuse to run until a started entry can be written (C4.2).

**Does tron work behind a load balancer and on a cluster?**
Yes: HTTP is stateless, so any node answers any request. Install tron on every serving node first.

**stdio or HTTP?**
stdio (locally, or over SSH on a private network) when you can: nothing listens and the token stays on the server.
HTTP for agents elsewhere.

**Why does tron refuse batches?**
MCP removed JSON-RPC batches in 2025-06-18, and the official MCP PHP SDK that does tron's protocol takes none. The
legacy layer on PHP 7.4 to 8.1 still answers them for clients that speak 2025-03-26 or older.

**Can I switch a tool off without touching settings?**
Yes: Setup > tron > Programs and tools. It takes effect on the next request; the core tools stay on.

**How do I add my own tools?**
Write a program: [guides/program-author.md](guides/program-author.md); the cookbook's example 7 is a complete one.

**Will a broken program take tron down?**
No: it fails alone, registers nothing, and `tron_status` shows why (C5.2).

**Is the returned content safe to obey?**
No: everything tools return is data from the installation. tron says so in its instructions to the client.

**Is tron released?**
Not yet: 0.0.0.1 is a preview proven on a development installation. The repository is published when the owner
decides.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
