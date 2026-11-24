<?php

declare( strict_types=1 );

/** Real MediaWiki SQLite lab: exercise canonical priority on existing pages. */
require getenv( 'WD_SEO_LAB_BOOTSTRAP' );

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\WikiSEO\Hooks\PageHooks;
use MediaWiki\Extension\Wikidebates\Seo\Metadata;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Title\Title;

$services = MediaWikiServices::getInstance();
$db = $services->getConnectionProvider()->getPrimaryDatabase();
$name = 'SEO_Canonical_Integration';
$db->newInsertQueryBuilder()
	->insertInto( 'page' )
	->row( [
		'page_id' => 41,
		'page_namespace' => 0,
		'page_title' => $name,
		'page_random' => 0.5,
		'page_touched' => '20261124100000',
		'page_latest' => 1,
		'page_len' => 100,
		'page_content_model' => 'wikitext',
	] )
	->caller( __METHOD__ )
	->execute();
$db->newInsertQueryBuilder()
	->insertInto( 'page_props' )
	->rows( [
		[ 'pp_page' => 41, 'pp_propname' => 'canonical', 'pp_value' => 'https://example.test/stored' ],
		[ 'pp_page' => 41, 'pp_propname' => 'description', 'pp_value' => 'Persisted description' ],
	] )
	->caller( __METHOD__ )
	->execute();

$title = Title::newFromText( $name );
same( 41, $title->getArticleID(), 'Canonical test title resolves to a real SQLite page' );
$hooks = new PageHooks( $services->getMainConfig() );

function canonicalOutput( Title $title ): \MediaWiki\Output\OutputPage {
	$context = new RequestContext();
	$context->setTitle( $title );
	$out = $context->getOutput();
	$out->setArticleFlag( true );
	return $out;
}

function renderCanonical( PageHooks $hooks, \MediaWiki\Output\OutputPage $out ): string {
	$tags = [ 17 => '<link rel="canonical" href="https://example.test/core">' ];
	$hooks->onOutputPageAfterGetHeadLinksArray( $tags, $out );
	same( 1, count( $tags ), 'Only one canonical link remains' );
	return $tags[17];
}

$out = canonicalOutput( $title );
check( str_contains( renderCanonical( $hooks, $out ), 'https://example.test/stored' ),
	'Read persisted canonical when current output has no SEO properties' );
$out->setProperty( 'description', 'Current preview description' );
check( str_contains( renderCanonical( $hooks, $out ), 'https://example.test/core' ),
	'Current preview description suppresses stale persisted canonical' );
$out->setProperty( 'canonical', 'https://example.test/fresh' );
check( str_contains( renderCanonical( $hooks, $out ), 'https://example.test/fresh' ),
	'Current canonical has priority' );
$out->setProperty( 'canonical', 'javascript:alert(1)' );
check( str_contains( renderCanonical( $hooks, $out ), 'https://example.test/core' ),
	'Unsafe canonical leaves the core canonical unchanged' );
$out->setProperty( 'canonical', serialize( 'https://example.test/serialized' ) );
check( str_contains( renderCanonical( $hooks, $out ), 'https://example.test/serialized' ),
	'Serialized current property is decoded as before' );

$parser = new ParserOutput();
$parser->setExtensionData( Metadata::DATA_KEY, [ 'wd_kind' => 'debate', 'title' => 'Native title' ] );
Metadata::onOutputPageParserOutput( $out, $parser );
check( str_contains( renderCanonical( $hooks, $out ), 'https://example.test/core' ),
	'Native page leaves MediaWiki canonical untouched' );

echo "OK: canonical precedence and policy on a real MediaWiki SQLite page\n";
