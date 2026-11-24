<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikiSEO\Generator\Plugins;

use Exception;
use InvalidArgumentException;
use MediaWiki\Extension\WikiSEO\Generator\AbstractBaseGenerator;
use MediaWiki\Extension\WikiSEO\Generator\GeneratorInterface;
use MediaWiki\Extension\WikiSEO\MetaValues;
use MediaWiki\Extension\WikiSEO\WikiSEO;
use OutputPage;

/** Schema.org JSON-LD for general pages, with article-specific fields opt-in. */
class SchemaOrg extends AbstractBaseGenerator implements GeneratorInterface {
	protected $tags = [
		'type', 'schema_type', 'title', 'description', 'keywords', 'image', 'author',
		'schema_author', 'published_time', 'modified_time', 'section', 'canonical',
	];

	public function init( array $metadata, OutputPage $out ): void {
		$this->metadata = $metadata;
		$this->outputPage = $out;
	}

	public function addMetadata(): void {
		$type = MetaValues::schemaType( $this->metadata );
		$isArticle = MetaValues::isArticleSchemaType( $type );
		$title = $this->metadata['title'] ?? $this->outputPage->getHTMLTitle();
		$meta = [
			'@context' => 'https://schema.org',
			'@type' => $type,
			'name' => $title,
		];

		$page = $this->outputPage->getTitle();
		if ( $page !== null ) {
			$url = MetaValues::httpUrl( $this->metadata['canonical'] ?? null ) ??
				WikiSEO::protocolizeUrl( $page->getFullURL(), $this->outputPage->getRequest() );
			$meta['url'] = $url;
			if ( $isArticle ) {
				$meta['mainEntityOfPage'] = $url;
			}
		}

		if ( $isArticle ) {
			$meta['headline'] = $title;
		}
		foreach ( [ 'description', 'keywords' ] as $key ) {
			if ( isset( $this->metadata[$key] ) && $this->metadata[$key] !== '' ) {
				$meta[$key] = $this->metadata[$key];
			}
		}
		$language = $this->getConfigValue( 'LanguageCode' );
		if ( is_string( $language ) && $language !== '' ) {
			$meta['inLanguage'] = $language;
		}

		if ( $isArticle && !empty( $this->metadata['section'] ) ) {
			$meta['articleSection'] = $this->metadata['section'];
		}
		foreach ( [ 'published_time' => 'datePublished', 'modified_time' => 'dateModified' ] as $from => $to ) {
			$date = MetaValues::date( $this->metadata[$from] ?? null );
			if ( $date !== null ) {
				$meta[$to] = $date;
			}
		}

		$image = $this->getImageMetadata();
		if ( $image !== null ) {
			$meta['image'] = $image;
		}

		// Being the publisher of a page does not imply being its author.
		$publisher = $this->getPublisherMetadata();
		if ( $publisher !== null ) {
			$meta['publisher'] = $publisher;
		}
		$author = $this->metadata['schema_author'] ?? $this->metadata['author'] ?? null;
		if ( $isArticle && is_string( $author ) && trim( $author ) !== '' ) {
			$meta['author'] = [ '@type' => 'Person', 'name' => trim( $author ) ];
		}

		$website = $this->getWebsiteMetadata();
		if ( $website !== null ) {
			// Keep the existing page node and add a single, identified site
			// node in the same JSON-LD document. The page title is not the
			// name of the site.
			unset( $meta['@context'] );
			$meta['isPartOf'] = [ '@id' => $website['@id'] ];
			$graph = [ $website, $meta ];
			if ( isset( $publisher['@id'] ) ) {
				// Both creative works describe the same explicitly configured
				// publisher. Do not emit two independent organizations.
				$reference = [ '@id' => $publisher['@id'] ];
				$graph[0]['publisher'] = $reference;
				$graph[1]['publisher'] = $reference;
				$graph[] = $publisher;
			}
			$meta = [
				'@context' => 'https://schema.org',
				'@graph' => $graph,
			];
		}

		$json = MetaValues::jsonLd( $meta );
		if ( $json !== null ) {
			$this->outputPage->addHeadItem( 'jsonld-metadata',
				'<script type="application/ld+json">' . $json . '</script>' );
		}
	}

	public function getAllowedParameterNames(): array {
		return $this->tags;
	}

