<?php
/**
 * Tests for Google Fonts localization.
 *
 * @package SH\SpeedOptimizer\Tests
 */

namespace SH\SpeedOptimizer\Tests\Fonts;

use PHPUnit\Framework\TestCase;
use SH\SpeedOptimizer\Assets\Fonts\FontLocalizer;
use SH\SpeedOptimizer\Assets\Fonts\LocalFontsRewriter;
use SH\SpeedOptimizer\Assets\HtmlDocument;
use SH\SpeedOptimizer\Core\Filesystem;

final class FontLocalizerTest extends TestCase {

	private const CSS_URL = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap';

	protected function setUp(): void {
		shso_test_reset();
		( new Filesystem() )->delete_tree( Filesystem::cache_root() . 'fonts' );
	}

	private static function css(): string {
		return "/* latin */\n@font-face {\n  font-family: 'Inter';\n  font-style: normal;\n  font-weight: 400;\n  font-display: swap;\n  src: url(https://fonts.gstatic.com/s/inter/v13/UcC73FwrK3iLTeHuS_fvQtMwCp50KnMa1ZL7.woff2) format('woff2');\n}\n"
			. "@font-face {\n  font-family: 'Inter';\n  font-weight: 700;\n  src: url(https://fonts.gstatic.com/s/inter/v13/UcC73FwrK3iLTeHuS_fvQtMwCp50KnMa2JL7.woff2) format('woff2');\n}\n";
	}

	private static function http( array $responses, array &$calls ): callable {
		return static function ( string $url, array $args ) use ( $responses, &$calls ) {
			$calls[] = array( $url, $args['user-agent'] ?? '' );
			return $responses[ $url ] ?? array(
				'status' => 404,
				'body'   => '',
				'type'   => '',
				'error'  => '',
			);
		};
	}

	public function test_downloads_validates_and_rewrites(): void {
		$calls     = array();
		$responses = array(
			self::CSS_URL => array(
				'status' => 200,
				'body'   => self::css(),
				'type'   => 'text/css; charset=utf-8',
			),
			'https://fonts.gstatic.com/s/inter/v13/UcC73FwrK3iLTeHuS_fvQtMwCp50KnMa1ZL7.woff2' => array(
				'status' => 200,
				'body'   => 'wOF2' . str_repeat( 'a', 100 ),
			),
			'https://fonts.gstatic.com/s/inter/v13/UcC73FwrK3iLTeHuS_fvQtMwCp50KnMa2JL7.woff2' => array(
				'status' => 200,
				'body'   => 'wOF2' . str_repeat( 'b', 100 ),
			),
		);

		$this->assertSame( 1, FontLocalizer::remember( array( self::CSS_URL, 'https://example.test/not-google.css' ) ) );
		$this->assertSame( 0, FontLocalizer::remember( array( 'http://fonts.googleapis.com/css2?family=Inter:wght@400;700' ) ), 'Same stylesheet without display is not stored twice.' );

		$localizer = new FontLocalizer( new Filesystem(), self::http( $responses, $calls ) );
		$this->assertSame( 0, $localizer->run() );

		$entry = FontLocalizer::mapping()[ FontLocalizer::id( self::CSS_URL ) ];
		$this->assertSame( FontLocalizer::STATUS_OK, $entry['status'] );
		$this->assertSame( 2, $entry['files'] );
		$this->assertStringContainsString( 'Chrome/', $calls[0][1], 'A modern browser User-Agent is sent to get WOFF2.' );

		$css = file_get_contents( Filesystem::cache_root() . 'fonts/' . $entry['css'] );
		$this->assertStringNotContainsString( 'fonts.gstatic.com', $css );
		$this->assertStringContainsString( 'url(inter-v13-UcC73FwrK3iLTeHuS_fvQtMwCp50KnMa1ZL7.woff2)', $css );
		$this->assertSame( 2, substr_count( $css, 'font-display' ), 'Missing font-display is added (display=swap semantics kept).' );
		$this->assertFileExists( Filesystem::cache_root() . 'fonts/' . dirname( $entry['css'] ) . '/inter-v13-UcC73FwrK3iLTeHuS_fvQtMwCp50KnMa2JL7.woff2' );

		// The HTML now points to the local copy and unused hints disappear.
		$doc = new HtmlDocument(
			'<!DOCTYPE html><html><head><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="dns-prefetch" href="//fonts.googleapis.com">'
			. '<link rel="stylesheet" id="inter-css" href="' . htmlspecialchars( 'https://fonts.googleapis.com/css2?family=Inter:wght@400;700', ENT_QUOTES ) . '" crossorigin></head><body><p>x</p></body></html>'
		);
		$rewriter = new LocalFontsRewriter( FontLocalizer::mapping(), 'https://example.test/wp-content/cache/sh-speed-optimizer/fonts/', static fn( string $rel ) => true );
		$this->assertSame( 1, $rewriter->transform( $doc ) );
		$html = $doc->html();
		$this->assertStringContainsString( '<link rel="stylesheet" id="inter-css" href="https://example.test/wp-content/cache/sh-speed-optimizer/fonts/' . $entry['css'] . '?ver=', $html );
		$this->assertStringNotContainsString( 'fonts.gstatic.com', $html );
		$this->assertStringNotContainsString( 'fonts.googleapis.com', $html );
	}

