<?php

declare( strict_types=1 );

/** Compare actual MetaTag and property decoder with the previous revision. */
namespace {
	class Config {
		public array $values = [];
		public function get( string $key ) {
			return $this->values[$key] ?? null;
		}
	}

	class Title {
		public static int $subpageReads = 0;

		public function __construct( private string $name ) {
		}
		public function getText(): string {
			return $this->name;
		}
		public function isSubpage(): bool {
			return str_contains( $this->name, '/' );
		}
		public function getSubpageText(): string {
			self::$subpageReads++;
			$parts = explode( '/', $this->name );
			return end( $parts );
		}
		public function getBaseTitle(): self {
			return new self( substr( $this->name, 0, strrpos( $this->name, '/' ) ) );
		}
		public function getFullURL(): string {
			return 'https://example.test/wiki/' . $this->name;
		}
	}

	class WebRequest {
		public function __construct( private array $params ) {
		}
		public function getValues(): array {
			return $this->params;
		}
		public function getProtocol(): string {
			return 'https';
		}
	}

	class OutputPage {
		public int $titleReads = 0;
		public int $metaReads = 0;
		public array $meta = [];
		public array $head = [];
		public string $index = 'index';
		public string $follow = 'follow';

		public function __construct( private ?Title $title, private array $params ) {
		}
		public function getTitle(): ?Title {
			$this->titleReads++;
			return $this->title;
		}
		public function getRequest(): WebRequest {
			return new WebRequest( $this->params );
		}
		public function getMetaTags(): array {
			$this->metaReads++;
			return $this->meta;
		}
		public function addMeta( string $name, string $value ): void {
			$this->meta[] = [ $name, $value ];
		}
		public function addHeadItem( string $name, string $html ): void {
			$this->head[$name] = $html;
		}
		public function setIndexPolicy( string $policy ): void {
			// Match the preservation of an already established noindex.
			if ( $this->index !== 'noindex' ) {
				$this->index = $policy;
			}
		}
		public function setFollowPolicy( string $policy ): void {
			if ( $this->follow !== 'nofollow' ) {
				$this->follow = $policy;
			}
		}
	}

	class Html {
		public static function element( string $name, array $attributes ): string {
			$result = '<' . $name;
			foreach ( $attributes as $key => $value ) {
				$result .= ' ' . $key . '="' . htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ) . '"';
			}
			return $result . '>';
		}
	}
}

namespace MediaWiki {
	final class MediaWikiServices {
		public \Config $config;
		public int $languageUtilsReads = 0;
		public int $languageFactoryReads = 0;

		private function __construct() {
			$this->config = new \Config();
		}
		public static function getInstance(): self {
			static $instance;
			return $instance ??= new self();
		}
		public function getConfigFactory(): self {
			return $this;
		}
		public function makeConfig( string $name ): \Config {
			return $this->config;
		}
		public function getLanguageNameUtils(): self {
			$this->languageUtilsReads++;
			return $this;
		}
		public function getLanguageFactory(): self {
			$this->languageFactoryReads++;
			return $this;
		}
		public function isSupportedLanguage( string $name ): bool {
			return in_array( $name, [ 'fr', 'en' ], true );
		}
		public function getLanguage( string $name ): object {
			return new class( $name ) {
				public function __construct( private string $name ) {
				}
				public function getHtmlCode(): string {
					return $this->name;
				}
			};
		}
	}
}

namespace Wikimedia\AtEase {
	final class AtEase {
		public static int $calls = 0;
		private static int $saved;

		public static function suppressWarnings(): void {
			self::$calls++;
			self::$saved = error_reporting( error_reporting() & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED );
		}
		public static function restoreWarnings(): void {
			error_reporting( self::$saved );
		}
	}
}

namespace {
	use MediaWiki\Extension\WikiSEO\Generator\MetaTag;
	use MediaWiki\Extension\WikiSEO\WikiSEO;
	use MediaWiki\MediaWikiServices;
	use Wikimedia\AtEase\AtEase;

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

	function same( $expected, $actual, string $label ): void {
		if ( $expected !== $actual ) {
			throw new RuntimeException( $label . ': ' . var_export( $actual, true ) );
		}
	}

