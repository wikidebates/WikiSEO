<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikiSEO\Hooks;

use ParserOutput;
use Title;

/**
 * Prevent WikiSEO's deferred description/page-image writes for a parsed page.
 *
 * The caller can examine extension data already attached to ParserOutput.
 * Any mode other than 'full' skips WikiSEO revision data updates.
 */
interface WikiSEOPageRevisionModeHook {
	public function onWikiSEOPageRevisionMode(
		Title $title, ParserOutput $output, string &$mode
	): void;
}