	public function test_failures_keep_original_links(): void {
		$calls = array();
		FontLocalizer::remember( array( self::CSS_URL ) );

		// Stylesheet referencing a file outside fonts.gstatic.com.
		$bad       = array(
			self::CSS_URL => array(
				'status' => 200,
				'body'   => '@font-face{font-family:X;src:url(https://evil.test/x.woff2)}',
				'type'   => 'text/css',
			),
		);
		$localizer = new FontLocalizer( new Filesystem(), self::http( $bad, $calls ) );
		$localizer->run();

		$entry = FontLocalizer::mapping()[ FontLocalizer::id( self::CSS_URL ) ];
		$this->assertSame( FontLocalizer::STATUS_FAILED, $entry['status'] );
		$this->assertSame( 1, $entry['attempts'] );
		$this->assertCount( 1, $calls, 'No font file was downloaded.' );
		$this->assertSame( array(), FontLocalizer::due( FontLocalizer::mapping(), time() ), 'Retries wait.' );

		$doc      = new HtmlDocument( '<!DOCTYPE html><html><head><link rel="stylesheet" href="' . htmlspecialchars( self::CSS_URL, ENT_QUOTES ) . '"></head><body></body></html>' );
		$before   = $doc->html();
		$rewriter = new LocalFontsRewriter( FontLocalizer::mapping(), 'https://example.test/fonts/', static fn( string $rel ) => true );
		$this->assertSame( 0, $rewriter->transform( $doc ) );
		$this->assertSame( $before, $doc->html() );
	}

	public function test_pure_helpers(): void {
		$this->assertSame( 'woff2', FontLocalizer::font_type( 'wOF2xxxx' ) );
		$this->assertSame( 'woff', FontLocalizer::font_type( 'wOFFxxxx' ) );
		$this->assertSame( 'ttf', FontLocalizer::font_type( "\x00\x01\x00\x00" ) );
		$this->assertSame( '', FontLocalizer::font_type( '<?php echo 1;' ) );

		$this->assertTrue( FontLocalizer::is_allowed_font_url( 'https://fonts.gstatic.com/s/roboto/v30/a.woff2' ) );
		$this->assertTrue( FontLocalizer::is_allowed_font_url( '//fonts.gstatic.com/l/font?kit=abc&skey=1' ) );
		$this->assertFalse( FontLocalizer::is_allowed_font_url( 'http://fonts.gstatic.com/s/a.woff2' ) );
		$this->assertFalse( FontLocalizer::is_allowed_font_url( 'https://fonts.gstatic.com.evil.test/a.woff2' ) );
		$this->assertFalse( FontLocalizer::is_allowed_font_url( 'https://fonts.gstatic.com/../x.woff2' ) );

		$this->assertSame( 'roboto-v30-KFOm.woff2', FontLocalizer::local_name( 'https://fonts.gstatic.com/s/roboto/v30/KFOm.woff2', 'woff2' ) );
		$this->assertMatchesRegularExpression( '/^l-font-[a-f0-9]{10}\.woff2$/', FontLocalizer::local_name( 'https://fonts.gstatic.com/l/font?kit=abc', 'woff2' ) );
		$this->assertStringNotContainsString( '/', FontLocalizer::local_name( 'https://fonts.gstatic.com/s/../../evil.php', 'php' ) );

		$this->assertSame( array( 'a.woff2', 'b.woff' ), FontLocalizer::extract_urls( "/* url(c.woff2) */src:url('a.woff2'),url(b.woff),url(\"a.woff2\")" ) );
		$this->assertSame( 'src:url(x.woff2),url(keep.woff)', FontLocalizer::rewrite_css( "src:url('a.woff2'),url(keep.woff)", array( 'a.woff2' => 'x.woff2' ) ) );
	}
}
