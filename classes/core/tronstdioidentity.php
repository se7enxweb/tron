<?php
/**
 * Who a stdio server (tron.php serve) acts as.
 *
 * A token always decides: TRON_TOKEN or --token-file, checked like every other token (hash, expiry, revocation, the
 * user still enabled). serve --user=<login> names a user without any token, so whoever can start the process could
 * be any user, an administrator included. It is therefore refused unless the installation enables it explicitly in
 * an override: [StdioSettings] AllowLoginOption=enabled. Anything else, and no setting at all, refuses it.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

final class tronStdioIdentity
{
    /** Whether serve --user=<login> is allowed: only with [StdioSettings] AllowLoginOption=enabled. */
    public static function loginAllowed( array $settings ): bool
    {
        return strtolower( trim( (string)( $settings['StdioSettings']['AllowLoginOption'] ?? 'disabled' ) ) ) === 'enabled';
    }

    /**
     * The principal of a stdio server, or why there is none.
     *
     * @param string $token the token (TRON_TOKEN or the content of --token-file), '' for none
     * @param string $login the value of --user, '' for none
     * @return array( ?tronPrincipal, int exit code (0 when there is a principal; 1 refused, 2 usage), string line for stderr )
     */
    public static function resolve( array $settings, string $token, string $login, tronTokenService $tokens, tronUserDirectory $users ): array
    {
        if ( $token !== '' )
        {
            $reason = null;
            $principal = $tokens->authenticate( $token, $reason );
            if ( !$principal )
                return array( null, 1, "FAIL: tron: the token was refused ($reason)" );
            return array( $principal, 0, '' );
        }
        if ( $login !== '' )
        {
            if ( !self::loginAllowed( $settings ) )
                return array( null, 1, 'FAIL: tron: serve --user is disabled ([StdioSettings] AllowLoginOption=disabled, the default); '
                                     . 'give a token: TRON_TOKEN=<token> or --token-file=<file> (tron.php token:create makes one)' );
            $u = $users->byLogin( $login );
            if ( !$u || empty( $u['enabled'] ) )
                return array( null, 1, "FAIL: tron: no enabled user '$login'" );
            return array( new tronPrincipal( (int)$u['id'], (string)$u['login'] ), 0, '' );
        }
        return array( null, 2, 'tron serve needs a token: TRON_TOKEN=<token> or --token-file=<file>'
                               . ( self::loginAllowed( $settings ) ? ' (or --user=<login>, enabled here by [StdioSettings] AllowLoginOption)' : '' ) );
    }
}