	$services = MediaWikiServices::getInstance();
	$global = [
		'GoogleSiteVerificationKey' => 'google-test',
		'BingSiteVerificationKey' => 'bing-test',
		'YandexSiteVerificationKey' => 'yandex-test',
		'PinterestSiteVerificationKey' => 'pinterest-test',
		'NortonSiteVerificationKey' => 'norton-test',
		'NaverSiteVerificationKey' => 'naver-test',
		'FacebookAppId' => '123',
		'FacebookAdmins' => '456',
		'WikiSeoDefaultLanguage' => 'en-us',
		'WikiSeoNoindexPageTitles' => [ 'Excluded' ],
	];

	function render( ?string $name, array $params, array $config, array $metadata = [], bool $full = false, bool $blocked = false ): array {
		$services = MediaWikiServices::getInstance();
		$services->config->values = $config;
		$out = new OutputPage( $name === null ? null : new Title( $name ), $params );
		$out->meta = [ [ 'description', 'Existing core description' ] ];
		if ( $blocked ) {
			$out->index = 'noindex';
			$out->follow = 'nofollow';
		}
		$meta = new MetaTag();
		$meta->init( $metadata, $out );
		$full ? $meta->addMetadata() : $meta->addGlobalMetadata();
		return [ $out->meta, $out->head, $out->index, $out->follow, $out->titleReads, $out->metaReads ];
	}

	// Verify the exact robot policy for every formerly blocked URL key/value.
	$cases = [
		[ 'ordinary', 'Ordinary', [], 'index' ],
		[ 'translation', 'Project/fr', [], 'index' ],
		[ 'nonlanguage subpage', 'Project/help', [], 'index' ],
		[ 'no title', null, [ 'oldid' => '5' ], 'index' ],
		[ 'excluded title', 'Excluded', [], 'noindex' ],
		[ 'excluded request title', 'Ordinary', [ 'title' => 'Excluded' ], 'noindex' ],
		[ 'normal view', 'Ordinary', [ 'action' => 'view', 'limit' => '50' ], 'index' ],
		[ 'strict numeric limit', 'Ordinary', [ 'limit' => 500 ], 'index' ],
		[ 'strict key case', 'Ordinary', [ 'OLDID' => '2' ], 'index' ],
		[ 'unrelated numeric key', 'Ordinary', [ 3 => 'x' ], 'index' ],
	];
	foreach ( [ 'curid', 'diff', 'from', 'group', 'mobileaction', 'oldid', 'printable',
		'profile', 'redirect', 'redlink', 'stableid' ] as $key ) {
		$cases[] = [ $key, 'Ordinary', [ $key => '' ], 'noindex' ];
	}
	foreach ( [
		'action' => [ 'delete', 'edit', 'history', 'info', 'pagevalues', 'purge', 'visualeditor', 'watch' ],
		'feed' => [ 'rss' ], 'limit' => [ '500' ], 'veaction' => [ 'edit', 'editsource' ],
	] as $key => $values ) {
		foreach ( $values as $value ) {
			$cases[] = [ $key . ':' . $value, 'Ordinary', [ $key => $value ], 'noindex' ];
		}
	}
	$outputs = [];
	$counts = [ 'title_reads' => 0, 'meta_reads' => 0 ];
	foreach ( $cases as [ $label, $name, $params, $expected ] ) {
		$result = render( $name, $params, $global );
		same( $expected, $result[2], $label . ' robots policy' );
		same( 7, count( $result[0] ), 'Six global verification tags and core description' );
		$counts['title_reads'] += $result[4];
		$counts['meta_reads'] += $result[5];
		$outputs[$label] = array_slice( $result, 0, 4 );
	}
	same( true, isset( $outputs['translation'][1]['en-us'], $outputs['translation'][1]['fr'] ), 'Translated subpage links' );
	same( true, isset( $outputs['ordinary'][1]['en-us'] ), 'Configured default language link' );
	same( false, isset( $outputs['nonlanguage subpage'][1]['help'] ), 'Unsupported language is not advertised' );

