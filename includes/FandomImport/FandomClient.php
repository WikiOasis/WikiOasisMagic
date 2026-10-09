<?php

namespace WikiOasis\WikiOasisMagic\FandomImport;

use MediaWiki\Http\HttpRequestFactory;
use Psr\Log\LoggerInterface;
use StatusValue;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use function in_array;
use function is_array;
use function is_numeric;
use function json_decode;
use function preg_match;
use function strtoupper;
use function substr;
use function wfAppendQuery;

/**
 * Asks Fandom about a wiki: its siteinfo, whether its dumps can be
 * downloaded, and which groups a user is in there.
 *
 * Everything here is advisory: the import worker checks again before it acts.
 */
class FandomClient {

	public const VARIANT_FULL = 'full';
	public const VARIANT_CURRENT = 'current';

	private const DUMP_BASE = 'https://s3.amazonaws.com/wikia_xml_dumps';

	/** S3 keeps these objects but cannot serve them without a restore first. */
	private const ARCHIVED_STORAGE = [ 'GLACIER', 'DEEP_ARCHIVE' ];

	private const TIMEOUT = 10;

	public function __construct(
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly WANObjectCache $cache,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @return StatusValue Good with a FandomWikiInfo, or fatal with
	 *  wikioasismagic-fandomimport-lookup-missing (no such wiki) or
	 *  wikioasismagic-fandomimport-lookup-unreachable (we could not ask)
	 */
	public function getWikiInfo( FandomSource $source ): StatusValue {
		$result = $this->cache->getWithSetCallback(
			$this->cache->makeGlobalKey( 'wikioasismagic-fandom-siteinfo', $source->getKey() ),
			WANObjectCache::TTL_MINUTE * 10,
			function ( $old, &$ttl ) use ( $source ) {
				$response = $this->get( $source->getApiUrl(), [
					'action' => 'query',
					'meta' => 'siteinfo',
					'siprop' => 'general|statistics|extensions|rightsinfo',
					'format' => 'json',
					'formatversion' => 2,
				] );

				if ( $response === null || $response === false ) {
					$ttl = WANObjectCache::TTL_MINUTE;
					return [ 'error' => $response === false ? 'missing' : 'unreachable' ];
				}

				$info = FandomWikiInfo::newFromSiteInfo( $response );
				if ( !$info ) {
					$ttl = WANObjectCache::TTL_MINUTE;
					return [ 'error' => 'missing' ];
				}

				return [ 'info' => $info->toArray() ];
			}
		);

		$info = is_array( $result['info'] ?? null ) ? FandomWikiInfo::newFromArray( $result['info'] ) : null;
		if ( $info ) {
			return StatusValue::newGood( $info );
		}

		return StatusValue::newFatal( ( $result['error'] ?? '' ) === 'missing' ?
			'wikioasismagic-fandomimport-lookup-missing' :
			'wikioasismagic-fandomimport-lookup-unreachable'
		);
	}

	/**
	 * Whether the dumps on Special:Statistics can be downloaded right now.
	 *
	 * @param string $wikiId
	 * @return array<int,array{variant:string,url:string,status:int,available:bool,lastModified:?string,size:?int,storageClass:?string}>
	 */
	public function getDumps( string $wikiId ): array {
		if ( !preg_match( '/^[a-z0-9_]{1,64}$/', $wikiId ) ) {
			return [];
		}

		return $this->cache->getWithSetCallback(
			$this->cache->makeGlobalKey( 'wikioasismagic-fandom-dumps', $wikiId ),
			WANObjectCache::TTL_HOUR,
			function ( $old, &$ttl ) use ( $wikiId ) {
				$dumps = [];
				foreach ( [ self::VARIANT_FULL, self::VARIANT_CURRENT ] as $variant ) {
					$dump = $this->headDump( $wikiId, $variant );
					if ( $dump['status'] === 0 ) {
						$ttl = WANObjectCache::TTL_MINUTE;
					}
					$dumps[] = $dump;
				}
				return $dumps;
			}
		);
	}

	/**
	 * The groups a user is in on the Fandom wiki.
	 *
	 * @return array{exists:bool,groups:string[]}|null Null when Fandom could not be asked
	 */
	public function getUserGroups( FandomSource $source, string $username ): ?array {
		$response = $this->get( $source->getApiUrl(), [
			'action' => 'query',
			'list' => 'users',
			'ususers' => $username,
			'usprop' => 'groups',
			'format' => 'json',
			'formatversion' => 2,
		] );

		$user = is_array( $response ) ? ( $response['query']['users'][0] ?? null ) : null;
		if ( !is_array( $user ) ) {
			return null;
		}

		if ( isset( $user['missing'] ) || isset( $user['invalid'] ) ) {
			return [ 'exists' => false, 'groups' => [] ];
		}

		return [
			'exists' => true,
			'groups' => is_array( $user['groups'] ?? null ) ? $user['groups'] : [],
		];
	}

	/**
	 * Where Special:Statistics links the dump of a wiki: Fandom's S3 bucket,
	 * under the first one and two characters of its database name.
	 */
	public static function getDumpUrl( string $wikiId, string $variant ): string {
		$suffix = $variant === self::VARIANT_FULL ? 'pages_full' : 'pages_current';
		return self::DUMP_BASE . '/' . substr( $wikiId, 0, 1 ) . '/' . substr( $wikiId, 0, 2 ) .
			"/{$wikiId}_{$suffix}.xml.7z";
	}

	/**
	 * @return array{variant:string,url:string,status:int,available:bool,lastModified:?string,size:?int,storageClass:?string}
	 */
	private function headDump( string $wikiId, string $variant ): array {
		$url = self::getDumpUrl( $wikiId, $variant );
		$request = $this->httpRequestFactory->create( $url, $this->getRequestOptions( 'HEAD' ), __METHOD__ );
		$request->execute();

		$status = $request->getStatus();
		$storageClass = $request->getResponseHeader( 'x-amz-storage-class' );
		$lastModified = $request->getResponseHeader( 'last-modified' );
		$size = $request->getResponseHeader( 'content-length' );

		return [
			'variant' => $variant,
			'url' => $url,
			'status' => $status,
			'available' => $status === 200 &&
				!in_array( strtoupper( (string)$storageClass ), self::ARCHIVED_STORAGE, true ),
			'lastModified' => $lastModified ? ( ConvertibleTimestamp::convert( TS_MW, $lastModified ) ?: null ) : null,
			'size' => is_numeric( $size ) ? (int)$size : null,
			'storageClass' => $storageClass ?: null,
		];
	}

	/**
	 * @return array|false|null The decoded response; false when the wiki does not
	 *  exist (Fandom redirects those elsewhere); null when Fandom could not be reached
	 */
	private function get( string $url, array $query ): array|false|null {
		$request = $this->httpRequestFactory->create(
			wfAppendQuery( $url, $query ),
			$this->getRequestOptions( 'GET' ),
			__METHOD__
		);
		$status = $request->execute();
		$code = $request->getStatus();

		if ( ( $code >= 300 && $code < 400 ) || $code === 404 || $code === 410 ) {
			return false;
		}

		if ( !$status->isOK() ) {
			$this->logger->info( 'Fandom request to {url} failed with HTTP {code}', [
				'url' => $url,
				'code' => $code,
			] );
			return null;
		}

		$data = json_decode( $request->getContent(), true );
		return is_array( $data ) ? $data : null;
	}

	private function getRequestOptions( string $method ): array {
		return [
			'method' => $method,
			'timeout' => self::TIMEOUT,
			'connectTimeout' => 5,
			'followRedirects' => false,
		];
	}
}
