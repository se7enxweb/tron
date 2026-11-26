<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The API coverage catalog: what tronSystemInventory found, as JSON (doc/api-coverage.json, or the file named by
 * tron.ini [SystemAdapterSettings] CatalogFile), its summary per area, searches over it and its Markdown page
 * (doc/api-coverage.md). Both files are generated: bin/php/tron-inventory.php writes them.
 */
final class tronSystemCatalog
{
    /** The generic adapters: unit id => tool, with what goes in and what comes out (doc/system-adapters.md). */
    const ADAPTERS = array(
        'catalog.search' => array( 'tool' => 'system_catalog', 'in' => 'area, q, risk, covered, origin, limit, offset', 'out' => 'total, items[id, area, name, risk, covered, by, mode, planned]' ),
        'catalog.coverage' => array( 'tool' => 'system_coverage', 'in' => '-', 'out' => 'generated, totals, areas{points, read, read_covered, write, write_covered}' ),
        'fetch.describe' => array( 'tool' => 'system_fetch_describe', 'in' => 'module, function?', 'out' => 'module, functions[name, risk, params[name, type, required], callable]' ),
        'fetch.call' => array( 'tool' => 'system_fetch_call', 'in' => 'module, function, params{}', 'out' => 'module, function, ran_as, result, truncated' ),
        'operator.eval' => array( 'tool' => 'system_operator_eval', 'in' => 'operator, input, params[]', 'out' => 'operator, input, params, result' ),
        'module.view.describe' => array( 'tool' => 'system_module_view_describe', 'in' => 'module, view?', 'out' => 'module, origin, views[name, params, unordered_params, functions, risk, readable], functions{name: limitations}' ),
        'module.view.read' => array( 'tool' => 'system_module_view_read', 'in' => 'module, view, params[], unordered{}, max_text?', 'out' => 'module, view, ran_as, title, path[], text, links[], truncated' ),
        'console.command' => array( 'tool' => 'system_console_command', 'in' => 'command, args[]', 'out' => 'command, mode, exit_code, ms, stdout, stderr, truncated, job' ),
        'cli.script' => array( 'tool' => 'system_cli_script', 'in' => 'script, args[]', 'out' => 'script, mode, exit_code, ms, stdout, stderr, truncated, job' ),
        'queue.jobs' => array( 'tool' => 'system_queue_jobs', 'in' => 'state?, type?, class?, limit, offset', 'out' => 'installed, total, counts{state: n}, items[id, uuid, type, state, class, progress, created, summary]' ),
        'queue.job' => array( 'tool' => 'system_queue_job', 'in' => 'job (id or uuid)', 'out' => 'job{id, uuid, type, state, payload, result, checkpoint, attempts, error}' ),
    );

    const AREA_TITLES = array(
        'module' => 'Modules', 'module_view' => 'Module views', 'policy_function' => 'Policy functions',
        'fetch_function' => 'Template fetch functions', 'operation' => 'Operations', 'template_operator' => 'Template operators',
        'datatype' => 'Datatypes', 'workflow_event' => 'Workflow event types', 'service_method' => 'Service methods (content, class, user, role, extensions)',
        'ini_file' => 'INI files', 'ini_block' => 'INI blocks', 'console_command' => 'Console commands', 'bin_script' => 'bin/php scripts',
        'cronjob_part' => 'Cronjob parts', 'queue_job_type' => 'expqueue job types',
    );

    /** @var array */ private $data;

    public function __construct( array $data )
    {
        if ( ( $data['catalog'] ?? '' ) !== 'tron-api-coverage' || !isset( $data['points'] ) || !is_array( $data['points'] ) )
            throw new InvalidArgumentException( 'Not a tron API coverage catalog' );
        foreach ( $data['points'] as $i => $p )
            $data['points'][$i] = $p + array( 'origin' => '', 'source' => '', 'summary' => '', 'meta' => array(), 'covered' => false,
                                              'by' => '', 'via' => '', 'mode' => '', 'planned' => '' );
        $this->data = $data;
    }