	foreach ( [
		[ 'disabled language', [ 'WikiSeoDefaultLanguage' => null ] + $global, [], false, false ],
		[ 'invalid language', [ 'WikiSeoDefaultLanguage' => 'invalid' ] + $global, [], false, false ],
		[ 'core noindex', $global, [], false, true ],
		[ 'full without page tags', $global, [], true, false ],
		[ 'full robots only', $global, [ 'robots' => 'noindex,nofollow' ], true, false ],
		[ 'full metadata', $global, [ 'description' => 'Replacement', 'keywords' => 'Keywords',
			'googlebot' => 'noarchive', 'hreflang_fr-fr' => 'https://example.test/fr' ], true, false ],
	] as [ $label, $config, $metadata, $full, $blocked ] ) {
		$result = render( 'Ordinary', [], $config, $metadata, $full, $blocked );
		$counts['title_reads'] += $result[4];
		$counts['meta_reads'] += $result[5];
		$outputs[$label] = array_slice( $result, 0, 4 );
	}
	same( 'noindex', $outputs['core noindex'][2], 'Core noindex kept' );
	same( 'nofollow', $outputs['full robots only'][3], 'Explicit robots kept' );
	same( [ 'description', 'Existing core description' ], $outputs['full metadata'][0][0], 'Existing description preserved' );

	$counts['subpage_reads'] = Title::$subpageReads;
	$counts['language_utils'] = $services->languageUtilsReads;
	$counts['language_factory'] = $services->languageFactoryReads;
	if ( !$baseline ) {
		same( 2, $counts['subpage_reads'], 'Only actual subpages need subpage text' );
		same( 2, $counts['language_utils'], 'Only actual subpages need the language service' );
		same( 1, $counts['language_factory'], 'Only an actual translation needs the language factory' );
		same( 1, $counts['meta_reads'], 'Only requested page metadata needs a tag scan' );
	}

	// Preserve legacy serialized values, malformed encodings, binary data,
	// warnings, false-value handling and allowed_classes=false behavior.
	$decode = new ReflectionMethod( WikiSEO::class, 'decodeOutputProperty' );
	$values = [
		'', 'Ordinary title', 'Description française', 'https://example.test/path',
		'index,follow', '0', 'N', 'N;trailing', 'a:broken', "i:2;trailing",
		' S:1:"a";', 'S:1:"a";', "plain\0text", 'javascript:alert(1)',
		serialize( null ), serialize( false ), serialize( true ), serialize( 42 ),
		serialize( 1.25 ), serialize( '' ), serialize( 'serialized title' ),
		serialize( [ 'x' => 'y' ] ), serialize( (object)[ 'key' => 'value' ] ),
		'd:INF;', 'd:NAN;', 'O:4:"Nope":0:{}', 'C:4:"Nope":0:{}', 'r:1;',
	];
	$decoded = [];
	$reporting = error_reporting();
	foreach ( $values as $value ) {
		// Compare serialized representations, including NAN and incomplete objects.
		AtEase::suppressWarnings();
		try {
			$old = unserialize( $value, [ 'allowed_classes' => false ] );
		} finally {
			AtEase::restoreWarnings();
		}
		$actual = $decode->invoke( null, $value );
		same( serialize( $old !== false ? $old : $value ), serialize( $actual ), 'Exact decode semantics' );
		same( $reporting, error_reporting(), 'Warning handling restored' );
		$decoded[] = serialize( $actual );
	}
	$counts['decoder_calls'] = AtEase::$calls - count( $values );

	function medianNs( callable $work, int $iterations = 20000 ): float {
		$samples = [];
		for ( $sample = -2; $sample < 7; $sample++ ) {
			$start = hrtime( true );
			for ( $i = 0; $i < $iterations; $i++ ) {
				$work();
			}
			if ( $sample >= 0 ) {
				$samples[] = ( hrtime( true ) - $start ) / $iterations;
			}
		}
		sort( $samples, SORT_NUMERIC );
		return round( $samples[3], 1 );
	}
	$plain = [ 'A normal title', 'Description française', 'https://example.test/image.png', 'index,follow' ];
	$decodeTime = medianNs( static function () use ( $decode, $plain ): void {
		foreach ( $plain as $value ) {
			$decode->invoke( null, $value );
		}
	} );
	$globalTime = medianNs( static function () use ( $global ): void {
		render( 'Ordinary', [ 'action' => 'view', 'title' => 'Ordinary' ], $global );
	}, 5000 );
	echo 'WD_GLOBAL_WORK ' . json_encode( [
		'mode' => $baseline ? 'before' : 'after',
		'php' => PHP_VERSION, 'global_cases' => count( $outputs ),
		'decoder_cases' => count( $values ), 'counts' => $counts,
		'decode_four_text_values_ns' => $decodeTime, 'global_render_ns' => $globalTime,
		'output_sha256' => hash( 'sha256', serialize( [ $outputs, $decoded ] ) ),
	], JSON_THROW_ON_ERROR ) . PHP_EOL;
}
