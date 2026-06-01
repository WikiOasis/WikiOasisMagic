<?php

namespace WikiOasis\WikiOasisMagic\HookHandlers;

use ManualLogEntry;
use MediaWiki\Config\Config;
use MediaWiki\Page\Hook\ArticlePurgeHook;
use MediaWiki\Hook\LocalFilePurgeThumbnailsHook;
use MediaWiki\Hook\PageMoveCompleteHook;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\Title\Title;
use WikiOasis\WikiOasisMagic\Jobs\CloudflarePurgeJob;

class CloudflarePurge implements
	PageSaveCompleteHook,
	PageDeleteCompleteHook,
	PageMoveCompleteHook,
	LocalFilePurgeThumbnailsHook,
	ArticlePurgeHook
{

	private Config $config;

	public function __construct( Config $config ) {
		$this->config = $config;
	}

	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ): void {
		LoggerFactory::getInstance( 'WikiOasisMagic' )->info( 'CloudflarePurge: PageSaveComplete hook fired', [
			'title' => $wikiPage->getTitle()->getPrefixedText(),
		] );
		$this->enqueuePurge( [ $wikiPage->getTitle()->getFullURL() ], 'PageSaveComplete' );
	}

	public function onPageDeleteComplete( ProperPageIdentity $page, Authority $deleter, string $reason, int $pageID, RevisionRecord $deletedRev, ManualLogEntry $logEntry, int $archivedRevisionCount ): void {
		$title = Title::castFromPageIdentity( $page );
		LoggerFactory::getInstance( 'WikiOasisMagic' )->info( 'CloudflarePurge: PageDeleteComplete hook fired', [
			'title' => $title ? $title->getPrefixedText() : '(unknown)',
		] );
		if ( $title ) {
			$this->enqueuePurge( [ $title->getFullURL() ], 'PageDeleteComplete' );
		}
	}

	public function onPageMoveComplete( $old, $new, $user, $pageid, $redirid, $reason, $revision ): void {
		LoggerFactory::getInstance( 'WikiOasisMagic' )->info( 'CloudflarePurge: PageMoveComplete hook fired', [
			'old' => $old->getDBkey(),
			'new' => $new->getDBkey(),
		] );
		$this->enqueuePurge( [
			Title::newFromLinkTarget( $old )->getFullURL(),
			Title::newFromLinkTarget( $new )->getFullURL(),
		], 'PageMoveComplete' );
	}

	public function onArticlePurge( $wikiPage ) {
		LoggerFactory::getInstance( 'WikiOasisMagic' )->info( 'CloudflarePurge: ArticlePurge hook fired', [
			'title' => $wikiPage->getTitle()->getPrefixedText(),
		] );
		$this->enqueuePurge( [ $wikiPage->getTitle()->getFullURL() ], 'ArticlePurge' );
	}

	public function onLocalFilePurgeThumbnails( $file, $archiveName, $urls ): void {
		LoggerFactory::getInstance( 'WikiOasisMagic' )->info( 'CloudflarePurge: LocalFilePurgeThumbnails hook fired', [
			'file' => $file->getName(),
		] );
		$purgeURLs = [ $this->expandURL( $file->getUrl() ) ];
		foreach ( $urls as $url ) {
			$purgeURLs[] = $this->expandURL( $url );
		}
		$this->enqueuePurge( $purgeURLs, 'LocalFilePurgeThumbnails' );
	}

	private function enqueuePurge( array $urls, string $hook ): void {
		$logger = LoggerFactory::getInstance( 'WikiOasisMagic' );
		$apiToken = $this->config->get( 'WikiOasisMagicCloudflareAPIToken' );
		$zoneID = $this->config->get( 'WikiOasisMagicCloudflareZoneID' );

		if ( !$apiToken || !$zoneID ) {
			$logger->warning( 'CloudflarePurge: credentials not configured, skipping purge', [
				'hook' => $hook,
				'hasToken' => (bool)$apiToken,
				'hasZoneID' => (bool)$zoneID,
			] );
			return;
		}

		$logger->info( 'Cloudflare purge job enqueued', [
			'hook' => $hook,
			'urls' => $urls,
		] );

		$job = new CloudflarePurgeJob( [ 'urls' => $urls ] );
		MediaWikiServices::getInstance()->getJobQueueGroup()->push( $job );
	}

	private function expandURL( string $url ): string {
		return (string)MediaWikiServices::getInstance()->getUrlUtils()->expand( $url, PROTO_INTERNAL );
	}
}
