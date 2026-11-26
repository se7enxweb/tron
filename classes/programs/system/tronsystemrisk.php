<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The risk class of an API point: what calling it can do.
 *
 *   read       reads; changes nothing (a fetch, a list, a status)
 *   write      changes content or state an editor owns (publish, move, store, send)
 *   admin      changes the system or other people's rights (settings, roles, caches, deploys, tokens)
 *   dangerous  removes or replaces things that cannot simply be made again (delete, purge, reinstall, upgrade)
 *
 * The class comes from the point's own declaration where it has one (a fetch function's operation_types, a console
 * command's "dry run unless --apply") and otherwise from the words of its name, read conservatively: a name with no
 * known word counts as a write, never as a read, except for template fetch functions and operators, which are reads
 * by their nature. See doc/api-coverage.md, "Risk classes".
 */
final class tronSystemRisk
{
    const READ = 'read';
    const WRITE = 'write';
    const ADMIN = 'admin';
    const DANGEROUS = 'dangerous';

    const ORDER = array( self::READ => 0, self::WRITE => 1, self::ADMIN => 2, self::DANGEROUS => 3 );

    const DANGEROUS_WORDS = array( 'remove', 'delete', 'drop', 'purge', 'truncate', 'wipe', 'reset', 'kickstart', 'kickstarter',
        'install', 'uninstall', 'reinstall', 'upgrade', 'restore', 'rollback', 'destroy', 'empty', 'kill', 'erase', 'cleanup',
        'clean', 'flush', 'redump', 'dfscleanup', 'trash', 'removed', 'revoke' );
    const ADMIN_WORDS = array( 'setup', 'settings', 'setting', 'cache', 'caches', 'role', 'roles', 'policy', 'policies', 'permission',
        'permissions', 'assign', 'unassign', 'workflow', 'trigger', 'triggers', 'package', 'packages', 'token', 'tokens', 'key',
        'keys', 'secret', 'secrets', 'password', 'deploy', 'restart', 'reload', 'config', 'configphp', 'crontab', 'cronjob',
        'cronjobs', 'extension', 'extensions', 'autoload', 'autoloads', 'siteaccess', 'database', 'schema', 'sql', 'phar',
        'velocity', 'license', 'migration', 'migrations', '2fa', 'passkey', 'passkeys', 'section', 'sections', 'switch' );
    const WRITE_WORDS = array( 'edit', 'create', 'new', 'add', 'store', 'save', 'publish', 'update', 'set', 'move', 'copy',
        'swap', 'hide', 'unhide', 'reveal', 'upload', 'import', 'send', 'translate', 'approve', 'reject', 'enqueue', 'run',
        'apply', 'register', 'activate', 'deactivate', 'login', 'logout', 'action', 'actions', 'bookmark', 'notify', 'vote',
        'collect', 'sort', 'priority', 'lock', 'unlock', 'cancel', 'retry', 'pause', 'resume', 'generate', 'build', 'sync',
        'fix', 'repair', 'rebuild', 'regenerate', 'clear', 'rename', 'link', 'unlink', 'attach', 'detach', 'change', 'write',
        'commit', 'push', 'tag', 'release', 'export', 'merge', 'enable', 'disable', 'insert', 'append', 'modify', 'process',
        'mail', 'subscribe', 'unsubscribe', 'confirm', 'reorder', 'restore', 'convert', 'fill', 'seed', 'index', 'reindex',
        'replace', 'patch', 'post', 'put', 'execute', 'exec', 'start', 'stop', 'schedule', 'queue', 'addtobasket', 'checkout',
        'basket', 'wishlist', 'upload', 'crop', 'rotate', 'resize', 'instantiate', 'fork', 'spawn', 'flag',
        'mark', 'touch', 'refresh', 'rewrite', 'transfer', 'shift', 'archive', 'expire', 'feedback', 'comment', 'reply',
        'invite', 'share', 'claim', 'grant', 'restart', 'warm', 'warmup', 'prime', 'trashrestore', 'discard', 'draft',
        'translation', 'translations', 'preferences', 'selfedit', 'forgotpassword', 'setlang' );
    const READ_WORDS = array( 'view', 'views', 'list', 'show', 'status', 'check', 'checks', 'info', 'about', 'browse', 'search',
        'fetch', 'get', 'read', 'count', 'has', 'can', 'is', 'find', 'describe', 'diff', 'history', 'tail', 'preview', 'report',
        'stats', 'statistics', 'verify', 'lint', 'explain', 'inventory', 'overview', 'dump', 'print', 'display', 'download',
        'tree', 'children', 'subtree', 'feed', 'rss', 'sitemap', 'map', 'load', 'lookup', 'resolve', 'test', 'tests', 'probe',
        'health', 'diagnose', 'compare', 'measure', 'benchmark', 'bench', 'calendar', 'versionview', 'versions', 'reverserelatedlist',
        'relations', 'attribute', 'attributes', 'collectedinfo', 'urlalias', 'suggest', 'autocomplete', 'query', 'select',
        'dashboard', 'summary', 'state', 'states', 'listing', 'detail', 'details', 'help', 'whoami', 'who', 'trace', 'watch',
        'monitor', 'doc', 'docs', 'documentation', 'manual', 'copyright', 'credits', 'version', 'image', 'thumbnail', 'avatar' );

