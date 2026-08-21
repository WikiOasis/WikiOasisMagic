<?php

namespace WikiOasis\WikiOasisMagic;

use GuzzleHttp\Exception\RequestException;
use MediaWiki\Config\Config;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Logger\LoggerFactory;

/**
 * Purges entries from the Cloudflare cache.
 *
 * Callers pass any combination of:
 *  - `urls`: exact URLs to purge (Cloudflare `files`).
 *  - `prefixes`: URL prefixes to purge, without a scheme, e.g.
 *    `example.org/w/load.php`. Used to drop every variant of a query-string
 *    heavy endpoint such as load.php, where the exact URLs are unknowable.
 *  - `hosts`: hostnames whose entire cached content should be dropped, e.g.
 *    `example.org`.
 *
 * Prefix and host purges require an Enterprise Cloudflare plan; on other plans
 * they are rejected by the API and the failure is logged.
 */
class CloudflarePurger {

	private Config $config;

	private HttpRequestFactory $httpRequestFactory;

	public function __construct( Config $config, HttpRequestFactory $httpRequestFactory ) {
		$this->config = $config;
		$this->httpRequestFactory = $httpRequestFactory;
	}

	/**
	 * @return bool Whether credentials are configured for purging at all.
	 */
	public function isConfigured(): bool {
		return (bool)$this->config->get( 'WikiOasisMagicCloudflareAPIToken' )
			&& (bool)$this->config->get( 'WikiOasisMagicCloudflareZoneID' );
	}

	/**
	 * Translate our purge parameters into a Cloudflare purge_cache request body.
	 *
	 * @param array $params
	 * @return array Empty when there is nothing to purge.
	 */
	public function buildBody( array $params ): array {
		$body = [];
		foreach ( [ 'files' => 'urls', 'prefixes' => 'prefixes', 'hosts' => 'hosts' ] as $key => $param ) {
			$values = array_values( array_unique( array_filter( (array)( $params[$param] ?? [] ) ) ) );
			if ( $values ) {
				$body[$key] = $values;
			}
		}

		return $body;
	}

	/**
	 * Send a purge request to Cloudflare. Intended to be called from a deferred
	 * update, so it never blocks the request that triggered the change.
	 *
	 * @param array $params See the class documentation.
	 * @param string $hook Name of the hook requesting the purge, for logging.
	 * @return bool Whether the purge succeeded.
	 */
	public function purge( array $params, string $hook ): bool {
		$logger = LoggerFactory::getInstance( 'WikiOasisMagic' );

		$body = $this->buildBody( $params );
		if ( !$body ) {
			$logger->debug( 'Cloudflare purge skipped: nothing to purge', [
				'hook' => $hook,
			] );
			return true;
		}

		$apiToken = $this->config->get( 'WikiOasisMagicCloudflareAPIToken' );
		$zoneID = $this->config->get( 'WikiOasisMagicCloudflareZoneID' );

		if ( !$apiToken || !$zoneID ) {
			$logger->debug( 'Cloudflare purge skipped: credentials not configured', [
				'hook' => $hook,
				'purge' => $body,
			] );
			return true;
		}

		$logger->info( 'Cloudflare purge running', [
			'hook' => $hook,
			'purge' => $body,
		] );

		$guzzleClient = $this->httpRequestFactory->createGuzzleClient();

		try {
			$response = $guzzleClient->post(
				"https://api.cloudflare.com/client/v4/zones/{$zoneID}/purge_cache",
				[
					'headers' => [
						'Authorization' => "Bearer {$apiToken}",
						'Content-Type' => 'application/json',
					],
					'json' => $body,
				]
			);
			$logger->info( 'Cloudflare purge succeeded', [
				'hook' => $hook,
				'purge' => $body,
				'httpStatus' => $response->getStatusCode(),
			] );
			return true;
		} catch ( RequestException $e ) {
			$logger->error( 'Cloudflare purge failed', [
				'hook' => $hook,
				'purge' => $body,
				'error' => $e->getMessage(),
			] );
			return false;
		}
	}
}
