<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\WikiSEO\Generator;

use File;
use WeakMap;

/**
 * @internal Scratch data for consecutive built-in generators within one
 * addMetadataToPage() call. No persistent cache or cross-page state.
 *
 * Only completed lookups are shared. Unexpected service exceptions are not
 * cached; ordinary invalid-title/not-found outcomes keep their existing
 * exception messages. Each generator still decides its own image precedence.
 */
final class ImageLookup {
	/** @var array<string,File|string> File or expected lookup failure message. */
	public array $files = [];

	/** @var WeakMap<File,array>|null Lazily allocated for resolved files. */
	public ?WeakMap $info = null;

	/** @var bool|null PageImages result, when a configured fallback needs it. */
	public ?bool $hasPageImage = null;
}
