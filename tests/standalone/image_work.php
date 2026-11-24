<?php

declare( strict_types=1 );

namespace {
	class OutputPage {
		public function getTitle(): string {
			return 'Image probe page';
		}

		public function getRequest(): object {
			return new \stdClass();
		}
	}

	class ExtensionRegistry {
		public static bool $pageImagesLoaded = true;

		public static function getInstance(): self {
			return new self();
		}

		public function isLoaded( string $name ): bool {
			return $name === 'PageImages' && self::$pageImagesLoaded;
		}
	}

	class File {
		public int $widthCalls = 0;
		public int $heightCalls = 0;
		public int $timestampCalls = 0;

		public function getTimestamp(): string {
			$this->timestampCalls++;
			return '20261124100000';
		}

		public function getWidth(): int {
			$this->widthCalls++;
			return 640;
		}

		public function getHeight(): int {
			$this->heightCalls++;
			return 480;
		}

		public function getFullUrl(): string {
			return '//example.test/image.png';
		}
	}
}

namespace PageImages {
	final class PageImages {
		public static int $calls = 0;
		public static $result = false;

		public static function getPageImage( $title ) {
			self::$calls++;
			if ( $title !== 'Image probe page' ) {
				throw new \RuntimeException( 'Unexpected page for image lookup' );
			}
			return self::$result;
		}
	}
}

namespace MediaWiki\Extension\WikiSEO {
	final class WikiSEO {
		public static function protocolizeUrl( string $url, object $request ): string {
			return 'https:' . $url;
		}
	}
}

namespace {
	use MediaWiki\Extension\WikiSEO\Generator\AbstractBaseGenerator;
	use PageImages\PageImages;

	// Optional path lets the identical probe measure a pinned original file
	// on the same CI runner. The default exercises the current implementation.
	require $argv[1] ?? __DIR__ . '/../../includes/Generator/AbstractBaseGenerator.php';
	$baseline = ( $argv[2] ?? '' ) === '--baseline';

	final class ImageProbe extends AbstractBaseGenerator {
		private $defaultImage;

		public function fallback( array $metadata, $defaultImage ): array {
			$this->metadata = $metadata;
			$this->defaultImage = $defaultImage;
			$this->outputPage = new OutputPage();
			$this->setFallbackImageIfEnabled();
			return $this->metadata;
		}

		protected function getConfigValue( string $key ) {
			if ( $key !== 'WikiSeoDefaultImage' ) {
				throw new \RuntimeException( 'Unexpected configuration lookup' );
			}
			return $this->defaultImage;
		}

		public function fileInfo( File $file ): array {
			$this->outputPage = new OutputPage();
			return $this->getFileInfo( $file );
		}
	}

	function same( $expected, $actual, string $message ): void {
		if ( $actual !== $expected ) {
			throw new \RuntimeException( $message . ': ' . var_export( $actual, true ) );
		}
	}

	$probe = new ImageProbe();
	$cases = [
		// Label, input, configured default, extension loaded, PageImages result,
		// exact output, lookup count before, lookup count after.
		[ 'explicit local image', [ 'image' => 'File.png' ], 'Default.png', true, false,
			[ 'image' => 'File.png' ], 1, 0 ],
		[ 'explicit remote image', [ 'image' => 'https://example.test/a.png' ], 'Default.png', true, 'Page.png',
			[ 'image' => 'https://example.test/a.png' ], 1, 0 ],
		[ 'no configured default', [], null, true, false, [], 1, 0 ],
		[ 'no configured default with page image', [], null, true, 'Page.png', [], 1, 0 ],
		[ 'page image exists', [], 'Default.png', true, 'Page.png', [], 1, 1 ],
		[ 'fallback needed', [], 'Default.png', true, false, [ 'image' => 'Default.png' ], 1, 1 ],
		[ 'PageImages disabled', [], 'Default.png', false, false, [ 'image' => 'Default.png' ], 0, 0 ],
		[ 'explicit empty string', [ 'image' => '' ], 'Default.png', true, false, [ 'image' => '' ], 1, 0 ],
		[ 'null image', [ 'image' => null ], 'Default.png', true, false, [ 'image' => 'Default.png' ], 1, 1 ],
		[ 'empty configured default', [], '', true, false, [ 'image' => '' ], 1, 1 ],
		[ 'nonfalse empty PageImages result', [], 'Default.png', true, '', [], 1, 1 ],
	];
	$total = 0;
	foreach ( $cases as [ $label, $metadata, $default, $loaded, $pageImage, $expected, $before, $after ] ) {
		ExtensionRegistry::$pageImagesLoaded = $loaded;
		PageImages::$result = $pageImage;
		PageImages::$calls = 0;
		same( $expected, $probe->fallback( $metadata, $default ), $label . ': fallback output' );
		same( $baseline ? $before : $after, PageImages::$calls, $label . ': PageImages calls' );
		$total += PageImages::$calls;
	}

	$file = new File();
	same( [
		'url' => 'https://example.test/image.png?version=' . md5( '20261124100000640480' ),
		'width' => 640,
		'height' => 480,
	], $probe->fileInfo( $file ), 'Exactly the same local file metadata and version hash' );
	same( 1, $file->timestampCalls, 'Timestamp calls' );
	same( $baseline ? 2 : 1, $file->widthCalls, 'Width calls' );
	same( $baseline ? 2 : 1, $file->heightCalls, 'Height calls' );
	echo 'WD_SEO_IMAGE_WORK ' . json_encode( [
		'mode' => $baseline ? 'before' : 'after',
		'fallback_cases' => count( $cases ),
		'pageimages_calls' => $total,
		'width_calls' => $file->widthCalls,
		'height_calls' => $file->heightCalls,
	], JSON_THROW_ON_ERROR ) . PHP_EOL;
}
