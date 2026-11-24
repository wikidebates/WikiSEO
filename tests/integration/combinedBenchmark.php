<?php

declare( strict_types=1 );

/**
 * Isolated combined performance benchmark. Run one case per PHP process.
 * Only the disposable Wikidebates SQLite lab is supported by this script.
 */
if ( getenv( 'WD_TEST_MARIADB_SOCKET' ) || getenv( 'WD_TEST_DISPOSABLE_MARIADB' ) ) {
	throw new RuntimeException( 'The combined benchmark requires a fresh SQLite lab' );
}
require getenv( 'WD_SEO_LAB_BOOTSTRAP' );

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\WikiSEO\Hooks\PageHooks;
use MediaWiki\Extension\WikiSEO\PagePolicy;
use MediaWiki\Extension\Wikidebates\Seo\Metadata;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Title\Title;
use Psr\Log\AbstractLogger;
use Wikimedia\Rdbms\IDatabase;

final class BenchmarkSqlCounter extends AbstractLogger {
	public int $total = 0;
	public int $pageProps = 0;
	public int $files = 0;

	public function log( $level, $message, array $context = [] ): void {
		if ( ( $context['db_log_category'] ?? '' ) !== 'query' || !isset( $context['sql'] ) ) {
			return;
		}
		$this->total++;
		$sql = $context['sql'];
		if ( preg_match( '/\b(?:FROM|JOIN|UPDATE|INTO)\s+[\x60"]?page_props\b/i', $sql ) ) {
			$this->pageProps++;
		}
		if ( preg_match( '/\b(?:FROM|JOIN|UPDATE|INTO)\s+[\x60"]?(?:image|oldimage)\b/i', $sql ) ) {
			$this->files++;
		}
	}

	public function reset(): void {
		$this->total = $this->pageProps = $this->files = 0;
	}

	public function counts(): array {
		return [ 'total' => $this->total, 'page_props' => $this->pageProps, 'file_catalog' => $this->files ];
	}
}

$case = $argv[1] ?? '';
$label = $argv[2] ?? '';
$round = (int)( $argv[3] ?? 0 );
$cases = [ 'ordinary-empty', 'ordinary-persisted', 'ordinary-text', 'ordinary-local-file',
	'ordinary-separate-x', 'native-debate', 'native-argument', 'native-hreflang', 'parser' ];
check( in_array( $case, $cases, true ) && in_array( $label, [ 'baseline', 'optimized' ], true ),
	'Choose a valid benchmark case and version label' );

$services = MediaWikiServices::getInstance();
$provider = $services->getConnectionProvider();
$db = $provider->getPrimaryDatabase();
$dbr = $provider->getReplicaDatabase();
same( 'sqlite', $db->getType(), 'Only disposable SQLite is allowed' );

// All fixtures are synthetic; the same page and file catalogue rows exist
// in each new database. File metadata is real MediaWiki LocalFile data,
// not a simulated repository; no binary image is read or transformed.
$pageName = 'SEO_Benchmark_Page';
$emptyPageName = 'SEO_Benchmark_Without_Props';
foreach ( [ 41 => $pageName, 42 => $emptyPageName ] as $id => $name ) {
	$db->newInsertQueryBuilder()->insertInto( 'page' )->row( [
		'page_id' => $id, 'page_namespace' => 0, 'page_title' => $name,
		'page_random' => 0.5, 'page_touched' => '20261124100000',
		'page_latest' => 1, 'page_len' => 100, 'page_content_model' => 'wikitext',
	] )->caller( __METHOD__ )->execute();
}
$db->newInsertQueryBuilder()->insertInto( 'page_props' )->rows( [
	[ 'pp_page' => 41, 'pp_propname' => 'title', 'pp_value' => 'Stored SEO title' ],
	[ 'pp_page' => 41, 'pp_propname' => 'description', 'pp_value' => 'Stored SEO description' ],
	[ 'pp_page' => 41, 'pp_propname' => 'canonical', 'pp_value' => 'https://example.test/stored' ],
	[ 'pp_page' => 41, 'pp_propname' => 'image', 'pp_value' => 'https://example.test/stored.png' ],
] )->caller( __METHOD__ )->execute();
$db->newInsertQueryBuilder()->insertInto( 'actor' )->row( [
	'actor_id' => 51, 'actor_user' => null, 'actor_name' => 'SEO benchmark',
] )->caller( __METHOD__ )->execute();
$db->newInsertQueryBuilder()->insertInto( 'comment' )->row( [
	'comment_id' => 51, 'comment_hash' => 0, 'comment_text' => '', 'comment_data' => null,
] )->caller( __METHOD__ )->execute();
foreach ( [ 'SEO-Bench.png' => 640, 'SEO-Bench-X.png' => 800 ] as $name => $width ) {
	$db->newInsertQueryBuilder()->insertInto( 'image' )->row( [
		'img_name' => $name, 'img_size' => 1000, 'img_width' => $width, 'img_height' => 480,
		'img_metadata' => '', 'img_bits' => 8, 'img_media_type' => 'BITMAP',
		'img_major_mime' => 'image', 'img_minor_mime' => 'png',
		'img_description_id' => 51, 'img_actor' => 51,
		'img_timestamp' => '20261124100000', 'img_sha1' => str_repeat( '0', 31 ),
	] )->caller( __METHOD__ )->execute();
}
if ( $db->trxLevel() ) {
	$db->commit( __METHOD__ );
}

