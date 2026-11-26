<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The questionnaire that writes a starter AGENTS.md without any model (doc/agent-rules.md, "The questionnaire").
 * Setup > tron > Agent rules shows QUESTIONS as a form; the prompt tron_rules_wizard asks the same questions in a
 * conversation; generate() turns the answers into Markdown. Nothing here calls out: the same answers always give the
 * same file.
 */
final class tronRulesGenerator
{
    /** name => array( label, kind (text|choice|lines), help, choices ) */
    const QUESTIONS = array(
        'project_name' => array( 'label' => 'Project name', 'kind' => 'text', 'help' => 'What the team calls this site or product.' ),
        'project_type' => array( 'label' => 'Project type', 'kind' => 'choice', 'help' => 'What agents mostly work on.',
            'choices' => array( 'site' => 'A website on Exponential', 'extension' => 'An Exponential extension', 'design' => 'A design or theme',
                                'integration' => 'An integration (API, import, export)', 'migration' => 'A migration or upgrade', 'other' => 'Something else' ) ),
        'purpose' => array( 'label' => 'What it is for', 'kind' => 'text', 'help' => 'One or two sentences an agent should know first.' ),
        'stack' => array( 'label' => 'Stack', 'kind' => 'lines', 'help' => 'One per line: PHP version, database, web server, cache, front-end tools.' ),
        'conventions' => array( 'label' => 'Conventions', 'kind' => 'lines', 'help' => 'One per line: naming, file layout, coding style, language of the UI.' ),
        'do' => array( 'label' => 'Always do', 'kind' => 'lines', 'help' => 'One per line: what an agent must always do.' ),
        'dont' => array( 'label' => 'Never do', 'kind' => 'lines', 'help' => 'One per line: what an agent must never do.' ),
        'verify' => array( 'label' => 'How a change is verified', 'kind' => 'lines', 'help' => 'One per line: the commands or checks that prove a change works.' ),
        'release' => array( 'label' => 'Releases', 'kind' => 'choice', 'help' => 'Whether agents may tag or publish.',
            'choices' => array( 'never' => 'Agents never tag, push or publish', 'ask' => 'Only when a person asks, each time', 'allowed' => 'Allowed, following the versioning rules' ) ),
        'commits' => array( 'label' => 'Commit messages', 'kind' => 'choice', 'help' => 'How commit messages are written.',
            'choices' => array( 'prefixes' => 'Added: / Updated: / Removed: / Renamed: prefixes', 'free' => 'Free form, describing why and what', 'none' => 'Agents do not commit' ) ),
        'contacts' => array( 'label' => 'Who decides', 'kind' => 'text', 'help' => 'A role, not a person\'s details: e.g. "the site owner".' ),
    );

    /** @return array the answers cleaned: unknown keys dropped, choices checked, lines split */
    public static function clean( array $answers ): array
    {
        $out = array();
        foreach ( self::QUESTIONS as $name => $q )
        {
            $v = $answers[$name] ?? '';
            if ( is_array( $v ) )
                $v = implode( "\n", array_map( 'strval', $v ) );
            $v = trim( str_replace( array( "\r\n", "\r" ), "\n", (string)$v ) );
            if ( function_exists( 'mb_substr' ) )
                $v = mb_substr( $v, 0, 4000, 'UTF-8' );
            if ( $q['kind'] === 'choice' )
                $v = isset( $q['choices'][$v] ) ? $v : '';
            else if ( $q['kind'] === 'lines' )
            {
                $lines = array();
                foreach ( explode( "\n", $v ) as $l )
                {
                    $l = trim( preg_replace( '/^([-*+]|\d+\.)\s+/', '', trim( $l ) ) );
                    if ( $l !== '' )
                        $lines[] = $l;
                }
                $v = array_slice( $lines, 0, 60 );
            }
            else
                $v = preg_replace( '/\s+/u', ' ', $v );
            $out[$name] = $v;
        }
        return $out;
    }

