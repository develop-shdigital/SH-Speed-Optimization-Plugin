<?php
/**
 * Tests for the conservative JavaScript minifier.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Assets;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Assets\Minify\JsMinifier;

final class MinifyJsTest extends TestCase {

	/**
	 * Input => expected output.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function corpus(): array {
		return array(
			'basic'                        => array( "var a = 1;\nvar b = a + 2;\n", "var a=1;\nvar b=a+2;\n" ),
			'indentation and blank lines'  => array( "function f( x ) {\n\n\n    return x * 2;\n}\n", "function f(x){\nreturn x*2;\n}\n" ),
			'line comment'                 => array( "a(); // call a\nb();", "a();\nb();" ),
			'block comment'                => array( "a(/* inline */1, 2);", 'a(1,2);' ),
			'multiline comment is newline' => array( "return /*\n*/ x", "return\nx" ),
			'license comment kept'         => array( "/*! MIT License */\nvar a = 1;", "/*! MIT License */\nvar a=1;" ),
			'preserve comment kept'        => array( "/** @preserve keep */\nvar a;", "/** @preserve keep */\nvar a;" ),
			'license line comment kept'    => array( "// @license GPL\nvar a;", "// @license GPL\nvar a;" ),
			'cc_on kept'                   => array( "var ie = /*@cc_on!@*/false;", 'var ie= /*@cc_on!@*/false;' ),
			'plus plus'                    => array( 'x = a + +b; y = a++ + b; z = a + ++b;', 'x=a+ +b;y=a++ +b;z=a+ ++b;' ),
			'minus minus'                  => array( 'x = a - -b; y = a-- - b; z = a - --b;', 'x=a- -b;y=a-- -b;z=a- --b;' ),
			'return newline asi'           => array( "function f() {\n  return\n  x;\n}", "function f(){\nreturn\nx;\n}" ),
			'newline increment asi'        => array( "a\n++b", "a\n++b" ),
			'newline paren kept'           => array( "var a = b\n(function () {})()", "var a=b\n(function(){})()" ),
			'regex after return'           => array( 'function f(s) { return /\/\*[a b]+ /g.test(s); }', 'function f(s){return /\/\*[a b]+ /g.test(s);}' ),
			'regex after paren of if'      => array( 'if (x) /a  b/.test(y) && z();', 'if(x)/a  b/.test(y)&&z();' ),
			'division chain'               => array( 'a = b / c / d;', 'a=b/c/d;' ),
			'division after paren'         => array( 'x = (a + b) / 2 / c;', 'x=(a+b)/2/c;' ),
			'division after bracket'       => array( 'x = a[0] / b[1];', 'x=a[0]/b[1];' ),
			'regex class with slash'       => array( 'var r = /[/]/g , s = 1;', 'var r=/[/]/g,s=1;' ),
			'regex with escaped slash'     => array( "var r = /a\\/b  c/;", "var r=/a\\/b  c/;" ),
			'regex then division space'    => array( 'x = a / /re/.exec(b).length;', 'x=a/ /re/.exec(b).length;' ),
			'regex flags then keyword'     => array( 'x = /re/ in y;', 'x=/re/ in y;' ),
			'regex after keyword'          => array( 'x = typeof /re/;', 'x=typeof /re/;' ),
			'template literal with //'     => array( 'var u = `https://example.test/  ${ a }  //x`;', 'var u=`https://example.test/  ${ a }  //x`;' ),
			'nested template'              => array( 'var t = `a ${ `b ${ c + `d` } e` } f`; g();', 'var t=`a ${ `b ${ c + `d` } e` } f`;g();' ),
			'template with braces in expr' => array( 'var t = `x ${ { a: 1 }.a } y ${ "}" } z`;', 'var t=`x ${ { a: 1 }.a } y ${ "}" } z`;' ),
			'template with regex in expr'  => array( 'var t = `${ s.replace( /}/g, "" ) }`;', 'var t=`${ s.replace( /}/g, "" ) }`;' ),
			'string containing comment'    => array( 'var s = "/* not a comment */" + \'// nor this\';', 'var s="/* not a comment */"+\'// nor this\';' ),
			'string with escapes'          => array( 'var s = "a \\" b" , t = \'c \\\' d\';', 'var s="a \\" b",t=\'c \\\' d\';' ),
			'string line continuation'     => array( "var s = 'a\\\nb';", "var s='a\\\nb';" ),
			'number member'                => array( 'x = 1 .toString();', 'x=1 .toString();' ),
			'optional chaining kept'       => array( 'x = a ? .5 : b?.c;', 'x=a? .5:b?.c;' ),
			'keywords spacing'             => array( 'if ( a instanceof B ) { return typeof c === "x"; } else { delete d[ e ]; }', 'if(a instanceof B){return typeof c==="x";}else{delete d[e];}' ),
			'arrow and spread'             => array( 'const f = ( ...args ) => ( { ...args } );', 'const f=(...args)=>({...args});' ),
			'tabs'                         => array( "\tif\t(a)\t{\tb();\t}", 'if(a){b();}' ),
			'hashbang'                     => array( "#!/usr/bin/env node\nvar a = 1;", "#!/usr/bin/env node\nvar a=1;" ),
			'private field'                => array( 'class A { static #x = 1; m() { return this.#x in o; } }', 'class A{static #x=1;m(){return this.#x in o;}}' ),
			'less than not'                => array( 'x = a < !b;', 'x=a< !b;' ),
			'decrement greater'            => array( "x = a --\n> b;", "x=a--\n>b;" ),
			'crlf'                         => array( "var a = 1;\r\nvar b = 2;\r\n", "var a=1;\nvar b=2;\n" ),
			'unicode identifiers'          => array( 'var café = "ü"; café += 1;', 'var café="ü";café+=1;' ),
			'regex after brace statement'  => array( "function a() {}\n/x y/.test(z);", "function a(){}\n/x y/.test(z);" ),
			'postfix then division'        => array( 'x = i++ / 2;', 'x=i++/2;' ),
			'object after return'          => array( "return {\n  a: 1,\n  b: [ 1, 2 ]\n};", "return{\na:1,\nb:[1,2]\n};" ),
			'jquery ready'                 => array( "jQuery( function ( $ ) {\n\t$( '.a' ).on( 'click', function () { return false; } );\n} );", "jQuery(function($){\n$('.a').on('click',function(){return false;});\n});" ),
			'exponent'                     => array( 'x = 1e-5 / 2 + 2 ** 3;', 'x=1e-5/2+2**3;' ),
			'for of regex'                 => array( 'for (const a of b) /x/.test(a);', 'for(const a of b)/x/.test(a);' ),
		);
	}

	#[DataProvider( 'corpus' )]
	public function test_corpus( string $input, string $expected ): void {
		$this->assertSame( $expected, JsMinifier::minify( $input ) );
	}

	#[DataProvider( 'corpus' )]
	public function test_corpus_is_idempotent( string $input ): void {
		$once = JsMinifier::minify( $input );
		$this->assertSame( $once, JsMinifier::minify( $once ) );
	}

	/**
	 * Inputs that must come back unchanged.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function unchanged(): array {
		return array(
			'unterminated string'      => array( "var a = 'open;\nvar b = 1;" ),
			'unterminated comment'     => array( "var a = 1; /* open\n" ),
			'unterminated template'    => array( 'var a = `open ${ b }' ),
			'unterminated substitution' => array( 'var a = `open ${ b ;' ),
			'unterminated regex'       => array( "var a = /open\nvar b;" ),
			'html comment open'        => array( "var a = 1;\n<!-- hidden\nvar b = 2;" ),
			'html comment close'       => array( "var a = 1;\n--> hidden\nvar b = 2;" ),
			'goes to operator'         => array( "while ( x --> 0 ) {\n  y();\n}" ),
			'raw newline in string'    => array( "var a = \"x\ny\";" ),
			'already minified'         => array( str_repeat( 'var a=function(b){return b+1};', 40 ) ),
			'empty'                    => array( '' ),
		);
	}

	#[DataProvider( 'unchanged' )]
	public function test_unchanged_on_failure( string $input ): void {
		$this->assertSame( $input, JsMinifier::minify( $input ) );
	}

	public function test_real_world_snippet_keeps_semantics_markers(): void {
		$js = <<<'JS'
/**
 * Plugin script.
 */
