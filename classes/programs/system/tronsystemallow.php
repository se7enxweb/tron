<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * What the generic adapters of the program "system" let through, in one place, so that the inventory (which says
 * what is covered) and the adapters (which do it) can never disagree. Settings in tron.ini [SystemAdapterSettings]
 * extend the lists; nothing in the settings can make a write, admin or dangerous point run outside its dry run.
 *
 *   OperatorDeny[]      operators operator.eval never evaluates, on top of the built-in list
 *   ConsoleAllow[]      more console commands console.command may run (each still read or dry run only)
 *   ScriptAllow[]       more bin/php scripts cli.script may run (paths from the installation root)
 *   ViewDeny[]          module/view pairs module.view.read never renders
 *   CommandTimeout      seconds a command may run (default 120, at most 900)
 */
final class tronSystemAllow
{
    /**
     * Operators that read something other than the input (INI values, the request, the session), that run another
     * API point past its own adapter (fetch is fetch.call's), or that write files. The rest of the read operators
     * are evaluated.
     */
    const OPERATOR_DENY = array(
        'fetch' => 'a fetch goes through fetch.call, which checks the function and its parameters',
        'fetch_alias' => 'a fetch goes through fetch.call, which checks the function and its parameters',
        'ezini' => 'INI values go through the ini units, which redact secrets',
        'ezini_hasvariable' => 'INI values go through the ini units',
        'ezhttp' => 'reads the request and the session of the process, not the caller\'s',
        'ezhttp_hasvariable' => 'reads the request and the session of the process, not the caller\'s',
        'ezsys' => 'shows server paths and host names',
        'texttoimage' => 'writes image files', 'wordtoimage' => 'writes image files', 'image' => 'writes image files',
        'imagefile' => 'writes image files', 'mimetype_icon' => 'reads the design files of the process',
        'dump' => 'prints whole object graphs; use the units instead',
        'attribute' => 'prints whole object graphs; use the units instead',
        'ezpreference' => 'reads the preference of the process user',
        'ezsetpreference' => 'writes a preference',
    );

