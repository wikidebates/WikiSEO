<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikiSEO;

use Html;
use MediaWiki\Output\OutputPage;
use MediaWiki\Title\TitleValue;

/**
 * Opt-in interwiki hreflang, independent of the skin and of Interlanguage.
 *
 * The rendered OutputPage already contains the effective language links.
 * Resolve these titles with administrator-supplied canonical article routes,
 * using the same encoding as MediaWiki's Interwiki::getURL(). Do not invoke
 * Skin::getLanguages(), Title::getFullURL(), a database or an HTTP service.
 * A cold interwiki lookup could otherwise introduce SQL/cache traffic and
 * skin-specific filters could change the search-engine annotations.
 */
final class InterlanguageHreflang {
	/**
	 * The opt-in mode owns hreflang in the head, not the UI language links.
	 * Retain all other head items including feeds and mobile alternates.
	 *
	 * @param array &$tags Final MediaWiki head links and meta tags
	 * @param OutputPage $out
	 * @param array $routes Map of language codes to canonical article routes
	 * @param string $language Language of the wiki edition
	 * @param bool $enabled Whether this page permits WikiSEO output
	 */
	public static function apply(
		array &$tags, OutputPage $out, array $routes, string $language, bool $enabled
	): void {
		if ( $routes === [] ) {
			return;
		}

		// Core variants, manual legacy links, and other single-link head
		// items must not create duplicate or conflicting hreflang clusters.
		$canonical = [];
		$noindex = false;
		foreach ( $tags as $key => $html ) {
			$attrs = self::attributes( $html, 'link' );
			if ( self::hasRel( $attrs, 'alternate' ) && isset( $attrs['hreflang'] ) ) {
				unset( $tags[$key] );
				continue;
			}
			if ( self::hasRel( $attrs, 'canonical' ) ) {
				$canonical[] = $attrs['href'] ?? '';
			}
			$noindex = $noindex || self::isNoindex( $html );
		}
		foreach ( $out->getHeadItemsArray() as $key => $html ) {
			$attrs = self::attributes( $html, 'link' );
			if ( self::hasRel( $attrs, 'alternate' ) && isset( $attrs['hreflang'] ) ) {
				$out->addHeadItem( $key, '' );
				continue;
			}
			if ( self::hasRel( $attrs, 'canonical' ) ) {
				$canonical[] = $attrs['href'] ?? '';
			}
			$noindex = $noindex || self::isNoindex( $html );
		}

		// Article::view preloads the current-revision flag. Explicit oldid,
		// diff and non-view URLs are excluded even if their revision is current.
		if ( !$enabled || $noindex || !$out->isArticle() ||
			str_starts_with( $out->getRobotPolicy(), 'noindex,' ) ||
			!$out->getRevisionId() || $out->getContext()->getActionName() !== 'view'
		) {
			return;
		}
		$request = $out->getRequest();
		foreach ( [ 'oldid', 'diff', 'curid', 'redirect', 'redlink', 'printable', 'mobileaction', 'stableid' ] as $param ) {
			if ( $request->getCheck( $param ) ) {
				return;
			}
		}
		if ( !$out->isRevisionCurrent() ) {
			return;
		}

		// Do not label a noncanonical source (or a canonical alias to another
		// title) as an alternate. Require the actual final canonical element.
		$title = $out->getTitle();
		if ( $title === null || count( $canonical ) !== 1 ||
			!self::validLanguage( $language ) || !isset( $routes[$language] )
		) {
			return;
		}
		$self = self::articleUrl( $routes[$language], $title->getPrefixedDBkey() );
		if ( $self === null || $self !== $canonical[0] ) {
			return;
		}

		$alternates = self::alternates( $out->getLanguageLinks(), $routes, $language, $self );
		foreach ( $alternates as $code => $url ) {
			$tags['wikiseo-interlanguage-' . $code] = Html::element( 'link', [
				'rel' => 'alternate', 'hreflang' => $code, 'href' => $url,
			] );
		}
	}

