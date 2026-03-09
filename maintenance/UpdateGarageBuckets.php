<?php

namespace WikiOasis\WikiOasisMagic\Maintenance;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use Miraheze\CreateWiki\Exceptions\MissingWikiError;
use Miraheze\CreateWiki\Services\RemoteWikiFactory;
use function in_array;
use function is_array;
use function strtolower;

class UpdateGarageBuckets extends Maintenance {
	private HttpRequestFactory $httpRequestFactory;

	private RemoteWikiFactory $remoteWikiFactory;

	public function __construct() {
		parent::__construct();

		$this->addDescription(
			'Create/update a Garage bucket for a wiki and set website access from private/public state.'
		);
		$this->addOption(
			'target-wiki',
			'Target wiki database name (defaults to current --wiki context).',
			false,
			true
		);
		$this->addOption(
			'public',
			'Force public bucket website access (skip CreateWiki state lookup).',
			false,
			false
		);
		$this->addOption(
			'private',
			'Force private bucket website access (skip CreateWiki state lookup).',
			false,
			false
		);

		$this->requireExtension( 'AWS' );
		$this->requireExtension( 'CreateWiki' );
		$this->setBatchSize( 1 );
	}

	private function initServices(): void {
		$this->remoteWikiFactory = $this->getServiceContainer()->get( 'RemoteWikiFactory' );
		$this->httpRequestFactory = $this->getServiceContainer()->getHttpRequestFactory();
	}

	/** @inheritDoc */
	public function execute(): void {
		$this->initServices();

		$targetWiki = strtolower(
			(string)$this->getOption(
				'target-wiki',
				$this->getConfig()->get( MainConfigNames::DBname )
			)
		);

		$forcePublic = $this->hasOption( 'public' );
		$forcePrivate = $this->hasOption( 'private' );

		if ( $forcePublic && $forcePrivate ) {
			$this->fatalError( 'Use either --public or --private, not both.' );
		}

		$isPrivate = $this->resolvePrivateState( $targetWiki, $forcePublic, $forcePrivate );

		$this->output( "Updating Garage bucket for wiki '{$targetWiki}'...\n" );
		$this->ensureGarageBucket( $targetWiki );
		$this->ensureGarageGlobalAlias( $targetWiki );
		$this->syncBucketWebsiteAccess( $targetWiki, !$isPrivate );
		$this->output(
			$isPrivate
				? "Bucket for '{$targetWiki}' is now private.\n"
				: "Bucket for '{$targetWiki}' is now public.\n"
		);
	}

	private function resolvePrivateState(
		string $targetWiki,
		bool $forcePublic,
		bool $forcePrivate
	): bool {
		if ( $forcePrivate ) {
			return true;
		}

		if ( $forcePublic ) {
			return false;
		}

		try {
			return $this->remoteWikiFactory->newInstance( $targetWiki )->isPrivate();
		} catch ( MissingWikiError ) {
			$this->fatalError(
				"Wiki '{$targetWiki}' was not found in CreateWiki. " .
				"Use --public or --private to force a state."
			);
		}
	}

	private function getGarageBucketName( string $dbname ): string {
		return strtolower( $dbname );
	}

	private function getGarageAdminApiKey(): string {
		return trim( (string)$this->getConfig()->get( 'WikiOasisMagicGarageAdminAPIKey' ) );
	}

