<?php

declare( strict_types=1 );

/**
 * Exercise the real canonical hook and loader with instrumented stand-ins
 * for OutputPage and PageProps. No SQL, network or production settings.
 * Compare the same cases against the previous implementation.
 */
namespace {
	class Config {
		public array $generators = [ 'OpenGraph', 'Twitter', 'SchemaOrg' ];
		public function get( string $key ) {
			if ( $key === 'WikiSeoLargeImagePreview' ) {
				return false;
			}
			if ( $key !== 'MetadataGenerators' ) {
				throw new \RuntimeException( 'Unexpected config key: ' . $key );
			}
			return $this->generators;
		}
	}

	class Title {
	}

	class OutputPage {
		public int $reads = 0;
		public string $mode = 'full';

		public function __construct( private array $properties, private ?Title $title ) {
		}

		public function getTitle(): ?Title {
			return $this->title;
		}

		public function getProperty( string $name ) {
			$this->reads++;
			return $this->properties[$name] ?? null;
		}

		public function setProperty( string $name, $value ): void {
			$this->properties[$name] = $value;
		}
	}

	class Html {
		public static function element( string $name, array $attrs ): string {
			$html = '<' . $name;
			foreach ( $attrs as $key => $value ) {
				$html .= ' ' . $key . '="' . htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ) . '"';
			}
			return $html . '>';
		}
	}

	function wfMessage( ...$args ): object {
		return new \stdClass();
	}
}

namespace MediaWiki\Hook {
	interface BeforePageDisplayHook {
	}
}

namespace MediaWiki\Storage\Hook {
	interface RevisionDataUpdatesHook {
	}
}

namespace MediaWiki {
	final class TestPageProps {
		public array $values = [];
		public array $queries = [];

		public function getProperties( \Title $title, array $names ): array {
			$this->queries[] = $names;
			$selected = array_intersect_key( $this->values, array_fill_keys( $names, true ) );
			return $selected === [] ? [] : [ 1 => $selected ];
		}
	}

	final class TestConfigFactory {
		public function __construct( private \Config $config ) {
		}
		public function makeConfig( string $name ): \Config {
			return $this->config;
		}
	}

	final class TestHooks {
		public function run( string $hook, array $args ): bool {
			if ( $hook === 'WikiSEOPageMetadataMode' ) {
				$args[1] = $args[0]->mode;
			}
			return true;
		}
	}

	final class MediaWikiServices {
		public \Config $config;
		public TestPageProps $props;

		private function __construct() {
			$this->config = new \Config();
			$this->props = new TestPageProps();
		}
		public static function getInstance(): self {
			static $instance;
			return $instance ??= new self();
		}
		public function getConfigFactory(): TestConfigFactory {
			return new TestConfigFactory( $this->config );
		}
		public function getPageProps(): TestPageProps {
			return $this->props;
		}
		public function getHookContainer(): TestHooks {
			return new TestHooks();
		}
	}
}

namespace Wikimedia\AtEase {
	final class AtEase {
		private static int $previous;

		public static function suppressWarnings(): void {
			self::$previous = error_reporting( error_reporting() & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED );
		}
		public static function restoreWarnings(): void {
			error_reporting( self::$previous );
		}
	}
}

namespace MediaWiki\Extension\WikiSEO\Generator {
	interface GeneratorInterface {
		public function getAllowedParameterNames(): array;
	}
	class MetaTag implements GeneratorInterface {
		public function getAllowedParameterNames(): array {
			return [ 'description', 'robots' ];
		}
	}
}

namespace MediaWiki\Extension\WikiSEO\Generator\Plugins {
	use MediaWiki\Extension\WikiSEO\Generator\GeneratorInterface;
	class OpenGraph implements GeneratorInterface {
		public function getAllowedParameterNames(): array {
			return [ 'image' ];
		}
	}
	class Twitter implements GeneratorInterface {
		public function getAllowedParameterNames(): array {
			return [ 'twitter_site' ];
		}
	}
	class SchemaOrg implements GeneratorInterface {
		public function getAllowedParameterNames(): array {
			return [ 'schema_type' ];
		}
	}
	class Citation implements GeneratorInterface {
		public function getAllowedParameterNames(): array {
			return [ 'citation_doi' ];
		}
	}
}

namespace {
	use MediaWiki\Extension\WikiSEO\Hooks\PageHooks;
	use MediaWiki\MediaWikiServices;

	$root = $argv[1] ?? dirname( __DIR__, 2 );
	$baseline = ( $argv[2] ?? '' ) === '--baseline';
	foreach ( [ 'Validator.php', 'MetaValues.php', 'PagePolicy.php', 'WikiSEO.php', 'Hooks/PageHooks.php' ] as $path ) {
		require $root . '/includes/' . $path;
	}

