<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikiSEO;

use MediaWiki\MediaWikiServices;
use OutputPage;
use ParserOutput;
use Title;

/**
 * Per-page WikiSEO output modes. Another extension may opt out using the
 * WikiSEOPageMetadataMode and WikiSEOPageRevisionMode hooks.
 *
 * The normal behavior remains FULL whenever no handler is registered.
 */
final class PagePolicy {
	public const FULL = 'full';
	public const GLOBAL_ONLY = 'global-only';
	public const OFF = 'off';

	/**
	 * @param OutputPage $out
	 * @return string One of FULL, GLOBAL_ONLY and OFF
	 */
	public static function forOutput( OutputPage $out ): string {
		$mode = self::FULL;
		MediaWikiServices::getInstance()->getHookContainer()->run(
			'WikiSEOPageMetadataMode',
			[ $out, &$mode ]
		);
		return self::normalize( $mode );
	}

	/**
	 * @param Title $title
	 * @param ParserOutput $output
	 * @return string One of FULL, GLOBAL_ONLY and OFF
	 */
	public static function forRevision( Title $title, ParserOutput $output ): string {
		$mode = self::FULL;
		MediaWikiServices::getInstance()->getHookContainer()->run(
			'WikiSEOPageRevisionMode',
			[ $title, $output, &$mode ]
		);
		return self::normalize( $mode );
	}

	private static function normalize( string $mode ): string {
		return in_array( $mode, [ self::FULL, self::GLOBAL_ONLY, self::OFF ], true )
			? $mode
			: self::FULL;
	}
}
