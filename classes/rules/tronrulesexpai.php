<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The guided wizard through Exponential Intelligence (expai), when expai is active (doc/agent-rules.md, "The expai
 * wizard"). It takes the questionnaire's answers and the starter that tronRulesGenerator wrote from them, and asks the
 * user's expai model to turn them into a fuller AGENTS.md. The answer only fills the editor: a person reads it and
 * saves it ("Verify it's you"). The request goes through expai's usage recorder, so it counts against expai's
 * budgets and shows in its usage log like every other expai request; expai's switches and the policy expai/write
 * decide whether it may run at all.
 */
final class tronRulesExpai
{
    /** Why the wizard cannot run here, or '' when it can. */
    public static function unavailable(): string
    {
        if ( !class_exists( 'expAiConfig' ) || !class_exists( 'expAiUsageRecorder' ) )
            return 'Exponential Intelligence (expai) is not active on this site.';
        if ( class_exists( 'expAiGate' ) )
        {
            $off = expAiGate::switchedOff();
            if ( $off !== '' )
                return $off;
        }
        $access = eZUser::currentUser()->hasAccessTo( 'expai', 'write' );
        if ( ( $access['accessWord'] ?? 'no' ) === 'no' )
            return 'Your role does not grant expai/write.';
        return '';
    }

    /** The request sent to the model: the system rules and one user message. */
    public static function request( array $answers, string $starter ): array
    {
        $a = tronRulesGenerator::clean( $answers );
        $system = "You write AGENTS.md files: the rules AI coding agents follow in one software project built on Exponential, "
                . "a PHP content management system. Write Markdown only, no preamble and no closing remarks. Use short imperative "
                . "rules grouped under ## headings. Keep every fact the team gave; add only rules that follow from them or that "
                . "any careful team would want (verification, safety, no secrets in files). Never invent people, host names, "
                . "passwords or paths; where a value is needed write a placeholder such as <ADMIN_PASSWORD> or www.example.org. "
                . "Write the product name as Exponential.";
        $user = "The team's answers (JSON):\n" . json_encode( $a, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
              . "\n\nA starter written from them:\n\n" . $starter
              . "\n\nWrite the finished AGENTS.md. Keep it under 250 lines.";
        return array( 'system' => $system, 'messages' => array( array( 'role' => 'user', 'content' => $user ) ), 'max_tokens' => 4000 );
    }

    /**
     * Asks expai for a draft.
     * @return array text (the draft), model
     * @throws RuntimeException when expai cannot or did not answer
     */
    public static function draft( array $answers ): array
    {
        $why = self::unavailable();
        if ( $why !== '' )
            throw new RuntimeException( $why );
        $starter = tronRulesGenerator::generate( $answers );
        $config = expAiConfig::instance();
        $provider = $config->registry()->providerFor( $config->effectiveModel() );
        $recorder = new expAiUsageRecorder( $provider, array( 'action' => 'tron:agents_md' ) );
        try
        {
            $answer = $recorder->send( self::request( $answers, $starter ) );
        }
        catch ( Throwable $e )
        {
            throw new RuntimeException( 'Exponential Intelligence did not answer: ' . $e->getMessage() );
        }
        if ( ( $answer['stop_reason'] ?? '' ) === 'refusal' )
            throw new RuntimeException( 'Exponential Intelligence declined this request.' );
        $text = '';
        foreach ( (array)( $answer['content'] ?? array() ) as $block )
            if ( ( $block['type'] ?? '' ) === 'text' )
                $text .= (string)$block['text'];
        if ( class_exists( 'expAiText' ) )
            $text = expAiText::stripReasoning( $text );
        $text = trim( preg_replace( '/^```(?:markdown|md)?\s*\n(.*)\n```\s*$/s', '$1', trim( $text ) ) );
        if ( $text === '' )
            throw new RuntimeException( 'Exponential Intelligence returned no text.' );
        return array( 'text' => $text . "\n", 'model' => (string)( $answer['model'] ?? '' ) );
    }
}
