<?php

namespace WikiOasis\WikiOasisMagic\FandomImport;

use function count;
use function explode;
use function in_array;
use function parse_url;
use function preg_match;
use function preg_replace;
use function str_contains;
use function str_ends_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;

/**
 * A wiki on Fandom, named the way Special:FandomImport names it.
 *
 * meta.fandom.com is "metawiki", following the farm's own "<subdomain>wiki"
 * database names, and a language wiki such as harrypotter.fandom.com/de is
 * "harrypotterwiki/de". The key stored in the database drops the suffix:
 * "meta", "harrypotter/de".
 */
class FandomSource {

	public const SUFFIX = 'wiki';

	private const SUBDOMAIN = '/^[a-z0-9][a-z0-9-]{0,62}$/';
	private const LANG = '/^[a-z]{2,3}(?:-[a-z0-9]{1,8})*$/';

	/** Hosts that serve Fandom wikis; the old Wikia ones redirect to fandom.com. */
	private const HOSTS = [ 'fandom.com', 'wikia.com', 'wikia.org' ];

	/** Subdomains of fandom.com that are not wikis. */
	private const RESERVED = [ 'www', 'static', 'services', 'api', 'auth', 'about', 'support' ];

	private function __construct(
		public readonly string $subdomain,
		public readonly ?string $lang,
	) {
	}

	public static function newFromParts( string $subdomain, ?string $lang ): ?self {
		$subdomain = strtolower( $subdomain );
		$lang = $lang === null || $lang === '' ? null : strtolower( $lang );

		if (
			!preg_match( self::SUBDOMAIN, $subdomain ) ||
			str_ends_with( $subdomain, '-' ) ||
			in_array( $subdomain, self::RESERVED, true ) ||
			( $lang !== null && !preg_match( self::LANG, $lang ) )
		) {
			return null;
		}

		return new self( $subdomain, $lang );
	}

	/**
	 * Parse the Special:FandomImport subpage: "metawiki" or "harrypotterwiki/de".
	 */
	public static function newFromSubpage( string $subPage ): ?self {
		$parts = explode( '/', strtolower( trim( $subPage ) ) );
		if ( count( $parts ) > 2 ) {
			return null;
		}

		$name = $parts[0];
		if ( !str_ends_with( $name, self::SUFFIX ) || strlen( $name ) <= strlen( self::SUFFIX ) ) {
			return null;
		}

		return self::newFromParts( substr( $name, 0, -strlen( self::SUFFIX ) ), $parts[1] ?? null );
	}

	/**
	 * Parse the stored key: "meta" or "harrypotter/de".
	 */
	public static function newFromKey( string $key ): ?self {
		$parts = explode( '/', $key );
		if ( count( $parts ) > 2 ) {
			return null;
		}

		return self::newFromParts( $parts[0], $parts[1] ?? null );
	}

	/**
	 * Parse whatever someone pasted: a Fandom address with or without a path,
	 * a bare host, the subpage form, or just the subdomain.
	 */
	public static function newFromInput( string $input ): ?self {
		$input = strtolower( trim( $input ) );
		if ( $input === '' ) {
			return null;
		}

		if ( !str_contains( $input, '.' ) ) {
			return self::newFromSubpage( $input ) ?? self::newFromParts( $input, null );
		}

		if ( !preg_match( '#^[a-z][a-z0-9+.-]*://#', $input ) ) {
			$input = "https://$input";
		}

		$host = parse_url( $input, PHP_URL_HOST );
		if ( !$host ) {
			return null;
		}

		$subdomain = null;
		foreach ( self::HOSTS as $suffix ) {
			if ( str_ends_with( $host, ".$suffix" ) ) {
				$subdomain = substr( $host, 0, -strlen( ".$suffix" ) );
				break;
			}
		}

		if ( $subdomain === null || str_contains( $subdomain, '.' ) ) {
			return null;
		}

		$lang = null;
		$path = trim( (string)parse_url( $input, PHP_URL_PATH ), '/' );
		if ( $path !== '' ) {
			$first = explode( '/', $path )[0];
			if ( $first !== 'wiki' && preg_match( self::LANG, $first ) ) {
				$lang = $first;
			}
		}

		return self::newFromParts( $subdomain, $lang );
	}

	public function getKey(): string {
		return $this->lang === null ? $this->subdomain : "{$this->subdomain}/{$this->lang}";
	}

	public function getSubpage(): string {
		$name = $this->subdomain . self::SUFFIX;
		return $this->lang === null ? $name : "$name/{$this->lang}";
	}

	/** For display: "harrypotter.fandom.com/de". */
	public function getHost(): string {
		$host = "{$this->subdomain}.fandom.com";
		return $this->lang === null ? $host : "$host/{$this->lang}";
	}

	public function getBaseUrl(): string {
		return 'https://' . $this->getHost();
	}

	public function getApiUrl(): string {
		return $this->getBaseUrl() . '/api.php';
	}

	public function getPageUrl( string $page ): string {
		return $this->getBaseUrl() . '/wiki/' . $page;
	}

	/**
	 * A WikiOasis subdomain to offer by default: letters and digits only, with
	 * the language first the way Fandom names its own language wikis.
	 */
	public function getSuggestedSubdomain(): string {
		return preg_replace( '/[^a-z0-9]/', '', ( $this->lang ?? '' ) . $this->subdomain );
	}
}
