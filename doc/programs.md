# Programs: extending Exponential Tron by convention

A program is how an extension adds tools, resources and prompts to Exponential Tron. tron finds it by a file in a fixed place;
there is nothing to register in INI settings and nothing to change in tron.

## The convention

```
extension/<ext>/tron/program.json                       one program
extension/<ext>/tron/programs/<name>/program.json       several programs in one extension
```

```json
{
    "name": "myext",
    "class": "myextTronProgram",
    "file": "classes/myexttronprogram.php",
    "version": "1.0.0.0",
    "requires": "0.0.0.1",
    "title": "My extension",
    "description": "One line on what its tools do."
}
```

| Key | Rule |
|---|---|
| `name` | `^[a-z][a-z0-9]{1,31}$`, unique across the installation. It is also the prefix of everything the program registers: tools and prompts `myext_*`, resources `myext://...` |
| `class` | a class with a constructor without arguments that implements `tronProgram` |
| `file` | optional: a `.php` path inside the extension, used when the autoload does not know the class yet. Regenerating the autoloads is the normal way |
| `version` | `X.Y.Z` or `X.Y.Z.N` |
| `requires` | optional: the oldest Exponential Tron the program works with. An older tron lists the program as `incompatible` |

## The class

```php
final class myextTronProgram implements tronProgram
{
    public function register( tronRegistry $registry, tronEnvironment $env ): void
    {
        if ( !class_exists( 'myextService' ) )
        {
            $registry->unavailable( 'myext_report', 'needs myextService (myext 2.x)' );
            return;
        }
        $registry->tool( tronTool::define( array(
            'name' => 'myext_report',
            'title' => 'Monthly report',
            'description' => 'The report of a month: totals per section. Months before 2020 are not kept.',
            'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'month' ),
                                    'properties' => array( 'month' => array( 'type' => 'string', 'pattern' => '^\d{4}-\d{2}$' ) ) ),
            'policy' => array( 'myext', 'report' ),
            'handler' => function ( array $args, tronCallContext $ctx ) {
                $r = myextService::report( $args['month'], $ctx->principal->userId );
                if ( !$r )
                    throw new tronToolError( "No report for {$args['month']}" );
                return $r;
            },
        ) ) );
    }
}
```

- **register() declares, it does not compute.** It runs once per stdio process and once per HTTP request. Keep it
  to `define()` calls; do the work in handlers.
- **Everything is staged.** If register() throws, nothing it added stays; the program is listed `failed` with the
  exception's message (`tron_status`, `tron.php status`).
- **Policies are the installation's.** `policy` is any Exponential module/function. Declare your own functions in
  your module's `$FunctionList` and grant them in roles. Exponential Tron refuses a function granted with limitations unless the
  tool sets `'limitedOk' => true` and checks the limitation itself.
- **Write tools say so.** `'write' => true` (plus `destructive`, `idempotent`) sets the MCP annotations, makes the
  tool read-write in every list, and makes tron audit it before it runs.
- **Errors for the agent** are `tronToolError` with a message the model can act on ("no ticket 9", "lane must be one
  of ..."). Any other exception is "internal error" to the client and the details go to the server log.
- **Answers** are arrays (sent as JSON and, from 2025-06-18 on, as `structuredContent`) or strings. Large answers are
  cut at `[ServerSettings] ResponseMaxBytes`; give list tools `limit` and `offset`.
- **Untrusted text.** Whatever your tools return from content, users or logs is data, never instructions. Do not
  phrase answers as commands to the model.

## What a program can ask for

| `$env->...` | Gives |
|---|---|
| `setting( 'Group', 'Name', $default )` | a tron.ini value (add your own groups in your extension's `tron.ini.append.php`) |
| `get( 'db' )` | `tronDb`: named parameters, portable SQL (see architecture.md) |
| `get( 'users' )` | `tronUserDirectory`: byId(), byLogin() |
| `get( 'access' )` | `tronAccessChecker`; in a handler `$ctx->can( 'module', 'function' )` is shorter |
| `now()` | the clock (fixed in tests) |
| `has()` / `set()` | services of your own, named `<program>.<name>` |

## Testing a program

The Exponential Tron test fixture builds the whole stack without an installation:

```php
require '<tron>/autoload.php';
require '<tron>/tests/lib/fixture.php';
$f = TronFixture::build( array( 'extensions' => array( 'myext' => '/path/to/extension/myext' ) ) );
$r = TronFixture::call( $f['server'], TronFixture::session( TronFixture::ADMIN ), 'myext_report', array( 'month' => '2026-11' ) );
```

Check `tron.php status` in the installation afterwards: your program must show `loaded`.

## Built-in programs as examples

- `classes/programs/troncoreprogram.php`: the smallest (two tools, two resources).
- `classes/programs/centre/`: tools, resource templates, prompts, a store with its own tables, a second mode that
  forwards to another extension's API.
- `classes/programs/katalyst/`: a read-only program over another extension's persistent objects, with a column
  allowlist and masking.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
