<?php

declare( strict_types=1 );

/** Real MW 1.43: exercise the final head and count SQL during the new feature. */
require getenv( 'WD_SEO_LAB_BOOTSTRAP' );

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\WikiSEO\Generator\MetaTag;
use MediaWiki\Extension\WikiSEO\Hooks\PageHooks;
use MediaWiki\Extension\WikiSEO\InterlanguageHreflang;
use MediaWiki\Extension\Wikidebates\Seo\Metadata;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Title\Title;
use Psr\Log\AbstractLogger;

$language = getenv( 'WD_TEST_LANGUAGE' ) ?: 'en';
$routes = [];
$titles = [
	'fr' => 'Wikidébats:École et débat ?',
	'en' => 'Wikidebates:School & debate',
	'de' => 'Wikidebates:Schule und Debatte',
	'es' => 'Wikidebates:Escuela y debate',
	'it' => 'Wikidebates:Scuola e dibattito',
	'pt' => 'Wikidebates:Escola e debate',
];
foreach ( $titles as $code => $target ) {
	$routes[$code] = 'https://' . $code . '.example.test/wiki/$1';
}
// The bootstrap's local site uses example.test.
$routes[$language] = 'https://example.test/wiki/$1';
// Use an ordinary title for the local page so its canonical namespace is
// not dependent on the bootstrap's project namespace setting.
$titles[$language] = 'Ordinary ' . $language . ' & accents é';
$canonical = str_replace( '$1', wfUrlencode( str_replace( ' ', '_', $titles[$language] ) ), $routes[$language] );
$expected = [];
foreach ( $titles as $code => $target ) {
	$expected[$code] = str_replace( '$1', wfUrlencode( str_replace( ' ', '_', $target ) ), $routes[$code] );
}
ksort( $expected );
$links = [];
foreach ( $titles as $code => $target ) {
	if ( $code !== $language ) {
		$links[] = $code . ':' . $target;
	}
}
$links[] = 'ja:Not in this farm';
$links[] = 'en-us:Regional variant';
$links[] = 'x-default:Not a selector';

$GLOBALS['wgWikiSeoInterlanguageArticleUrls'] = $routes;
$GLOBALS['wgWikiSeoDefaultLanguage'] = null;
$GLOBALS['wgHideInterlanguageLinks'] = true;
$GLOBALS['wgEnableCanonicalServerLink'] = true;
$services = MediaWikiServices::getInstance();
$hooks = new PageHooks( $services->getMainConfig() );

function fixture( string $name, array $links, array $params = [] ): OutputPage {
	$context = new RequestContext();
	$context->setTitle( Title::newFromText( $name ) );
	$context->setRequest( new \FauxRequest( $params ) );
	$out = $context->getOutput();
	$out->setArticleFlag( true );
	$out->setRevisionId( 123 );
	$out->setRevisionIsCurrent( true );
	$out->setProperty( 'description', 'Fixture description' );
	$out->addLanguageLinks( $links );
	// Prime MediaWiki's regular action resolution as in getHeadLinksArray().
	$context->getActionName();
	return $out;
}

function initialTags( string $canonical ): array {
	return [
		'link-canonical' => Html::element( 'link', [ 'rel' => 'canonical', 'href' => $canonical ] ),
		'core-variant' => Html::element( 'link', [ 'rel' => 'alternate', 'hreflang' => 'x-default', 'href' => $canonical ] ),
		'feed' => Html::element( 'link', [ 'rel' => 'alternate', 'type' => 'application/rss+xml', 'href' => '/feed' ] ),
	];
}