    public static function load( string $file ): self
    {
        $raw = @file_get_contents( $file );
        if ( $raw === false )
            throw new RuntimeException( "The catalog $file cannot be read; generate it with bin/php/tron-inventory.php" );
        $d = json_decode( $raw, true );
        if ( !is_array( $d ) )
            throw new RuntimeException( "The catalog $file is not JSON" );
        return new self( $d );
    }

    /** The catalog file of an installation: the setting, else var/tron/api-coverage.json, else tron's doc copy. */
    public static function file( tronEnvironment $env ): string
    {
        $set = (string)$env->setting( 'SystemAdapterSettings', 'CatalogFile', '' );
        foreach ( array( $set, 'var/tron/api-coverage.json', dirname( __DIR__, 3 ) . '/doc/api-coverage.json' ) as $f )
            if ( $f !== '' && is_file( $f ) )
                return $f;
        return dirname( __DIR__, 3 ) . '/doc/api-coverage.json';
    }

    /**
     * The catalog as JSON: compact, one point per line, so that a regenerated catalog diffs point by point and stays
     * small. Empty meta and plan fields are left out of a point (load() puts the defaults back).
     */
    public static function encode( array $data ): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $points = $data['points'];
        unset( $data['points'] );
        $lines = array();
        foreach ( $points as $p )
        {
            foreach ( array( 'meta' => array(), 'planned' => '', 'by' => '', 'via' => '', 'mode' => '', 'source' => '' ) as $k => $empty )
                if ( array_key_exists( $k, $p ) && $p[$k] === $empty )
                    unset( $p[$k] );
            $lines[] = json_encode( $p, $flags );
        }
        $head = json_encode( $data, $flags | JSON_PRETTY_PRINT );
        return substr( $head, 0, -2 ) . ",\n    \"points\": [\n" . implode( ",\n", $lines ) . "\n    ]\n}";
    }

    public function data(): array
    {
        return $this->data;
    }

    public function summary(): array
    {
        return $this->data['summary'] ?? self::summarize( $this->data['points'] );
    }

    /** Points by area: count, read points and how many are covered, write/admin/dangerous points and how many have a dry run. */
    public static function summarize( array $points ): array
    {
        $areas = array();
        $tot = array( 'points' => 0, 'read' => 0, 'read_covered' => 0, 'write' => 0, 'write_covered' => 0 );
        foreach ( $points as $p )
        {
            $a = $p['area'];
            if ( !isset( $areas[$a] ) )
                $areas[$a] = array( 'points' => 0, 'read' => 0, 'read_covered' => 0, 'write' => 0, 'write_covered' => 0,
                                    'risk' => array( 'read' => 0, 'write' => 0, 'admin' => 0, 'dangerous' => 0 ), 'by' => array() );
            $areas[$a]['points']++;
            $areas[$a]['risk'][$p['risk']] = ( $areas[$a]['risk'][$p['risk']] ?? 0 ) + 1;
            $isRead = $p['risk'] === tronSystemRisk::READ;
            $k = $isRead ? 'read' : 'write';
            $areas[$a][$k]++;
            if ( !empty( $p['covered'] ) )
            {
                $areas[$a][$k . '_covered']++;
                $areas[$a]['by'][$p['by']] = ( $areas[$a]['by'][$p['by']] ?? 0 ) + 1;
            }
        }
        foreach ( $areas as $a => $row )
        {
            arsort( $areas[$a]['by'] );
            foreach ( array( 'points', 'read', 'read_covered', 'write', 'write_covered' ) as $k )
                $tot[$k] += $row[$k];
        }
        $pct = function ( int $n, int $of ) { return $of > 0 ? round( 100 * $n / $of, 1 ) : 0.0; };
        $tot['read_pct'] = $pct( $tot['read_covered'], $tot['read'] );
        $tot['write_pct'] = $pct( $tot['write_covered'], $tot['write'] );
        $tot['all_pct'] = $pct( $tot['read_covered'] + $tot['write_covered'], $tot['points'] );
        foreach ( $areas as $a => $row )
        {
            $areas[$a]['read_pct'] = $pct( $row['read_covered'], $row['read'] );
            $areas[$a]['write_pct'] = $pct( $row['write_covered'], $row['write'] );
        }
        $ordered = array();
        foreach ( tronSystemInventory::AREAS as $a )
            if ( isset( $areas[$a] ) )
                $ordered[$a] = $areas[$a];
        return array( 'totals' => $tot, 'areas' => $ordered + $areas );
    }

    /** Points matching a filter: area, risk, covered (bool), origin, q (words in id, summary or plan). */
    public function search( array $f, int $limit = 50, int $offset = 0 ): array
    {
        $q = array_filter( preg_split( '/\s+/', strtolower( trim( (string)( $f['q'] ?? '' ) ) ) ) ?: array(), 'strlen' );
        $hits = array();
        foreach ( $this->data['points'] as $p )
        {
            if ( isset( $f['area'] ) && $f['area'] !== '' && $p['area'] !== $f['area'] )
                continue;
            if ( isset( $f['risk'] ) && $f['risk'] !== '' && $p['risk'] !== $f['risk'] )
                continue;
            if ( isset( $f['origin'] ) && $f['origin'] !== '' && $p['origin'] !== $f['origin'] )
                continue;
            if ( isset( $f['covered'] ) && is_bool( $f['covered'] ) && (bool)$p['covered'] !== $f['covered'] )
                continue;
            if ( isset( $f['by'] ) && $f['by'] !== '' && $p['by'] !== $f['by'] )
                continue;
            if ( $q )
            {
                $hay = strtolower( $p['id'] . ' ' . $p['summary'] . ' ' . $p['planned'] . ' ' . $p['origin'] );
                foreach ( $q as $w )
                    if ( strpos( $hay, $w ) === false )
                        continue 2;
            }
            $hits[] = $p;
        }
        return array( 'total' => count( $hits ), 'items' => array_values( array_map( function ( $p ) {
            return array( 'id' => $p['id'], 'area' => $p['area'], 'name' => $p['name'], 'origin' => $p['origin'], 'risk' => $p['risk'],
                          'summary' => $p['summary'], 'covered' => (bool)$p['covered'], 'by' => $p['by'], 'mode' => $p['mode'], 'planned' => $p['planned'] );
        }, array_slice( $hits, max( 0, $offset ), max( 1, $limit ) ) ) ) );
    }

    public function point( string $id ): ?array
    {
        foreach ( $this->data['points'] as $p )
            if ( $p['id'] === $id )
                return $p;
        return null;
    }

    /** doc/api-coverage.md: how to read it, the adapters, the totals, the plan, and every point by area. */
    public function markdown(): string
    {
        $s = $this->summary();
        $t = $s['totals'];
        $L = array();
        $L[] = '# API coverage';
        $L[] = '';
        $L[] = '<!-- Generated by bin/php/tron-inventory.php; do not edit by hand. Regenerate it after a change to modules, fetch functions,';
        $L[] = '     operators, settings, scripts or the adapters. -->';
        $L[] = '';
        $L[] = 'Every API point of the installation this catalog was made on, found in its files (never listed by hand), with its risk class and';
        $L[] = 'whether tron covers it, and by which tool. Generated ' . $this->data['generated'] . ' by `bin/php/tron-inventory.php`; the same data as JSON is';
        $L[] = '`doc/api-coverage.json`, and the tools `system_catalog` and `system_coverage` answer from it.';
        $L[] = '';
        $L[] = '## How to read it';
        $L[] = '';
        $L[] = '- **Point**: one thing code, templates, scripts or an agent can call: a module view, a fetch function, an operator, a script ...';
        $L[] = '- **Risk class**: `read` reads only; `write` changes content an editor owns; `admin` changes the system or other people\'s rights;';
        $L[] = '  `dangerous` removes or replaces what cannot simply be made again. From the point\'s own declaration where it has one (a fetch';
        $L[] = '  function\'s `operation_types`, a command\'s "dry run unless --apply"), else from the words of its name, read conservatively: an';
        $L[] = '  unknown word counts as a write (fetch functions and operators excepted, which read by nature).';
        $L[] = '- **Covered**: a tool runs it today, as the calling Exponential user, permission-checked, redacted and audited. A read point is';
        $L[] = '  covered when a tool executes it; a write, admin or dangerous point only when a tool runs it as a dry run (its dry-run twin). The';
        $L[] = '  `by` column names the tool; `unit` marks a unit of the units programs, `adapter` a generic adapter of the program `system`.';
        $L[] = '- **Planned**: for a point not covered, the unit or step that will cover it. Writes stay inactive until their dry-run twin exists.';
        $L[] = '- Every point is described by `system_catalog` whether covered or not; describing is not counted as covering.';
        $L[] = '- The INI blocks are many and all read; the totals per area below show the coverage without them too.';
        $L[] = '';
        $L[] = '## Regenerating';
        $L[] = '';
        $L[] = 'From the installation root, on a node where a heavy job is allowed:';
        $L[] = '';
        $L[] = '```bash';
        $L[] = 'php extension/tron/bin/php/tron-inventory.php --siteaccess=<sa>                        # the summary';
        $L[] = 'php extension/tron/bin/php/tron-inventory.php --siteaccess=<sa> --write                # var/tron/api-coverage.json (the tools read it)';
        $L[] = 'php extension/tron/bin/php/tron-inventory.php --siteaccess=<sa> --doc=extension/tron/doc   # this page and its JSON';
        $L[] = 'php extension/tron/bin/php/tron-inventory.php --siteaccess=<sa> --area=module_view --uncovered';
        $L[] = '```';
        $L[] = '';
        $L[] = 'It reads module.php, function_definition.php and operation_definition.php of every module, the template operator';
        $L[] = 'autoloads, the merged content.ini, workflow.ini, cronjob.ini and expqueue.ini of the siteaccess, the INI files, the';
        $L[] = 'autoload arrays (service classes) and `bin/php/console list`. It writes only where asked. The JSON has one point per';
        $L[] = 'line, so a regenerated catalog diffs point by point.';
        $L[] = '';
        $L[] = '## Left out';
        $L[] = '';
        $L[] = 'Only active extensions count. What the installation names in `[SystemAdapterSettings] PrivateNames[]` (set in';
        $L[] = '`settings/override`; its other MCP servers, private client projects ...) is left out of the catalog and of the system';
        $L[] = 'tools, so those names appear nowhere in it.';
        $L[] = '';
        $L[] = '## Adapters';
        $L[] = '';
        $L[] = 'The generic adapters of the program `system` (doc/system-adapters.md has examples for each):';
        $L[] = '';
        $L[] = '| Unit | Tool | In | Out |';
        $L[] = '|---|---|---|---|';
        foreach ( self::ADAPTERS as $id => $a )
            $L[] = "| `$id` | `{$a['tool']}` | {$a['in']} | {$a['out']} |";
        $L[] = '';
        $L[] = 'INI settings are read by the ini units (`ini_resolve`, `ini_list`, `ini_diff`), nodes, classes, roles, templates and layouts by';
        $L[] = 'their units (doc/units.md); the system adapters do not duplicate them.';
        $L[] = '';
        $L[] = '## Totals';
        $L[] = '';
        $L[] = "- API points: **{$t['points']}**";
        $L[] = "- Read points: {$t['read']}, covered {$t['read_covered']} (**{$t['read_pct']} %**)";
        $L[] = "- Write, admin and dangerous points: {$t['write']}, covered as a dry run {$t['write_covered']} (**{$t['write_pct']} %**)";
        if ( isset( $s['areas']['ini_block'] ) )
        {
            $ir = $t['read'] - $s['areas']['ini_block']['read'];
            $ic = $t['read_covered'] - $s['areas']['ini_block']['read_covered'];
            $L[] = "- Without the INI blocks: read points $ir, covered $ic (**" . ( $ir > 0 ? round( 100 * $ic / $ir, 1 ) : 0 ) . ' %**)';
        }
        $L[] = '';
        $L[] = '| Area | Points | Read | Read covered | % | Write/admin/dangerous | Dry run covered | Covered by |';
        $L[] = '|---|---:|---:|---:|---:|---:|---:|---|';
        foreach ( $s['areas'] as $a => $r )
        {
            $by = array();
            foreach ( array_slice( $r['by'], 0, 3, true ) as $tool => $n )
                $by[] = "`$tool` $n";
            $L[] = '| ' . ( self::AREA_TITLES[$a] ?? $a ) . " | {$r['points']} | {$r['read']} | {$r['read_covered']} | {$r['read_pct']} | {$r['write']} | {$r['write_covered']} | " . implode( ', ', $by ) . ' |';
        }
        $L[] = '';
        $L[] = '## The plan for the rest';
        $L[] = '';
        $plans = array();
        foreach ( $this->data['points'] as $p )
            if ( !$p['covered'] && $p['planned'] !== '' )
            {
                $key = $p['area'] . '|' . preg_replace( '/ConsoleAllow\[\]=\S+|ScriptAllow\[\]/', 'ConsoleAllow[]', $p['planned'] );
                $plans[$key] = ( $plans[$key] ?? 0 ) + 1;
            }
        arsort( $plans );
        $L[] = '| Area | Points | Plan |';
        $L[] = '|---|---:|---|';
        foreach ( $plans as $key => $n )
        {
            list( $a, $plan ) = explode( '|', $key, 2 );
            $L[] = '| ' . ( self::AREA_TITLES[$a] ?? $a ) . " | $n | " . str_replace( '|', '\|', $plan ) . ' |';
        }
        $L[] = '';
        if ( !empty( $this->data['problems'] ) )
        {
            $L[] = '## Files the inventory could not read';
            $L[] = '';
            foreach ( $this->data['problems'] as $pr )
                $L[] = "- `{$pr['file']}`: " . str_replace( '|', '\|', $pr['error'] );
            $L[] = '';
        }
        $L[] = '## Every point';
        $L[] = '';
        $L[] = 'One line per point: name, origin, risk, and the tool that covers it (with `dry run` for a write covered by its dry run) or `-`.';
        $L[] = 'Service methods and INI blocks are grouped by class and file; `system_catalog` lists them one by one.';
        $byArea = array();
        foreach ( $this->data['points'] as $p )
            $byArea[$p['area']][] = $p;
        foreach ( $byArea as $a => $points )
        {
            $L[] = '';
            $L[] = '### ' . ( self::AREA_TITLES[$a] ?? $a ) . ' (' . count( $points ) . ')';
            $L[] = '';
            if ( $a === 'service_method' || $a === 'ini_block' )
            {
                $groups = array();
                foreach ( $points as $p )
                {
                    $g = $a === 'service_method' ? explode( '::', $p['name'] )[0] : explode( '/', $p['name'] )[0];
                    if ( !isset( $groups[$g] ) )
                        $groups[$g] = array( 'origin' => $p['origin'], 'n' => 0, 'read' => 0, 'covered' => 0, 'by' => array() );
                    $groups[$g]['n']++;
                    $groups[$g]['read'] += $p['risk'] === 'read' ? 1 : 0;
                    $groups[$g]['covered'] += $p['covered'] ? 1 : 0;
                    if ( $p['by'] !== '' )
                        $groups[$g]['by'][$p['by']] = true;
                }
                $L[] = '| ' . ( $a === 'service_method' ? 'Class' : 'File' ) . ' | Origin | Points | Read | Covered | By |';
                $L[] = '|---|---|---:|---:|---:|---|';
                foreach ( $groups as $g => $r )
                    $L[] = "| `$g` | {$r['origin']} | {$r['n']} | {$r['read']} | {$r['covered']} | " . ( $r['by'] ? '`' . implode( '`, `', array_keys( $r['by'] ) ) . '`' : '-' ) . ' |';
                continue;
            }
            $L[] = '| Point | Origin | Risk | Covered by |';
            $L[] = '|---|---|---|---|';
            foreach ( $points as $p )
                $L[] = '| `' . str_replace( '|', '\|', $p['name'] ) . "` | {$p['origin']} | {$p['risk']} | "
                    . ( $p['covered'] ? '`' . $p['by'] . '`' . ( $p['mode'] === 'dry_run' ? ' dry run' : '' ) : '-' ) . ' |';
        }
        $L[] = '';
        return implode( "\n", $L );
    }
}
