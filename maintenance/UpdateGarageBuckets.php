<?php

namespace WikiOasis\WikiOasisMagic\Maintenance;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use Miraheze\CreateWiki\Exceptions\MissingWikiError;
use Miraheze\CreateWiki\Services\RemoteWikiFactory;
use function in_array;
use function is_array;
use function json_encode;
use function strtolower;

class UpdateGarageBuckets extends Maintenance {

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
			$policy = json_encode( [
				'Version' => '2012-10-17',
				'Statement' => [
					[
						'Sid' => 'AllowPublicRead',
						'Effect' => 'Allow',
						'Principal' => '*',
						'Action' => [ 's3:GetObject' ],
						'Resource' => [ "arn:aws:s3:::{$bucket}/*" ],
					],
				],
			] );

			try {
				if ( $policy !== false ) {
					$s3->putBucketPolicy( [
						'Bucket' => $bucket,
						'Policy' => $policy,
					] );
				}

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

		try {
			$s3->deleteBucketPolicy( [ 'Bucket' => $bucket ] );
		} catch ( AwsException $exception ) {
			$errorCode = (string)$exception->getAwsErrorCode();
			if ( !in_array( $errorCode, [ 'NoSuchBucketPolicy', 'NoSuchBucket' ], true ) ) {
				$this->fatalError(
					"Failed removing website policy for '{$bucket}': {$exception->getMessage()}"
				);
			}
		}

		$this->output( "Disabled public website access for '{$bucket}'.\n" );
	}
}

// @codeCoverageIgnoreStart
return UpdateGarageBuckets::class;
// @codeCoverageIgnoreEnd