    /** A starter AGENTS.md for the answers. Sections without an answer get a short default the team can edit. */
    public static function generate( array $answers ): string
    {
        $a = self::clean( $answers );
        $name = $a['project_name'] !== '' ? $a['project_name'] : 'This project';
        $type = $a['project_type'] !== '' ? self::QUESTIONS['project_type']['choices'][$a['project_type']] : '';
        $md = "# AGENTS.md: $name\n\n"
            . "Rules for AI agents working on $name. Read this file before changing anything. tron serves it to every agent as\n"
            . "tron://rules/agents; while MASTER.md is on, MASTER.md comes first and wins where the two conflict.\n\n";

        $md .= "## Project\n\n";
        if ( $type !== '' )
            $md .= "- Type: $type.\n";
        $md .= '- Purpose: ' . ( $a['purpose'] !== '' ? self::sentence( $a['purpose'] ) . '.' : 'describe in one or two sentences what this project is for.' ) . "\n";
        $md .= '- Decisions: ' . ( $a['contacts'] !== '' ? self::sentence( $a['contacts'] ) . ' decides' : 'the site owner decides' )
             . " anything this file does not cover. When unsure, stop and ask rather than guess.\n\n";

        $md .= "## Stack\n\n" . self::bullets( $a['stack'], array(
            'Exponential on PHP 8.x; check `php -v` and composer.json before assuming a version.',
            'Settings live in INI files; never commit settings/override or any file holding credentials.' ) ) . "\n";

        $md .= "## Conventions\n\n" . self::bullets( $a['conventions'], array(
            'Follow the style of the code around the change; new classes and templates use the exp prefix, never ez.',
            'Write Exponential for the product name in code, documentation and messages.',
            'No inline CSS in templates: styles live in the design\'s stylesheets and use its tokens, in light and dark mode.' ) ) . "\n";

        $md .= "## Always\n\n" . self::bullets( $a['do'], array(
            'Run `php -l` on every PHP file you touch.',
            'Regenerate the autoloads after adding or renaming a class, and clear the caches after INI or class changes.',
            'Say what you verified and how; never claim a check you did not run.' ) ) . "\n";

        $md .= "## Never\n\n" . self::bullets( $a['dont'], array(
            'Never put a password, token, API key or connection string into a file, a message or a commit.',
            'Never delete files, drop tables or rewrite history without a person\'s explicit approval.',
            'Never weaken a security setting (.htaccess, php.ini, policies) to make a build pass.' ) ) . "\n";

        $md .= "## Verification\n\n" . self::bullets( $a['verify'], array(
            '`php -l <file>` for every touched PHP file.',
            'The project\'s test suite, and a request to the changed page that returns 200 with the expected content.' ) ) . "\n";

        $md .= "## Commits and releases\n\n";
        switch ( $a['commits'] )
        {
            case 'free': $md .= "- Commit messages say why and what, written for the people who read the history.\n"; break;
            case 'none': $md .= "- Agents do not commit: leave the changes in the working tree for a person to review.\n"; break;
            default:     $md .= "- Commit messages start with Added:, Updated:, Removed: or Renamed: and say why and what. One kind of change per commit.\n";
        }
        $md .= "- Commit messages never mention AI agents or assistants.\n";
        switch ( $a['release'] )
        {
            case 'allowed': $md .= "- Releases follow the versioning rules: check the existing tags first, only the last position counts on (x.y.z.9 -> x.y.z.10), and a pushed tag is never moved.\n"; break;
            case 'ask':     $md .= "- Tag, push or publish only when a person asks for it, every time. A pushed tag is permanent.\n"; break;
            default:        $md .= "- Agents never tag, push or publish. A pushed tag is permanent.\n";
        }
        return $md;
    }

    private static function sentence( string $s ): string
    {
        return rtrim( trim( $s ), " .!?" );
    }

    private static function bullets( $lines, array $defaults ): string
    {
        $lines = is_array( $lines ) && $lines ? $lines : $defaults;
        $out = '';
        foreach ( $lines as $l )
            $out .= '- ' . $l . "\n";
        return $out;
    }

    /** The interview of the prompt tron_rules_wizard, as text. */
    public static function interview(): string
    {
        $s = '';
        $i = 1;
        foreach ( self::QUESTIONS as $name => $q )
        {
            $s .= $i++ . '. ' . $q['label'] . ' (' . $name . '): ' . $q['help'];
            if ( isset( $q['choices'] ) )
                $s .= ' Choices: ' . implode( ', ', array_map( function ( $k, $v ) { return "$k ($v)"; }, array_keys( $q['choices'] ), $q['choices'] ) ) . '.';
            $s .= "\n";
        }
        return $s;
    }
}