// Known, reproducible configuration, not a copy of the production settings.
$GLOBALS['wgLogo'] = 'https://example.test/logo.png';
$GLOBALS['wgLogos'] = [ '1x' => 'https://example.test/logo.png' ];
$GLOBALS['wgGoogleSiteVerificationKey'] = 'benchmark-verification';
$GLOBALS['wgWikiSeoDefaultLanguage'] = $case === 'native-hreflang' ? 'en-us' : null;
$GLOBALS['wgWikiSeoDisableLogoFallbackImage'] = false;
$GLOBALS['wgWikiSeoEnableSocialImages'] = false;
$GLOBALS['wgWikiSeoNoindexPageTitles'] = [];
$hooks = new PageHooks( $services->getMainConfig() );
$skin = new class( [ 'name' => 'vector' ] ) extends \Skin {
	public function outputPage() {}
};

$ordinary = [
	'title' => 'Fresh SEO title',
	'description' => 'A reproducible description for the SEO performance benchmark.',
	'keywords' => 'project, wiki, debate',
	'canonical' => 'https://example.test/fresh',
	'image' => 'https://example.test/image.png',
	'image_alt' => 'A test image',
	'robots' => 'index,follow',
	'og_type' => 'website',
	'schema_type' => 'AboutPage',
	'hreflang_fr-fr' => 'https://example.test/fr',
];