function cluster( array $tags ): array {
	$result = [];
	foreach ( $tags as $tag ) {
		if ( preg_match( '~^<link\b.*\bhreflang="([^"]+)"~', $tag, $lang ) &&
			preg_match( '~\bhref="([^"]+)"~', $tag, $href )
		) {
			check( !isset( $result[$lang[1]] ), 'No duplicate language in head' );
			$result[$lang[1]] = html_entity_decode( $href[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
	}
	ksort( $result );
	return $result;
}

$out = fixture( $titles[$language], $links );
$out->addHeadItem( 'legacy-regional', '<link hreflang="en-US" href="https://old.example.test" rel="alternate">' );
$out->addHeadItem( 'unrelated', '<link rel="alternate" media="only screen" href="https://mobile.example.test">' );
$tags = initialTags( $canonical );
$hooks->onOutputPageAfterGetHeadLinksArray( $tags, $out );
same( $expected, cluster( $tags ), 'All six generic language editions, including self' );
same( '', $out->getHeadItemsArray()['legacy-regional'], 'Legacy head item suppressed' );
check( isset( $tags['feed'] ) && $out->getHeadItemsArray()['unrelated'] !== '', 'Unrelated alternates preserved' );
same( $canonical, html_entity_decode( preg_replace( '~^.*href="([^"]+)".*$~', '$1', $tags['link-canonical'] ) ), 'Canonical unchanged' );
same( $links, $out->getLanguageLinks(), 'UI language mappings unchanged' );
$hooks->onOutputPageAfterGetHeadLinksArray( $tags, $out );
same( $expected, cluster( $tags ), 'Repeated head assembly stays idempotent' );

foreach ( [ 'debate', 'argument' ] as $kind ) {
	$out = fixture( $titles[$language], $links );
	$parser = new ParserOutput();
	$parser->setExtensionData( Metadata::DATA_KEY, [ 'wd_kind' => $kind, 'title' => 'Native fixture' ] );
	Metadata::onOutputPageParserOutput( $out, $parser );
	$tags = initialTags( $canonical );
	$hooks->onOutputPageAfterGetHeadLinksArray( $tags, $out );
	same( $expected, cluster( $tags ), "Native $kind global-only mode" );
}

$out = fixture( $titles[$language], $links );
$generator = new MetaTag();
$generator->init( [ 'hreflang_en-us' => 'https://old.example.test' ], $out );
$generator->addMetadata();
same( [], cluster( $out->getHeadItemsArray() ), 'Automatic mode does not emit historical manual tags' );

foreach ( [
	'noindex' => static function ( OutputPage $out, array &$tags ) { $out->setIndexPolicy( 'noindex' ); },
	'googlebot' => static function ( OutputPage $out, array &$tags ) { $tags['bot'] = '<meta name="googlebot" content="noindex,follow">'; },
	'old revision' => static function ( OutputPage $out, array &$tags ) { $out->setRevisionIsCurrent( false ); },
	'nonarticle' => static function ( OutputPage $out, array &$tags ) { $out->setArticleFlag( false ); },
	'missing revision' => static function ( OutputPage $out, array &$tags ) { $out->setRevisionId( null ); },
	'canonical elsewhere' => static function ( OutputPage $out, array &$tags ) { $out->setProperty( 'canonical', 'https://other.example.test/wiki/Alias' ); },
	'absent canonical' => static function ( OutputPage $out, array &$tags ) { unset( $tags['link-canonical'] ); },
	'no translations' => static function ( OutputPage $out, array &$tags ) { $out->getMetadata()->setLanguageLinks( [] ); },
] as $label => $mutate ) {
	$out = fixture( $titles[$language], $links );
	$tags = initialTags( $canonical );
	$mutate( $out, $tags );
	$hooks->onOutputPageAfterGetHeadLinksArray( $tags, $out );
	same( [], cluster( $tags ), "Excluded: $label" );
}
foreach ( [ [ 'oldid' => '123' ], [ 'diff' => '123' ], [ 'action' => 'history' ], [ 'mobileaction' => 'toggle_view_mobile' ] ] as $params ) {
	$out = fixture( $titles[$language], $links, $params );
	$tags = initialTags( $canonical );
	$hooks->onOutputPageAfterGetHeadLinksArray( $tags, $out );
	same( [], cluster( $tags ), 'Noncanonical request excluded' );
}

$homeRoutes = $routes;
$homeRoutes['fr'] = [ 'article' => $routes['fr'], 'mainPageTitle' => 'Wikidébats:Accueil', 'mainPageUrl' => 'https://fr.example.test/' ];
if ( $language !== 'fr' ) {
	$result = InterlanguageHreflang::alternates( [ 'fr:Wikidébats:Accueil' ], $homeRoutes, $language, $canonical );
	same( 'https://fr.example.test/', $result['fr'], 'Configured canonical main page route' );
}
// Production stores Project: titles, but each edition publishes a localized
// project namespace. Do not treat an arbitrary colon as a namespace.
$projectPrefixes = [
	'fr' => 'Wikidébats', 'en' => 'Wikidebates', 'de' => 'Wikidebatten',
	'es' => 'Wikidebates', 'it' => 'Wikidibattiti', 'pt' => 'Wikidebates',
];
$aliasRoutes = $routes;
$aliasLinks = [];
$aliasExpected = [ $language => $canonical ];
foreach ( $projectPrefixes as $code => $prefix ) {
	if ( $code === $language ) {
		continue;
	}
	$aliasRoutes[$code] = [
		'article' => $routes[$code],
		'namespaceAliases' => [ 'Project' => $prefix, $prefix => $prefix ],
	];
	$aliasLinks[] = $code . ':Project: École: débat';
	$aliasLinks[] = $code . ':' . $prefix . ':École: débat';
	$aliasExpected[$code] = str_replace( '$1', wfUrlencode( $prefix . ':École:_débat' ), $routes[$code] );
}
ksort( $aliasExpected );
same( $aliasExpected, InterlanguageHreflang::alternates( $aliasLinks, $aliasRoutes, $language, $canonical ), 'Canonical remote namespace aliases and duplicate equivalence' );
$out = fixture( $titles[$language], $aliasLinks );
$tags = initialTags( $canonical );
InterlanguageHreflang::apply( $tags, $out, $aliasRoutes, $language, true );
same( $aliasExpected, cluster( $tags ), 'Localized canonical aliases in the actual head' );
$other = $language === 'en' ? 'fr' : 'en';
$result = InterlanguageHreflang::alternates( [ $other . ':Topic: A colon' ], $aliasRoutes, $language, $canonical );
same( str_replace( '$1', wfUrlencode( 'Topic:_A_colon' ), $routes[$other] ), $result[$other], 'Unknown prefix remains part of an ordinary title' );

// Interlanguage now accepts the full MediaWiki whitespace syntax. The
// corresponding head URLs must be identical, including receiving-only
// namespace aliases and explicitly verified first-letter case rules.
$normalizedRoutes = $aliasRoutes;
$normalizedRoutes[$other]['namespaceAliases'] += [
	'Template' => $other === 'fr' ? 'Modèle' : 'Template',
	'Custom Alias' => $other === 'fr' ? 'Modèle' : 'Template',
	'Module' => 'Module',
];
$templatePrefix = $other === 'fr' ? 'Modèle' : 'Template';
$normalizedRoutes[$other]['namespaceCase'] = [ '' => 'first-letter', $templatePrefix => 'first-letter', 'Module' => 'case-sensitive' ];
$variants = [
	$other . ':Template:Argument with spaces',
	$other . ': Template: Argument__with_spaces ',
	$other . ":__custom__alias__:__argument\u{00A0}with spaces",
	$other . ': :Template:argument with spaces#',
];
$normalizedExpected = [ $language => $canonical, $other => str_replace( '$1', wfUrlencode( $templatePrefix . ':Argument_with_spaces' ), $routes[$other] ) ];
ksort( $normalizedExpected );
same( $normalizedExpected, InterlanguageHreflang::alternates( $variants, $normalizedRoutes, $language, $canonical ), 'All legitimate remote title spellings produce one alternate' );
$out = fixture( $titles[$language], $variants );
$tags = initialTags( $canonical );
InterlanguageHreflang::apply( $tags, $out, $normalizedRoutes, $language, true );
same( $normalizedExpected, cluster( $tags ), 'Normalized variants in the final head' );
$result = InterlanguageHreflang::alternates( [ $other . ': topic: A__colon' ], $normalizedRoutes, $language, $canonical );
same( str_replace( '$1', wfUrlencode( 'Topic:_A_colon' ), $routes[$other] ), $result[$other], 'Normalize whitespace, not unknown namespace-like prefixes' );
$result = InterlanguageHreflang::alternates( [ $other . ': Module:lowercase' ], $normalizedRoutes, $language, $canonical );
same( str_replace( '$1', wfUrlencode( 'Module:lowercase' ), $routes[$other] ), $result[$other], 'Configured case-sensitive namespace preserved' );
$result = InterlanguageHreflang::alternates( [ $other . ':Template:alpha', $other . ':Template:beta' ], $normalizedRoutes, $language, $canonical );
same( [], $result, 'Distinct normalized targets for one language remain ambiguous' );
same( [], InterlanguageHreflang::alternates( [ $other . ':Template:../Page', $other . ':Template:Page#Section' ], $normalizedRoutes, $language, $canonical ), 'Reject partial and unsafe normalized titles' );

// Matching aliases must still be fully validated, and conflicting normalized
// keys must not be resolved by configuration order.

$collisionRoutes = $normalizedRoutes;
$collisionRoutes[$other]['namespaceAliases'] = [
	'Template' => $templatePrefix,
	' TEMPLATE ' => ' ' . $templatePrefix . ' ',
	'template_' => 'Invalid:namespace',
	'Unrelated' => 'Category',
	'Invalid key' => false,
];
$expectedAlias = [ $language => $canonical, $other => str_replace( '$1', wfUrlencode( $templatePrefix . ':Argument_with_spaces' ), $routes[$other] ) ];
ksort( $expectedAlias );
$aliasTarget = [ $other . ':Template:Argument with spaces' ];
same( $expectedAlias, InterlanguageHreflang::alternates( $aliasTarget, $collisionRoutes, $language, $canonical ), 'Equivalent alias spellings agree; invalid destinations are ignored' );
$collisionRoutes[$other]['namespaceAliases']['_template'] = 'Category';
same( [], InterlanguageHreflang::alternates( $aliasTarget, $collisionRoutes, $language, $canonical ), 'Conflicting valid destinations suppress the alternate' );
$collisionRoutes[$other]['namespaceAliases'] = array_reverse( $collisionRoutes[$other]['namespaceAliases'], true );
same( [], InterlanguageHreflang::alternates( $aliasTarget, $collisionRoutes, $language, $canonical ), 'Conflicting destinations remain ambiguous in reverse order' );
same( $expectedAlias, InterlanguageHreflang::alternates( $aliasTarget, $normalizedRoutes, $language, $canonical ), 'Independent calls preserve their own configuration' );

$bad = [ 'zz' => 'javascript:$1', 'ja' => 'https://user@other.example.test/$1', 'nl' => 'https://$1.example.test/wiki/' ];
same( [], InterlanguageHreflang::alternates( [ 'zz:Page', 'ja:Page', 'nl:Page', 'de:Page#section', 'es:../Page' ], $routes + $bad, $language, $canonical ), 'Reject invalid routes and partial page links' );

// Use real database query logging with a positive control, after normal
// OutputPage preparation. Neither the cold interwiki table nor the UI
// resolver should be needed by the new final-head operation.
$logger = new class extends AbstractLogger {
	public int $queries = 0;
	public function log( $level, $message, array $context = [] ): void {
		if ( isset( $context['sql'] ) ) {
			$this->queries++;
		}
	}
};
$provider = $services->getConnectionProvider();
$primary = $provider->getPrimaryDatabase();
$replica = $provider->getReplicaDatabase();
foreach ( [ $primary, $replica ] as $db ) {
	$db->setLogger( $logger );
	$db->setFlag( DBO_DEBUG );
}
$primary->query( 'SELECT 1', __METHOD__ );
check( $logger->queries > 0, 'SQL instrumentation positive control' );
$out = fixture( $titles[$language], $variants );
$logger->queries = 0;
$iterations = 1000;
$started = hrtime( true );
for ( $i = 0; $i < $iterations; $i++ ) {
	$tags = initialTags( $canonical );
	InterlanguageHreflang::apply( $tags, $out, $normalizedRoutes, $language, true );
}
$elapsed = ( hrtime( true ) - $started ) / 1e6;
same( 0, $logger->queries, 'No extra real SQL queries in final hreflang generation' );
same( $normalizedExpected, cluster( $tags ), 'Benchmark retains normalized cluster' );
printf( "PASS hreflang %s: %d iterations, %0.3f ms total, %d additional SQL queries\n", $language, $iterations, $elapsed, $logger->queries );
