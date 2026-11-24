<?php

declare( strict_types=1 );

namespace {
	class TestTitle {
		public function getFullURL(): string { return 'https://fr.wikidebates.org/wiki/Wikidebates:Accueil'; }
		public function getPrefixedText(): string { return 'Wikidebates:Accueil'; }
	}
	class TestRequest {
		public function getProtocol(): string { return 'https'; }
	}
	class OutputPage {
		public array $tags = [];
		public array $head = [];
		public function getTitle(): TestTitle { return new TestTitle(); }
		public function getHTMLTitle(): string { return 'Page d\'accueil'; }
		public function getPageTitle(): string { return 'Page d\'accueil'; }
		public function getRequest(): TestRequest { return new TestRequest(); }
		public function addMeta( string $name, string $value ): void { $this->tags[$name] = $value; }
		public function addHeadItem( string $name, string $markup ): void { $this->head[$name] = $markup; }
		public function getMetaTags(): array {
			return array_map( static fn ( $k, $v ) => [ $k, $v ], array_keys( $this->tags ), $this->tags );
		}
		public function getHeadItemsArray(): array { return $this->head; }
	}
	class Html {
		public static function element( string $name, array $attrs ): string {
			$markup = '<' . $name;
			foreach ( $attrs as $key => $value ) {
				$markup .= ' ' . $key . '="' . htmlspecialchars( (string)$value, ENT_QUOTES, 'UTF-8' ) . '"';
			}
			return $markup . '>';
		}
	}
}

namespace MediaWiki {
	class TestContentLanguage {
		public function getCode(): string { return 'fr'; }
	}
	class MediaWikiServices {
		public static function getInstance(): self { return new self(); }
		public function getContentLanguage(): TestContentLanguage { return new TestContentLanguage(); }
	}
}

namespace MediaWiki\Extension\WikiSEO {
	class WikiSEO {
		public static function protocolizeUrl( string $url, \TestRequest $request ): string { return $url; }
	}
}

namespace MediaWiki\Extension\WikiSEO\Generator {
	interface GeneratorInterface {
		public function init( array $metadata, \OutputPage $out ): void;
		public function addMetadata(): void;
		public function getAllowedParameterNames(): array;
	}
	abstract class AbstractBaseGenerator {
		protected $metadata = [];
		protected $outputPage;
		protected $tags = [];
		public static int $fallbackCalls = 0;
		public static int $preprocessCalls = 0;
		public static int $socialCalls = 0;
		public static int $fileCalls = 0;
		public static ?string $socialImage = null;
		protected function getConfigValue( string $name ) {
			return [
				'TwitterCardType' => 'summary_large_image',
				'TwitterSiteHandle' => null,
				'Sitename' => 'Wikidebates',
				'Server' => 'https://fr.wikidebates.org',
				'LanguageCode' => 'fr',
				'WikiSeoDisableLogoFallbackImage' => false,
			][$name] ?? null;
		}
		protected function setFallbackImageIfEnabled(): void {
			self::$fallbackCalls++;
			$this->metadata['image'] ??= 'https://fr.wikidebates.org/logo.png';
		}
		protected function preprocessFileMetadata(): void {
			self::$preprocessCalls++;
		}
		protected function getSocialMediaImageUrl(): ?string {
			self::$socialCalls++;
			return self::$socialImage;
		}
		protected function getFileObject( string $name ) {
			self::$fileCalls++;
			if ( $name === 'Share.png' ) {
				return $name;
			}
			throw new \InvalidArgumentException();
		}
		protected function getFileInfo( $file ): array {
			return [ 'url' => 'https://example.test/files/' . $file ];
		}
		protected function getWikiLogo(): string { return 'https://fr.wikidebates.org/logo.png'; }
	}
}

namespace {
	$root = $argv[1] ?? dirname( __DIR__, 2 );
	$baseline = ( $argv[2] ?? '' ) === '--baseline';
	require_once $root . '/includes/MetaValues.php';
	require_once $root . '/includes/Generator/Plugins/Twitter.php';
	require_once $root . '/includes/Generator/Plugins/SchemaOrg.php';
	require_once $root . '/includes/Generator/Plugins/OpenGraph.php';

	use MediaWiki\Extension\WikiSEO\Generator\AbstractBaseGenerator;

	use MediaWiki\Extension\WikiSEO\Generator\Plugins\OpenGraph;
	use MediaWiki\Extension\WikiSEO\Generator\Plugins\SchemaOrg;
	use MediaWiki\Extension\WikiSEO\Generator\Plugins\Twitter;

	function testAssert( bool $ok, string $message ): void {
		if ( !$ok ) {
			throw new \RuntimeException( $message );
		}
	}