function operation(): array {
	global $case, $services, $hooks, $skin, $ordinary, $pageName, $emptyPageName;
	if ( $case === 'parser' ) {
		$title = Title::newFromText( 'SEO repeated parser benchmark' );
		$output = $services->getParserFactory()->create()->parse(
			'{{#seo:|title=Original title|description=A description|image=https://example.test/image.png}}' .
			'<seo title="Final title" twitter_image="https://example.test/x.png"></seo>' .
			'{{#seo:|canonical=https://example.test/fresh|schema_type=AboutPage}}',
			$title, ParserOptions::newFromAnon()
		);
		return [ 'properties' => $output->getPageProperties() ];
	}

	$context = new RequestContext();
	$context->setTitle( Title::newFromText( match ( $case ) {
		'ordinary-persisted' => $pageName,
		'ordinary-empty' => $emptyPageName,
		default => 'SEO benchmark ' . $case,
	} ) );
	$out = $context->getOutput();
	$out->setArticleFlag( true );
	$out->setRevisionTimestamp( '20261124100000' );
	if ( str_starts_with( $case, 'native-' ) ) {
		$kind = $case === 'native-argument' ? 'argument' : 'debate';
		$data = [
			'wd_kind' => $kind, 'wd_creation_date' => '2020-12-31',
			'title' => 'Native SEO title',
			'description' => 'Native SEO description',
			'wd_summary' => 'Native argument summary',
			'image' => 'https://example.test/native.png',
			'image_alt' => 'Native illustration',
			'site_name' => 'Wikidebates', 'locale' => 'en_US',
			'robots' => 'index,follow', 'twitter_site' => '@Wikidebats',
		];
		$parserOutput = new ParserOutput();
		$parserOutput->setExtensionData( Metadata::DATA_KEY, $data );
		Metadata::onOutputPageParserOutput( $out, $parserOutput );
		// Legacy properties must not be emitted by WikiSEO in global-only mode.
		$out->setProperty( 'description', 'Stale WikiSEO description' );
		Metadata::beforePageDisplay( $out );
	} elseif ( !in_array( $case, [ 'ordinary-empty', 'ordinary-persisted' ], true ) ) {
		$props = $ordinary;
		if ( $case === 'ordinary-local-file' || $case === 'ordinary-separate-x' ) {
			$props['image'] = 'SEO-Bench.png';
		}
		if ( $case === 'ordinary-separate-x' ) {
			$props['twitter_image'] = 'SEO-Bench-X.png';
		}
		foreach ( $props as $key => $value ) {
			$out->setProperty( $key, $value );
		}
	}

	$hooks->onBeforePageDisplay( $out, $skin );
	// The actual canonical and native final-head hooks run here.
	$head = implode( "\n", $out->getHeadLinksArray() ) . "\n" . implode( "\n", $out->getHeadItemsArray() );
	return [
		'title' => $out->getHTMLTitle(),
		'index' => $out->getIndexPolicy(), 'follow' => $out->getFollowPolicy(),
		'head' => $head,
	];
}

function verifyAndHash( array $output ): string {
	global $case;
	if ( $case === 'parser' ) {
		$props = $output['properties'];
		same( 'Final title', $props['title'] ?? null, 'Current parser title' );
		same( 'A description', $props['description'] ?? null, 'Previous parser description' );
		same( 'https://example.test/fresh', $props['canonical'] ?? null, 'Current canonical' );
		ksort( $props );
		return hash( 'sha256', serialize( $props ) );
	}
	$head = $output['head'];
	if ( str_starts_with( $case, 'native-' ) ) {
		check( !str_contains( $head, 'Stale WikiSEO description' ), 'Native mode ignores stale SEO' );
		check( !str_contains( $head, 'application/ld+json' ), 'No competing native head JSON-LD' );
		check( str_contains( $head, 'Native SEO title' ), 'Native title is present' );
	} else {
		check( str_contains( $head, 'application/ld+json' ), 'Ordinary page has WikiSEO JSON-LD' );
	}
	if ( $case === 'ordinary-persisted' ) {
		check( str_contains( $head, 'https://example.test/stored' ), 'Persisted canonical is read' );
		check( str_contains( $head, 'Stored SEO description' ), 'Persisted description is read' );
	}
	if ( $case === 'ordinary-local-file' || $case === 'ordinary-separate-x' ) {
		check( str_contains( $head, 'property="og:image:width" content="640"' ),
			'Actual local file dimensions loaded from image catalogue' );
		check( str_contains( $head, 'SEO-Bench.png?version=' ), 'Versioned local image URL' );
		if ( $case === 'ordinary-separate-x' ) {
			check( str_contains( $head, 'SEO-Bench-X.png?version=' ), 'Separate local X image' );
		}
	}
	// Hash only deterministic SEO-bearing output, not unrelated resources or
	// session-dependent head items. Preserve the exact order and markup.
	preg_match_all(
		'~<meta\b[^>]*>|<link\b[^>]*\brel="(?:canonical|alternate)"[^>]*>|<script\b[^>]*type="application/ld\+json"[^>]*>.*?</script>~is',
		$head, $matches
	);
	unset( $output['head'] );
	$output['seo_head'] = $matches[0];
	return hash( 'sha256', serialize( $output ) );
}

function cpuUs(): float {
	$r = getrusage();
	return $r['ru_utime.tv_sec'] * 1e6 + $r['ru_utime.tv_usec'] +
		$r['ru_stime.tv_sec'] * 1e6 + $r['ru_stime.tv_usec'];
}

