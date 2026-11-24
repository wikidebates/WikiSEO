<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikiSEO\Hooks;

use OutputPage;

/**
 * Veto or reduce WikiSEO output for a single rendered page.
 *
 * Set $mode to 'global-only' to preserve noncompeting site verification,
 * hreflang and MediaWiki-safe robot controls, or 'off' to suppress all output.
 * No SQL or title-name heuristics are needed.
 */
interface WikiSEOPageMetadataModeHook {
	public function onWikiSEOPageMetadataMode( OutputPage $out, string &$mode ): void;
}
