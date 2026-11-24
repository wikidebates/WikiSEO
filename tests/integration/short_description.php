<?php

declare( strict_types=1 );

/** Real MediaWiki and the production-pinned ShortDescription extension. */
require getenv( 'WD_SEO_LAB_BOOTSTRAP' );

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\WikiSEO\WikiSEO;
use MediaWiki\Extension\Wikidebates\Seo\Metadata;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Title\Title;

function shortOutput( string $wikitext, bool $article = true ): OutputPage {
	$title = Title::newFromText( 'SEO short description integration' );
	$context = new RequestContext();
	$context->setTitle( $title );
	$out = $context->getOutput();
	$out->setArticleFlag( $article );
	$parsed = MediaWikiServices::getInstance()->getParserFactory()->create()->parse(
		$wikitext, $title, ParserOptions::newFromAnon()
	);
	$out->addParserOutputMetadata( $parsed );
	return $out;
}

function loadedSEO( OutputPage $out ): WikiSEO {
	$seo = new WikiSEO();
	$seo->setMetadataFromPageProps( $out );
	return $seo;
}

$short = getenv( 'WD_TEST_LANGUAGE' ) === 'fr'
	? 'Encyclopédie collaborative des arguments'
	: 'Collaborative encyclopedia of arguments';
$out = shortOutput( '{{SHORTDESC:' . $short . '}}' );
same( $short, $out->getProperty( 'shortdesc' ), 'Real ShortDescription hook provides its property' );
$seo = loadedSEO( $out );
same( $short, $seo->getMetadataValue( 'description' ), 'Short description is fallback only' );
$seo->addMetadataToPage( $out );
check( in_array( [ 'description', $short ], $out->getMetaTags(), true ), 'HTML description emitted' );
$head = implode( "\n", $out->getHeadItemsArray() ) . "\n" . implode( "\n", $out->getHeadLinksArray() );
check( str_contains( $head, 'property="og:description" content="' . $short . '"' ), 'Open Graph description emitted' );
check( str_contains( $head, 'name="twitter:description" content="' . $short . '"' ), 'X description emitted' );
$html = $out->getHeadItemsArray()['jsonld-metadata'];
$schema = json_decode( substr( $html, strlen( '<script type="application/ld+json">' ), -strlen( '</script>' ) ),
	true, 512, JSON_THROW_ON_ERROR );
same( $short, $schema['description'], 'JSON-LD description emitted' );

$out = shortOutput( '{{SHORTDESC:' . $short . '}}' );
$out->setProperty( 'description', 'Explicit editorial description' );
same( 'Explicit editorial description', loadedSEO( $out )->getMetadataValue( 'description' ), 'Explicit WikiSEO description wins' );
$out = shortOutput( '{{SHORTDESC:' . $short . '}}' );
$out->setProperty( 'twitter_description', 'Dedicated X description' );
$seo = loadedSEO( $out );
same( $short, $seo->getMetadataValue( 'description' ), 'Dedicated X text does not suppress general fallback' );
same( 'Dedicated X description', $seo->getMetadataValue( 'twitter_description' ), 'Dedicated X text retained' );
$out = shortOutput( 'No description' );
same( null, loadedSEO( $out )->getMetadataValue( 'description' ), 'No invented description' );
$out = shortOutput( '{{SHORTDESC:' . $short . '}}', false );
same( null, loadedSEO( $out )->getMetadataValue( 'description' ), 'No fallback on a nonarticle output' );
$out = shortOutput( '{{SHORTDESC:' . $short . '}}' );
$out->setProperty( 'shortdesc', '   ' );
same( null, loadedSEO( $out )->getMetadataValue( 'description' ), 'Blank description ignored' );
$out->setProperty( 'shortdesc', 'i:42;' );
same( 'i:42;', loadedSEO( $out )->getMetadataValue( 'description' ), 'Short description is plain text, not serialized' );
$out = shortOutput( '{{SHORTDESC:' . $short . '}}' );
$out->setProperty( Metadata::DATA_KEY, [ 'wd_kind' => 'argument', 'title' => 'Native fixture' ] );
loadedSEO( $out )->addMetadataToPage( $out );
check( !isset( $out->getHeadItemsArray()['jsonld-metadata'] ), 'Native mode still skips WikiSEO page metadata' );
echo "OK: ShortDescription integration in " . getenv( 'WD_TEST_LANGUAGE' ) . "\n";