function median( array $values ): float {
	sort( $values, SORT_NUMERIC );
	$n = count( $values );
	return ( $values[intdiv( $n - 1, 2 )] + $values[intdiv( $n, 2 )] ) / 2;
}

// Actual Database::executeQuery debug records, not counts of PageProps
// service calls. Count in a separate pass to exclude logging from timings.
$counter = new BenchmarkSqlCounter();
// Classification is by table reference, not string literals in IN lists.
$counter->log( 'debug', '', [
	'db_log_category' => 'query', 'sql' => "SELECT pp_value FROM page_props WHERE pp_propname IN ('image','title')",
] );
same( [ 'total' => 1, 'page_props' => 1, 'file_catalog' => 0 ], $counter->counts(), 'Page props containing image name are not file queries' );
$counter->log( 'debug', '', [
	'db_log_category' => 'query', 'sql' => 'SELECT img_width FROM "image" WHERE img_name = ?',
] );
same( [ 'total' => 2, 'page_props' => 1, 'file_catalog' => 1 ], $counter->counts(), 'Quoted image table is recognized' );
foreach ( [ $db, $dbr ] as $connection ) {
	$connection->setLogger( $counter );
	$connection->setFlag( IDatabase::DBO_DEBUG );
	$counter->reset();
	$connection->query( 'SELECT 1', __METHOD__ );
	check( $counter->total >= 1, 'SQL instrumentation must observe a real query on each connection' );
}
$counter->reset();
$first = operation();
$firstSql = $counter->counts();
$hash = verifyAndHash( $first );
unset( $first );
foreach ( [ $db, $dbr ] as $connection ) {
	$connection->clearFlag( IDatabase::DBO_DEBUG );
}

// Warm services and caches, then measure multiple batches without SQL logging.
$iterations = $case === 'parser' ? 12 : 60;
for ( $i = 0; $i < 10; $i++ ) {
	operation();
}
$wall = $cpu = $peak = [];
for ( $batch = 0; $batch < 7; $batch++ ) {
	gc_collect_cycles();
	$memoryStart = memory_get_usage( false );
	memory_reset_peak_usage();
	$cpuStart = cpuUs();
	$start = hrtime( true );
	for ( $i = 0; $i < $iterations; $i++ ) {
		operation();
	}
	$elapsed = hrtime( true ) - $start;
	$cpuElapsed = cpuUs() - $cpuStart;
	$peak[] = memory_get_peak_usage( false ) - $memoryStart;
	$wall[] = $elapsed / $iterations / 1000;
	$cpu[] = $cpuElapsed / $iterations;
}

// Warm SQL measurements: report all logged statements, including those
// that are unrelated to SEO. Bootstrap and fixture creation are excluded.
foreach ( [ $db, $dbr ] as $connection ) {
	$connection->setFlag( IDatabase::DBO_DEBUG );
}
$counter->reset();
$sqlIterations = 20;
for ( $i = 0; $i < $sqlIterations; $i++ ) {
	operation();
}
$warmSql = $counter->counts();
foreach ( [ $db, $dbr ] as $connection ) {
	$connection->clearFlag( IDatabase::DBO_DEBUG );
}
same( $hash, verifyAndHash( operation() ), 'SEO output stays stable across repeated invocations' );

echo 'WD_COMBINED_SEO ' . json_encode( [
	'version' => $label, 'round' => $round, 'case' => $case,
	'php' => PHP_VERSION, 'mediawiki' => MW_VERSION, 'database' => $db->getType(),
	'iterations_per_batch' => $iterations, 'batches' => count( $wall ),
	'wall_us_samples' => $wall, 'cpu_us_samples' => $cpu,
	'peak_increment_bytes_samples' => $peak,
	'wall_median_us' => round( median( $wall ), 3 ),
	'cpu_median_us' => round( median( $cpu ), 3 ),
	'peak_increment_median_bytes' => median( $peak ),
	'first_sql' => $firstSql, 'warm_sql_iterations' => $sqlIterations, 'warm_sql' => $warmSql,
	'seo_sha256' => $hash,
], JSON_THROW_ON_ERROR ) . PHP_EOL;
