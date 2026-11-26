<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Setup > tron > Agent rules (tron/rules, doc/agent-rules.md): what the page shows and what its forms do. The view
 * reads the request and the uploaded file, this class decides; every change is recorded in the audit log as an
 * administrator's action (rules.*). The rules layer is built from the context's settings and services, so the tests
 * run it on a scratch directory.
 */
final class tronAdminRulesData
{
    /** @var tronAdminContext */ private $c;
    /** @var tronRules */ private $rules;
    /** @var tronRulesStore */ private $store;
    /** @var callable|null */ private $setPreference;
    /** @var callable|null */ private $expai;
    /** @var string */ private $expaiUnavailable;

    /**
     * Options: rules (tronRules; default from the context's settings and the services given as services), setPreference (callable( userId, ?string value )),
     * expai (callable( answers ) => array( text, model )), expaiUnavailable (why the expai wizard cannot run, '' when it can).
     */
    public function __construct( tronAdminContext $c, array $o = array() )
    {
        $this->c = $c;
        $this->rules = $o['rules'] ?? tronRules::fromEnvironment( new tronEnvironment( $c->settings(), (array)( $o['services'] ?? array() ) ) );
        $this->store = new tronRulesStore( $this->rules, function () use ( $c ) { return $c->now(); } );
        $this->setPreference = $o['setPreference'] ?? null;
        $this->expai = $o['expai'] ?? null;
        $this->expaiUnavailable = (string)( $o['expaiUnavailable'] ?? ( $this->expai ? '' : 'Exponential Intelligence (expai) is not active on this site.' ) );
    }

    public function rules(): tronRules
    {
        return $this->rules;
    }

    /** Everything the page shows. */
    public function data( string $editor = '' ): array
    {
        $actor = $this->c->actor();
        $st = $this->rules->state( (int)$actor['id'], array(), true );
        $agents = $this->rules->agentsText();
        $switches = array();
        foreach ( $st['switches'] as $row )
            $switches[] = $row + array( 'shown' => $row['value'] !== '' ? $row['value'] : 'not set' );
        $questions = array();
        foreach ( tronRulesGenerator::QUESTIONS as $name => $q )
        {
            $choices = array();
            foreach ( (array)( $q['choices'] ?? array() ) as $k => $label )
                $choices[] = array( 'value' => $k, 'label' => $label );
            $questions[] = array( 'name' => $name, 'label' => $q['label'], 'kind' => $q['kind'], 'help' => $q['help'], 'choices' => $choices );
        }
        $adminState = $this->rules->adminState();
        return array(
            'master' => $st['master'] + array( 'path_shown' => self::shown( $st['master']['path'] ),
                                               'override_shown' => self::shown( (string)$st['master']['override_path'] ),
                                               'default_shown' => self::shown( (string)$st['master']['default_path'] ),
                                               'text' => $this->rules->masterText() ),
            'agents' => $st['agents'] + array( 'path_shown' => self::shown( (string)$st['agents']['path'] ), 'text' => $agents,
                                               'exists' => $this->rules->agentsFile()['exists'] ),
            'switches' => $switches,
            'decided_by' => $st['master']['decided_by'],
            'admin_toggle' => $this->rules->adminToggle(),
            'admin_changed' => isset( $adminState['changed'] ) ? (int)$adminState['changed'] : 0,
            'marker' => $this->rules->markerExists(),
            'marker_shown' => self::shown( (string)$st['marker_path'] ),
            'dir_shown' => self::shown( (string)$st['dir'] ),
            'preference' => self::switchValue( $st['switches'], 'user' ),
            'questions' => $questions,
            'editor' => $editor !== '' ? $editor : $agents,
            'expai' => $this->expaiUnavailable === '',
            'expai_unavailable' => $this->expaiUnavailable,
            'combined' => $this->rules->combined( (int)$actor['id'] ),
        );
    }

    private static function switchValue( array $rows, string $name ): string
    {
        foreach ( $rows as $r )
            if ( $r['name'] === $name )
                return (string)$r['value'];
        return '';
    }

    /** A path as the page shows it: relative to the installation root where it is under it. */
    private static function shown( string $path ): string
    {
        if ( $path === '' )
            return '';
        $cwd = rtrim( str_replace( '\\', '/', (string)getcwd() ), '/' ) . '/';
        $path = str_replace( '\\', '/', $path );
        return strpos( $path, $cwd ) === 0 ? substr( $path, strlen( $cwd ) ) : $path;
    }

