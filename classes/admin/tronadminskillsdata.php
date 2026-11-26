<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Setup > tron > Skills: the packs with the extension that provides each (name, version, license) and their state,
 * the skills with search, tag, state and pack filters and counts, one skill in full (manifest, parameters with their
 * CLI options, examples, proof, versions, recent runs from the audit log), and the actions: approve (after proving the
 * skill again as the approving administrator), revoke, switch a pack off or on, fork into a local pack, approve a draft.
 *
 * Proving needs a server that checks the administrator's real policies; the view passes one (the kernel's access
 * checker), the tests pass their own. Without one, the context's server is used.
 */
final class tronAdminSkillsData
{
    const PAGE = 50;
    const STATES = array( 'live', 'ready', 'unproven', 'parked', 'failed', 'disabled' );

    /** @var tronAdminContext */ private $c;
    /** @var tronServer|null */ private $prover;
    /** @var tronSession|null */ private $session;

    public function __construct( tronAdminContext $c, ?tronServer $prover = null, ?tronSession $session = null )
    {
        $this->c = $c;
        $this->prover = $prover;
        $this->session = $session;
    }

    private function env(): tronEnvironment
    {
        return tronSkillProgram::env( $this->c->server( true ) );
    }

    /** The view parameters a skills page understands, cleaned. */
    public static function filtersFrom( array $p ): array
    {
        $f = array();
        if ( isset( $p['pack'] ) && preg_match( tronSkillPack::ID_PATTERN, (string)$p['pack'] ) )
            $f['pack'] = (string)$p['pack'];
        if ( isset( $p['state'] ) && in_array( (string)$p['state'], self::STATES, true ) )
            $f['state'] = (string)$p['state'];
        if ( isset( $p['tag'] ) && preg_match( tronSkillManifest::TAG_PATTERN, (string)$p['tag'] ) )
            $f['tag'] = (string)$p['tag'];
        if ( isset( $p['q'] ) && trim( (string)$p['q'] ) !== '' )
            $f['q'] = mb_substr( trim( (string)$p['q'] ), 0, 100 );
        if ( isset( $p['offset'] ) && ctype_digit( (string)$p['offset'] ) )
            $f['offset'] = (int)$p['offset'];
        return $f;
    }

    /** The view path of a filter set (for links and redirects), e.g. tron/skills/(pack)/seo/(state)/live */
    public static function base( array $f, array $drop = array() ): string
    {
        $s = 'tron/skills';
        foreach ( array( 'pack', 'state', 'tag', 'q', 'offset' ) as $k )
            if ( isset( $f[$k] ) && !in_array( $k, $drop, true ) && !( $k === 'offset' && !$f[$k] ) )
                $s .= '/(' . $k . ')/' . rawurlencode( (string)$f[$k] );
        return $s;
    }

    public function data( array $f ): array
    {
        $env = $this->env();
        $all = tronSkillProgram::all( $env, $this->c->server( true )->registry() );
        $counts = tronSkillCatalog::counts( $all );
        $hits = tronSkillCatalog::search( $all, (string)( $f['q'] ?? '' ), isset( $f['tag'] ) ? array( $f['tag'] ) : array(), (string)( $f['state'] ?? '' ), (string)( $f['pack'] ?? '' ) );
        $tags = array();
        foreach ( $all as $s )
            foreach ( $s['manifest']->data['tags'] as $t )
                $tags[$t] = ( $tags[$t] ?? 0 ) + 1;
        arsort( $tags );
        $offset = (int)( $f['offset'] ?? 0 );
        $rows = array();
        $locale = $this->locale();
        foreach ( array_slice( $hits, $offset, self::PAGE ) as $s )
            $rows[] = tronSkillProgram::row( $s ) + array( 'title_local' => $s['manifest']->title( $locale ), 'reason' => $s['reason'] );
        $drafts = array();
        $store = tronSkillProgram::store( $env );
        if ( $store )
            foreach ( $store->drafts( 50 ) as $d )
                if ( $d['state'] !== 'approved' )
                    $drafts[] = array( 'id' => (int)$d['id'], 'name' => $d['name'], 'version' => $d['version'], 'pack' => $d['pack'], 'state' => $d['state'],
                                       'derived_from' => $d['derived_from'], 'changed' => (int)$d['changed'] );
        return array(
            'packs' => tronSkillProgram::packRows( $env ),
            'counts' => $counts, 'total' => count( $hits ), 'skills' => $rows, 'offset' => $offset, 'page' => self::PAGE,
            'tags' => array_slice( $tags, 0, 30, true ), 'states' => self::STATES, 'filters' => $f, 'drafts' => $drafts,
            'local_packs' => array_merge( array( 'local' ), array_keys( tronSkillProgram::catalog( $env )->localPacks() ) ),
        );
    }