	/** Optional site-name markup, only on the indexable main page. */
	private function getWebsiteMetadata(): ?array {
		$url = MetaValues::httpUrl( $this->getConfigValue( 'WikiSeoWebsiteUrl' ) );
		if ( $url === null ) {
			return null;
		}
		$parts = parse_url( $url );
		if ( !is_array( $parts ) || !in_array( $parts['path'] ?? '', [ '', '/' ], true ) ||
			isset( $parts['query'] ) || isset( $parts['fragment'] ) ||
			isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		if ( !$this->outputPage->isArticle() || $this->outputPage->getIndexPolicy() === 'noindex' ||
			!$this->outputPage->getTitle()?->isMainPage() ) {
			return null;
		}
		$name = $this->getConfigValue( 'WikiSeoWebsiteName' );
		if ( !is_string( $name ) || trim( $name ) === '' ) {
			$name = $this->getConfigValue( 'Sitename' );
		}
		if ( !is_string( $name ) || trim( $name ) === '' ) {
			return null;
		}
		$url = rtrim( $url, '/' ) . '/';
		return [
			'@type' => 'WebSite',
			'@id' => $url . '#website',
			'name' => trim( $name ),
			'url' => $url,
		];
	}

	private function getImageMetadata(): ?array {
		$this->setFallbackImageIfEnabled();
		if ( !empty( $this->metadata['image'] ) ) {
			$image = (string)$this->metadata['image'];
			try {
				return [ '@type' => 'ImageObject' ] + $this->getFileInfo( $this->getFileObject( $image ) );
			} catch ( InvalidArgumentException $e ) {
				$url = MetaValues::httpUrl( $image );
				if ( $url !== null ) {
					return [ '@type' => 'ImageObject', 'url' => $url ];
				}
			}
		}
		if ( $this->getConfigValue( 'WikiSeoDisableLogoFallbackImage' ) === true ) {
			return null;
		}
		try {
			$logo = $this->getWikiLogo();
		} catch ( Exception $e ) {
			return null;
		}
		return is_string( $logo ) && $logo !== ''
			? [ '@type' => 'ImageObject', 'url' => $logo ] : null;
	}

	private function getPublisherMetadata(): ?array {
		$configured = $this->getConfigValue( 'WikiSeoPublisher' );
		if ( $configured !== null ) {
			return $this->getConfiguredPublisher( $configured );
		}
		$name = $this->getConfigValue( 'Sitename' );
		if ( !is_string( $name ) || trim( $name ) === '' ) {
			return null;
		}
		$publisher = [ '@type' => 'Organization', 'name' => $name ];
		$server = MetaValues::httpUrl( $this->getConfigValue( 'Server' ) );
		if ( $server !== null ) {
			$publisher['url'] = $server;
		}
		try {
			$logo = $this->getWikiLogo();
			if ( is_string( $logo ) && $logo !== '' ) {
				$publisher['logo'] = [ '@type' => 'ImageObject', 'url' => $logo ];
			}
		} catch ( Exception $e ) {
			// The publisher remains valid even when its logo cannot be resolved.
		}
		return $publisher;
	}

	/**
	 * A site's visual identity is not necessarily its publisher's identity.
	 * Do not invent a publisher logo, even when a wiki logo is available.
	 */
	private function getConfiguredPublisher( mixed $configured ): ?array {
		if ( !is_array( $configured ) ) {
			return null;
		}
		$name = $configured['name'] ?? null;
		$url = MetaValues::httpUrl( $configured['url'] ?? null );
		if ( !is_string( $name ) || trim( $name ) === '' || $url === null ) {
			return null;
		}
		$parts = parse_url( $url );
		if ( !is_array( $parts ) || isset( $parts['fragment'] ) ||
			isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		$publisher = [
			'@type' => 'Organization',
			'@id' => $url . '#organization',
			'name' => trim( $name ),
			'url' => $url,
		];
		$alternate = $configured['alternateName'] ?? null;
		if ( is_string( $alternate ) && trim( $alternate ) !== '' ) {
			$publisher['alternateName'] = trim( $alternate );
		}
		$logo = MetaValues::httpUrl( $configured['logo'] ?? null );
		if ( $logo !== null ) {
			$logoParts = parse_url( $logo );
			if ( is_array( $logoParts ) && !isset( $logoParts['user'] ) && !isset( $logoParts['pass'] ) ) {
				$publisher['logo'] = [ '@type' => 'ImageObject', 'url' => $logo ];
			}
		}
		return $publisher;
	}
}
