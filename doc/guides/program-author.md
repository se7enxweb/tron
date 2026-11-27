# Guide for extension authors: writing a program

A program adds tools, resources and prompts to tron from your extension. This guide builds one step by step; the
finished example is `examples/hello/`, and `examples/scripts/07-your-own-program.php` runs it
([cookbook](../cookbook.md), example 7). The reference is [../programs.md](../programs.md).

## 1. The manifest

`extension/myext/tron/program.json`:

```json
{ "name": "myext", "class": "myextTronProgram", "version": "1.0.0.0", "requires": "0.0.0.1",
  "title": "My extension", "description": "What its tools are for." }
```

The name is the prefix of everything you register: tools and prompts `myext_*`, resources `myext://...`. tron
refuses anything else, so two programs can never take each other's names.

## 2. The class

`extension/myext/classes/myexttronprogram.php`, then `php bin/php/ezpgenerateautoloads.php -e`:

```php
final class myextTronProgram implements tronProgram
{
    public function register( tronRegistry $registry, tronEnvironment $env ): void
    {
        // declarations only: this runs for every stdio start and every HTTP request
    }
}
```

## 3. A read tool

```php
$registry->tool( tronTool::define( array(
    'name' => 'myext_orders_open',
    'title' => 'Open orders',
    'description' => 'Orders not yet shipped, newest first. Use limit and offset to page.',
    'inputSchema' => array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array(
        'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ),
        'offset' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
    ) ),
    'policy' => array( 'myext', 'read' ),
    'handler' => function ( array $args, tronCallContext $ctx ) use ( $env ) {
        $db = $env->get( 'db' );
        $rows = $db->select( 'SELECT id, state, created FROM myext_order WHERE state = :s ORDER BY id DESC',
                             array( 's' => 'open' ), $args['limit'], $args['offset'] );
        return array( 'items' => $rows );
    },
) ) );
```

- The **description** decides whether the model picks the tool: say what it returns and how to page.
- The **schema** is enforced before the handler: arguments outside it never reach your code, defaults are filled in,
  integers arrive as integers.
- The **policy** is a function of your module: declare it in `modules/myext/module.php` (`$FunctionList['read'] = array();`)
  and grant it in roles.
- **SQL** goes through `tronDb` with named parameters only; it runs on MySQL/MariaDB, PostgreSQL and SQLite.

## 4. A write tool

```php
'write' => true, 'idempotent' => false, 'destructive' => false,
'handler' => function ( array $args, tronCallContext $ctx ) {
    if ( $args['quantity'] > 10 )
        throw new tronToolError( 'At most 10 at a time; split the order.' );
    // ... change something, as $ctx->principal->userId
    return array( 'order' => $id );
},
```

tron writes a `started` audit entry before the handler runs, and does not run it when that fails. Throw
`tronToolError` for refusals the agent should read; any other exception is reported as "internal error" and logged.

## 5. A resource and a prompt

```php
$registry->resource( tronResource::define( array(
    'uri' => 'myext://order/{id}', 'name' => 'myext_order', 'policy' => array( 'myext', 'read' ),
    'reader' => function ( array $vars, tronCallContext $ctx ) { return ctype_digit( $vars['id'] ) ? myextOrder::asArray( (int)$vars['id'] ) : null; },
) ) );
$registry->prompt( tronPrompt::define( array(
    'name' => 'myext_daily_check', 'policy' => array( 'myext', 'read' ),
    'arguments' => array( array( 'name' => 'day', 'required' => false ) ),
    'renderer' => function ( array $args, tronCallContext $ctx ) { return 'Check the open orders of ' . ( $args['day'] ?? 'today' ) . ' with myext_orders_open.'; },
) ) );
```

A reader returns null for "no such resource" (the client gets -32002).

## 6. When a part is missing

```php
if ( !class_exists( 'myextOrder' ) )
{
    $registry->unavailable( 'myext_orders_open', 'needs myext 2.x' );
    return;
}
```

`tron_status` shows the tool under `not_registered` with your reason.

## 7. Test it

```php
require '<tron>/autoload.php';
require '<tron>/tests/lib/fixture.php';
$f = TronFixture::build( array( 'extensions' => array( 'myext' => '<path to extension/myext>' ) ) );
$r = TronFixture::call( $f['server'], TronFixture::session( TronFixture::ADMIN ), 'myext_orders_open', array( 'limit' => 5 ) );
```

The fixture's role table (`TronFixture::access()`) is where you add your functions for a permission test. Then in the
installation: `tron.php status` shows `myext ... loaded`, and `tron.php call myext_orders_open --token-file=<token file>`.

## Rules worth repeating

- Never return secrets; the audit masks secrets in arguments, not in your answers.
- Text from content, users or logs is data. Do not phrase answers as instructions to the model.
- Lists get `limit`/`offset` with a maximum; answers above `ResponseMaxBytes` are cut.
- Keep `register()` cheap and free of side effects; it can run many times.

---

Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