    /**
     * Console commands console.command may run. Each entry: the arguments it may take ("*" any not refused below),
     * and whether it only reports (read) or has a dry run that is forced (dry_run: the flag tron adds, nothing
     * else may apply it).
     */
    const CONSOLE = array(
        'exp:accesscheck' => array( 'mode' => 'read' ),
        'exp:audit' => array( 'mode' => 'read', 'first' => array( 'tail', 'show', 'verify', 'channels' ) ),
        'exp:checkclasses' => array( 'mode' => 'read' ),
        'exp:checkdbfiles' => array( 'mode' => 'read' ),
        'exp:checkmanifest' => array( 'mode' => 'read' ),
        'exp:configphp' => array( 'mode' => 'read', 'first' => array( 'list', 'get' ) ),
        'exp:contentcheck' => array( 'mode' => 'dry_run' ),
        'exp:expfetch' => array( 'mode' => 'read' ),
        'exp:expgraphqlstatus' => array( 'mode' => 'read' ),
        'exp:exphelp' => array( 'mode' => 'read' ),
        'exp:expmigrationcheck' => array( 'mode' => 'read' ),
        'exp:expmigrationdryrun' => array( 'mode' => 'read' ),
        'exp:expmigrationrecordlist' => array( 'mode' => 'read' ),
        'exp:expmigrationrecordshow' => array( 'mode' => 'read' ),
        'exp:expmigrationremotelist' => array( 'mode' => 'read' ),
        'exp:expmigrationstatus' => array( 'mode' => 'read' ),
        'exp:expstaticstatus' => array( 'mode' => 'read' ),
        'exp:expstatictargets' => array( 'mode' => 'read' ),
        'exp:exptemplatecheck' => array( 'mode' => 'read' ),
        'exp:expuserlist' => array( 'mode' => 'read' ),
        'exp:expusershow' => array( 'mode' => 'read' ),
        'exp:ezcheckphptag' => array( 'mode' => 'read' ),
        'exp:ezchecktranslation' => array( 'mode' => 'read' ),
        'exp:ezrequestrules' => array( 'mode' => 'read' ),
        'exp:eztemplatecheck' => array( 'mode' => 'read' ),
        'exp:i18n' => array( 'mode' => 'read' ),
        'exp:installcrontab' => array( 'mode' => 'dry_run' ),
        'exp:mailstatus' => array( 'mode' => 'read' ),
        'exp:expimagealias' => array( 'mode' => 'read', 'first' => array( 'status' ) ),
        'exp:exphelpcontent' => array( 'mode' => 'dry_run' ),
        'exp:expgraphqlkeys' => array( 'mode' => 'read', 'first' => array( 'list' ) ),
        'crontab:list' => array( 'mode' => 'read' ),
        'exp:exptemplateengines' => array( 'mode' => 'read' ),
        'exp:expuserwhoami' => array( 'mode' => 'read' ),
        'exp:notificationstatus' => array( 'mode' => 'read' ),
        'exp:systeminfo' => array( 'mode' => 'read' ),
        'exp:expmigrationremotestatus' => array( 'mode' => 'read' ),
        'exp:expgraphqlschema' => array( 'mode' => 'read' ),
        'ext:bccie:status' => array( 'mode' => 'read' ),
        'ext:cjw_newsletter:status' => array( 'mode' => 'read' ),
        'ext:explayouts:block_list' => array( 'mode' => 'read' ),
        'ext:explayouts:collection_list' => array( 'mode' => 'read' ),
        'ext:explayouts:diagnose' => array( 'mode' => 'read' ),
        'ext:explayouts:layout_info' => array( 'mode' => 'read' ),
        'ext:explayouts:layout_list' => array( 'mode' => 'read' ),
        'ext:explayouts:rule_list' => array( 'mode' => 'read' ),
        'ext:expqueue:list' => array( 'mode' => 'read' ),
        'ext:expqueue:show' => array( 'mode' => 'read' ),
        'ext:expqueue:status' => array( 'mode' => 'read' ),
        'ext:sqliimport:list' => array( 'mode' => 'read' ),
        'ext:sqliimport:status' => array( 'mode' => 'read' ),
        'ext:syndication:status' => array( 'mode' => 'read' ),
        'ext:tron:tron-engines' => array( 'mode' => 'read' ),
        'shell:checkini' => array( 'mode' => 'read' ),
        'shell:i18nstats' => array( 'mode' => 'read' ),
        'shell:stats' => array( 'mode' => 'read' ),
    );

    /** Arguments no command run by tron may carry: they apply, force or confirm a change. */
    const REFUSED_ARGS = '/^-{1,2}(apply|force|yes|confirm|fix|delete|remove|purge|drop|clear|clear-all|quarantine|fix-permissions|add|reset|restore|run|execute|commit|push|token|password|out|output|file|dsn)(=|$)|^-[A-Za-z]*D[A-Za-z]*$/';

    /** One argument: options and plain words, no shell characters, no path outside the installation. */
    const ARG = '/^[A-Za-z0-9_@:.,=\/+-]{1,200}$/';

    /** Views module.view.read never renders, whatever their risk class says. */
    const VIEW_DENY = array(
        'user/logout' => 'signs the process user out', 'user/login' => 'signs in',
        'user/activate' => 'activates an account', 'user/forgotpassword' => 'sends mail',
        'content/download' => 'streams a file; read the attribute with the node units instead',
        'layout/set' => 'stores a layout choice', 'switchlanguage/to' => 'changes the session language',
        'setup/info' => 'shows server paths and versions', 'setup/systemupgrade' => 'compares and reports the code base',
        'notification/addtonotification' => 'subscribes on GET', 'content/collectedinfo' => 'shows what visitors sent',
    );

    /** Modules whose reads show the system itself: their reads are admin reads, never rendered by module.view.read. */
    const ADMIN_MODULES = array( 'setup', 'settings', 'role', 'workflow', 'trigger', 'package', 'section', 'infocollector',
        'state', 'class', 'shop', 'pdf', 'url', 'rss', 'visual', 'collaboration', 'tron', 'expqueue' );

    /** @var array tron.ini [SystemAdapterSettings] */ private $settings;

    public function __construct( array $settings = array() )
    {
        $this->settings = $settings;
    }

    public static function fromEnv( tronEnvironment $env ): self
    {
        return new self( (array)( $env->settings()['SystemAdapterSettings'] ?? array() ) );
    }

