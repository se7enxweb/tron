<?php
/**
 * The skill harness's world on top of TronFixture: centre tickets, cards and decisions on two boards with due dates,
 * labels, assignees, comments and links, so every centre skill has something to find; and planted canaries (a fake
 * password, a fake token, an e-mail address) in ticket bodies and comments, which must never reach a skill's answer.
 * Made-up data only.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

final class TronSkillFixtures
{
    const CANARIES = array( 'password=CanaryPw-7731', 'tron_CanaryPrefx1_abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ', 'canary.person@example.net' );

    public static function seed( tronDb $db, int $now ): void
    {
        $store = new tronCentreStore( $db, array( 'inbox', 'next', 'doing', 'waiting', 'done' ), array(), function () use ( $now ) { return $now; } );
        $day = 86400;
        $tickets = array(
            array( 'title' => 'Front page renders slowly after deploy', 'board' => 'main', 'lane' => 'doing', 'priority' => 'urgent', 'labels' => array( 'perf', 'site' ), 'assignee_id' => TronFixture::EDITOR, 'due' => $now - 3 * $day ),
            array( 'title' => 'Translate the help pages', 'board' => 'main', 'lane' => 'next', 'priority' => 'normal', 'labels' => array( 'i18n', 'docs' ), 'assignee_id' => TronFixture::VIEWER, 'due' => $now + 5 * $day ),
            array( 'title' => 'Broken link in footer', 'board' => 'main', 'lane' => 'inbox', 'priority' => 'low', 'labels' => array( 'seo', 'site' ) ),
            array( 'title' => 'Image aliases missing for banners', 'board' => 'main', 'lane' => 'waiting', 'priority' => 'high', 'labels' => array( 'media' ), 'assignee_id' => TronFixture::EDITOR, 'due' => $now - 10 * $day ),
            array( 'title' => 'Renew certificates on the cluster', 'board' => 'ops', 'lane' => 'next', 'priority' => 'high', 'labels' => array( 'ops', 'security' ), 'assignee_id' => TronFixture::OPERATOR, 'due' => $now + 2 * $day,
                   'body' => 'Use the key store. ' . self::CANARIES[0] . ' was pasted here by mistake; ask ' . self::CANARIES[2] ),
            array( 'title' => 'Backup restore drill', 'board' => 'ops', 'lane' => 'inbox', 'priority' => 'normal', 'labels' => array( 'ops', 'backup' ), 'due' => $now + 20 * $day ),
            array( 'title' => 'Database index for audit table', 'board' => 'ops', 'lane' => 'doing', 'priority' => 'normal', 'labels' => array( 'perf', 'db' ), 'assignee_id' => TronFixture::OPERATOR ),
            array( 'title' => 'Layout for the news section', 'board' => 'main', 'lane' => 'inbox', 'priority' => 'normal', 'labels' => array( 'layouts' ), 'kind' => 'card' ),
            array( 'title' => 'Shared header block cleanup', 'board' => 'main', 'lane' => 'next', 'priority' => 'low', 'labels' => array( 'layouts', 'site' ), 'kind' => 'card', 'assignee_id' => TronFixture::EDITOR ),
            array( 'title' => 'Choose the search engine', 'board' => 'main', 'lane' => 'waiting', 'priority' => 'high', 'labels' => array( 'search' ), 'kind' => 'decision', 'decision_week' => '2026-W46' ),
            array( 'title' => 'Adopt PHP 8.5 everywhere', 'board' => 'main', 'lane' => 'next', 'priority' => 'normal', 'labels' => array( 'php' ), 'kind' => 'decision', 'decision_week' => '2026-W48' ),
            array( 'title' => 'Retire the old admin design', 'board' => 'main', 'lane' => 'inbox', 'priority' => 'low', 'labels' => array( 'admin' ), 'kind' => 'decision', 'decision_week' => '2026-W50' ),
            array( 'title' => 'Plan the reinstall test', 'board' => 'ops', 'lane' => 'inbox', 'priority' => 'normal', 'labels' => array( 'ops' ), 'kind' => 'decision' ),
            array( 'title' => 'Spam in contact form', 'board' => 'main', 'lane' => 'inbox', 'priority' => 'urgent', 'labels' => array( 'security', 'site' ), 'body' => 'Token seen in log: ' . self::CANARIES[1] ),
            array( 'title' => 'Sitemap lists hidden nodes', 'board' => 'main', 'lane' => 'doing', 'priority' => 'high', 'labels' => array( 'seo' ), 'assignee_id' => TronFixture::VIEWER, 'due' => $now - 1 * $day ),
        );
        $ids = array();
        foreach ( $tickets as $t )
            $ids[] = (int)$store->create( $t, TronFixture::ADMIN )['id'];
        $store->comment( $ids[0], 'Profiled: the menu fetch dominates. Contact ' . self::CANARIES[2], TronFixture::EDITOR );
        $store->comment( $ids[0], 'Fix ready for review.', TronFixture::EDITOR );
        $store->comment( $ids[4], 'Scheduled for Thursday.', TronFixture::OPERATOR );
        $store->link( $ids[0], 'url', 'https://example.org/perf-report', 'profile', TronFixture::EDITOR );
        $store->link( $ids[4], 'commit', 'tron@0123abc', 'config', TronFixture::OPERATOR );
        $store->link( $ids[2], 'url', 'https://example.org/footer', '', TronFixture::ADMIN );
        // closed work, for throughput and history skills
        foreach ( array( 'Old cache bug', 'Migrate cron to timers', 'Update the README', 'Fix the login form label' ) as $i => $title )
        {
            $t = $store->create( array( 'title' => $title, 'board' => $i % 2 ? 'ops' : 'main', 'lane' => 'done', 'priority' => 'normal', 'labels' => array( 'done-work' ) ), TronFixture::EDITOR );
            $store->close( (int)$t['id'], 'done', TronFixture::EDITOR );
        }
        $store->decide( $ids[9], 'Built-in search, no external engine.', true, TronFixture::DECIDER );
    }
}
