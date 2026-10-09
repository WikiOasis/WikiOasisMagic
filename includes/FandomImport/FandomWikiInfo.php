<?php

namespace WikiOasis\WikiOasisMagic\FandomImport;

use function array_column;
use function array_filter;
use function array_values;
use function is_array;
use function is_string;

/**
 * What Fandom's api.php says about a wiki, as much of it as an import needs.
 */
class FandomWikiInfo {

	/**
	 * @param string $sitename
	 * @param string $wikiId Fandom's database name; the dump files are named after it
	 * @param string $language
	 * @param int $articles
	 * @param int $pages
	 * @param int $files
	 * @param int $edits
	 * @param string $license
	 * @param string $licenseUrl
	 * @param string[] $extensions
	 */
	public function __construct(
		public readonly string $sitename,
		public readonly string $wikiId,
		public readonly string $language,
		public readonly int $articles,
		public readonly int $pages,
		public readonly int $files,
		public readonly int $edits,
		public readonly string $license,
		public readonly string $licenseUrl,
		public readonly array $extensions,
	) {
	}

	/**
	 * Build from an api.php siteinfo response (formatversion=2).
	 */
	public static function newFromSiteInfo( array $response ): ?self {
		$query = $response['query'] ?? null;
		$general = is_array( $query ) ? ( $query['general'] ?? null ) : null;
		if ( !is_array( $general ) || !is_string( $general['wikiid'] ?? null ) || $general['wikiid'] === '' ) {
			return null;
		}

		$statistics = is_array( $query['statistics'] ?? null ) ? $query['statistics'] : [];
		$rights = is_array( $query['rightsinfo'] ?? null ) ? $query['rightsinfo'] : [];
		$extensions = is_array( $query['extensions'] ?? null ) ?
			array_values( array_filter( array_column( $query['extensions'], 'name' ), 'is_string' ) ) :
			[];

		return new self(
			sitename: (string)( $general['sitename'] ?? '' ),
			wikiId: $general['wikiid'],
			language: (string)( $general['lang'] ?? '' ),
			articles: (int)( $statistics['articles'] ?? 0 ),
			pages: (int)( $statistics['pages'] ?? 0 ),
			files: (int)( $statistics['images'] ?? 0 ),
			edits: (int)( $statistics['edits'] ?? 0 ),
			license: (string)( $rights['text'] ?? '' ),
			licenseUrl: (string)( $rights['url'] ?? '' ),
			extensions: $extensions,
		);
	}

	public static function newFromArray( array $data ): ?self {
		if ( !is_string( $data['wikiid'] ?? null ) ) {
			return null;
		}

		return new self(
			sitename: (string)( $data['sitename'] ?? '' ),
			wikiId: $data['wikiid'],
			language: (string)( $data['language'] ?? '' ),
			articles: (int)( $data['articles'] ?? 0 ),
			pages: (int)( $data['pages'] ?? 0 ),
			files: (int)( $data['files'] ?? 0 ),
			edits: (int)( $data['edits'] ?? 0 ),
			license: (string)( $data['license'] ?? '' ),
			licenseUrl: (string)( $data['licenseurl'] ?? '' ),
			extensions: is_array( $data['extensions'] ?? null ) ? $data['extensions'] : [],
		);
	}

	public function toArray(): array {
		return [
			'sitename' => $this->sitename,
			'wikiid' => $this->wikiId,
			'language' => $this->language,
			'articles' => $this->articles,
			'pages' => $this->pages,
			'files' => $this->files,
			'edits' => $this->edits,
			'license' => $this->license,
			'licenseurl' => $this->licenseUrl,
			'extensions' => $this->extensions,
		];
	}
}
