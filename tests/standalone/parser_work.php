<?php

declare( strict_types=1 );

/** Compare actual validation and parser-property loading with the pinned baseline. */
class ParserOutput {
	public int $singleReads = 0;
	public int $bulkReads = 0;

	public function __construct( private array $properties ) {
	}

	public function getPageProperty( string $name ) {
		$this->singleReads++;
		return $this->properties[$name] ?? null;
	}

	public function getPageProperties(): array {
		$this->bulkReads++;
		return $this->properties;
	}
}

$root = $argv[1] ?? dirname( __DIR__, 2 );
$baseline = ( $argv[2] ?? '' ) === '--baseline';
require $root . '/includes/Validator.php';
require $root . '/includes/WikiSEO.php';

use MediaWiki\Extension\WikiSEO\Validator;
use MediaWiki\Extension\WikiSEO\WikiSEO;

function same( $expected, $actual, string $label ): void {
	if ( $actual !== $expected ) {
		throw new RuntimeException( $label . ': ' . var_export( $actual, true ) );
	}
}

$initialNames = Validator::$validParameterNames;
$initialLanguages = Validator::$isoLanguageCodes;
$initialAllowed = Validator::getValidParams();

class CustomValidator extends Validator {
	public static function getValidParams(): array {
		return [ 'custom_only', '7', 8 ];
	}
}

same( [ 'custom_only' => false, 8 => 'eight' ],
	CustomValidator::validateParams( [ 'title' => 'ignored', 'custom_only' => false, 7 => 'seven', 8 => 'eight' ] ),
	'Strict subclass membership' );

$cases = [
	[],
	[ 'description' => 'Description', 'title' => 'Title', 'canonical' => 'https://example.test/' ],
	[ 'not_allowed' => 1, 'title' => null, 'hreflang_zh-tw' => 'https://example.test/zh', 'robots' => '' ],
	[ 'hreflang_%' => 'invalid', 'hreflang_invalid' => 'invalid', 'image_width' => '0', 'image' => false ],
	[ 'Title' => 'case sensitive', 'title' => 'allowed', 123 => 'numeric key' ],
];
foreach ( $cases as $case ) {
	$expected = array_filter( $case,
		static fn ( $key ): bool => in_array( $key, $initialAllowed, true ), ARRAY_FILTER_USE_KEY );
	same( $expected, Validator::validateParams( $case ), 'Validation preserves values and input order' );
}
Validator::$validParameterNames[] = 'citation_example';
Validator::$validParameterNames[] = '123';
same( [ 'citation_example' => 'x' ], Validator::validateParams( [ 'citation_example' => 'x', 123 => 'numeric' ] ),
	'Newly registered generator field is accepted; numeric keys stay strict' );
Validator::$validParameterNames = $initialNames;
same( [], Validator::validateParams( [ 'citation_example' => 'x' ] ), 'Removed generator field is no longer accepted' );

if ( !$baseline ) {
	// The old cache only tracked parameter names, not changes to the public
	// language list. The candidate must invalidate both additions and removals.
	Validator::$isoLanguageCodes[] = 'test-xy';
	same( [ 'hreflang_test-xy' => 'x' ], Validator::validateParams( [ 'hreflang_test-xy' => 'x' ] ),
		'Newly registered language is recognized immediately' );
	Validator::$isoLanguageCodes = $initialLanguages;
	same( [], Validator::validateParams( [ 'hreflang_test-xy' => 'x' ] ), 'Removed language is rejected immediately' );
}
same( $initialAllowed, Validator::getValidParams(), 'Original valid-name list restored exactly' );

$reflection = new ReflectionClass( WikiSEO::class );
$seo = $reflection->newInstanceWithoutConstructor();
$load = $reflection->getMethod( 'loadCurrentRenderProps' );
$parserCases = [
	[],
	[ 'unrelated' => 'not SEO' ],
	[ 'hreflang_zh-tw' => 'https://example.test/zh', 'description' => 'Current', 'title' => 'Fresh' ],
	[ 'title' => null, 'image' => false, 'description' => '', 'keywords' => '0' ],
	array_fill_keys( $initialAllowed, 'value' ),
];
$single = $bulk = 0;
foreach ( $parserCases as $properties ) {
	$expected = [];
	foreach ( $initialAllowed as $name ) {
		$value = $properties[$name] ?? null;
		if ( $value !== null && $value !== false ) {
			$expected[$name] = $value;
		}
	}
	$output = new ParserOutput( $properties );
	same( $expected, $load->invoke( $seo, $output ), 'Current-render values and allow-list order preserved' );
	$single += $output->singleReads;
	$bulk += $output->bulkReads;
}
same( $baseline ? count( $initialAllowed ) * count( $parserCases ) : 0, $single, 'Individual getter count' );
same( $baseline ? 0 : count( $parserCases ), $bulk, 'Bulk getter count' );

/** Warmed timing samples; informational only, never a CI threshold. */
function medianNs( callable $work, int $iterations ): float {
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

$small = [
	'title' => 'A title', 'description' => 'A description', 'image' => 'Example.png',
	'canonical' => 'https://example.test/', 'twitter_image' => 'Share.png',
	'hreflang_fr-fr' => 'https://example.test/fr', 'hreflang_en-us' => 'https://example.test/en',
	'invalid' => 'ignored',
];
$dense = array_fill_keys( $initialAllowed, 'value' ) + [ 'invalid' => 'ignored' ];
$empty = new ParserOutput( [] );
$sparse = new ParserOutput( $small );
echo 'WD_SEO_PARSER_WORK ' . json_encode( [
	'mode' => $baseline ? 'before' : 'after',
	'php' => PHP_VERSION,
	'allowed_names' => count( $initialAllowed ),
	'parser_cases' => count( $parserCases ),
	'individual_getters' => $single,
	'bulk_getters' => $bulk,
	'validation_typical_ns' => medianNs( static fn () => Validator::validateParams( $small ), 10000 ),
	'validation_dense_ns' => medianNs( static fn () => Validator::validateParams( $dense ), 1000 ),
	'parser_empty_ns' => medianNs( static fn () => $load->invoke( $seo, $empty ), 10000 ),
	'parser_sparse_ns' => medianNs( static fn () => $load->invoke( $seo, $sparse ), 10000 ),
], JSON_THROW_ON_ERROR ) . PHP_EOL;
