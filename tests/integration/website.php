<?php

declare( strict_types=1 );

/** Real MediaWiki lab: opt-in site-name graph on the main page only. */
require getenv( 'WD_SEO_LAB_BOOTSTRAP' );

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\WikiSEO\Generator\Plugins\SchemaOrg;
use MediaWiki\Title\Title;

function websiteGraph( Title $title, bool $noindex = false ): array {
	$context = new RequestContext();
	$context->setTitle( $title );
	$out = $context->getOutput();
	$out->setArticleFlag( true );
	if ( $noindex ) {
		$out->setIndexPolicy( 'noindex' );
	}
	$generator = new SchemaOrg();
	$generator->init( [ 'title' => 'Editorial page title', 'description' => 'Editorial description' ], $out );
	$generator->addMetadata();
	$html = $out->getHeadItemsArray()['jsonld-metadata'];
	return json_decode( substr( $html, strlen( '<script type="application/ld+json">' ), -strlen( '</script>' ) ),
		true, 512, JSON_THROW_ON_ERROR );
}

$main = Title::newMainPage();
$ordinary = Title::newFromText( 'SEO ordinary page' );
$GLOBALS['wgWikiSeoWebsiteUrl'] = null;
same( 'WebPage', websiteGraph( $main )['@type'], 'Disabled option preserves legacy output' );

foreach ( [
	[ 'https://fr.wikidebates.org/', 'Wikidébats' ],
	[ 'https://en.wikidebates.org', 'Wikidebates' ],
] as [ $url, $name ] ) {
	$GLOBALS['wgWikiSeoWebsiteUrl'] = $url;
	$GLOBALS['wgWikiSeoWebsiteName'] = $name;
	$graph = websiteGraph( $main );
	$root = rtrim( $url, '/' ) . '/';
	same( 'https://schema.org', $graph['@context'], 'Shared JSON-LD context' );
	same( [ '@type' => 'WebSite', '@id' => $root . '#website', 'name' => $name, 'url' => $root ],
		$graph['@graph'][0], 'Site node uses explicit site name and root URL' );
	$page = $graph['@graph'][1];
	same( 'WebPage', $page['@type'], 'Page type retained' );
	same( 'Editorial page title', $page['name'], 'Editorial title retained' );
	same( 'Editorial description', $page['description'], 'Editorial description retained' );
	same( [ '@id' => $root . '#website' ], $page['isPartOf'], 'Page references site node' );
	same( 'WebPage', websiteGraph( $ordinary )['@type'], 'Ordinary page output unchanged' );
	same( 'WebPage', websiteGraph( $main, true )['@type'], 'No site node on noindex page' );
}

foreach ( [ 'https://example.test/wiki/Home', 'https://example.test/?q=1',
	'https://example.test/#fragment', 'https://user@example.test/', 'javascript:alert(1)' ] as $invalid ) {
	$GLOBALS['wgWikiSeoWebsiteUrl'] = $invalid;
	same( 'WebPage', websiteGraph( $main )['@type'], 'Invalid root URL rejected' );
}
$organizationUrl = 'https://fr.wikidebates.org/wiki/Wikid%C3%A9bats:Laboratoire_du_d%C3%A9bat_m%C3%A9thodique';
$organization = [
	'@type' => 'Organization',
	'@id' => $organizationUrl . '#organization',
	'name' => 'Le Laboratoire du débat méthodique',
	'url' => $organizationUrl,
	'alternateName' => 'The Laboratory of Methodical Debate',
];
$GLOBALS['wgWikiSeoPublisher'] = [
	'name' => $organization['name'],
	'url' => $organizationUrl,
	'alternateName' => $organization['alternateName'],
];
foreach ( [ 'https://fr.wikidebates.org/', 'https://en.wikidebates.org/' ] as $url ) {
	$GLOBALS['wgWikiSeoWebsiteUrl'] = $url;
	$graph = websiteGraph( $main )['@graph'];
	same( 3, count( $graph ), 'Exactly one explicit organization node' );
	same( $organization, $graph[2], 'Same organization across language sites, no invented logo' );
	same( [ '@id' => $organization['@id'] ], $graph[0]['publisher'], 'Site publisher reference' );
	same( [ '@id' => $organization['@id'] ], $graph[1]['publisher'], 'Page publisher reference' );
	same( $organization, websiteGraph( $ordinary )['publisher'], 'Ordinary page publisher retains identity' );
}
$GLOBALS['wgWikiSeoPublisher']['logo'] = 'https://example.test/organization-logo.png';
same( [ '@type' => 'ImageObject', 'url' => 'https://example.test/organization-logo.png' ],
	websiteGraph( $ordinary )['publisher']['logo'], 'Explicit publisher logo' );
$GLOBALS['wgWikiSeoPublisher']['logo'] = 'javascript:alert(1)';
check( !isset( websiteGraph( $ordinary )['publisher']['logo'] ), 'Invalid organization logo omitted' );
foreach ( [ [], 'invalid', [ 'name' => '', 'url' => $organizationUrl ],
	[ 'name' => 'Invalid URL', 'url' => 'https://example.test/#other' ],
	[ 'name' => 'Credentials', 'url' => 'https://user@example.test/' ] ] as $invalid ) {
	$GLOBALS['wgWikiSeoPublisher'] = $invalid;
	check( !isset( websiteGraph( $ordinary )['publisher'] ), 'Invalid explicit publisher omitted' );
}
$GLOBALS['wgWikiSeoPublisher'] = null;
check( isset( websiteGraph( $ordinary )['publisher']['name'] ), 'Legacy default publisher restored' );

echo "OK: optional WebSite graph, explicit organization and unchanged default metadata\n";
