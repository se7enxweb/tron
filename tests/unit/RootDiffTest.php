<?php
/**
 * The diff program: Myers and patience produce valid, minimal-enough edit scripts (property check over random
 * inputs: applying the script to the left gives the right, Myers is minimal against an LCS), renderers, structured
 * diffs, the three-way merge, and the tools through the server.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

class RootDiffTest extends TronTestCase
{
    private static function apply( array $a, array $b, array $ops ): array
    {
        $out = array();
        $ia = 0;
        foreach ( $ops as $o )
        {
            if ( $o[0] === '=' )
            {
                if ( $o[1] !== $ia || $a[$o[1]] !== $b[$o[2]] )
                    throw new RuntimeException( 'bad equal op' );
                $out[] = $a[$ia++];
            }
            else if ( $o[0] === '-' )
            {
                if ( $o[1] !== $ia )
                    throw new RuntimeException( 'bad delete op' );
                $ia++;
            }
            else
                $out[] = $b[$o[2]];
        }
        if ( $ia !== count( $a ) )
            throw new RuntimeException( 'left not consumed' );
        return $out;
    }

    private static function lcs( array $a, array $b ): int
    {
        $prev = array_fill( 0, count( $b ) + 1, 0 );
        foreach ( $a as $x )
        {
            $cur = array( 0 );
            foreach ( $b as $j => $y )
                $cur[$j + 1] = $x === $y ? $prev[$j] + 1 : max( $prev[$j + 1], $cur[$j] );
            $prev = $cur;
        }
        return $prev[count( $b )];
    }

    public function testMyersAndPatienceAreValidOnRandomInputs(): void
    {
        mt_srand( 4711 );
        for ( $round = 0; $round < 300; $round++ )
        {
            $a = array();
            $b = array();
            $na = mt_rand( 0, 25 );
            $nb = mt_rand( 0, 25 );
            for ( $i = 0; $i < $na; $i++ )
                $a[] = chr( 97 + mt_rand( 0, 5 ) );
            for ( $i = 0; $i < $nb; $i++ )
                $b[] = chr( 97 + mt_rand( 0, 5 ) );
            $m = tronDiffEngine::diff( $a, $b, 'myers' );
            $this->assertSame( $b, self::apply( $a, $b, $m ), "myers round $round" );
            $s = tronDiffEngine::summary( $m );
            $this->assertSame( self::lcs( $a, $b ), $s['unchanged'], "myers is minimal, round $round" );
            $p = tronDiffEngine::diff( $a, $b, 'patience' );
            $this->assertSame( $b, self::apply( $a, $b, $p ), "patience round $round" );
        }
    }

    public function testUnifiedOutputMatchesTheUsualForm(): void
    {
        $r = tronDiffProgram::text( array( 'left' => "a\nb\nc\nd\n", 'right' => "a\nB\nc\nd\ne\n" ) );
        $this->assertSame( "--- a\n+++ b\n@@ -1,4 +1,5 @@\n a\n-b\n+B\n c\n d\n+e\n", $r['rendered'] );
        $this->assertSame( array( 'added' => 2, 'removed' => 1, 'unchanged' => 3 ), $r['summary'] );
        $this->assertFalse( $r['identical'] );
        $same = tronDiffProgram::text( array( 'left' => "x\n", 'right' => "x\n" ) );
        $this->assertTrue( $same['identical'] );
        $this->assertSame( '', $same['rendered'] );
        $eol = tronDiffProgram::text( array( 'left' => "x", 'right' => "y" ) );
        $this->assertContains( "\\ No newline at end of file", $eol['rendered'] );
    }

    public function testPatienceKeepsFunctionsTogether(): void
    {
        $l = "int a()\n{\n  return 1;\n}\n\nint b()\n{\n  return 2;\n}\n";
        $r = "int b()\n{\n  return 2;\n}\n\nint a()\n{\n  return 1;\n}\n";
        $p = tronDiffProgram::text( array( 'left' => $l, 'right' => $r, 'algorithm' => 'patience', 'format' => 'ops' ) );
        $this->assertSame( 'patience', $p['algorithm'] );
        $this->assertTrue( $p['summary']['unchanged'] >= 4 );
    }

    public function testWordCharAnsiSideBySideAndHtml(): void
    {
        $w = tronDiffProgram::text( array( 'left' => 'the quick fox', 'right' => 'the slow fox', 'granularity' => 'word' ) );
        $this->assertSame( 'the [-quick-]{+slow+} fox', $w['rendered'] );
        $c = tronDiffProgram::text( array( 'left' => 'kitten', 'right' => 'sitting', 'granularity' => 'char' ) );
        $this->assertSame( array( 'added' => 3, 'removed' => 2, 'unchanged' => 4 ), $c['summary'] );
        $ansi = tronDiffProgram::text( array( 'left' => "a\nb\n", 'right' => "a\nc\n", 'format' => 'ansi' ) );
        $this->assertContains( "\033[31m-b\033[0m", $ansi['rendered'] );
        $this->assertContains( "\033[32m+c\033[0m", $ansi['rendered'] );
        $s = tronDiffProgram::text( array( 'left' => "a\nb\n", 'right' => "a\nc\nd\n", 'format' => 'side_by_side' ) );
        $this->assertSame( array( 'same', 'change', 'add' ), array_column( $s['rows'], 'op' ) );
        $h = tronDiffProgram::text( array( 'left' => "a <b>\nold value\n", 'right' => "a <b>\nnew value\n", 'format' => 'html' ) );
        $this->assertContains( 'class="a4-table a4-tron-diff"', $h['rendered'] );
        $this->assertContains( '&lt;b&gt;', $h['rendered'] );
        $this->assertContains( '<del>old</del>', $h['rendered'] );
        $this->assertContains( '<ins>new</ins>', $h['rendered'] );
        $this->assertNotContains( 'style=', $h['rendered'] );
    }

    public function testStructuredDiffs(): void
    {
        $c = tronDiffStructured::compare( array( 'a' => 1, 'list' => array( array( 'id' => 1, 'v' => 'x' ), array( 'id' => 2, 'v' => 'y' ) ) ),
                                          array( 'a' => 2, 'b' => true, 'list' => array( array( 'id' => 0, 'v' => 'n' ), array( 'id' => 1, 'v' => 'x' ), array( 'id' => 2, 'v' => 'z' ) ) ) );
        $paths = array_map( function ( $x ) { return $x['op'] . ' ' . $x['path']; }, $c );
        sort( $paths );
        $this->assertSame( array( 'add $.b', 'add $.list[id=0]', 'change $.a', 'change $.list[id=2].v' ), $paths );
        $ini = tronDiffStructured::ini( tronIniParser::parse( "[A]\nX=1\nL[]=a\n[B]\nY=2\n" )['blocks'], tronIniParser::parse( "[A]\nX=2\nL[]=a\nL[]=b\n" )['blocks'] );
        $this->assertSame( array( 'change [A] X', 'change [A] L', 'remove [B]' ), array_map( function ( $x ) { return $x['op'] . ' ' . $x['path']; }, $ini ) );
        $errors = array();
        $x = tronDiffStructured::flat( tronDiffStructured::xmlFlat( tronWebExtract::xml( '<r><i a="1">t</i><i/></r>', $errors ) ),
                                       tronDiffStructured::xmlFlat( tronWebExtract::xml( '<r><i a="2">t</i><i>u</i></r>', $errors ) ) );
        $this->assertSame( array( 'change /r/i[1]/@a', 'add /r/i[2]/text()' ), array_map( function ( $x ) { return $x['op'] . ' ' . $x['path']; }, $x ) );
    }

    public function testThreeWayMerge(): void
    {
        $base = "a\nb\nc\nd\n";
        $clean = tronDiffMerge::merge( $base, "A\nb\nc\nd\n", "a\nb\nc\nD\n" );
        $this->assertTrue( $clean['clean'] );
        $this->assertSame( "A\nb\nc\nD\n", $clean['merged'] );
        $same = tronDiffMerge::merge( $base, "a\nX\nc\nd\n", "a\nX\nc\nd\n" );
        $this->assertSame( "a\nX\nc\nd\n", $same['merged'] );
        $conflict = tronDiffMerge::merge( $base, "a\nL\nc\nd\n", "a\nR\nc\nd\n" );
        $this->assertFalse( $conflict['clean'] );
        $this->assertSame( "a\n<<<<<<< left\nL\n||||||| base\nb\n=======\nR\n>>>>>>> right\nc\nd\n", $conflict['merged'] );
        $this->assertSame( 2, $conflict['conflicts'][0]['base_line'] );
        $ins = tronDiffMerge::merge( $base, "top\na\nb\nc\nd\n", "a\nb\nc\nd\nend\n" );
        $this->assertSame( "top\na\nb\nc\nd\nend\n", $ins['merged'] );
    }

    public function testToolsThroughTheServer(): void
    {
        $w = TronFixture::build( array( 'settings' => array( 'RootProgramSettings' => array( 'Enabled' => 'enabled' ) ) ) );
        $admin = TronFixture::session( TronFixture::ADMIN );
        $d = TronFixture::data( TronFixture::call( $w['server'], $admin, 'diff_json', array( 'left' => '{"a":1}', 'right' => '{"a":2}' ) ) );
        $this->assertSame( array( 'added' => 0, 'removed' => 0, 'changed' => 1 ), $d['summary'] );
        $bad = TronFixture::call( $w['server'], $admin, 'diff_json', array( 'left' => '{', 'right' => '{}' ) );
        $this->assertTrue( $bad['result']['isError'] ?? false );
        $y = TronFixture::data( TronFixture::call( $w['server'], $admin, 'diff_yaml', array( 'left' => "a: 1\nb: [x, y]\n", 'right' => "a: 1\nb: [x, z]\n" ) ) );
        $this->assertSame( '$.b[1]', $y['changes'][0]['path'] );
        $denied = TronFixture::call( $w['server'], TronFixture::session( TronFixture::USEONLY ), 'diff_text', array( 'left' => 'a', 'right' => 'b' ) );
        $this->assertContains( 'Permission denied', json_encode( $denied ) );
    }
}
