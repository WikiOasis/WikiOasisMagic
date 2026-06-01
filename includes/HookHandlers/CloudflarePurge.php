<?php

namespace WikiOasis\WikiOasisMagic\HookHandlers;

use GuzzleHttp\Exception\RequestException;
use ManualLogEntry;
use MediaWiki\Config\Config;
use MediaWiki\Hook\ArticlePurgeHook;
use MediaWiki\Hook\LocalFilePurgeThumbnailsHook;
use MediaWiki\Hook\PageMoveCompleteHook;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\Title\Title;
use WikiPage;

class CloudflarePurge implements
	PageSaveCompleteHook,
	PageDeleteCompleteHook,
	PageMoveCompleteHook,
	LocalFilePurgeThumbnailsHook,
	ArticlePurgeHook
{

	private Config $config;
	private HttpRequestFactory $httpRequestFactory;

	public function __construct( Config $config, HttpRequestFactory $httpRequestFactory ) {
		$this->config = $config;
		$this->httpRequestFactory = $httpRequestFactory;
	}

	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ): void {
		$this->cachePurge( [ $wikiPage->getTitle()->getFullURL() ] );
	}

	public function onPageDeleteComplete( ProperPageIdentity $page, Authority $deleter, string $reason, int $pageID, RevisionRecord $deletedRev, ManualLogEntry $logEntry, int $archivedRevisionCount ): void {
		$title = Title::castFromPageIdentity( $page );
		if ( $title ) {
			$this->cachePurge( [ $title->getFullURL() ] );
		}
	}

	public function onPageMoveComplete( $old, $new, $user, $pageid, $redirid, $reason, $revision ): void {
		$this->cachePurge( [
			Title::newFromLinkTarget( $old )->getFullURL(),
			Title::newFromLinkTarget( $new )->getFullURL(),
		] );
	}

	public function onArticlePurge( WikiPage $wikiPage ) {
		$this->cachePurge( [ $wikiPage->getTitle()->getFullURL() ] );
	}

	public function onLocalFilePurgeThumbnails( $file, $archiveName, $urls ): void {
		$purgeURLs = [ $this->expandURL( $file->getUrl() ) ];
		foreach ( $urls as $url ) {
			$purgeURLs[] = $this->expandURL( $url );
		}
		$this->cachePurge( $purgeURLs );
	}

	private function cachePurge( array $urls ): void {
		$apiToken = $this->config->get( 'WikiOasisMagicCloudflareAPIToken' );
		$zoneID = $this->config->get( 'WikiOasisMagicCloudflareZoneID' );

		if ( !$apiToken || !$zoneID ) {
			return;
		}

		$guzzleClient = $this->httpRequestFactory->createGuzzleClient();

		try {
			$guzzleClient->post(
				"https://api.cloudflare.com/client/v4/zones/{$zoneID}/purge_cache",
				[
					'headers' => [
						'Authorization' => "Bearer {$apiToken}",
						'Content-Type' => 'application/json',
					],
					'json' => [ 'files' => $urls ],
				]
			);
		} catch ( RequestException $e ) {
			wfDebugLog( 'WikiOasisMagic', 'Cloudflare purge failed: ' . $e->getMessage() );
		}
	}

	private function expandURL( string $url ): string {
		return (string)MediaWikiServices::getInstance()->getUrlUtils()->expand( $url, PROTO_INTERNAL );
	}
}