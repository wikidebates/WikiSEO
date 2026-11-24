<?php

declare( strict_types=1 );

/** The real built-in generators, with instrumented local repo/services. */
namespace {
	define( 'NS_FILE', 6 );

	class Config {
		public array $values = [
			'MetadataGenerators' => [ 'OpenGraph', 'Twitter', 'SchemaOrg' ],
			'Sitename' => 'SEO lab',
			'LanguageCode' => 'en',
			'Server' => 'https://example.test',
			'Logos' => [ '1x' => 'https://example.test/logo.png' ],
			'WikiSeoDefaultImage' => null,
			'WikiSeoDisableLogoFallbackImage' => false,
			'WikiSeoEnableSocialImages' => false,
			'WikiSeoSocialImageWidth' => 1200,
			'WikiSeoSocialImageHeight' => 620,
			'TwitterCardType' => 'summary_large_image',
		];

		public function get( string $key ) {
			return $this->values[$key] ?? null;
		}
	}

	class Title {
		public function __construct( public string $name ) {
		}
		public function getText(): string { return $this->name; }
		public function getPrefixedText(): string { return $this->name; }
		public function getFullURL(): string { return 'https://example.test/wiki/' . $this->name; }
		public function getLinkURL(): string { return '/wiki/' . $this->name; }
	}

	class WebRequest {
		public function getProtocol(): string { return 'https'; }
		public function getQueryValues(): array { return []; }
		public function getValues(): array { return []; }
	}

	class OutputPage {
		public array $meta = [];
		public array $head = [];
		public string $title = 'SEO lab page';
		public string $mode = 'full';
		public function getTitle(): Title { return new Title( 'Test' ); }
		public function getRequest(): WebRequest { return new WebRequest(); }
		public function isArticle(): bool { return true; }
		public function getHTMLTitle(): string { return $this->title; }
		public function getPageTitle(): string { return $this->title; }
		public function setHTMLTitle( string $title ): void { $this->title = $title; }
		public function addMeta( string $name, string $value ): void { $this->meta[] = [ $name, $value ]; }
		public function getMetaTags(): array { return $this->meta; }
		public function addHeadItem( string $name, string $markup ): void { $this->head[$name] = $markup; }
	}

	class Html {
		public static function element( string $name, array $attrs ): string {
			$html = '<' . $name;
			foreach ( $attrs as $key => $value ) {
				$html .= ' ' . $key . '="' . htmlspecialchars( (string)$value, ENT_QUOTES, 'UTF-8' ) . '"';
			}
			return $html . '>';
		}
	}

	class ExtensionRegistry {
		public static function getInstance(): self { return new self(); }
		public function isLoaded( string $name ): bool { return $name === 'PageImages'; }
	}

	class File {
		public static int $timestampCalls = 0;
		public static int $widthCalls = 0;
		public static int $heightCalls = 0;
		public function __construct( private string $name, private int $width ) {
		}
		public function getTimestamp(): string {
			self::$timestampCalls++;
			return '20261124100000';
		}
		public function getWidth(): int {
			self::$widthCalls++;
			return $this->width;
		}
		public function getHeight(): int {
			self::$heightCalls++;
			return 480;
		}
		public function getFullUrl(): string { return '//example.test/files/' . $this->name; }
		public function getMimeType(): string { return 'image/png'; }
	}
}

namespace PageImages {
	final class PageImages {
		public static int $calls = 0;
		public static bool $present = false;
		public static function getPageImage( \Title $title ) {
			self::$calls++;
			return self::$present ? 'Existing.png' : false;
		}
	}
}

namespace MediaWiki {
	final class MediaWikiServices {
		public \Config $config;
		public int $titles = 0;
		public int $finds = 0;
		public int $width = 640;
		public array $files = [ 'Shared.png', 'X.png', 'Default.png' ];