    /** Verbs a run-together word may start with, and the word they count as. */
    const PREFIXES = array( 'remove' => 'remove', 'delete' => 'delete', 'purge' => 'purge', 'truncate' => 'truncate', 'wipe' => 'wipe',
        'erase' => 'erase', 'destroy' => 'destroy', 'edit' => 'edit', 'create' => 'create', 'add' => 'add', 'store' => 'store',
        'publish' => 'publish', 'update' => 'update', 'upload' => 'upload', 'import' => 'import', 'send' => 'send', 'move' => 'move',
        'copy' => 'copy', 'view' => 'view', 'list' => 'list', 'fetch' => 'fetch', 'browse' => 'browse', 'search' => 'search',
        'show' => 'show', 'count' => 'count' );

    /** The words of a name: camelCase, snake_case, kebab-case, paths and colons split, lower case. */
    public static function words( string $name ): array
    {
        $s = preg_replace( '/([a-z])([A-Z])/', '$1 $2', $name );
        $parts = preg_split( '/[^A-Za-z0-9]+/', strtolower( (string)$s ) ) ?: array();
        return array_values( array_filter( $parts, 'strlen' ) );
    }

    /**
     * The class of a name. $default is what a name with no known word gets; $adminArea makes every write an admin
     * write and every read an admin read (setup screens, which show the system's own state).
     */
    public static function classify( string $name, string $default = self::WRITE, bool $adminArea = false ): string
    {
        $words = self::words( $name );
        $all = array_merge( self::DANGEROUS_WORDS, self::ADMIN_WORDS, self::WRITE_WORDS, self::READ_WORDS );
        $exact = false;
        foreach ( $words as $w )
            $exact = $exact || in_array( $w, $all, true );
        // no word known as it is: the words that start with a known verb (removeobject, addtobasket, listitems)
        if ( !$exact )
        {
            $expanded = array();
            foreach ( $words as $w )
                foreach ( self::PREFIXES as $prefix => $as )
                    if ( strlen( $w ) > strlen( $prefix ) && strpos( $w, $prefix ) === 0 )
                    {
                        $expanded[] = $as;
                        break;
                    }
            $words = array_merge( $words, $expanded );
        }
        $has = function ( array $list ) use ( $words ) {
            foreach ( $words as $w )
            {
                if ( in_array( $w, $list, true ) )
                    return true;
            }
            return false;
        };
        if ( $has( self::DANGEROUS_WORDS ) )
            return self::DANGEROUS;
        if ( $has( self::WRITE_WORDS ) )
            return $adminArea || $has( self::ADMIN_WORDS ) ? self::ADMIN : self::WRITE;
        if ( $has( self::ADMIN_WORDS ) && !$has( self::READ_WORDS ) )
            return self::ADMIN;
        if ( $has( self::READ_WORDS ) )
            return $adminArea ? self::ADMIN : self::READ;
        return $adminArea && $default !== self::READ ? self::ADMIN : $default;
    }

    /**
     * The class of a console command or script from its description: "dry run unless --apply" (or a --dry-run
     * option) is a write with a dry-run twin; a description that starts with a read verb is a read.
     *
     * @return array( risk, dry_run ) dry_run: whether the command has a mode that only reports
     */
    public static function classifyCommand( string $name, string $description ): array
    {
        $d = strtolower( $description );
        $dryRun = (bool)preg_match( '/dry[ -]run|--dry-run|report only|read-only|read only|--simulate/', $d );
        // the name's own words count when they say more than "read" (billing_sync, sequence-sync, ora-grant); the
        // namespace and the extension do not (ext:explayouts:layout_list is about layouts, not an admin action)
        $short = preg_replace( '/^(exp|ext:[^:]+|shell|bin|cron|ezpm):/', '', $name );
        $byName = self::classify( (string)$short, self::READ );
        if ( strpos( $name, 'cron:' ) === 0 )
            $byName = self::max( $byName, self::ADMIN );     // a cron command runs a cronjob part
        $first = self::words( $description )[0] ?? '';
        $readVerb = in_array( $first, array( 'list', 'lists', 'show', 'shows', 'check', 'checks', 'print', 'prints', 'display',
            'displays', 'verify', 'verifies', 'report', 'reports', 'measure', 'find', 'finds', 'compare', 'describe', 'the',
            'what', 'which', 'inventory', 'statistics', 'diagnose', 'test', 'tests', 'search', 'read', 'reads', 'view' ), true );
        $dangerous = (bool)preg_match( '/\b(delete|deletes|remove|removes|drop|drops|purge|purges|truncate|wipe|reinstall|uninstall|kickstart|erase)\b/', $d );
        if ( $dangerous && !$dryRun )
            return array( self::DANGEROUS, false );
        if ( $dryRun )
            return array( $dangerous ? self::DANGEROUS : self::max( $byName, self::WRITE ), true );
        $writesToo = (bool)preg_match( '/\b(and|or) (set|add|change|create|update|import|send|fix|repair|rebuild|regenerate|reset|write|make|generate|sync|run)s?\b/', $d );
        if ( $readVerb && !$writesToo )
            return array( $byName, false );                 // a read, unless the name says otherwise
        return array( self::max( $byName, self::WRITE ), false );
    }

    /** The higher of two classes. */
    public static function max( string $a, string $b ): string
    {
        return ( self::ORDER[$a] ?? 1 ) >= ( self::ORDER[$b] ?? 1 ) ? $a : $b;
    }

    public static function isValid( string $risk ): bool
    {
        return isset( self::ORDER[$risk] );
    }
}
