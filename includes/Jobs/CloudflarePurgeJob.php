<?php

namespace WikiOasis\WikiOasisMagic\Jobs;

use GuzzleHttp\Exception\RequestException;
use Job;
use MediaWiki\JobQueue\GenericParameterJob;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

class CloudflarePurgeJob extends Job implements GenericParameterJob {

	public function __construct( array $params ) {
		parent::__construct( 'cloudflarePagePurge', $params );
	}

	public function run(): bool {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$logger = LoggerFactory::getInstance( 'WikiOasisMagic' );

		$apiToken = $config->get( 'WikiOasisMagicCloudflareAPIToken' );
		$zoneID = $config->get( 'WikiOasisMagicCloudflareZoneID' );
		$urls = $this->params['urls'] ?? [];

		if ( !$apiToken || !$zoneID ) {
			$logger->debug( 'Cloudflare purge skipped: credentials not configured', [
				'urls' => $urls,
			] );
			return true;
		}

		$logger->info( 'Cloudflare purge job running', [
			'urls' => $urls,
		] );

		$guzzleClient = $services->getHttpRequestFactory()->createGuzzleClient();

		try {
			$response = $guzzleClient->post(
				"https://api.cloudflare.com/client/v4/zones/{$zoneID}/purge_cache",
				[
					'headers' => [
						'Authorization' => "Bearer {$apiToken}",
						'Content-Type' => 'application/json',
					],
					'json' => [ 'files' => $urls ],
				]
			);
			$logger->info( 'Cloudflare purge succeeded', [
				'urls' => $urls,
				'httpStatus' => $response->getStatusCode(),
			] );
			return true;
		} catch ( RequestException $e ) {
			$logger->error( 'Cloudflare purge failed', [
				'urls' => $urls,
				'error' => $e->getMessage(),
			] );
			return false;
		}
	}
}
