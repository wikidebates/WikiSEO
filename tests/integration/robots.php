<?php

declare( strict_types=1 );

/** Real MediaWiki: editor input cannot relax index/follow restrictions. */
require getenv( 'WD_SEO_LAB_BOOTSTRAP' );

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\WikiSEO\Generator\MetaTag;
use MediaWiki\Extension\Wikidebates\Seo\Metadata;
use MediaWiki\Html\Html;
use MediaWiki\Output\OutputPage;
use MediaWiki\Title\Title;

function robotOutput( string $title = 'SEO robot policy fixture', array $params = [] ): OutputPage {
	$context = new RequestContext();
	$context->setTitle( Title::newFromText( $title ) );
	$context->setRequest( new \FauxRequest( $params ) );
	$out = $context->getOutput();
	$out->setArticleFlag( true );
	$out->setProperty( 'description', 'Fixture description' );
	return $out;
}

function applyEditorialRobots( OutputPage $out, mixed $policy ): void {
	$generator = new MetaTag();
	$generator->init( [ 'robots' => $policy ], $out );
	$generator->addMetadata();
}

$out = robotOutput();
$out->setIndexPolicy( 'noindex' );
$out->setFollowPolicy( 'nofollow' );
applyEditorialRobots( $out, 'index,follow' );
same( 'noindex,nofollow', $out->getRobotPolicy(), 'Existing restrictive MediaWiki policies are kept' );

$GLOBALS['wgWikiSeoNoindexPageTitles'] = [ 'SEO excluded fixture' ];
foreach ( [
	[ 'SEO excluded fixture', [] ],
	[ 'SEO robot policy fixture', [ 'oldid' => '123' ] ],
	[ 'SEO robot policy fixture', [ 'diff' => '123' ] ],
	[ 'SEO robot policy fixture', [ 'action' => 'history' ] ],
] as [ $title, $params ] ) {
	$out = robotOutput( $title, $params );
	applyEditorialRobots( $out, 'index,follow' );
	same( 'noindex,follow', $out->getRobotPolicy(), 'URL/title exclusion cannot be overridden' );
}

foreach ( [
	[ 'index,follow', 'index,follow' ],
	[ 'noindex', 'noindex,follow' ],
	[ 'nofollow', 'index,nofollow' ],
	[ 'NOFOLLOW, NOINDEX', 'noindex,nofollow' ],
	[ 'none', 'noindex,nofollow' ],
	[ 'all', 'index,follow' ],
	[ [ 'index' ], 'index,follow' ],
] as [ $policy, $expected ] ) {
	$out = robotOutput();
	applyEditorialRobots( $out, $policy );
	same( $expected, $out->getRobotPolicy(), 'Valid restrictions are independent of order and case' );
}

function previewTag( OutputPage $out ): string {
	return $out->getHeadLinksArray()['meta-robots'];
}

function expectedTag( string $content ): string {
	return Html::element( 'meta', [ 'name' => 'robots', 'content' => $content ] );
}

$GLOBALS['wgWikiSeoLargeImagePreview'] = false;
same( expectedTag( 'max-image-preview:standard' ), previewTag( robotOutput() ), 'Default preview unchanged' );
$GLOBALS['wgWikiSeoLargeImagePreview'] = true;
same( expectedTag( 'max-image-preview:large' ), previewTag( robotOutput() ), 'Opt-in large preview on ordinary view' );

foreach ( [
	[ [ 'max-image-preview' => 'none' ], 'max-image-preview:none' ],
	[ [ 'max-snippet' => 20 ], 'max-image-preview:standard,max-snippet:20' ],
	[ [ 'noimageindex' ], 'max-image-preview:standard,noimageindex' ],
] as [ $options, $expected ] ) {
	$out = robotOutput();
	$out->setRobotsOptions( $options );
	same( expectedTag( $expected ), previewTag( $out ), 'Existing robots options are not replaced' );
}
$out = robotOutput();
$out->setIndexPolicy( 'noindex' );
same( expectedTag( 'noindex,follow,max-image-preview:standard' ), previewTag( $out ), 'Noindex output unchanged' );
$out = robotOutput();
$out->setFollowPolicy( 'nofollow' );
same( expectedTag( 'index,nofollow,max-image-preview:standard' ), previewTag( $out ), 'Nofollow output unchanged' );
$out = robotOutput();
$out->setArticleFlag( false );
same( expectedTag( 'max-image-preview:standard' ), previewTag( $out ), 'Nonarticle output unchanged' );
$out = robotOutput();
$out->setProperty( Metadata::DATA_KEY, [ 'wd_kind' => 'debate', 'title' => 'Native fixture' ] );
same( expectedTag( 'max-image-preview:standard' ), previewTag( $out ), 'Native global-only output unchanged' );

echo "OK: restrictive robots policy and opt-in image preview on real MediaWiki\n";
