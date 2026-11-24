<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 * @file
 */

declare( strict_types=1 );

namespace MediaWiki\Extension\WikiSEO\Generator\Plugins;

use Html;
use MediaWiki\Extension\WikiSEO\Generator\AbstractBaseGenerator;
use MediaWiki\Extension\WikiSEO\Generator\GeneratorInterface;
use MediaWiki\Extension\WikiSEO\WikiSEO;
use MediaWiki\Extension\WikiSEO\MetaValues;
use MediaWiki\MediaWikiServices;
use OutputPage;

/**
 * OpenGraph metadata generator
 *
 * @package MediaWiki\Extension\WikiSEO\Generator\Plugins
 */
class OpenGraph extends AbstractBaseGenerator implements GeneratorInterface {
	protected static $htmlElementPropertyKey = 'property';
	protected static $htmlElementContentKey = 'content';

	/**
	 * Valid Tags for this generator
	 *
	 * @var array
	 */
	protected $tags = [
		'author',
		'description',
		'image',
		'image_width',
		'image_height',
		'image_alt',
		'image_type',
		'image_secure_url',
		'keywords',
		'locale',
		'modified_time',
		'published_time',
		'section',
		'site_name',
		'title',
		'type',
	];

	/**
	 * Tag name conversions for this generator
	 *
	 * @var array
	 */
	protected $conversions = [
		'image'        => 'og:image',
		'image_width'  => 'og:image:width',
		'image_height' => 'og:image:height',
		'image_alt'    => 'og:image:alt',
		'image_type'   => 'og:image:type',
		'image_secure_url' => 'og:image:secure_url',

		'locale'      => 'og:locale',
		'type'        => 'og:type',
		'title'       => 'og:title',
		'site_name'   => 'og:site_name',
		'description' => 'og:description',

		'author'         => 'article:author',
		'modified_time'  => 'article:modified_time',
		'published_time' => 'article:published_time',
		'section'        => 'article:section',
		'keywords'       => 'article:tag',
	];

	/**
	 * Page title property name
	 *
	 * @var string
	 */
	protected $titlePropertyName = 'og:title';

	/**
	 * Initialize the generator with all metadata and the page to output the metadata onto
	 *
	 * @param array $metadata All metadata
	 * @param OutputPage $out The page to add the metadata to
	 *
	 * @return void
	 */
	public function init( array $metadata, OutputPage $out ): void {
		$this->metadata = $metadata;
		$this->outputPage = $out;

		$this->metadata['type'] = MetaValues::openGraphType( $metadata );
		if ( $this->metadata['type'] !== 'article' ) {
			// article:* properties must not appear on generic project or help pages.
			foreach ( [ 'author', 'modified_time', 'published_time', 'section', 'keywords' ] as $key ) {
				unset( $this->metadata[$key] );
			}
		} else {
			foreach ( [ 'modified_time', 'published_time' ] as $key ) {
				$date = MetaValues::date( $this->metadata[$key] ?? null );
				if ( $date === null ) {
					unset( $this->metadata[$key] );
				} else {
					$this->metadata[$key] = $date;
				}
			}
		}

		$this->setFallbackImageIfEnabled();
		$this->preprocessFileMetadata();
		$secure = MetaValues::secureUrl(
			$this->metadata['image_secure_url'] ?? $this->metadata['image'] ?? null
		);
		if ( $secure !== null ) {
			$this->metadata['image_secure_url'] = $secure;
		} else {
			unset( $this->metadata['image_secure_url'] );
		}
		if ( !isset( $this->metadata['locale'] ) ) {
			$lang = MediaWikiServices::getInstance()->getContentLanguage()->getCode();
			$locales = [ 'fr' => 'fr_FR', 'en' => 'en_US' ];
			if ( isset( $locales[$lang] ) ) {
				$this->metadata['locale'] = $locales[$lang];
			}
		}
	}