	$out = new OutputPage();
	$schema = new SchemaOrg();
	$schema->init( [ 'description' => 'Présentation Wikidebates' ], $out );
	$schema->addMetadata();
	$json = substr( $out->head['jsonld-metadata'], strlen( '<script type="application/ld+json">' ), -strlen( '</script>' ) );
	$data = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
	testAssert( $data['@type'] === 'WebPage', 'Generic pages must not be Article' );
	testAssert( !isset( $data['author'], $data['datePublished'] ), 'Do not infer author or publication' );
	testAssert( $data['image']['url'] === 'https://fr.wikidebates.org/logo.png', 'Keep logo fallback' );

	$out = new OutputPage();
	$schema->init( [ 'type' => 'Article', 'schema_author' => 'Auteur', 'published_time' => '2026-11-24' ], $out );
	$schema->addMetadata();
	$json = substr( $out->head['jsonld-metadata'], strlen( '<script type="application/ld+json">' ), -strlen( '</script>' ) );
	$data = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
	testAssert( $data['@type'] === 'Article' && $data['author']['name'] === 'Auteur', 'Explicit article author' );
	testAssert( $data['datePublished'] === '2026-11-24' && !isset( $data['dateModified'] ), 'No inferred modification date' );

	$out = new OutputPage();
	$twitter = new Twitter();
	$twitter->init( [ 'description' => 'Présentation du projet', 'twitter_creator' => '@example' ], $out );
	$twitter->addMetadata();
	testAssert( $out->tags['twitter:card'] === 'summary_large_image', 'Card default' );
	testAssert( $out->tags['twitter:image'] === 'https://fr.wikidebates.org/logo.png', 'X logo fallback' );
	testAssert( $out->tags['twitter:description'] === 'Présentation du projet', 'X description' );
	testAssert( $out->tags['twitter:creator'] === '@example', 'X creator' );
	testAssert( !isset( $out->head['og:title'] ), 'X generator must not emit Open Graph' );

	$out = new OutputPage();
	$og = new OpenGraph();
	$og->init( [], $out );
	$og->addMetadata();
	testAssert( isset( $out->head['og:type'] ), 'Generic OG type' );
	testAssert( !isset( $out->head['article:published_time'] ), 'No inferred article publication' );
	// A dedicated X image bypasses all general-image preparation without
	// changing the selected image or the alt-text fallback.
	AbstractBaseGenerator::$socialImage = 'https://example.test/generated.jpg';
	$dedicatedCases = [
		[ 'https://example.test/x.png', 'https://example.test/x.png' ],
		[ 'Share.png', 'https://example.test/files/Share.png' ],
		[ 'javascript:alert(1)', null ],
		[ '', null ],
	];
	$work = [ 'fallback' => 0, 'preprocess' => 0, 'social' => 0, 'dedicated_file' => 0 ];
	foreach ( $dedicatedCases as [ $image, $expected ] ) {
		AbstractBaseGenerator::$fallbackCalls = 0;
		AbstractBaseGenerator::$preprocessCalls = 0;
		AbstractBaseGenerator::$socialCalls = 0;
		AbstractBaseGenerator::$fileCalls = 0;
		$out = new OutputPage();
		$twitter = new Twitter();
		$twitter->init( [
			'image' => 'General.png', 'image_alt' => 'General alt',
			'twitter_image' => $image,
		], $out );
		$twitter->addMetadata();
		testAssert( ( $out->tags['twitter:image'] ?? null ) === $expected, 'Exact dedicated image output' );
		testAssert( ( $out->tags['twitter:image:alt'] ?? null ) ===
			( $baseline || $expected !== null ? 'General alt' : null ),
			'Alt fallback is retained only for a published image' );
		testAssert( AbstractBaseGenerator::$fallbackCalls === ( $baseline ? 1 : 0 ), 'Unneeded fallback call' );
		testAssert( AbstractBaseGenerator::$preprocessCalls === ( $baseline ? 1 : 0 ), 'Unneeded preprocess call' );
		testAssert( AbstractBaseGenerator::$socialCalls === ( $baseline ? 1 : 0 ), 'Unneeded social image URL call' );
		testAssert( AbstractBaseGenerator::$fileCalls === 1, 'Dedicated image still resolves and validates' );
		$work['fallback'] += AbstractBaseGenerator::$fallbackCalls;
		$work['preprocess'] += AbstractBaseGenerator::$preprocessCalls;
		$work['social'] += AbstractBaseGenerator::$socialCalls;
		$work['dedicated_file'] += AbstractBaseGenerator::$fileCalls;
	}

	$out = new OutputPage();
	$twitter = new Twitter();
	$twitter->init( [ 'image' => 'https://example.test/general.png' ], $out );
	$twitter->addMetadata();
	testAssert( $out->tags['twitter:image'] === 'https://example.test/generated.jpg', 'Configured social image still wins without explicit X image' );
	echo 'WD_SEO_TWITTER_WORK ' . json_encode( [
		'mode' => $baseline ? 'before' : 'after', 'cases' => count( $dedicatedCases ), 'calls' => $work,
	], JSON_THROW_ON_ERROR ) . PHP_EOL;
	echo "WikiSEO generator smoke checks passed\n";
}
