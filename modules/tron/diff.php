<?php
/**
 * Setup > tron > Diff: two texts compared with the diff program's engines (line, word or character; Myers or
 * patience) and shown with the reusable part tron/parts/diff.tpl, the same rows and classes the diff tools' html
 * format returns. The texts are posted and never stored. Needs the policy tron/diff.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

$Module = $Params['Module'];
$http = eZHTTPTool::instance();
$left = "<?php /* #?ini charset=\"utf-8\"?\n\n[WebSettings]\nNetwork=enabled\nTimeout=15\nMaxRedirects=5\nRespectRobots=enabled\n\n[BrowserSettings]\nRunnerHosts[]\nDefaultEngine=chromium\n*/ ?>\n";
$right = "<?php /* #?ini charset=\"utf-8\"?\n\n[WebSettings]\nNetwork=enabled\nTimeout=20\nMaxRedirects=5\nRespectRobots=enabled\nCacheTTL=600\n\n[BrowserSettings]\nRunnerHosts[]=node1.example.org\nDefaultEngine=firefox\n*/ ?>\n";
$options = array( 'granularity' => 'line', 'algorithm' => 'myers', 'context' => 3, 'ignore_whitespace' => false );
$error = null;
if ( $Module->isCurrentAction( 'Compare' ) )
{
    $left = str_replace( "\r\n", "\n", (string)$http->postVariable( 'TronDiffLeft', '' ) );
    $right = str_replace( "\r\n", "\n", (string)$http->postVariable( 'TronDiffRight', '' ) );
    $g = (string)$http->postVariable( 'TronDiffGranularity', 'line' );
    $a = (string)$http->postVariable( 'TronDiffAlgorithm', 'myers' );
    $options = array( 'granularity' => in_array( $g, array( 'line', 'word', 'char' ), true ) ? $g : 'line',
                      'algorithm' => in_array( $a, array( 'myers', 'patience' ), true ) ? $a : 'myers',
                      'context' => max( 0, min( 100, (int)$http->postVariable( 'TronDiffContext', 3 ) ) ),
                      'ignore_whitespace' => $http->hasPostVariable( 'TronDiffIgnoreWhitespace' ) );
}
$data = null;
try
{
    if ( strlen( $left ) + strlen( $right ) > 2 * tronDiffProgram::MAX_TEXT )
        throw new tronToolError( 'The texts are too long to compare here' );
    $data = tronDiffProgram::text( array( 'left' => $left, 'right' => $right, 'format' => $options['granularity'] === 'line' ? 'side_by_side' : 'html' ) + $options );
    if ( isset( $data['rows'] ) )
        foreach ( $data['rows'] as $i => $row )
        {
            // the part prints these as they are: escaped here, with the changed words of a changed pair marked
            list( $l, $r ) = $row['op'] === 'change' ? tronDiffRender::pairHtml( (string)$row['left'], (string)$row['right'] )
                                                     : array( tronDiffRender::e( $row['left'] ), tronDiffRender::e( $row['right'] ) );
            $data['rows'][$i]['left_html'] = $l;
            $data['rows'][$i]['right_html'] = $r;
        }
}
catch ( tronToolError $e )
{
    $error = $e->getMessage();
}

$tpl = eZTemplate::factory();
$tpl->setVariable( 'left', $left );
$tpl->setVariable( 'right', $right );
$tpl->setVariable( 'options', $options );
$tpl->setVariable( 'diff', $data );
$tpl->setVariable( 'error', $error );
$tpl->setVariable( 'view', 'diff' );
$Result = tronAdmin::result( 'diff', $tpl->fetch( 'design:tron/diff.tpl' ), ezpI18n::tr( 'extension/tron', 'Diff' ) );
