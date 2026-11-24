<?php

declare( strict_types=1 );

namespace {
	class OutputPage {
		public function __construct( public string $mode = 'full' ) {
		}
	}

	class Title {
	}

	class ParserOutput {
		public function __construct( public string $mode = 'full' ) {
		}
	}
}

namespace MediaWiki {
	final class TestPagePolicyHookContainer {
		public function run( string $name, array $arguments ): bool {
			if ( $name === 'WikiSEOPageMetadataMode' ) {
				$arguments[1] = $arguments[0]->mode;
			} elseif ( $name === 'WikiSEOPageRevisionMode' ) {
				$arguments[2] = $arguments[1]->mode;
			}
			return true;
		}
	}

	final class MediaWikiServices {
		private TestPagePolicyHookContainer $hooks;

		private function __construct() {
			$this->hooks = new TestPagePolicyHookContainer();
		}

		public static function getInstance(): self {
			static $instance;
			return $instance ??= new self();
		}

		public function getHookContainer(): TestPagePolicyHookContainer {
			return $this->hooks;
		}
	}
}

namespace {
	require_once __DIR__ . '/../../includes/PagePolicy.php';

	use MediaWiki\Extension\WikiSEO\PagePolicy;

	function checkMode( string $label, string $actual, string $expected ): void {
		if ( $actual !== $expected ) {
			throw new \RuntimeException( "$label: expected $expected, got $actual" );
		}
	}

	foreach ( [ 'full', 'global-only', 'off', 'unsupported' ] as $mode ) {
		$expected = $mode === 'unsupported' ? 'full' : $mode;
		checkMode( 'Output mode ' . $mode, PagePolicy::forOutput( new OutputPage( $mode ) ), $expected );
		checkMode( 'Revision mode ' . $mode,
			PagePolicy::forRevision( new Title(), new ParserOutput( $mode ) ), $expected );
	}

	echo "WikiSEO page policy smoke checks passed\n";
}
