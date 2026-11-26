<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Writes the files of the agent rules layer (doc/agent-rules.md): the site's AGENTS.md, the site's MASTER.md
 * override, the admin toggle (rules-state.json) and the file marker MASTER.disabled, all in the layer's prompt
 * directory. Every write goes to a temporary file first and is then renamed into place, so an agent never reads half
 * a file. Only Setup > tron > Agent rules writes (after "Verify it's you") and the command tron-rules.php.
 */
final class tronRulesStore
{
    const FILES = array( 'agents' => 'AGENTS.md', 'master' => 'MASTER.md' );

    /** @var tronRules */ private $rules;
    /** @var callable */ private $clock;

    public function __construct( tronRules $rules, ?callable $clock = null )
    {
        $this->rules = $rules;
        $this->clock = $clock ?: 'time';
    }

    private function dir(): string
    {
        $d = $this->rules->dir();
        if ( $d === null )
            throw new RuntimeException( 'No prompt directory is known: set tron.ini [PromptRules] Directory' );
        if ( !is_dir( $d ) && !@mkdir( $d, 0775, true ) && !is_dir( $d ) )
            throw new RuntimeException( 'The prompt directory cannot be created' );
        return $d;
    }

    /**
     * Checks a rules text before it is stored.
     * @return array array( 'text' => normalised text, 'warnings' => list of strings )
     * @throws InvalidArgumentException
     */
    public function check( string $text ): array
    {
        if ( strpos( $text, "\0" ) !== false )
            throw new InvalidArgumentException( 'The file is not a text file' );
        if ( !preg_match( '//u', $text ) )
            throw new InvalidArgumentException( 'The text is not valid UTF-8' );
        $text = str_replace( array( "\r\n", "\r" ), "\n", $text );
        if ( substr( $text, 0, 3 ) === "\xEF\xBB\xBF" )
            $text = substr( $text, 3 );
        if ( strlen( $text ) > $this->rules->maxBytes() )
            throw new InvalidArgumentException( 'The text is larger than [PromptRules] MaxBytes (' . $this->rules->maxBytes() . ' bytes)' );
        if ( trim( $text ) === '' )
            throw new InvalidArgumentException( 'The text is empty; remove the file instead' );
        $warnings = array();
        // every agent reads this file: a secret in it reaches every agent. Name the kind and line, never the value.
        $secretKinds = array( 'credential pair', 'tron token', 'GitHub token', 'GitLab token', 'API key', 'JSON web token',
                              'private key', 'credentials in a URL', 'secret assignment' );
        foreach ( ( new tronRulesSanitizer() )->leaks( $text ) as $leak )
            if ( in_array( $leak['pattern'], $secretKinds, true ) )
                $warnings[] = "Line {$leak['line']} looks like it holds a secret ({$leak['pattern']}). Every agent reads this file: use a placeholder such as <ADMIN_PASSWORD> instead.";
        if ( tronRules::strength( $text ) === 'hollow' )
            $warnings[] = 'The text reads like a skeleton: it has hardly a rule in it. MASTER.md fills the gaps while it is on.';
        return array( 'text' => rtrim( $text ) . "\n", 'warnings' => $warnings );
    }

    /** Stores AGENTS.md or the MASTER.md override. @return array the warnings of check() */
    public function save( string $which, string $text ): array
    {
        if ( !isset( self::FILES[$which] ) )
            throw new InvalidArgumentException( 'Unknown rules file' );
        $c = $this->check( $text );
        $this->atomicWrite( $this->dir() . '/' . self::FILES[$which], $c['text'] );
        return $c['warnings'];
    }

    /** Removes AGENTS.md or the MASTER.md override (the shipped default then applies). @return bool whether a file was removed */
    public function remove( string $which ): bool
    {
        if ( !isset( self::FILES[$which] ) )
            throw new InvalidArgumentException( 'Unknown rules file' );
        $p = $this->rules->path( self::FILES[$which] );
        return $p !== null && is_file( $p ) && unlink( $p );
    }

    /** The admin toggle: enabled, disabled, or '' to leave the decision to the INI. */
    public function setAdminToggle( string $value, int $userId ): void
    {
        if ( !in_array( $value, array( 'enabled', 'disabled', '' ), true ) )
            throw new InvalidArgumentException( 'The admin toggle is enabled, disabled or empty' );
        $state = array( 'master' => $value, 'changed' => (int)call_user_func( $this->clock ), 'changed_by' => $userId );
        $this->atomicWrite( $this->dir() . '/' . tronRules::STATE, json_encode( $state, JSON_PRETTY_PRINT ) . "\n" );
    }

    /** Puts the file marker MASTER.disabled in place, or takes it away. */
    public function setMarker( bool $present ): void
    {
        $p = $this->dir() . '/' . tronRules::MARKER;
        if ( $present )
            $this->atomicWrite( $p, "MASTER.md is switched off for this site while this file exists (tron, doc/agent-rules.md).\n" );
        else if ( is_file( $p ) )
            unlink( $p );
    }

    private function atomicWrite( string $path, string $content ): void
    {
        $tmp = $path . '.tmp-' . bin2hex( random_bytes( 4 ) );
        if ( file_put_contents( $tmp, $content, LOCK_EX ) !== strlen( $content ) )
        {
            @unlink( $tmp );
            throw new RuntimeException( 'The rules file could not be written' );
        }
        @chmod( $tmp, 0664 );
        if ( !rename( $tmp, $path ) )
        {
            @unlink( $tmp );
            throw new RuntimeException( 'The rules file could not be put in place' );
        }
    }
}
