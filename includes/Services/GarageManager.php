<?php

namespace WikiOasis\WikiOasisMagic\Services;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Http\HttpRequestFactory;
use function in_array;
use function is_array;
use function is_string;
use function strtolower;
use function trim;

class GarageManager {

	public const CONSTRUCTOR_OPTIONS = [
		'WikiOasisMagicGarageAdminAPIKey',
	];

	private ServiceOptions $options;
	private HttpRequestFactory $httpRequestFactory;

	public function __construct(
		ServiceOptions $options,
		HttpRequestFactory $httpRequestFactory
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->options = $options;
		$this->httpRequestFactory = $httpRequestFactory;
	}

	/**
	 * Create a Garage bucket with the given name and ensure a global alias is set for it.
	 *
	 * The bucket name will be lowercased. Returns true if the bucket exists (or was
	 * just created) and alias handling completed, false if bucket creation failed.
	 *
	 * @param string $name Bucket/alias name
	 * @return bool
	 */
	public function createBucket( string $name ): bool {
		$name = strtolower( $name );
		if ( !$this->ensureBucket( $name ) ) {
			return false;
		}
		$this->ensureGlobalAlias( $name );
		return true;
	}

	private function ensureBucket( string $name ): bool {
		$s3 = $this->getS3Client();
		try {
			$s3->createBucket( [ 'Bucket' => $name ] );
			$s3->waitUntil( 'BucketExists', [ 'Bucket' => $name ] );
			wfDebugLog( 'WikiOasisMagic', "Garage bucket '{$name}' created." );
		} catch ( AwsException $e ) {
			$code = (string)$e->getAwsErrorCode();
			if ( in_array( $code, [ 'BucketAlreadyExists', 'BucketAlreadyOwnedByYou' ], true ) ) {
				return true;
			}
			wfDebugLog( 'WikiOasisMagic', "Failed creating Garage bucket '{$name}': {$e->getMessage()}" );
			return false;
		}
		return true;
	}

	private function ensureGlobalAlias( string $name ): void {
		$listBuckets = $this->adminRequest( 'ListBuckets' );
		if ( !is_array( $listBuckets ) ) {
			return;
		}

		foreach ( $listBuckets as $bucketInfo ) {
			if ( !is_array( $bucketInfo ) ) {
				continue;
			}

			$bucketId = (string)( $bucketInfo['id'] ?? '' );
			if ( $bucketId === '' ) {
				continue;
			}

			foreach ( (array)( $bucketInfo['globalAliases'] ?? [] ) as $globalAlias ) {
				if ( (string)$globalAlias === $name ) {
					return;
				}
			}

			$matchedLocalAlias = false;
			foreach ( (array)( $bucketInfo['localAliases'] ?? [] ) as $localAlias ) {
				if ( is_array( $localAlias ) && (string)( $localAlias['alias'] ?? '' ) === $name ) {
					$matchedLocalAlias = true;
					break;
				}
			}

			if ( !$matchedLocalAlias ) {
				continue;
			}

			$response = $this->adminRequest(
				'AddBucketAlias',
				'POST',
				[
					'bucketId' => $bucketId,
					'globalAlias' => $name,
				]
			);

			if ( is_array( $response ) ) {
				wfDebugLog( 'WikiOasisMagic', "Added global Garage alias '{$name}' to bucket '{$bucketId}'." );
			} else {
				wfDebugLog( 'WikiOasisMagic', "Failed to add global Garage alias '{$name}' to bucket '{$bucketId}'." );
			}

			return;
		}

		wfDebugLog( 'WikiOasisMagic', "No Garage bucket with local alias '{$name}' found for global alias promotion." );
	}

	private function getAdminApiKey(): string {
		return trim( (string)$this->options->get( 'WikiOasisMagicGarageAdminAPIKey' ) );
	}

	private function getAdminEndpoint(): ?string {
		global $wgFileBackends;

		$s3Endpoint = $wgFileBackends['s3']['endpoint'] ?? '';
		if ( !is_string( $s3Endpoint ) || $s3Endpoint === '' ) {
			return null;
		}

		$parts = parse_url( $s3Endpoint );
		if ( !is_array( $parts ) || !isset( $parts['scheme'], $parts['host'] ) ) {
			return null;
		}

		$port = isset( $parts['port'] ) ? (int)$parts['port'] : 3900;
		if ( $port === 3900 ) {
			$port = 3903;
		}

		return "{$parts['scheme']}://{$parts['host']}:{$port}";
	}

	private function adminRequest( string $path, string $method = 'GET', array $body = [] ): ?array {
		$apiKey = $this->getAdminApiKey();
		if ( $apiKey === '' ) {
			wfDebugLog( 'WikiOasisMagic', 'Garage admin key is empty; skipping alias management.' );
			return null;
		}

		$endpoint = $this->getAdminEndpoint();
		if ( $endpoint === null ) {
			wfDebugLog( 'WikiOasisMagic', 'Could not derive Garage admin endpoint from S3 endpoint.' );
			return null;
		}

		$requestOptions = [
			'url' => rtrim( $endpoint, '/' ) . '/v2/' . ltrim( $path, '/' ),
			'method' => $method,
			'headers' => [
				'Authorization' => 'Bearer ' . $apiKey,
				'Content-Type' => 'application/json',
			],
		];

		if ( $method === 'POST' ) {
			$requestOptions['body'] = json_encode( $body );
		}

		$response = $this->httpRequestFactory->createMultiClient()->run(
			$requestOptions,
			[ 'reqTimeout' => 15 ]
		);

		if ( ( $response['code'] ?? 0 ) !== 200 ) {
			wfDebugLog(
				'WikiOasisMagic',
				'Garage admin request failed for ' . $path . ' with HTTP ' .
					(string)( $response['code'] ?? 0 ) . ': ' . (string)( $response['body'] ?? '' )
			);
			return null;
		}

		$decoded = json_decode( (string)( $response['body'] ?? '' ), true );
		return is_array( $decoded ) ? $decoded : null;
	}

	private function getS3Client(): S3Client {
		global $wgAWSCredentials, $wgAWSRegion, $wgFileBackends;

		$s3Config = $wgFileBackends['s3'] ?? [];
		$clientConfig = [
			'version' => $s3Config['version'] ?? 'latest',
			'region' => $wgAWSRegion ?: 'garage',
		];

		if ( !empty( $wgAWSCredentials['key'] ) && !empty( $wgAWSCredentials['secret'] ) ) {
			$clientConfig['credentials'] = $wgAWSCredentials;
		}

		if ( isset( $s3Config['endpoint'] ) ) {
			$clientConfig['endpoint'] = $s3Config['endpoint'];
		}

		if ( isset( $s3Config['use_path_style_endpoint'] ) ) {
			$clientConfig['use_path_style_endpoint'] = (bool)$s3Config['use_path_style_endpoint'];
		}

		if ( isset( $s3Config['http'] ) && is_array( $s3Config['http'] ) ) {
			$clientConfig['http'] = $s3Config['http'];
		}

		return new S3Client( $clientConfig );
	}
}
