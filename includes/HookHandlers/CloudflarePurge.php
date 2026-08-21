<?php

namespace WikiOasis\WikiOasisMagic\HookHandlers;

use ManualLogEntry;
use MediaWiki\Config\Config;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Hook\LocalFilePurgeThumbnailsHook;
use MediaWiki\Hook\PageMoveCompleteHook;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\Hook\ArticlePurgeHook;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\CreateWiki\Hooks\CreateWikiDeletionHook;
use Miraheze\ManageWiki\Helpers\Factories\ModuleFactory;
use Miraheze\ManageWiki\Hooks\ManageWikiDataStoreBuilderHook;
use Wikimedia\Rdbms\DBConnRef;
use WikiOasis\WikiOasisMagic\CloudflarePurger;

class CloudflarePurge implements
	PageSaveCompleteHook,
	PageDeleteCompleteHook,
	PageMoveCompleteHook,
	LocalFilePurgeThumbnailsHook,
	ArticlePurgeHook,
	CreateWikiDeletionHook,
	ManageWikiDataStoreBuilderHook
{

	/**
	 * ManageWiki core states whose change makes a wiki's cached content wrong
	 * everywhere: whether it is readable at all, or reachable at all.
	 */
	private const SITE_STATES = [ 'private', 'closed', 'deleted', 'locked' ];

	private Config $config;

	private CloudflarePurger $purger;

	public function __construct( Config $config, HttpRequestFactory $httpRequestFactory ) {
		$this->config = $config;
		$this->purger = new CloudflarePurger( $config, $httpRequestFactory );
	}

	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ): void {
		LoggerFactory::getInstance( 'WikiOasisMagic' )->info( 'CloudflarePurge: PageSaveComplete hook fired', [
			'title' => $wikiPage->getTitle()->getPrefixedText(),
		] );
		$this->deferURLPurge( [ $wikiPage->getTitle()->getFullURL() ], 'PageSaveComplete' );
		$this->maybePurgeLoadScriptForTitle( $wikiPage->getTitle(), 'PageSaveComplete' );
	}

	public function onPageDeleteComplete( ProperPageIdentity $page, Authority $deleter, string $reason, int $pageID, RevisionRecord $deletedRev, ManualLogEntry $logEntry, int $archivedRevisionCount ): void {
		$title = Title::castFromPageIdentity( $page );
		LoggerFactory::getInstance( 'WikiOasisMagic' )->info( 'CloudflarePurge: PageDeleteComplete hook fired', [
			'title' => $title ? $title->getPrefixedText() : '(unknown)',
		] );
		if ( $title ) {
			$this->deferURLPurge( [ $title->getFullURL() ], 'PageDeleteComplete' );
			$this->maybePurgeLoadScriptForTitle( $title, 'PageDeleteComplete' );
		}
	}

	public function onPageMoveComplete( $old, $new, $user, $pageid, $redirid, $reason, $revision ): void {
		LoggerFactory::getInstance( 'WikiOasisMagic' )->info( 'CloudflarePurge: PageMoveComplete hook fired', [
			'old' => $old->getDBkey(),
			'new' => $new->getDBkey(),
		] );
		$oldTitle = Title::newFromLinkTarget( $old );
		$newTitle = Title::newFromLinkTarget( $new );
		$this->deferURLPurge( [
			$oldTitle->getFullURL(),
			$newTitle->getFullURL(),
		], 'PageMoveComplete' );
		$this->maybePurgeLoadScriptForTitle( $oldTitle, 'PageMoveComplete' );
		$this->maybePurgeLoadScriptForTitle( $newTitle, 'PageMoveComplete' );
	}

	public function onArticlePurge( $wikiPage ) {
		LoggerFactory::getInstance( 'WikiOasisMagic' )->info( 'CloudflarePurge: ArticlePurge hook fired', [
			'title' => $wikiPage->getTitle()->getPrefixedText(),
		] );
		$this->deferURLPurge( [ $wikiPage->getTitle()->getFullURL() ], 'ArticlePurge' );
	}

	public function onLocalFilePurgeThumbnails( $file, $archiveName, $urls ): void {
		LoggerFactory::getInstance( 'WikiOasisMagic' )->info( 'CloudflarePurge: LocalFilePurgeThumbnails hook fired', [
			'file' => $file->getName(),
		] );
		$purgeURLs = [ $this->expandURL( $file->getUrl() ) ];
		foreach ( $urls as $url ) {
			$purgeURLs[] = $this->expandURL( $url );
		}
		$this->deferURLPurge( $purgeURLs, 'LocalFilePurgeThumbnails' );
	}

	/**
	 * @inheritDoc
	 *
	 * ManageWiki rebuilds a wiki's data store whenever any of its modules is
	 * committed, and this hook runs with the freshly built cache array before it
	 * replaces the one on disk. Diffing the two tells us what actually changed,
	 * so a settings-only edit does not trigger a purge.
	 */
	public function onManageWikiDataStoreBuilder(
		ModuleFactory $moduleFactory,
		string $dbname,
		array &$cacheArray
	): void {
		$cachedData = $this->readManageWikiCache( $dbname );
		if ( $cachedData === null ) {
			// Nothing was cached for this wiki yet, so there is nothing stale
			// in Cloudflare to drop either.
			return;
		}

		$oldExtensions = (array)( $cachedData['extensions'] ?? [] );
		$newExtensions = (array)( $cacheArray['extensions'] ?? [] );
		sort( $oldExtensions );
		sort( $newExtensions );

		if ( $oldExtensions !== $newExtensions ) {
			// Enabling or disabling an extension changes which modules
			// ResourceLoader serves, and their contents.
			$this->deferLoadScriptPurge( $dbname, 'ManageWikiDataStoreBuilder' );
		}

		$oldStates = (array)( $cachedData['states'] ?? [] );
		$newStates = (array)( $cacheArray['states'] ?? [] );
		foreach ( self::SITE_STATES as $state ) {
			if ( ( $oldStates[$state] ?? null ) !== ( $newStates[$state] ?? null ) ) {
				$this->deferSitePurge( $dbname, 'ManageWikiDataStoreBuilder' );
				break;
			}
		}
	}

	/** @inheritDoc */
	public function onCreateWikiDeletion( DBConnRef $cwdb, string $dbname ): void {
		LoggerFactory::getInstance( 'WikiOasisMagic' )->info( 'CloudflarePurge: CreateWikiDeletion hook fired', [
			'dbname' => $dbname,
		] );
		$this->deferSitePurge( $dbname, 'CreateWikiDeletion' );
	}

	/**
	 * Purge load.php when a page that ResourceLoader serves through it changes.
	 */
	private function maybePurgeLoadScriptForTitle( Title $title, string $hook ): void {
		if ( $title->getNamespace() !== NS_MEDIAWIKI ) {
			return;
		}

		if ( !str_ends_with( strtolower( $title->getDBkey() ), '.css' ) ) {
			return;
		}

		$this->deferLoadScriptPurge( WikiMap::getCurrentWikiId(), $hook );
	}

	/**
	 * Drop every cached load.php response for a wiki. The exact URLs cannot be
	 * enumerated - they vary by module list, skin, language and version - so
	 * this purges by prefix.
	 */
	private function deferLoadScriptPurge( string $dbname, string $hook ): void {
		$prefix = $this->loadScriptPrefix( $dbname );
		if ( $prefix === null ) {
			LoggerFactory::getInstance( 'WikiOasisMagic' )->warning(
				'CloudflarePurge: could not resolve load.php prefix', [
					'dbname' => $dbname,
					'hook' => $hook,
				]
			);
			return;
		}

		$this->deferPurge( [ 'prefixes' => [ $prefix ] ], $hook );
	}

	/**
	 * Drop everything cached for a wiki's hostname.
	 */
	private function deferSitePurge( string $dbname, string $hook ): void {
		$host = $this->siteHost( $dbname );
		if ( $host === null ) {
			LoggerFactory::getInstance( 'WikiOasisMagic' )->warning(
				'CloudflarePurge: could not resolve host for wiki', [
					'dbname' => $dbname,
					'hook' => $hook,
				]
			);
			return;
		}

		$this->deferPurge( [ 'hosts' => [ $host ] ], $hook );
	}

	private function deferURLPurge( array $urls, string $hook ): void {
		$this->deferPurge( [ 'urls' => $urls ], $hook );
	}

	/**
	 * @param array $params Purge parameters, see {@see CloudflarePurger}.
	 * @param string $hook Name of the hook requesting the purge, for logging.
	 */
	private function deferPurge( array $params, string $hook ): void {
		if ( !$this->purger->isConfigured() ) {
			LoggerFactory::getInstance( 'WikiOasisMagic' )->warning(
				'CloudflarePurge: credentials not configured, skipping purge', [
					'hook' => $hook,
				]
			);
			return;
		}

		// Purge from a deferred update rather than the job queue. Deferred updates
		// run after the response has been sent, so the Cloudflare round trip costs
		// the user nothing, and they only run once the request that triggered the
		// change has committed - we never purge on the back of a rolled back edit
		// or ManageWiki save.
		$purger = $this->purger;
		DeferredUpdates::addCallableUpdate(
			static fn () => $purger->purge( $params, $hook )
		);
	}

	/**
	 * Read the data store ManageWiki currently has cached for a wiki.
	 *
	 * @return array|null Null when nothing is cached, or the cache is unreadable.
	 */
	private function readManageWikiCache( string $dbname ): ?array {
		// Mirrors ManageWiki's own fallback in DataStoreFactory.
		$cacheDir = $this->config->get( 'ManageWikiCacheDirectory' )
			?: $this->config->get( MainConfigNames::CacheDirectory );
		if ( !$cacheDir ) {
			return null;
		}

		$path = "$cacheDir/$dbname.php";
		if ( !is_file( $path ) ) {
			return null;
		}

		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		$data = @( static fn ( string $file ): mixed => include $file )( $path );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * The scheme-less `host/path` prefix of a wiki's load.php, as Cloudflare's
	 * prefix purging expects it.
	 */
	private function loadScriptPrefix( string $dbname ): ?string {
		$url = $this->wikiURL( $dbname, $this->config->get( MainConfigNames::LoadScript ) );
		if ( $url === null ) {
			return null;
		}

		$parsed = MediaWikiServices::getInstance()->getUrlUtils()->parse( $url );
		if ( !isset( $parsed['host'] ) ) {
			return null;
		}

		return $parsed['host'] . ( $parsed['path'] ?? '' );
	}

	/**
	 * The hostname a wiki is served from.
	 */
	private function siteHost( string $dbname ): ?string {
		$url = $this->wikiURL( $dbname, '/' );
		if ( $url === null ) {
			return null;
		}

		$parsed = MediaWikiServices::getInstance()->getUrlUtils()->parse( $url );

		return $parsed['host'] ?? null;
	}

	/**
	 * Resolve a path on a wiki to a canonical absolute URL. $dbname may name a
	 * wiki other than the current one, which is the normal case for ManageWiki
	 * and CreateWiki changes made from a central wiki.
	 */
	private function wikiURL( string $dbname, string $path ): ?string {
		// An absolute load script is already all we need.
		if ( str_contains( $path, '//' ) || $dbname === WikiMap::getCurrentWikiId() ) {
			return $this->expandCanonicalURL( $path );
		}

		$wiki = WikiMap::getWiki( $dbname );
		if ( $wiki === null ) {
			return null;
		}

		return $wiki->getCanonicalServer() . $path;
	}

	private function expandURL( string $url ): string {
		return (string)MediaWikiServices::getInstance()->getUrlUtils()->expand( $url, PROTO_INTERNAL );
	}

	private function expandCanonicalURL( string $url ): ?string {
		return MediaWikiServices::getInstance()->getUrlUtils()->expand( $url, PROTO_CANONICAL );
	}
}