    /** @return string|null why the operator is not evaluated, null when it is */
    public function operatorRefusal( string $name, string $risk ): ?string
    {
        if ( isset( self::OPERATOR_DENY[$name] ) )
            return self::OPERATOR_DENY[$name];
        if ( in_array( $name, (array)( $this->settings['OperatorDeny'] ?? array() ), true ) )
            return 'tron.ini [SystemAdapterSettings] OperatorDeny[]';
        if ( $risk !== tronSystemRisk::READ )
            return "a $risk operator: operator.eval evaluates read operators only";
        return null;
    }

    /** @return array|null the console entry (mode, first) of an allowed command */
    public function console( string $command ): ?array
    {
        if ( isset( self::CONSOLE[$command] ) )
            return self::CONSOLE[$command] + array( 'first' => array() );
        foreach ( (array)( $this->settings['ConsoleAllow'] ?? array() ) as $line )
        {
            // "exp:foo" (read) or "exp:foo;dry_run"
            $p = explode( ';', (string)$line );
            if ( trim( $p[0] ) === $command )
                return array( 'mode' => trim( $p[1] ?? 'read' ) === 'dry_run' ? 'dry_run' : 'read', 'first' => array() );
        }
        return null;
    }

    /** @return string[] allowed console commands */
    public function consoleCommands(): array
    {
        $out = array_keys( self::CONSOLE );
        foreach ( (array)( $this->settings['ConsoleAllow'] ?? array() ) as $line )
            $out[] = trim( explode( ';', (string)$line )[0] );
        $out = array_values( array_unique( array_filter( $out ) ) );
        sort( $out );
        return $out;
    }

    /**
     * The bin/php script of a console command in the "exp" namespace (exp:foo -> bin/php/foo.php), and back. Scripts
     * are allowed exactly when their command is.
     */
    public static function scriptOfCommand( string $command ): ?string
    {
        if ( preg_match( '/^exp:([a-z0-9_]+)$/', $command, $m ) )
            return 'bin/php/' . $m[1] . '.php';
        if ( preg_match( '/^ext:([a-z0-9_]+):([a-z0-9_-]+)$/', $command, $m ) )
            return 'extension/' . $m[1] . '/bin/php/' . $m[2] . '.php';
        return null;
    }

    public static function commandOfScript( string $path ): ?string
    {
        if ( preg_match( '#^bin/php/([a-z0-9_]+)\.php$#', $path, $m ) )
            return 'exp:' . $m[1];
        if ( preg_match( '#^extension/([a-z0-9_]+)/bin/php/([a-z0-9_-]+)\.php$#', $path, $m ) )
            return 'ext:' . $m[1] . ':' . $m[2];
        return null;
    }

    /** @return array|null the console entry of an allowed script */
    public function script( string $path ): ?array
    {
        if ( in_array( $path, (array)( $this->settings['ScriptAllow'] ?? array() ), true ) )
            return array( 'mode' => 'read', 'first' => array() );
        $cmd = self::commandOfScript( $path );
        return $cmd === null ? null : $this->console( $cmd );
    }

    /** @return string|null why an argument is refused */
    public function argumentRefusal( string $arg ): ?string
    {
        if ( !preg_match( self::ARG, $arg ) )
            return "the argument '" . substr( $arg, 0, 40 ) . "' has characters tron does not pass on";
        if ( strpos( $arg, '..' ) !== false )
            return 'no path outside the installation';
        if ( preg_match( self::REFUSED_ARGS, $arg ) )
            return "the argument '$arg' applies or forces a change; tron runs commands read-only or as a dry run";
        return null;
    }

    /** @return string|null why a view is not rendered */
    public function viewRefusal( string $module, string $view, string $risk ): ?string
    {
        $key = $module . '/' . $view;
        if ( isset( self::VIEW_DENY[$key] ) )
            return self::VIEW_DENY[$key];
        if ( in_array( $key, (array)( $this->settings['ViewDeny'] ?? array() ), true ) )
            return 'tron.ini [SystemAdapterSettings] ViewDeny[]';
        if ( $risk !== tronSystemRisk::READ )
            return "a $risk view: module.view.read renders read views only";
        return null;
    }

    public function commandTimeout(): int
    {
        return max( 5, min( 900, (int)( $this->settings['CommandTimeout'] ?? 120 ) ) );
    }
}
