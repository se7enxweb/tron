<?php
/**
 * The world the contract fuzzer runs against (doc/contract-testing.md): the test users with their roles plus content
 * grants, centre tickets, Katalyst rows, content nodes and an INI instruction, with canary secrets planted in all of
 * them, and a logger that collects every error the server logs.
 *
 *   $w = TronFuzzWorld::build( 'tables' );         // centre on tron's tables
 *   $w = TronFuzzWorld::build( 'bridge' );         // centre through a stand-in of centre's API
 *   $w = TronFuzzWorld::build( 'tables', $pdo );   // on another database (inside a transaction the caller opened)
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

final class TronFuzzWorld
{
    /** @var tronServer */ public $server;
    /** @var tronDb */ public $db;
    /** @var tronAuditMemory */ public $audit;
    /** @var string[] errors the server logged */ public $errors = array();
    /** @var PDO|null a connection inside the caller's transaction (PostgreSQL runs) */ public $pdo = null;

    /**
     * Around each call on a database inside the run's transaction: a savepoint, released after the call, or rolled
     * back to when a failed statement left the transaction aborted (as autocommit would have ended it), so one failed
     * statement does not fail every call after it.
     */
    public function before(): void
    {
        if ( $this->pdo )
            $this->pdo->exec( 'SAVEPOINT tron_fuzz_call' );
    }

    public function after(): void
    {
        if ( !$this->pdo )
            return;
        try
        {
            $this->pdo->exec( 'RELEASE SAVEPOINT tron_fuzz_call' );
        }
        catch ( PDOException $e )
        {
            $this->pdo->exec( 'ROLLBACK TO SAVEPOINT tron_fuzz_call' );
            $this->pdo->exec( 'RELEASE SAVEPOINT tron_fuzz_call' );
        }
    }

    public static function build( string $kind = 'tables', ?PDO $pdo = null ): self
    {
        $w = new self();
        if ( $pdo )
        {
            $w->pdo = $pdo;
            $w->db = new tronDbPdo( $pdo );
            tronSchema::install( $w->db );
        }
        else
            $w->db = tronDbPdo::sqliteMemory();
        $w->audit = new tronAuditMemory();
        $canaries = array_keys( TronFuzz::canaries() );
        $clock = function () { return TronFixture::NOW; };

        $services = array(
            'katalyst.source' => self::katalyst(),
            'content.source' => self::content( $clock ),
        );
        if ( $kind === 'bridge' )
            $services['centre.api'] = function () { return TronFuzzWorld::centreApi(); };

        $w->server = tronFactory::build( array(
            'settings' => array( 'ServerSettings' => array( 'Instructions' => 'Ops note: the deploy password=CanaryIniPw-55 is rotated weekly.' ),
                                 'RootProgramSettings' => array( 'Enabled' => 'enabled' ) ),
            'access' => self::access(),
            'audit' => $w->audit,
            'db' => $w->db,
            'users' => new tronArrayUserDirectory( TronFixture::users() ),
            'services' => $services,
            'clock' => $clock,
            'logger' => function ( $level, $message ) use ( $w ) {
                if ( $level === 'error' )
                    $w->errors[] = $message;
            },
        ) );

        if ( $kind === 'tables' )
        {
            // tickets by several users, with the canaries in titles, bodies, comments and links
            $store = new tronCentreStore( $w->db, array( 'inbox', 'next', 'doing', 'waiting', 'done' ), array(), $clock );
            foreach ( $canaries as $i => $c )
            {
                $t = $store->create( array( 'title' => 'seed ' . $i . ' ' . substr( str_replace( "\n", ' ', $c ), 0, 200 ), 'body' => "Notes:\n" . $c,
                                            'kind' => $i % 3 === 0 ? 'decision' : 'ticket', 'decision_week' => '2026-W48' ), 1 + $i % 6 );
                $store->comment( (int)$t['id'], 'from user ' . ( 2 + $i % 5 ) . ': ' . $c . ' / ' . sprintf( TronFuzz::userCanaries()['email'], 2 + $i % 5 ), 2 + $i % 5 );
            }
            $store->link( 1, 'url', 'https://example.org/?token=CanaryApiKey-3141', 'see ' . $canaries[0], 1 );
        }
        return $w;
    }

    /** The fixture's roles, plus content/edit for the editor (node 43 only) and the decider (all). */
    public static function access(): tronRoleAccessChecker
    {
        $viewer = array( 'tron/use', 'tron/centre_read', 'centre/api' );
        $editor = array_merge( $viewer, array( 'tron/centre_write', 'content/edit:Subtree=/1/2/43/' ) );
        $operator = array( 'tron/use', 'tron/katalyst_read' );
        return new tronRoleAccessChecker(
            array( 'admin' => array( '*/*' ), 'viewer' => $viewer, 'editor' => $editor,
                   'decider' => array_merge( $editor, array( 'tron/centre_decide', 'content/edit' ) ), 'operator' => $operator,
                   'detail' => array_merge( $operator, array( 'tron/katalyst_detail' ) ),
                   'limited' => array( 'tron/use', 'tron/centre_read:limited' ), 'useonly' => array( 'tron/use' ) ),
            array( TronFixture::ADMIN => array( 'admin' ), TronFixture::VIEWER => array( 'viewer' ), TronFixture::EDITOR => array( 'editor' ),
                   TronFixture::DECIDER => array( 'decider' ), TronFixture::OPERATOR => array( 'operator' ), TronFixture::DETAIL => array( 'detail' ),
                   TronFixture::DISABLED => array( 'admin' ), TronFixture::LIMITED => array( 'limited' ), TronFixture::USEONLY => array( 'useonly' ) ),
            array( TronFixture::DISABLED ) );
    }

    public static function katalyst(): tronKatalystArraySource
    {
        $k = TronFixture::katalyst();
        $c = TronFuzz::canaryInputs();
        return new tronKatalystArraySource( array(
            'plans' => array( array( 'id' => 9, 'source' => 'shop', 'server_id' => 0, 'external_id' => 'x', 'name' => $c[0], 'group_name' => $c[4],
                                     'type' => 'product', 'pay_type' => '', 'price_monthly' => '1.00', 'currency' => 'EUR', 'hidden' => 0, 'gone' => 0,
                                     'created' => TronFixture::NOW, 'modified' => TronFixture::NOW ) ),
            'services' => array( array( 'id' => 19, 'customer_id' => 509, 'server_id' => 2, 'domain' => 'canary.person@example.org', 'status' => 'active',
                                        'hosting_type' => $c[7], 'plan_id' => 9, 'plesk_plan_id' => 0, 'service_kind' => 'hosting', 'billing_cycle' => $c[2],
                                        'next_due_date' => '2026-12-01', 'disk_total_bytes' => 1, 'traffic_bytes' => 0, 'gone' => 0,
                                        'created' => TronFixture::NOW, 'modified' => TronFixture::NOW ) ),
            'orders' => array( array( 'id' => 39, 'source' => 'shop', 'shop_order_nr' => 1009, 'customer_id' => 509, 'state' => $c[8], 'currency' => 'EUR',
                                      'total_ex_vat' => '1.00', 'total_inc_vat' => '1.19', 'item_count' => 1, 'fraud_state' => $c[10],
                                      'created' => TronFixture::NOW, 'modified' => TronFixture::NOW, 'note' => $c[6], 'domain' => '' ) ),
        ) + array() );
    }

    public static function content( callable $clock ): tronContentArraySource
    {
        $c = TronFuzz::canaryInputs();
        $node = function ( int $nodeId, int $objectId, string $name, array $attrs ) {
            $a = array();
            foreach ( $attrs as $k => $v )
                $a[$k] = array( 'type' => $v[0], 'value' => $v[1], 'editable' => in_array( $v[0], tronContentSource::EDITABLE, true ) );
            return array( 'node_id' => $nodeId, 'object_id' => $objectId, 'name' => $name, 'class' => 'article', 'language' => 'eng-GB',
                          'current_version' => 3, 'next_version' => 4, 'modified' => TronFixture::NOW - 3600, 'attributes' => $a );
        };
        return new tronContentArraySource( array(
            $node( 2, 1, 'Home', array( 'title' => array( 'ezstring', 'Home' ), 'body' => array( 'eztext', $c[0] . "\n" . $c[1] ),
                                         'count' => array( 'ezinteger', '3' ), 'show' => array( 'ezboolean', '1' ), 'image' => array( 'ezimage', null ) ) ),
            $node( 43, 41, 'Media', array( 'title' => array( 'ezstring', 'Media' ), 'body' => array( 'eztext', $c[5] . ' ' . $c[6] ),
                                            'price' => array( 'ezfloat', '1.5' ) ) ),
            // another user's profile: their e-mail address and phone number must never leave
            $node( 107, 107, 'Profile of viewer', array( 'title' => array( 'ezstring', 'viewer' ),
                   'email' => array( 'ezstring', sprintf( TronFuzz::userCanaries()['email'], TronFixture::VIEWER ) ),
                   'phone' => array( 'ezstring', sprintf( TronFuzz::userCanaries()['phone'], TronFixture::VIEWER ) ) ) ),
        ), array( TronFixture::ADMIN => '*', TronFixture::EDITOR => '*', TronFixture::DECIDER => '*', TronFixture::VIEWER => array( 2, 43, 107 ) ),
           array( TronFixture::ADMIN => '*', TronFixture::EDITOR => array( 43 ), TronFixture::DECIDER => '*' ), $clock );
    }

    /** A stand-in of centre's API, deterministic, with canaries in its cards. */
    public static function centreApi(): callable
    {
        $cards = array( 'EXP-1' => array( 'number' => 'EXP-1', 'title' => 'Card one', 'lane' => 'Ready',
                                          'body' => TronFuzz::canaryInputs()[3] . ' ' . TronFuzz::canaryInputs()[9], 'priority' => 'medium' ) );
        return function ( string $tool, array $args, int $userId ) use ( &$cards ) {
            if ( isset( $args['number'] ) && !isset( $cards[$args['number']] ) )
                return array( 'ok' => false, 'error' => array( 'code' => 'not_found', 'message' => 'no card ' . $args['number'] ) );
            switch ( $tool )
            {
                case 'centre_get_card':
                    return array( 'ok' => true, 'result' => $cards[$args['number']] );
                case 'centre_create_card':
                    if ( $args['prefix'] !== 'EXP' )
                        return array( 'ok' => false, 'error' => array( 'code' => 'not_found', 'message' => 'no project ' . $args['prefix'] ) );
                    $n = 'EXP-' . ( count( $cards ) + 1 );
                    $cards[$n] = array( 'number' => $n, 'title' => $args['title'], 'lane' => $args['lane'] ?? 'Backlog', 'body' => $args['body'] ?? '', 'priority' => $args['priority'] ?? 'medium' );
                    return array( 'ok' => true, 'result' => array( 'number' => $n ) );
                case 'centre_update_card':
                    foreach ( (array)$args['fields'] as $k => $v )
                        if ( is_string( $k ) && is_scalar( $v ) )
                            $cards[$args['number']][$k] = $v;
                    return array( 'ok' => true, 'result' => $cards[$args['number']] );
                case 'centre_search_cards':
                case 'centre_get_board':
                    return array( 'ok' => true, 'result' => array( 'cards' => array_values( $cards ) ) );
                case 'centre_whoami':
                    return array( 'ok' => true, 'result' => array( 'user_id' => $userId, 'email' => sprintf( TronFuzz::userCanaries()['email'], $userId ) ) );
            }
            return array( 'ok' => true, 'result' => array( 'tool' => $tool, 'args' => $args ) );
        };
    }

    /** Values that make sense for some argument names, so valid inputs reach deep into the tools. */
    public function hints(): array
    {
        $tools = array_map( function ( $t ) { return $t->name; }, $this->server->registry()->tools() );
        $logins = array_map( function ( $u ) { return $u['login']; }, TronFixture::users() );
        return array( 'tool' => array_merge( $tools, array( 'centre', 'katalyst', 'tron', '*', 'tron://status', 'centre://ticket/1', 'centre_triage' ) ),
                      'user' => array_values( $logins ), 'assignee' => array_values( $logins ), 'node_id' => array( 2, 43, 107, 999 ),
                      'id' => array( 1, 2, 3, 5, 13, 999 ), 'number' => array( 'EXP-1', 'EXP-2', 'NOPE-9' ), 'prefix' => array( 'EXP', 'ZZ' ),
                      'target' => array( '2', '43', '107', 'x' ),
                      '@attributes' => array( array( 'title' => 'New title' ), array( 'count' => 4 ), array( 'show' => false ), array( 'price' => 2.25 ),
                                              array( 'image' => 'x' ), array( 'nope' => 'x' ), array( 'title' => "two\nlines" ) ),
                      '@arguments' => array( array( 'title' => 'planned' ), array( 'id' => 1, 'title' => 'renamed' ),
                                             array( 'node_id' => 43, 'attributes' => array( 'title' => 'Media 2' ) ), array() ),
                      '@fields' => array( array( 'title' => 'x' ), array( 'priority' => 'high' ) ) );
    }
}