    /**
     * One form action.
     * @param array $post the posted fields
     * @param string|null $upload the content of an uploaded file
     * @return array notice, error, warnings (list), editor (text for the editor)
     */
    public function handle( string $action, array $post, ?string $upload = null ): array
    {
        $out = array( 'notice' => null, 'error' => null, 'warnings' => array(), 'editor' => '' );
        $actorId = (int)$this->c->actor()['id'];
        try
        {
            switch ( $action )
            {
                case 'SaveAgents':
                case 'UploadAgents':
                case 'SaveMaster':
                case 'UploadMaster':
                    $which = strpos( $action, 'Agents' ) !== false ? 'agents' : 'master';
                    $text = strpos( $action, 'Upload' ) === 0 ? $upload : (string)( $post[$which === 'agents' ? 'TronAgentsText' : 'TronMasterText'] ?? '' );
                    if ( $text === null || $text === '' )
                        throw new InvalidArgumentException( strpos( $action, 'Upload' ) === 0 ? 'Choose a file first.' : 'The text is empty; remove the file instead.' );
                    $out['warnings'] = $this->store->save( $which, $text );
                    $file = $which === 'agents' ? 'AGENTS.md' : 'the MASTER.md override';
                    $this->c->recordAction( 'rules.' . $which . '.save', $which, "$file saved (" . strlen( $text ) . ' bytes, sha256 ' . substr( hash( 'sha256', $text ), 0, 12 ) . ')' );
                    $out['notice'] = "Saved $file. Agents receive it from their next session.";
                    break;
                case 'RemoveAgents':
                case 'RemoveMaster':
                    $which = $action === 'RemoveAgents' ? 'agents' : 'master';
                    $removed = $this->store->remove( $which );
                    if ( $removed )
                        $this->c->recordAction( 'rules.' . $which . '.remove', $which, ( $which === 'agents' ? 'AGENTS.md' : 'the MASTER.md override' ) . ' removed' );
                    $out['notice'] = $removed ? ( $which === 'agents' ? 'Removed AGENTS.md.' : 'Removed the MASTER.md override: the shipped MASTER.md applies again.' ) : 'There was no file to remove.';
                    break;
                case 'AdminToggle':
                    $v = (string)( $post['TronAdminMaster'] ?? '' );
                    $this->store->setAdminToggle( $v, $actorId );
                    $this->c->recordAction( 'rules.master.toggle', 'master', 'admin toggle ' . ( $v === '' ? 'cleared' : $v ) );
                    $out['notice'] = $v === '' ? 'The admin toggle is cleared: the INI decides.' : "MASTER.md is $v by the admin toggle.";
                    break;
                case 'Marker':
                    $on = (string)( $post['TronMarker'] ?? '' ) === '1';
                    $this->store->setMarker( $on );
                    $this->c->recordAction( 'rules.master.marker', 'master', 'file marker ' . ( $on ? 'placed' : 'removed' ) );
                    $out['notice'] = $on ? 'The file marker MASTER.disabled is in place: MASTER.md is off whatever else is set.' : 'The file marker is removed.';
                    break;
                case 'Preference':
                    if ( !$this->setPreference )
                        throw new RuntimeException( 'Preferences are not available here.' );
                    $v = (string)( $post['TronPreference'] ?? '' ) === 'disabled' ? 'disabled' : null;
                    call_user_func( $this->setPreference, $actorId, $v );
                    $out['notice'] = $v ? 'MASTER.md is off for your own sessions.' : 'Your preference is cleared: the site decides.';
                    break;
                case 'Generate':
                    $out['editor'] = tronRulesGenerator::generate( (array)( $post['TronQ'] ?? array() ) );
                    $out['notice'] = 'A starter AGENTS.md is in the editor. Read it, change it, then save it.';
                    break;
                case 'ExpaiDraft':
                    if ( !$this->expai || $this->expaiUnavailable !== '' )
                        throw new RuntimeException( $this->expaiUnavailable !== '' ? $this->expaiUnavailable : 'expai is not available.' );
                    $r = call_user_func( $this->expai, (array)( $post['TronQ'] ?? array() ) );
                    $out['editor'] = (string)$r['text'];
                    $out['notice'] = 'Exponential Intelligence wrote a draft into the editor' . ( !empty( $r['model'] ) ? ' (' . $r['model'] . ')' : '' ) . '. Read it carefully before you save it.';
                    break;
                default:
                    throw new InvalidArgumentException( 'Unknown action' );
            }
        }
        catch ( InvalidArgumentException | RuntimeException $e )
        {
            $out['error'] = $e->getMessage();
            if ( isset( $post['TronAgentsText'] ) )
                $out['editor'] = (string)$post['TronAgentsText'];
        }
        return $out;
    }
}