		private function __construct() { $this->config = new \Config(); }
		public static function getInstance(): self {
			static $instance;
			return $instance ??= new self();
		}
		public function getConfigFactory(): self { return $this; }
		public function makeConfig( string $name ): \Config { return $this->config; }
		public function getHookContainer(): self { return $this; }
		public function run( string $name, array $args ): bool {
			if ( $name === 'WikiSEOPageMetadataMode' ) {
				$args[1] = $args[0]->mode;
			}
			return true;
		}
		public function getTitleFactory(): self { return $this; }
		public function makeTitleSafe( int $ns, string $name ): ?\Title {
			$this->titles++;
			return str_contains( $name, ':' ) ? null : new \Title( $name );
		}
		public function getRepoGroup(): self { return $this; }
		public function findFile( \Title $title ) {
			$this->finds++;
			// Always produce a new object; the test distinguishes work that
			// was actually eliminated from internal MediaWiki caching.
			return in_array( $title->name, $this->files, true )
				? new \File( $title->name, $this->width ) : false;
		}
		public function getContentLanguage(): self { return $this; }
		public function getCode(): string { return 'en'; }
		public function getUrlUtils(): self { return $this; }
		public function expand( string $url ): string {
			return str_starts_with( $url, '/' ) ? 'https://example.test' . $url : $url;
		}
	}
}

namespace {
	use MediaWiki\Extension\WikiSEO\WikiSEO;
	use MediaWiki\MediaWikiServices;
	use PageImages\PageImages;

	$root = $argv[1] ?? dirname( __DIR__, 2 );
	$baseline = ( $argv[2] ?? '' ) === '--baseline';
	spl_autoload_register( static function ( string $class ) use ( $root ): void {
		$prefix = 'MediaWiki\\Extension\\WikiSEO\\';
		if ( str_starts_with( $class, $prefix ) ) {
			$path = $root . '/includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( is_file( $path ) ) {
				require $path;
			}
		}
	} );

}

namespace MediaWiki\Extension\WikiSEO\Generator\Plugins {
	class ImageMutationProbe implements \MediaWiki\Extension\WikiSEO\Generator\GeneratorInterface {
		public function init( array $metadata, \OutputPage $out ): void {}
		public function addMetadata(): void {
			\MediaWiki\MediaWikiServices::getInstance()->width = 900;
		}
		public function getAllowedParameterNames(): array { return []; }
	}
}

namespace {
	use MediaWiki\Extension\WikiSEO\WikiSEO;
	use MediaWiki\MediaWikiServices;
	use PageImages\PageImages;

