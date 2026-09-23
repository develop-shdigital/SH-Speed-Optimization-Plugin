<?php
/**
 * Tests for the CSS minifier and url() rewriting.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Assets;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Assets\Minify\CssMinifier;

final class MinifyCssTest extends TestCase {

	/**
	 * Input => expected output.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function corpus(): array {
		return array(
			'basic rule'                  => array( "a {\n  color : red ;\n  margin: 0 auto;\n}\n", 'a{color:red;margin:0 auto}' ),
			'comments removed'            => array( "/* header */\na{color:red}/* x */ b{c:d}", 'a{color:red}b{c:d}' ),
			'license kept'                => array( "/*! License MIT */\na { b: c }", '/*! License MIT */a{b:c}' ),
			'license keyword kept'        => array( "/* @license GPL */ a{b:c}", '/* @license GPL */a{b:c}' ),
			'descendant space kept'       => array( '.a  .b   .c { x: y }', '.a .b .c{x:y}' ),
			'space before colon kept'     => array( 'a :hover { x: y } a:hover{x:y}', 'a :hover{x:y}a:hover{x:y}' ),
			'combinators'                 => array( 'ul > li + li ~ p , div { x: y }', 'ul>li+li~p,div{x:y}' ),
			'nth child'                   => array( 'li:nth-child( 2n + 1 ) { x: y }', 'li:nth-child(2n+1){x:y}' ),
			'nth child minus kept'        => array( 'li:nth-child(2n - 1){x:y}', 'li:nth-child(2n - 1){x:y}' ),
			'attribute brackets'          => array( 'a[ href $= ".pdf" ] { x: y }', 'a[ href $= ".pdf" ]{x:y}' ),
			'strings byte exact'          => array( 'a::after { content: "  /* not a comment */  " }', 'a::after{content:"  /* not a comment */  "}' ),
			'single quoted string'        => array( "a{content:'a;b}c'}", "a{content:'a;b}c'}" ),
			'escaped quote in string'     => array( 'a{content:"say \\"hi\\" { }"}', 'a{content:"say \\"hi\\" { }"}' ),
			'url unquoted padding'        => array( 'a { background: url( img/a.png ) no-repeat }', 'a{background:url(img/a.png) no-repeat}' ),
			'url unquoted exact'          => array( 'a{background:url(img/a\\(1\\).png)}', 'a{background:url(img/a\\(1\\).png)}' ),
			'url with double slash'       => array( 'a{background:url(//cdn.test/x.png)}', 'a{background:url(//cdn.test/x.png)}' ),
			'url data svg'                => array( "a{background:url(data:image/svg+xml;charset=utf8,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%3E%3C/svg%3E)}", "a{background:url(data:image/svg+xml;charset=utf8,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%3E%3C/svg%3E)}" ),
			'url quoted'                  => array( 'a { background : url( "a b.png" ) }', 'a{background:url("a b.png")}' ),
			'calc spaces kept'            => array( 'a { width: calc( 100% - ( 2 * 10px ) ); height: calc(1px + 2px) }', 'a{width:calc(100% - (2 * 10px));height:calc(1px + 2px)}' ),
			'clamp min max'               => array( 'a{font-size:clamp( 1rem , 2vw + 1rem , 3rem );w:min(10px + 1em, 5%)}', 'a{font-size:clamp(1rem,2vw + 1rem,3rem);w:min(10px + 1em,5%)}' ),
			'important'                   => array( 'a { color: red ! important; x: y !important }', 'a{color:red!important;x:y!important}' ),
			'media query'                 => array( "@media screen and ( max-width : 600px ) , print {\n a { x: y }\n}", '@media screen and (max-width :600px),print{a{x:y}}' ),
			'media and paren kept'        => array( '@media screen and (min-width:1px){a{x:y}}', '@media screen and (min-width:1px){a{x:y}}' ),
			'supports not paren'          => array( '@supports not (display: grid) { a { x: y } }', '@supports not (display:grid){a{x:y}}' ),
			'supports selector'           => array( '@supports selector(a :hover) { a { x: y } }', '@supports selector(a :hover){a{x:y}}' ),
			'container name paren'        => array( '@container sidebar (min-width: 400px) { a { x: y } }', '@container sidebar (min-width:400px){a{x:y}}' ),
			'layer statement'             => array( '@layer reset , base ;  @layer base { a { x: y } }', '@layer reset,base;@layer base{a{x:y}}' ),
			'charset exact'               => array( "@charset \"UTF-8\";\na{b:c}", '@charset "UTF-8";a{b:c}' ),
			'import'                      => array( "@import url( 'foo.css' ) screen ;\na{b:c}", "@import url('foo.css') screen;a{b:c}" ),
			'font face unicode range'     => array( "@font-face {\n font-family: 'X';\n src: url(x.woff2) format('woff2');\n unicode-range: U+0000-00FF, U+0131, U+0152-0153;\n}", "@font-face{font-family:'X';src:url(x.woff2) format('woff2');unicode-range:U+0000-00FF,U+0131,U+0152-0153}" ),
			'keyframes'                   => array( '@keyframes spin { from { transform: rotate( 0deg ) } 50% , 60% { opacity: .5 } to { transform: rotate(360deg) } }', '@keyframes spin{from{transform:rotate(0deg)}50%,60%{opacity:.5}to{transform:rotate(360deg)}}' ),
			'nesting'                     => array( ".card {\n  color: red;\n  & > .title { x: y }\n  p :hover { a: b }\n  &:hover { c: d; }\n}", '.card{color:red;&>.title{x:y}p :hover{a:b}&:hover{c:d}}' ),
			'custom properties'           => array( ':root { --gap :  1px   2px ; --list: a , b; --empty: ; }', ':root{--gap:1px 2px;--list:a , b;--empty: }' ),
			'hex escape keeps space'      => array( ".\\31 0 { x: y }\n.a\\ b{x:y}", '.\\31 0{x:y}.a\\ b{x:y}' ),
			'hex escape then combinator'  => array( '.\\31  .b{x:y}', '.\\31  .b{x:y}' ),
			'escaped colon'               => array( '.sm\\:flex { display: flex }', '.sm\\:flex{display:flex}' ),
			'comment between words'       => array( 'a{margin:1px/**/2px}', 'a{margin:1px/**/2px}' ),
			'comment between selectors'   => array( '.a/* x */.b{x:y}', '.a.b{x:y}' ),
			'filter progid'               => array( "a{filter:progid:DXImageTransform.Microsoft.gradient( startColorstr='#80000000', endColorstr='#80000000',GradientType=0 )}", "a{filter:progid:DXImageTransform.Microsoft.gradient(startColorstr='#80000000',endColorstr='#80000000',GradientType=0)}" ),
			'empty declarations'          => array( 'a{;color:red;;}', 'a{color:red}' ),
			'top level stray semicolon'   => array( ';a{x:y}', ';a{x:y}' ),
			'grid template areas'         => array( 'a{grid-template-areas: "a b"  "c d"}', 'a{grid-template-areas:"a b" "c d"}' ),
			'font shorthand slash'        => array( 'a{font: italic bold 12px / 30px Georgia , serif}', 'a{font:italic bold 12px / 30px Georgia,serif}' ),
			'negative values'             => array( 'a{margin:0 -1px -2px;transform:translate( -50% , -50% )}', 'a{margin:0 -1px -2px;transform:translate(-50%,-50%)}' ),
			'universal selector'          => array( 'a > * + * { x: y }', 'a>*+*{x:y}' ),
			'page rule'                   => array( '@page :first { margin: 1in }', '@page :first{margin:1in}' ),
			'has selector'                => array( 'a:has( > img ) { x: y }', 'a:has(>img){x:y}' ),
			'is selector list'            => array( ':is( h1 , h2 ) span { x: y }', ':is(h1,h2) span{x:y}' ),
			'crlf'                        => array( "a {\r\n  color: red;\r\n}\r\n", 'a{color:red}' ),
			'multiple rules and nesting'  => array( "@media (min-width: 1px) {\n  @supports (display:grid) {\n    a { b: c }\n  }\n}", '@media (min-width:1px){@supports (display:grid){a{b:c}}}' ),
			'range media'                 => array( '@media (400px <= width <= 700px) { a { b: c } }', '@media (400px <= width <= 700px){a{b:c}}' ),
			'uppercase url'               => array( 'a{background:URL( x.png )}', 'a{background:URL(x.png)}' ),
			'comment merging number'      => array( 'a{width:1/* x */.5em}', 'a{width:1/**/.5em}' ),
			'comment creating comment'    => array( 'a{b:x//* y */*}', 'a{b:x//**/*}' ),
			'image set'                   => array( 'a{background-image:image-set( "a.png" 1x , "b.png" 2x )}', 'a{background-image:image-set("a.png" 1x,"b.png" 2x)}' ),
			'var fallback'                => array( 'a{color:var( --x , rgba( 0 , 0 , 0 , .5 ) )}', 'a{color:var(--x,rgba(0,0,0,.5))}' ),
			'multibyte'                   => array( 'a::before{content:"✓"} .ü { x: y }', 'a::before{content:"✓"}.ü{x:y}' ),
			'last semicolon in nested'    => array( '@media print{a{b:c;}d{e:f;};}', '@media print{a{b:c}d{e:f}}' ),
			'cdo cdc'                     => array( "<!--\na{b:c}\n-->", '<!-- a{b:c}-->' ),
		);
	}

	#[DataProvider( 'corpus' )]
	public function test_corpus( string $input, string $expected ): void {
		$this->assertSame( $expected, CssMinifier::minify( $input ) );
	}

	#[DataProvider( 'corpus' )]
	public function test_corpus_is_idempotent( string $input ): void {
		$once = CssMinifier::minify( $input );
		$this->assertSame( $once, CssMinifier::minify( $once ) );
	}

	/**
	 * Inputs the minifier must return unchanged (cannot be minified safely).
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function invalid(): array {
		return array(
			'unterminated comment' => array( "a{b:c}\n/* open" ),
			'unterminated string'  => array( "a{content:\"open\n}" ),
			'unbalanced braces'    => array( 'a{b:c' ),
			'unbalanced parens'    => array( 'a{b:calc(1px}' ),
			'bad url'              => array( 'a{background:url(a"b.png)}' ),
		);
	}

	#[DataProvider( 'invalid' )]
	public function test_invalid_input_is_returned_unchanged( string $input ): void {
		$this->assertSame( $input, CssMinifier::minify( $input ) );
	}

	public function test_bom_is_preserved(): void {
		$this->assertSame( "\xEF\xBB\xBFa{b:c}", CssMinifier::minify( "\xEF\xBB\xBFa { b: c }" ) );
	}

	public function test_empty_input(): void {
		$this->assertSame( '', CssMinifier::minify( '' ) );
		$this->assertSame( "  \n", CssMinifier::minify( "  \n" ) );
	}

	public function test_is_minified(): void {
		$this->assertFalse( CssMinifier::is_minified( "a {\n  b: c;\n}\n" ) );
		$this->assertTrue( CssMinifier::is_minified( str_repeat( 'a{b:c}', 200 ) ) );
		$this->assertFalse( CssMinifier::is_minified( str_repeat( "a { b: c }\n", 200 ) ) );
	}

	public function test_large_realistic_stylesheet_is_idempotent_and_smaller(): void {
		$css = '';
		for ( $i = 0; $i < 200; $i++ ) {
			$css .= "/* block {$i} */\n.wp-block-{$i} > .inner ,\n.wp-block-{$i}:hover::after {\n\tcolor : #{$i}{$i}0 ;\n\tpadding: calc( {$i}px + 1em ) 0;\n\tbackground: url( ../img/{$i}.png ) no-repeat;\n}\n";
			$css .= "@media (max-width: {$i}px) {\n\t.x-{$i} { display: none !important; }\n}\n";
		}
		$min = CssMinifier::minify( $css );
		$this->assertLessThan( strlen( $css ) * 0.8, strlen( $min ) );
		$this->assertSame( $min, CssMinifier::minify( $min ) );
		$this->assertStringContainsString( '.wp-block-7>.inner,.wp-block-7:hover::after{color:#770;padding:calc(7px + 1em) 0;background:url(../img/7.png) no-repeat}', $min );
	}

	// ---------------------------------------------------------------------
	// url() rewriting.
	// ---------------------------------------------------------------------

	/**
	 * Reference => expected, relative to https://example.test/wp-content/plugins/foo/css/style.css?ver=1.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function references(): array {
		return array(
			'sibling'             => array( 'a.png', 'https://example.test/wp-content/plugins/foo/css/a.png' ),
			'parent'              => array( '../img/a.png', 'https://example.test/wp-content/plugins/foo/img/a.png' ),
			'dot'                 => array( './a.png', 'https://example.test/wp-content/plugins/foo/css/a.png' ),
			'too many parents'    => array( '../../../../../a.png', 'https://example.test/a.png' ),
			'query and fragment'  => array( '../fonts/x.eot?#iefix', 'https://example.test/wp-content/plugins/foo/fonts/x.eot?#iefix' ),
			'fragment svg'        => array( 'icons.svg#home', 'https://example.test/wp-content/plugins/foo/css/icons.svg#home' ),
			'data untouched'      => array( 'data:image/png;base64,AAA', 'data:image/png;base64,AAA' ),
			'hash untouched'      => array( '#gradient', '#gradient' ),
			'absolute untouched'  => array( 'https://cdn.test/a.png', 'https://cdn.test/a.png' ),
			'protocol relative'   => array( '//cdn.test/a.png', '//cdn.test/a.png' ),
			'root relative'       => array( '/wp-content/a.png', '/wp-content/a.png' ),
			'blob untouched'      => array( 'blob:abc', 'blob:abc' ),
		);
	}

	#[DataProvider( 'references' )]
	public function test_resolve_url( string $reference, string $expected ): void {
		$this->assertSame( $expected, CssMinifier::resolve_url( $reference, 'https://example.test/wp-content/plugins/foo/css/style.css?ver=1' ) );
	}

	public function test_resolve_url_root_relative(): void {
		$this->assertSame( '/wp-content/plugins/foo/img/a.png', CssMinifier::resolve_url( '../img/a.png', 'https://example.test/wp-content/plugins/foo/css/style.css', true ) );
		$this->assertSame( 'https://cdn.test/a.png', CssMinifier::resolve_url( 'https://cdn.test/a.png', 'https://example.test/x/style.css', true ) );
	}

	public function test_rewrite_urls_in_stylesheet(): void {
		$base = 'https://example.test/wp-content/themes/t/assets/css/main.css?ver=2';
		$css  = "@import 'reset.css';\n@import url(\"print.css\") print;\n"
			. ".a{background:url(../img/a.png)}\n"
			. ".b{background:url( 'b.png' )}\n"
			. ".c{background:url(\"data:image/svg+xml,%3Csvg%3E\")}\n"
			. ".d{background:url(#x)}\n"
			. ".e{background:url(/root.png)}\n"
			. ".f{background-image:image-set(\"f.png\" 1x, url(f2.png) 2x)}\n"
			. ".g{content:\"url(not-a-url.png)\"}\n"
			. "/* url(comment.png) */\n"
			. "@font-face{src:url(../fonts/x.woff2?v=1) format('woff2')}";

		$out = CssMinifier::rewrite_urls( $css, $base );

		$this->assertStringContainsString( "@import 'https://example.test/wp-content/themes/t/assets/css/reset.css';", $out );
		$this->assertStringContainsString( '@import url("https://example.test/wp-content/themes/t/assets/css/print.css") print;', $out );
		$this->assertStringContainsString( '.a{background:url(https://example.test/wp-content/themes/t/assets/img/a.png)}', $out );
		$this->assertStringContainsString( ".b{background:url( 'https://example.test/wp-content/themes/t/assets/css/b.png' )}", $out );
		$this->assertStringContainsString( '.c{background:url("data:image/svg+xml,%3Csvg%3E")}', $out );
		$this->assertStringContainsString( '.d{background:url(#x)}', $out );
		$this->assertStringContainsString( '.e{background:url(/root.png)}', $out );
		$this->assertStringContainsString( 'image-set("https://example.test/wp-content/themes/t/assets/css/f.png" 1x, url(https://example.test/wp-content/themes/t/assets/css/f2.png) 2x)', $out );
		$this->assertStringContainsString( '.g{content:"url(not-a-url.png)"}', $out, 'Strings that are not references stay untouched.' );
		$this->assertStringContainsString( '/* url(comment.png) */', $out, 'Comments stay untouched.' );
		$this->assertStringContainsString( "src:url(https://example.test/wp-content/themes/t/assets/fonts/x.woff2?v=1) format('woff2')", $out );
	}

	public function test_rewrite_urls_root_relative_mode(): void {
		$out = CssMinifier::rewrite_urls( '.a{background:url(../a.png)}', 'https://example.test/wp-content/plugins/p/css/s.css', true );
		$this->assertSame( '.a{background:url(/wp-content/plugins/p/a.png)}', $out );
	}

	public function test_rewrite_urls_fails_on_unparsable_css(): void {
		$this->assertNull( CssMinifier::rewrite_urls( ".a{background:url(a.png)}\n/* open", 'https://example.test/s.css' ) );
	}

	public function test_rewrite_urls_without_references_is_identity(): void {
		$css = '.a{color:red}';
		$this->assertSame( $css, CssMinifier::rewrite_urls( $css, 'https://example.test/s.css' ) );
	}

	public function test_rewrite_then_minify_keeps_urls(): void {
		$css = ".a {\n  background: url( ../img/a.png );\n}";
		$out = CssMinifier::minify( (string) CssMinifier::rewrite_urls( $css, 'https://example.test/wp-content/themes/t/css/s.css' ) );
		$this->assertSame( '.a{background:url(https://example.test/wp-content/themes/t/img/a.png)}', $out );
	}
}