	function same( $expected, $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new \RuntimeException( $message . ': ' . var_export( $actual, true ) );
		}
	}

	$services = MediaWikiServices::getInstance();
	$hooks = new PageHooks( $services->config );
	$stored = 'https://example.test/stored';
	$fresh = 'https://example.test/fresh';
	$native = '<link rel="canonical" href="https://example.test/core">';
	$canonical = static fn ( string $url ): string => Html::element( 'link', [
		'rel' => 'canonical', 'href' => $url
	] );

	$cases = [
		// Label, current output props, database props, expected URL, mode.
		[ 'explicit output wins', [ 'canonical' => $fresh ], [ 'canonical' => $stored ], $fresh, 'full' ],
		[ 'serialized output canonical', [ 'canonical' => serialize( $fresh ) ], [], $fresh, 'full' ],
		[ 'empty output canonical', [ 'canonical' => '' ], [ 'canonical' => $stored ], null, 'full' ],
		[ 'unsafe output canonical', [ 'canonical' => 'javascript:alert(1)' ], [ 'canonical' => $stored ], null, 'full' ],
		[ 'output title suppresses stored canonical', [ 'title' => 'Fresh title' ], [ 'canonical' => $stored ], null, 'full' ],
		[ 'empty title still suppresses database', [ 'title' => '' ], [ 'canonical' => $stored ], null, 'full' ],
		[ 'hreflang suppresses stored canonical', [ 'hreflang_zh-tw' => 'https://example.test/zh' ], [ 'canonical' => $stored ], null, 'full' ],
		[ 'unknown output property ignored', [ 'not_a_seo_property' => 'x' ], [ 'canonical' => $stored ], $stored, 'full' ],
		[ 'stored canonical used', [], [ 'canonical' => $stored, 'description' => 'Persisted' ], $stored, 'full' ],
		[ 'stored serialized URL stays invalid', [], [ 'canonical' => serialize( $stored ) ], null, 'full' ],
		[ 'no canonical anywhere', [], [ 'description' => 'Persisted' ], null, 'full' ],
		[ 'global only', [ 'canonical' => $fresh ], [ 'canonical' => $stored ], null, 'global-only' ],
		[ 'off', [], [ 'canonical' => $stored ], null, 'off' ],
	];

	$reads = $queries = $requested = 0;
	foreach ( $cases as [ $label, $current, $db, $expected, $mode ] ) {
		$out = new OutputPage( $current, new Title() );
		$out->mode = $mode;
		$services->props->values = $db;
		$services->props->queries = [];
		$tags = [ 17 => $native, 'unrelated' => '<link rel="alternate">' ];
		$hooks->onOutputPageAfterGetHeadLinksArray( $tags, $out );
		same( [ 17 => $expected === null ? $native : $canonical( $expected ),
			'unrelated' => '<link rel="alternate">' ], $tags, $label );
		foreach ( $services->props->queries as $names ) {
			if ( !$baseline ) {
				same( [ 'canonical' ], $names, 'Fetch only canonical from page_props' );
			}
			$requested += count( $names );
		}
		if ( $mode !== 'full' ) {
			same( [], $services->props->queries, 'Opt-out must not read database properties' );
			same( 0, $out->reads, 'Opt-out must not read output properties' );
		}
		$reads += $out->reads;
		$queries += count( $services->props->queries );
	}

	// A configured optional generator may add its own valid names.
	$services->config->generators = [ 'Citation' ];
	$services->props->values = [ 'canonical' => $stored ];
	$out = new OutputPage( [ 'citation_doi' => '10.1234/example' ], new Title() );
	$tags = [ $native ];
	$services->props->queries = [];
	$hooks->onOutputPageAfterGetHeadLinksArray( $tags, $out );
	same( [ $native ], $tags, 'Configured generator property suppresses stale canonical' );
	same( [], $services->props->queries, 'No fallback query for a registered Citation field' );

	// The same OutputPage may change between calls. Never cache the decision.
	$out = new OutputPage( [ 'canonical' => $fresh ], new Title() );
	$tags = [];
	$hooks->onOutputPageAfterGetHeadLinksArray( $tags, $out );
	same( [ 'canonical' => $canonical( $fresh ) ], $tags, 'Missing core canonical is added' );
	$out->setProperty( 'canonical', 'https://example.test/later' );
	$hooks->onOutputPageAfterGetHeadLinksArray( $tags, $out );
	same( [ 'canonical' => $canonical( 'https://example.test/later' ) ], $tags, 'Late output change is visible' );

	$out = new OutputPage( [ 'canonical' => $fresh ], null );
	$tags = [ $native ];
	$hooks->onOutputPageAfterGetHeadLinksArray( $tags, $out );
	same( [ $native ], $tags, 'Null title preserves the core canonical' );

	echo 'WD_CANONICAL_WORK ' . json_encode( [
		'mode' => $baseline ? 'before' : 'after',
		'cases' => count( $cases ),
		'output_property_reads' => $reads,
		'pageprops_calls' => $queries,
		'requested_property_names' => $requested,
	], JSON_THROW_ON_ERROR ) . PHP_EOL;
}
