<?php

declare( strict_types=1 );

/** Actual MediaWiki ParserOutput and rendering of multiple #seo/<seo> calls. */
require getenv( 'WD_SEO_LAB_BOOTSTRAP' );

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\WikiSEO\Hooks\PageHooks;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Title\Title;

$services = MediaWikiServices::getInstance();
$title = Title::newFromText( 'SEO repeated parser test' );
$output = $services->getParserFactory()->create()->parse(
	'{{#seo:|title=Original title|description=Current description|image=https://example.test/general.png}}' .
	'<seo title="Final title" twitter_image="https://example.test/x.png"></seo>',
	$title,
	ParserOptions::newFromAnon()
);
$props = $output->getPageProperties();
same( 'Final title', $props['title'] ?? null, 'Later tag overrides previous title' );
same( 'Current description', $props['description'] ?? null, 'Earlier description retained' );
same( 'https://example.test/general.png', $props['image'] ?? null, 'Earlier general image retained' );
same( 'https://example.test/x.png', $props['twitter_image'] ?? null, 'Dedicated X image retained' );

$context = new RequestContext();
$context->setTitle( $title );
$out = $context->getOutput();
$out->setArticleFlag( true );
foreach ( $props as $key => $value ) {
	$out->setProperty( $key, $value );
}
$skin = new class( [ 'name' => 'vector' ] ) extends \Skin {
	public function outputPage() {}
};
$hook = new PageHooks( $services->getMainConfig() );
$hook->onBeforePageDisplay( $out, $skin );
$head = implode( "\n", $out->getHeadLinksArray() ) . "\n" . implode( "\n", $out->getHeadItemsArray() );
same( 'Final title', $out->getHTMLTitle(), 'Rendered title uses the current parser values' );
check( str_contains( $head, 'Current description' ), 'Rendered description retained' );
check( str_contains( $head, 'property="og:image" content="https://example.test/general.png"' ), 'Open Graph retains general image' );
check( str_contains( $head, 'name="twitter:image" content="https://example.test/x.png"' ), 'X publishes its dedicated image' );

echo "OK: repeated real parser calls and dedicated X image output\n";