	/**
	 * Pure URL construction from effective language links. No translation
	 * lookup, no persistent state and no assumption that another page exists.
	 *
	 * @return array<string,string> Empty for an untranslated page
	 */
	public static function alternates(
		array $links, array $routes, string $language, string $self
	): array {
		$result = [];
		$conflicts = [];
		$seen = [ $self => true ];
		foreach ( $links as $link ) {
			if ( !is_string( $link ) || !str_contains( $link, ':' ) ) {
				continue;
			}
			[ $code, $target ] = explode( ':', $link, 2 );
			if ( $code === $language || !self::validLanguage( $code ) ||
				!isset( $routes[$code] ) || isset( $conflicts[$code] )
			) {
				continue;
			}
			$url = self::articleUrl( $routes[$code], $target );
			if ( $url === null || $url === $self ) {
				continue;
			}
			if ( isset( $result[$code] ) ) {
				if ( $result[$code] !== $url ) {
					unset( $result[$code] );
					$conflicts[$code] = true;
				}
				continue;
			}
			if ( isset( $seen[$url] ) ) {
				continue;
			}
			$seen[$url] = true;
			$result[$code] = $url;
		}
		if ( $result === [] ) {
			return [];
		}
		$result[$language] = $self;
		ksort( $result, SORT_STRING );
		return $result;
	}

	private static function validLanguage( string $language ): bool {
		return preg_match( '/^[a-z]{2}$/D', $language ) === 1;
	}

	/**
	 * Route strings describe infrastructure, not page correspondences.
	 * An optional main-page route supports installations whose canonical
	 * home is the domain root instead of the normal article path.
	 *
	 * @param string|array|mixed $route
	 */
	private static function articleUrl( $route, string $title ): ?string {
		if ( $title === '' || preg_match( '~[#\x00-\x1f\x7f<>\\\\]~', $title ) ||
			preg_match( '~(?:^|/)\.{1,2}(?:/|$)~', $title )
		) {
			return null;
		}
		$target = TitleValue::tryNew( NS_MAIN, $title );
		if ( $target === null ) {
			return null;
		}
		$dbkey = $target->getDBkey();
		if ( is_array( $route ) ) {
			$mainTitle = $route['mainPageTitle'] ?? null;
			if ( is_string( $mainTitle ) && str_replace( ' ', '_', $mainTitle ) === $dbkey ) {
				return self::absoluteUrl( $route['mainPageUrl'] ?? null );
			}
			$route = $route['article'] ?? null;
		}
		if ( !is_string( $route ) || substr_count( $route, '$1' ) !== 1 ) {
			return null;
		}
		// $1 may only occur in the path or query, never in the authority.
		$parts = parse_url( $route );
		if ( !is_array( $parts ) || !str_contains( ( $parts['path'] ?? '' ) . ( $parts['query'] ?? '' ), '$1' ) ) {
			return null;
		}
		return self::absoluteUrl( str_replace( '$1', wfUrlencode( $dbkey ), $route ) );
	}

	private static function absoluteUrl( $url ): ?string {
		$url = MetaValues::httpUrl( $url );
		if ( $url === null ) {
			return null;
		}
		$parts = parse_url( $url );
		return is_array( $parts ) && !isset( $parts['user'], $parts['pass'] ) &&
			!isset( $parts['user'] ) && !isset( $parts['pass'] ) && !isset( $parts['fragment'] )
			? $url : null;
	}

	/** Parse the single-element fragments emitted by Html::element(). */
	private static function attributes( $html, string $element ): array {
		if ( !is_string( $html ) || !preg_match( '~^\s*<' . $element . '\b([^<>]*)/?>\s*$~iD', $html, $m ) ) {
			return [];
		}
		preg_match_all( '~([a-z][a-z0-9:_-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')~i', $m[1], $matches, PREG_SET_ORDER );
		$attrs = [];
		foreach ( $matches as $attr ) {
			$attrs[strtolower( $attr[1] )] = html_entity_decode( $attr[3] ?? $attr[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return $attrs;
	}

	private static function hasRel( array $attrs, string $rel ): bool {
		return in_array( $rel, preg_split( '/\s+/', strtolower( $attrs['rel'] ?? '' ) ), true );
	}

	private static function isNoindex( $html ): bool {
		$attrs = self::attributes( $html, 'meta' );
		if ( !in_array( strtolower( $attrs['name'] ?? '' ), [ 'robots', 'googlebot', 'bingbot' ], true ) ) {
			return false;
		}
		$directives = preg_split( '/[\s,]+/', strtolower( $attrs['content'] ?? '' ) );
		return in_array( 'noindex', $directives, true ) || in_array( 'none', $directives, true );
	}
}