( function ( $, window, undefined ) {
	'use strict';

	var pattern = /^\s*\/\/(.*)$/gm; // Regex with // inside.
	var url     = "https://example.test/path?a=1&b=2"; // Not a comment.
	var html    = `<div class="${ cls }">
		${ items.map( ( item ) => `<span>${ item }</span>` ).join( '' ) }
	</div>`;

	function ratio( a, b ) {
		return a / b / 2;
	}

	$( document ).on( 'click', '.x', function ( e ) {
		e.preventDefault();
		var n = +this.dataset.n + -1;
		return n
	} );
}( jQuery, window ) );
JS;
		$min = JsMinifier::minify( $js );

		$this->assertStringContainsString( 'var pattern=/^\s*\/\/(.*)$/gm;', $min );
		$this->assertStringContainsString( 'var url="https://example.test/path?a=1&b=2";', $min );
		$this->assertStringContainsString( "var html=`<div class=\"\${ cls }\">\n\t\t\${ items.map( ( item ) => `<span>\${ item }</span>` ).join( '' ) }\n\t</div>`;", $min, 'Template literal content is byte exact.' );
		$this->assertStringContainsString( 'return a/b/2;', $min );
		$this->assertStringContainsString( 'var n=+this.dataset.n+-1;', $min );
		$this->assertStringContainsString( "return n\n});", $min, 'Newline before the closing brace (ASI) is kept.' );
		$this->assertStringNotContainsString( 'Plugin script', $min );
		$this->assertStringNotContainsString( 'Not a comment', $min );
		$this->assertLessThan( strlen( $js ), strlen( $min ) );
		$this->assertSame( $min, JsMinifier::minify( $min ) );
	}

	public function test_is_minified(): void {
		$this->assertFalse( JsMinifier::is_minified( "var a = 1;\nvar b = 2;\n" ) );
		$this->assertTrue( JsMinifier::is_minified( str_repeat( 'a();', 200 ) ) );
	}

	public function test_output_never_merges_tokens_on_large_generated_input(): void {
		$js = '';
		for ( $i = 0; $i < 300; $i++ ) {
			$js .= "var v{$i} = a{$i} + +b{$i} - -c{$i} / d{$i} / /r{$i}/.source.length;\n";
		}
		$min = JsMinifier::minify( $js );
		$this->assertStringContainsString( 'var v7=a7+ +b7- -c7/d7/ /r7/.source.length;', $min );
		$this->assertSame( $min, JsMinifier::minify( $min ) );
	}
}