	private function getGarageAdminEndpoint(): ?string {
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

	private function garageAdminRequest( string $path, string $method = 'GET', array $body = [] ): ?array {
		$apiKey = $this->getGarageAdminApiKey();
		if ( $apiKey === '' ) {
			$this->output( "Garage admin key is empty; skipping alias management.\n" );
			return null;
		}

		$endpoint = $this->getGarageAdminEndpoint();
		if ( $endpoint === null ) {
			$this->output( "Could not derive Garage admin endpoint from S3 endpoint.\n" );
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
			$this->output(
				'Garage admin request failed for ' . $path . ' with HTTP ' .
				(string)( $response['code'] ?? 0 ) . ': ' . (string)( $response['body'] ?? '' ) . "\n"
			);
			return null;
		}

		$decoded = json_decode( (string)( $response['body'] ?? '' ), true );
		return is_array( $decoded ) ? $decoded : null;
	}

	private function ensureGarageGlobalAlias( string $dbname ): void {
		$listBuckets = $this->garageAdminRequest( 'ListBuckets' );
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
				if ( (string)$globalAlias === $dbname ) {
					$this->output( "Global alias '{$dbname}' already exists.\n" );
					return;
				}
			}

			$matchedLocalAlias = false;
			foreach ( (array)( $bucketInfo['localAliases'] ?? [] ) as $localAlias ) {
				if ( is_array( $localAlias ) && (string)( $localAlias['alias'] ?? '' ) === $dbname ) {
					$matchedLocalAlias = true;
					break;
				}
			}

			if ( !$matchedLocalAlias ) {
				continue;
			}

			$response = $this->garageAdminRequest(
				'AddBucketAlias',
				'POST',
				[
					'bucketId' => $bucketId,
					'globalAlias' => $dbname,
				]
			);

			if ( is_array( $response ) ) {
				$this->output( "Added global alias '{$dbname}' to bucket '{$bucketId}'.\n" );
			} else {
				$this->output( "Failed to add global alias '{$dbname}' to bucket '{$bucketId}'.\n" );
			}

			return;
		}

		$this->output( "No bucket with local alias '{$dbname}' found for global alias promotion.\n" );
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

	private function ensureGarageBucket( string $dbname ): void {
		$bucket = $this->getGarageBucketName( $dbname );
		$s3 = $this->getS3Client();

		try {
			$s3->createBucket( [ 'Bucket' => $bucket ] );
			$s3->waitUntil( 'BucketExists', [ 'Bucket' => $bucket ] );
			$this->output( "Created bucket '{$bucket}'.\n" );
		} catch ( AwsException $exception ) {
			$errorCode = (string)$exception->getAwsErrorCode();

			if ( in_array( $errorCode, [ 'BucketAlreadyExists', 'BucketAlreadyOwnedByYou' ], true ) ) {
				$this->output( "Bucket '{$bucket}' already exists.\n" );
				return;
			}

			$this->fatalError( "Failed creating bucket '{$bucket}': {$exception->getMessage()}" );
		}
	}

	private function syncBucketWebsiteAccess( string $dbname, bool $public ): void {
		$bucket = $this->getGarageBucketName( $dbname );
		$s3 = $this->getS3Client();

		if ( $public ) {

			try {

				$s3->putBucketWebsite( [
					'Bucket' => $bucket,
					'WebsiteConfiguration' => [
						'IndexDocument' => [ 'Suffix' => 'index.html' ],
						'ErrorDocument' => [ 'Key' => 'error.html' ],
					],
				] );

				$this->output( "Enabled public website access for '{$bucket}'.\n" );
			} catch ( AwsException $exception ) {
				$this->fatalError(
					"Failed enabling website access for '{$bucket}': {$exception->getMessage()}"
				);
			}

			return;
		}

		try {
			$s3->deleteBucketWebsite( [ 'Bucket' => $bucket ] );
		} catch ( AwsException $exception ) {
			$errorCode = (string)$exception->getAwsErrorCode();
			if ( !in_array( $errorCode, [ 'NoSuchWebsiteConfiguration', 'NoSuchBucket' ], true ) ) {
				$this->fatalError(
					"Failed disabling website config for '{$bucket}': {$exception->getMessage()}"
				);
			}
		}

		$this->output( "Disabled public website access for '{$bucket}'.\n" );
	}
}

// @codeCoverageIgnoreStart
return UpdateGarageBuckets::class;
// @codeCoverageIgnoreEnd