    /** One skill in full, or null. */
    public function skill( string $name ): ?array
    {
        $env = $this->env();
        $all = tronSkillProgram::all( $env, $this->c->server( true )->registry() );
        if ( !isset( $all[$name] ) )
            return null;
        $s = $all[$name];
        $m = $s['manifest'];
        $params = array();
        foreach ( (array)( $m->data['parameters'] ?? array() ) as $pn => $p )
        {
            $range = array();
            foreach ( array( 'minimum' => 'min', 'maximum' => 'max', 'maxLength' => 'max length', 'pattern' => 'pattern' ) as $k => $label )
                if ( isset( $p[$k] ) )
                    $range[] = $label . ' ' . $p[$k];
            if ( isset( $p['enum'] ) )
                $range[] = implode( ' | ', $p['enum'] );
            $params[] = array( 'name' => $pn, 'type' => $p['type'], 'description' => $p['description'],
                               'option' => (string)( $p['option'] ?? '--' . str_replace( '_', '-', $pn ) ) . ( isset( $p['short'] ) ? ', ' . $p['short'] : '' ),
                               'default' => array_key_exists( 'default', $p ) ? json_encode( $p['default'] ) : '', 'range' => implode( '; ', $range ),
                               'examples' => implode( ', ', array_map( 'json_encode', (array)( $p['examples'] ?? array() ) ) ) );
        }
        $examples = array();
        foreach ( $m->data['examples'] as $ex )
        {
            $cli = '';
            foreach ( (array)( $ex['params'] ?? array() ) as $k => $v )
                $cli .= ' ' . (string)( $m->data['parameters'][$k]['option'] ?? '--' . str_replace( '_', '-', $k ) ) . ' ' . ( is_array( $v ) ? implode( ',', $v ) : ( is_bool( $v ) ? ( $v ? 'true' : 'false' ) : (string)$v ) );
            $examples[] = array( 'title' => $ex['title'], 'cli' => 'xsh tron:skill:run ' . $m->name . $cli,
                                 'json' => json_encode( (object)( $ex['params'] ?? array() ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        }
        $steps = array();
        foreach ( $m->data['steps'] as $st )
            $steps[] = array( 'id' => $st['id'], 'kind' => isset( $st['op'] ) ? 'op' : 'unit', 'what' => $st['op'] ?? $st['unit'],
                              'detail' => json_encode( array_diff_key( $st, array( 'id' => 1, 'op' => 1 ) ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        $store = tronSkillProgram::store( $env );
        $locale = $this->locale();
        return tronSkillProgram::row( $s ) + array(
            'title_local' => $m->title( $locale ), 'purpose_local' => $m->purpose( $locale ), 'use_case' => $m->data['use_case'],
            'reason' => $s['reason'], 'waits' => $s['waits'] ?? array(), 'origin' => $s['origin'], 'provider_info' => $s['provider'],
            'license' => $m->data['license'], 'author' => $m->data['author'], 'hash' => $m->hash(), 'proof' => $s['proof'],
            'parameters' => $params, 'examples' => $examples, 'steps' => $steps, 'guards' => (array)( $m->data['guards'] ?? array() ),
            'derived_from' => $m->data['derived_from'] ?? null, 'draft_id' => $s['draft_id'] ?? 0,
            'manifest_json' => json_encode( $m->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
            'versions' => $store ? $store->history( $m->name, 20 ) : array(),
            'runs' => $this->runs( $m->toolName() ),
        );
    }

    /** The last runs of a skill's tool from tron's audit table. */
    private function runs( string $tool ): array
    {
        $db = $this->c->db();
        if ( !$db->tableExists( 'tron_audit' ) )
            return array();
        $out = array();
        foreach ( $db->select( 'SELECT created, login, transport, outcome, duration_ms FROM tron_audit WHERE target = :t ORDER BY id DESC', array( 't' => $tool ), 10 ) as $r )
            $out[] = array( 'at' => gmdate( 'Y-m-d H:i', (int)$r['created'] ), 'login' => $r['login'], 'transport' => $r['transport'], 'outcome' => $r['outcome'], 'ms' => (int)$r['duration_ms'] );
        return $out;
    }

    private function locale(): string
    {
        return class_exists( 'eZLocale' ) ? (string)eZLocale::currentLocaleCode() : 'eng-GB';
    }

    private function proverAndSession(): array
    {
        // Proving must ask the real role engine about the administrator. The context's own server lists with an
        // access checker that grants only its internal principal, so it can never prove anything for a real user:
        // without a prover given, an installation proves through its kernel access checker (eZUser::hasAccessTo(),
        // which honours */* and module/* policies exactly as the admin interface does).
        $server = $this->prover;
        if ( !$server && class_exists( 'eZUser' ) && class_exists( 'tronKernel' ) )
            $server = $this->prover = tronKernel::server( null, new tronKernelAccessChecker() );
        if ( !$server )
            throw new tronToolError( 'Approving needs a server that checks the administrator\'s own policies' );
        $actor = $this->c->actor();
        $session = $this->session ?: new tronSession( new tronPrincipal( (int)( $actor['id'] ?? 0 ), (string)( $actor['login'] ?? 'admin' ) ), 'admin', false, '2025-11-25', 'admin' );
        return array( $server, $session );
    }

    /**
     * Approves a skill (a pack's, or a draft by its id): proves it again as the administrator first.
     * @return array notice or error, and the proof
     */
    public function approve( string $name, int $draftId = 0 ): array
    {
        $env = $this->env();
        $store = tronSkillProgram::store( $env );
        if ( !$store )
            return array( 'error' => $this->c->tr( 'Skills need the database.' ) );
        try
        {
            list( $server, $session ) = $this->proverAndSession();
        }
        catch ( tronToolError $e )
        {
            return array( 'error' => $e->getMessage() );
        }
        $actor = (int)( $this->c->actor()['id'] ?? 0 );
        if ( $draftId > 0 )
        {
            $row = $store->draftById( $draftId );
            if ( !$row )
                return array( 'error' => $this->c->tr( 'No draft %id.', array( '%id' => $draftId ) ) );
            try
            {
                $m = tronSkillManifest::fromArray( (array)json_decode( (string)$row['manifest'], true ), 'draft:' . $draftId );
            }
            catch ( tronSkillInvalid $e )
            {
                return array( 'error' => $e->getMessage() );
            }
            $proof = tronSkillValidator::prove( $server, $session, $m );
            if ( $proof['result'] !== 'pass' )
                return array( 'error' => $this->c->tr( 'Not approved: %name did not pass (%reason).', array( '%name' => $m->name, '%reason' => $proof['reason'] ) ), 'proof' => $proof );
            $store->setDraftState( $draftId, 'approved', $actor, (string)json_encode( $proof + array( 'hash' => $m->hash() ) ) );
            $this->c->recordAction( 'skill-approve', $m->toolName(), 'draft ' . $draftId . ' ' . $m->version );
            return array( 'notice' => $this->c->tr( '%name is approved and live.', array( '%name' => $m->name ) ), 'proof' => $proof );
        }
        $all = tronSkillProgram::all( $env, $this->c->server( true )->registry() );
        if ( !isset( $all[$name] ) )
            return array( 'error' => $this->c->tr( 'No skill %name.', array( '%name' => $name ) ) );
        $s = $all[$name];
        if ( !in_array( $s['state'], array( 'ready', 'live' ), true ) )
            return array( 'error' => $this->c->tr( 'Not approved: %name is %state (%reason).', array( '%name' => $name, '%state' => $s['state'], '%reason' => $s['reason'] ) ) );
        $proof = tronSkillValidator::prove( $server, $session, $s['manifest'] );
        if ( $proof['result'] !== 'pass' )
            return array( 'error' => $this->c->tr( 'Not approved: %name did not pass (%reason).', array( '%name' => $name, '%reason' => $proof['reason'] ) ), 'proof' => $proof );
        $store->approve( $s['manifest'], (string)$s['pack'], $actor, (string)json_encode( array( 'result' => 'pass', 'checks' => array_intersect_key( $proof['checks'], array_flip( array( 'schema', 'units', 'policy', 'output', 'redaction' ) ) ) ) ) );
        $this->c->recordAction( 'skill-approve', $s['manifest']->toolName(), $s['manifest']->version . ' ' . substr( $s['manifest']->hash(), 0, 12 ) );
        return array( 'notice' => $this->c->tr( '%name is approved and live.', array( '%name' => $name ) ), 'proof' => $proof );
    }

    /** Approves every ready skill of a pack, each proven again. */
    public function approvePack( string $pack ): array
    {
        $env = $this->env();
        $done = 0;
        $failed = array();
        foreach ( tronSkillProgram::all( $env, $this->c->server( true )->registry() ) as $name => $s )
        {
            if ( $s['pack'] !== $pack || $s['state'] !== 'ready' )
                continue;
            $r = $this->approve( $name );
            if ( isset( $r['notice'] ) )
                $done++;
            else
                $failed[] = $name;
        }
        if ( $failed )
            return array( 'error' => $this->c->tr( '%n skills approved; not approved: %names.', array( '%n' => $done, '%names' => implode( ', ', $failed ) ) ) );
        return array( 'notice' => $this->c->tr( '%n skills of %pack approved.', array( '%n' => $done, '%pack' => $pack ) ) );
    }

    public function revoke( string $name ): array
    {
        $store = tronSkillProgram::store( $this->env() );
        if ( !$store || !preg_match( tronSkillManifest::NAME_PATTERN, $name ) )
            return array( 'error' => $this->c->tr( 'No skill %name.', array( '%name' => $name ) ) );
        $store->revoke( $name, (int)( $this->c->actor()['id'] ?? 0 ), 'revoked in Setup > tron > Skills' );
        $this->c->recordAction( 'skill-revoke', tronSkillManifest::toolNameOf( $name ), '' );
        return array( 'notice' => $this->c->tr( '%name is revoked; it is no longer offered.', array( '%name' => $name ) ) );
    }

    public function setPack( string $pack, bool $enabled ): array
    {
        $store = tronSkillProgram::store( $this->env() );
        try
        {
            $store->setPack( $pack, $enabled, (int)( $this->c->actor()['id'] ?? 0 ) );
        }
        catch ( InvalidArgumentException $e )
        {
            return array( 'error' => $e->getMessage() );
        }
        $this->c->recordAction( $enabled ? 'skillpack-on' : 'skillpack-off', $pack, '' );
        return array( 'notice' => $this->c->tr( $enabled ? 'The pack %pack is switched on.' : 'The pack %pack is switched off.', array( '%pack' => $pack ) ) );
    }

    public function fork( string $name, string $newName, string $pack ): array
    {
        $env = $this->env();
        try
        {
            list( $server, $session ) = $this->proverAndSession();
        }
        catch ( tronToolError $e )
        {
            return array( 'error' => $e->getMessage() );
        }
        try
        {
            $s = tronSkillProgram::find( $env, $name );
            $ctx = new tronCallContext( $session, $env, method_exists( $server, 'access' ) ? $server->access() : new tronRoleAccessChecker( array(), array() ) );
            $r = tronSkillProgram::draftOne( $env, $ctx, tronSkillProgram::forkManifest( $s, $newName, array(), (string)( $this->c->actor()['login'] ?? '' ) ), $pack );
        }
        catch ( tronToolError $e )
        {
            return array( 'error' => $e->getMessage() . ( isset( $e->details()['reasons'] ) ? ': ' . implode( '; ', $e->details()['reasons'] ) : '' ) );
        }
        $this->c->recordAction( 'skill-fork', $newName, 'from ' . $name );
        return array( 'notice' => $this->c->tr( '%new is a draft in %pack, derived from %name.', array( '%new' => $newName, '%pack' => $pack, '%name' => $name ) ), 'draft_id' => $r['draft_id'] );
    }
}