	/**
	 * Add the metadata to the OutputPage
	 *
	 * @return void
	 */
	public function addMetadata(): void {
		$this->addTitleMeta();
		$this->addSiteName();
		if ( isset( $this->metadata['locale_alternate'] ) ) {
			foreach ( preg_split( '/\s*,\s*/', (string)$this->metadata['locale_alternate'] ) as $locale ) {
				if ( preg_match( '/^[a-z]{2,3}_[A-Z]{2}$/D', $locale ) ) {
					$this->outputPage->addHeadItem(
						'og:locale:alternate:' . $locale,
						Html::element( 'meta', [ 'property' => 'og:locale:alternate', 'content' => $locale ] )
					);
				}
			}
		}

		if ( $this->outputPage->getTitle() !== null ) {
			$url = MetaValues::httpUrl( $this->metadata['canonical'] ?? null ) ??
				$this->outputPage->getTitle()->getFullURL();
			$url = WikiSEO::protocolizeUrl( $url, $this->outputPage->getRequest() );

			$this->outputPage->addHeadItem(
				'og:url', Html::element(
					'meta', [
						self::$htmlElementPropertyKey => 'og:url',
						self::$htmlElementContentKey  => $url,
					]
				)
			);
		}

		$ogImageExists = false;
		foreach ( $this->outputPage->getMetaTags() as $metaTag ) {
			$name = array_shift( $metaTag );
			if ( $name !== null && $name === 'og:image' ) {
				$ogImageExists = true;
				break;
			}
		}

		$socialImage = $this->getSocialMediaImageUrl();
		if ( $socialImage !== null ) {
			$this->metadata['image'] = $socialImage;
			$this->metadata['image_width'] = $this->getConfigValue( 'WikiSeoSocialImageWidth' );
			$this->metadata['image_height'] = $this->getConfigValue( 'WikiSeoSocialImageHeight' );
			$this->metadata['image_type'] = 'image/jpeg';
			$secure = MetaValues::secureUrl( $socialImage );
			if ( $secure !== null ) {
				$this->metadata['image_secure_url'] = $secure;
			} else {
				unset( $this->metadata['image_secure_url'] );
			}
		}

		// Do not publish image dimensions, format or alt text for an image
		// provided by a different extension when WikiSEO's fallback is skipped.
		$skipFallback = $socialImage === null && $ogImageExists && $this->fallbackImageActive;
		foreach ( $this->tags as $tag ) {
			if ( $skipFallback && in_array( $tag, [
				'image', 'image_width', 'image_height', 'image_alt', 'image_type', 'image_secure_url'
			], true ) ) {
				continue;
			}
			if ( array_key_exists( $tag, $this->metadata ) ) {
				$convertedTag = $this->conversions[$tag];
				$this->outputPage->addHeadItem(
					$convertedTag, Html::element(
						'meta', [
							self::$htmlElementPropertyKey => $convertedTag,
							self::$htmlElementContentKey  => $this->metadata[$tag]
						]
					)
				);
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getAllowedParameterNames(): array {
		return $this->tags;
	}

	/**
	 * Add a title meta attribute to the output
	 *
	 * @return void
	 */
	protected function addTitleMeta(): void {
		$title = $this->metadata['title'] ?? (
			$this->outputPage->getTitle() !== null
				? $this->outputPage->getTitle()->getPrefixedText()
				: $this->outputPage->getHTMLTitle()
		);

		$this->outputPage->addHeadItem(
			$this->titlePropertyName, Html::element(
				'meta', [
					self::$htmlElementPropertyKey => $this->titlePropertyName,
					self::$htmlElementContentKey => $title,
				]
			)
		);
	}

	/**
	 * Add the sitename as og:site_name
	 *
	 * @return void
	 */
	private function addSiteName(): void {
		$sitename = $this->getConfigValue( 'Sitename' );

		if ( !isset( $this->metadata['site_name'] ) && $sitename !== null ) {
			$this->outputPage->addHeadItem(
				'og:site_name', Html::element(
					'meta', [
						self::$htmlElementPropertyKey => 'og:site_name',
						self::$htmlElementContentKey => $sitename,
					]
				)
			);
		}
	}
}