	function same( $expected, $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new RuntimeException( $message . ': ' . var_export( $actual, true ) );
		}
	}

	/** Render with the actual WikiSEO orchestration and three generators. */
	function render( array $metadata, array $config = [], string $mode = 'full', ?WikiSEO $seo = null ): array {
		$services = MediaWikiServices::getInstance();
		$original = $services->config->values;
		$services->config->values = $config + $original;
		$services->titles = $services->finds = 0;
		File::$timestampCalls = File::$widthCalls = File::$heightCalls = PageImages::$calls = 0;
		try {
			$out = new OutputPage();
			$out->mode = $mode;
			$seo ??= new WikiSEO();
			$seo->setMetadata( $metadata );
			$seo->addMetadataToPage( $out );
			return [
				'html' => [ $out->title, $out->meta, $out->head ],
				'counts' => [
					'titles' => $services->titles, 'finds' => $services->finds,
					'width' => File::$widthCalls, 'height' => File::$heightCalls,
					'timestamp' => File::$timestampCalls, 'pageimages' => PageImages::$calls,
				],
			];
		} finally {
			$services->config->values = $original;
		}
	}

	// A custom generator can change underlying file state. Sharing must end
	// at this boundary, even when the same file name is used on both sides.
	// Its implementation is declared above after registering autoload.
	$cases = [
		[ 'local', [ 'image' => 'Shared.png' ], [], false, [ 3, 3, 3, 0 ], [ 1, 1, 1, 0 ] ],
		[ 'external', [ 'image' => 'https://example.test/remote.png' ], [], false, [ 4, 0, 0, 0 ], [ 1, 0, 0, 0 ] ],
		[ 'missing', [ 'image' => 'Missing.png' ], [], false, [ 4, 4, 0, 0 ], [ 1, 1, 0, 0 ] ],
		[ 'separate X', [ 'image' => 'Shared.png', 'twitter_image' => 'X.png' ], [], false, [ 3, 3, 3, 0 ], [ 2, 2, 2, 0 ] ],
		[ 'same explicit X', [ 'image' => 'Shared.png', 'twitter_image' => 'Shared.png' ], [], false, [ 3, 3, 3, 0 ], [ 1, 1, 1, 0 ] ],
		[ 'fallback', [], [ 'WikiSeoDefaultImage' => 'Default.png' ], false, [ 3, 3, 3, 3 ], [ 1, 1, 1, 1 ] ],
		[ 'page image blocks default', [], [ 'WikiSeoDefaultImage' => 'Default.png' ], true, [ 0, 0, 0, 3 ], [ 0, 0, 0, 1 ] ],
		[ 'logo only', [], [], false, [ 0, 0, 0, 0 ], [ 0, 0, 0, 0 ] ],
		[ 'no fallback', [], [ 'WikiSeoDisableLogoFallbackImage' => true ], false, [ 0, 0, 0, 0 ], [ 0, 0, 0, 0 ] ],
		[ 'social endpoint', [ 'image' => 'Shared.png' ], [ 'WikiSeoEnableSocialImages' => true ], false, [ 3, 3, 3, 0 ], [ 1, 1, 1, 0 ] ],
		[ 'reordered', [ 'image' => 'Shared.png' ], [ 'MetadataGenerators' => [ 'SchemaOrg', 'Twitter', 'OpenGraph' ] ], false, [ 3, 3, 3, 0 ], [ 1, 1, 1, 0 ] ],
		[ 'Open Graph alone missing', [ 'image' => 'Missing.png' ], [ 'MetadataGenerators' => [ 'OpenGraph' ] ], false, [ 2, 2, 0, 0 ], [ 1, 1, 0, 0 ] ],
	];
	$allHtml = [];
	$total = array_fill_keys( [ 'titles', 'finds', 'width', 'height', 'timestamp', 'pageimages' ], 0 );
	foreach ( $cases as [ $label, $metadata, $config, $present, $before, $after ] ) {
		PageImages::$present = $present;
		$result = render( $metadata, $config );
		$c = $result['counts'];
		same( $baseline ? $before : $after, [ $c['titles'], $c['finds'], $c['width'], $c['pageimages'] ],
			$label . ' lookup counts' );
		same( $c['width'], $c['height'], 'Dimension calls remain paired' );
		same( $c['width'], $c['timestamp'], 'Timestamp calls remain paired' );
		foreach ( $c as $key => $count ) {
			$total[$key] += $count;
		}
		$allHtml[$label] = $result['html'];
	}
	// A custom generator must not inherit the shared lookup or cause stale
	// results for later built-ins.
	$services = MediaWikiServices::getInstance();
	$services->width = 640;
	$result = render( [ 'image' => 'Shared.png' ], [
		'MetadataGenerators' => [ 'OpenGraph', 'ImageMutationProbe', 'SchemaOrg' ],
	] );
	same( 2, $result['counts']['finds'], 'Custom generator breaks the sharing scope' );
	if ( !str_contains( $result['html'][2]['og:image:width'], '640' ) ||
		!str_contains( $result['html'][2]['jsonld-metadata'], '"width":900' )
	) {
		throw new RuntimeException( 'Custom file mutation must be visible to the later generator' );
	}
	$allHtml['custom boundary'] = $result['html'];

	// Reusing WikiSEO and rendering again must perform fresh lookups.
	$services->width = 640;
	$seo = new WikiSEO();
	$first = render( [ 'image' => 'Shared.png' ], [], 'full', $seo );
	$services->width = 800;
	$second = render( [ 'image' => 'Shared.png' ], [], 'full', $seo );
	same( $baseline ? 3 : 1, $second['counts']['finds'], 'Fresh file lookup on a second render' );
	if ( !str_contains( $second['html'][2]['og:image:width'], '800' ) ) {
		throw new RuntimeException( 'The second render must see the new file dimensions' );
	}
	$allHtml['repeat first'] = $first['html'];
	$allHtml['repeat second'] = $second['html'];
	$services->width = 640;

	foreach ( [ 'global-only', 'off' ] as $mode ) {
		$result = render( [ 'image' => 'Shared.png' ], [], $mode );
		same( array_fill_keys( array_keys( $total ), 0 ), $result['counts'], 'Opt-out does no image work' );
		$allHtml[$mode] = $result['html'];
	}

	echo 'WD_SHARED_IMAGE_WORK ' . json_encode( [
		'mode' => $baseline ? 'before' : 'after', 'cases' => count( $cases ),
		'counts' => $total, 'html_sha256' => hash( 'sha256', serialize( $allHtml ) ),
	], JSON_THROW_ON_ERROR ) . PHP_EOL;
}
