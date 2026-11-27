<?php
/**
 * Who a command line acts as: tron.php serve and call, tron.php token:create (who issues the token), and
 * tron-skills.php run, validate, try, unit and harness --live.
 *
 * A token always decides: TRON_TOKEN or --token-file, checked like every other token (hash, expiry, revocation, the
 * user still enabled). --user=<login> names a user without any token, so whoever can start the process could be any
 * user, an administrator included. It is therefore refused unless the installation enables it explicitly in an
 * override: [StdioSettings] AllowLoginOption=enabled. Anything else, and no setting at all, refuses it.
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
    public static function resolve( array $settings, string $token, string $login, tronTokenService $tokens, tronUserDirectory $users, string $what = 'serve' ): array
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
                return array( null, 1, "FAIL: tron: $what --user is disabled ([StdioSettings] AllowLoginOption=disabled, the default); "
                                     . 'give a token: TRON_TOKEN=<token> or --token-file=<file> (Setup > tron > Tokens or tron.php token:create makes one)' );
            $u = $users->byLogin( $login );
            if ( !$u || empty( $u['enabled'] ) )
                return array( null, 1, "FAIL: tron: no enabled user '$login'" );
            return array( new tronPrincipal( (int)$u['id'], (string)$u['login'] ), 0, '' );
        }
        return array( null, 2, "tron $what needs a token: TRON_TOKEN=<token> or --token-file=<file>"
                               . ( self::loginAllowed( $settings ) ? ' (or --user=<login>, enabled here by [StdioSettings] AllowLoginOption)' : '' ) );
    }

    /**
     * Whether $issuer may create a token for $user with $scopes: where AllowLoginOption is enabled anyone who runs the
     * command may (as before 0.0.0.15, $issuer null); otherwise the issuer is the token's user, who may create one for
     * themselves within their own token's scopes, and for another user only with tron/admin.
     *
     * @return string '' when allowed, else the reason
     */
    public static function mayIssue( ?tronPrincipal $issuer, int $userId, array $scopes, tronAccessChecker $access ): string
    {
        if ( $issuer === null )
            return '';
        $admin = $access->check( $issuer, 'tron', 'admin' ) === tronAccessChecker::YES;
        if ( $issuer->userId !== $userId && !$admin )
            return "the user {$issuer->login} may create tokens only for themselves (another user needs tron/admin)";
        if ( !$admin && !in_array( '*', $issuer->scopes, true ) )
        {
            foreach ( $scopes as $sc )
            {
                if ( !in_array( $sc, $issuer->scopes, true ) )
                    return "a token for the scopes " . implode( ',', $issuer->scopes ) . " cannot create one for the scope $sc";
            }
        }
        return '';
    }
}
