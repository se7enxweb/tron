# MCP engines

An MCP engine is an extension that serves tools to Exponential Tron and, through tron, to every host that asks tron for tools:
expai's assistant in the admin panel, and tron's own stdio and HTTP transports. Several engines are active at once;
each engine's tools carry its own prefix.

| Prefix | Tools of |
|---|---|
| `tron__<tool>` | Exponential Tron's own programs (`tron__tron_whoami`, `tron__centre_ticket_list`), as hosts see them |
| `<id>__<tool>` | the engine registered as `[McpEngine_<id>]` (`notes__note_list`) |

Over stdio and HTTP Exponential Tron's own tools keep their plain names (`tron_whoami`); engine tools are `<id>__<tool>` there
too.

Whatever engine serves a tool, Exponential Tron does the same around every call: it offers the tool only to users who hold its
policy, checks the policy again on the call, writes the call to the audit log (a write tool before it runs, and it
does not run when the audit log cannot take the entry), and honours a token's program scopes, `[ProgramSettings]
Disabled[]` and the switches of Setup > tron. An engine id is a program name for all of these.

## Registering an engine

An extension ships the registration in its own `settings/tron.ini.append.php`. Activating the extension is enough;
neither Exponential Tron nor the host changes.

```ini
[McpEngine_notes]
Class=myNotesEngine
Enabled=enabled
Label=Notes
# module/function a read tool without its own policy needs; empty: none beyond the session
ReadPolicy=
# module/function a write tool without its own policy needs; empty: the engine's write tools are not offered
WritePolicy=notes/write
```

- The id is `a-z0-9`, at most 20 characters, starting with a letter. `tron` is Exponential Tron itself; `mcp` is reserved for
  hosts that reach remote MCP servers. An id that is already a program name is a conflict.
- `Enabled=disabled` switches the engine off. The change takes effect on the next request after the INI cache is
  cleared (`--clear-tag=ini`); tron keeps nothing between requests.
- A failing engine (missing class, wrong kind, a broken catalogue) costs only itself: `tron_status`, Setup > tron
  and `tron-engines.php` show it with the reason.

## The two interfaces

### The neutral contract: `tronMcpEngine`

```php
interface tronMcpEngine
{
    public function describeServer(): array;                    // name, title, version, description
    public function listTools(): array;                         // the whole catalogue, independent of the caller
    public function callTool( string $name, array $arguments, tronPrincipal $principal );
    public function writes( string $name ): bool;
    public function schema( string $name ): array;
}
```

Each tool of `listTools()` is an array with `name` (`a-z0-9_`, at most 40 characters), `description`,
`inputSchema` (type object), `write` (bool) and optionally `title` and `policy` (`array( module, function )`).
`callTool()` runs as the Exponential user of `$principal` and returns an array or a string; it throws
`tronToolError` for an answer the caller should see as an error. Exponential Tron builds the class with
`new Class( $settings )` (the INI group), or `Class::fromSettings( $settings )` when it has that method.

### The bridge API: `tronMcpBridgeApi`

Some extensions already offer their tools to other extensions in process, through a class of static methods.
`tronMcpBridgeApi` describes that shape exactly:

```php
public static function tools();
public static function toolNames();
public static function isBridgeSafe( $name );
public static function description( $name );
public static function inputSchema( $name );
public static function tool( $name, $siteAccess = null, ?eZINI $ini = null );
public static function call( $name, array $input = array(), $siteAccess = null, ?eZINI $ini = null );
```

A class of this shape is wrapped by `tronMcpBridgeEngine` on its own. It does not even have to declare the
interface: a class with these seven public static methods and parameters is recognised, so an engine written
against the same API elsewhere drops in with only its `[McpEngine_<id>]` group.

Bridge tools are read tools that run as the signed-in user of the request. They are therefore offered only to
hosts in the same request (expai); over stdio and HTTP they are listed as not available, with the reason. A call for
another user than the signed-in one is refused.

## Hosts

A host asks the hub for the tools of its user and calls them by their prefixed names:

```php
$hub = tronMcpEngineHub::kernel( 'expai' );                // built anew for each request
$principal = new tronPrincipal( $userId, $login );
foreach ( $hub->tools( $principal ) as $tool )             // name, engine, tool, title, description, inputSchema, write
    ...
$answer = $hub->call( 'notes__note_list', array(), $principal );   // throws tronToolError on a refusal
```

The calls go through Exponential Tron's server as transport `inprocess`, with the host's name as the audit's remote. A call of
a tool tron does not have is audited as well (outcome `invalid`) before it is refused.

expai uses the hub when Exponential Tron is active: see expai's doc/mcp.md, `[McpEngineSettings]` (which engines, which may
write) and the policy `expai/mcp` with Server set to the engine id.

## Discovery: the interface scan

Besides the INI registration, Exponential Tron can scan the extension autoload array for classes that implement
`tronMcpEngine` or `tronMcpBridgeApi`, or have the bridge API's methods. The scan reads the class files; it loads
none of them.

```
php extension/tron/bin/php/tron-engines.php --siteaccess=<sa>                 engines, states, tool counts
php extension/tron/bin/php/tron-engines.php --siteaccess=<sa> --scan          plus every class the scan finds
php extension/tron/bin/php/tron-engines.php --siteaccess=<sa> --user=<login>  the prefixed tools that user may use
```

The scan is a diagnostic. With `[McpEngineSettings] ScanRegister=enabled` a class it finds and no group names is
registered too, under an id made from its class name; that reads the autoload array on every server build, so
register engines by INI for production.

## Tests

`tests/unit/McpEngineTest.php`, with the stand-ins of `tests/fixtures/mcp_engine_stubs.php` registered by settings
alone: a class with only the bridge API (no interface), an engine of the neutral contract with a read and a write
tool, and a class with half the bridge API that is refused. It proves the prefixes, the same refusal of a write tool
for a viewer through every engine with an audit entry for each call, switching engines off and on, failures that
cost only their engine, and the scan.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
