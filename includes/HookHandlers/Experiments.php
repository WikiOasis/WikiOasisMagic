<?php

namespace WikiOasis\WikiOasisMagic\HookHandlers;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\Hook\ApiCheckCanExecuteHook;
use MediaWiki\Auth\Hook\AuthPreserveQueryParamsHook;
use MediaWiki\ChangeTags\Hook\ChangeTagsAfterUpdateTagsHook;
use MediaWiki\Context\RequestContext;
use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\MediaWikiServices;
use MediaWiki\RecentChanges\RecentChange;
use MediaWiki\Request\WebRequest;
use MediaWiki\SpecialPage\Hook\SpecialPageBeforeExecuteHook;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\User\UserIdentity;
use Miraheze\ManageWiki\Helpers\Factories\ModuleFactory;
use Psr\Log\LoggerInterface;
use Throwable;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentDataStore;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentManager;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentStateStore;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentTracker;
use WikiOasis\WikiOasisMagic\Experiments\WikiRollout;
use function dirname;

class Experiments implements
	ApiCheckCanExecuteHook,
	AuthPreserveQueryParamsHook,
	ChangeTagsAfterUpdateTagsHook,
	LoadExtensionSchemaUpdatesHook,
	PageSaveCompleteHook,
	SpecialPageBeforeExecuteHook
{

	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly ExperimentTracker $tracker,
		private readonly WikiRollout $rollout,
	) {
		$this->logger = LoggerFactory::getInstance( 'WikiOasisMagic' );
	}

	/**
	 * @param array &$params
	 * @param array $options
	 */
	public function onAuthPreserveQueryParams( array &$params, array $options ) {
		$request = $options['request'];
		$params += [
			ExperimentManager::OVERRIDE_PARAM => $request->getRawVal( ExperimentManager::OVERRIDE_PARAM ),
			ExperimentManager::OVERRIDE_TOKEN_PARAM => $request->getRawVal( ExperimentManager::OVERRIDE_TOKEN_PARAM ),
		];
	}

	public static function onExtensionFunction(): void {
		try {
			/** @var WikiRollout $rollout */
			$rollout = MediaWikiServices::getInstance()->get( 'WikiOasisMagic.WikiRollout' );
			$rollout->applyToGlobals();
		} catch ( Throwable $e ) {
			LoggerFactory::getInstance( 'WikiOasisMagic' )->error(
				'Could not apply wiki experiments: {message}',
				[ 'message' => $e->getMessage(), 'exception' => $e ]
			);
		}
	}

	/**
	 * @param ModuleFactory $moduleFactory
	 * @param string $dbname
	 * @param array &$cacheArray
	 */
	public function onManageWikiDataStoreBuilder(
		ModuleFactory $moduleFactory,
		string $dbname,
		array &$cacheArray
	): void {
		try {
			$this->rollout->applyToCache( $dbname, $cacheArray );
		} catch ( Throwable $e ) {
			$this->logger->error( 'Could not apply wiki experiments to {wiki}: {message}', [
				'wiki' => $dbname,
				'message' => $e->getMessage(),
				'exception' => $e,
			] );
		}
	}

	public function onCreateWikiCreation( string $dbname, bool $private ): void {
		try {
			$this->rollout->onWikiCreated( $dbname );
		} catch ( Throwable $e ) {
			$this->logger->error( 'Could not queue wiki experiments for {wiki}: {message}', [
				'wiki' => $dbname,
				'message' => $e->getMessage(),
				'exception' => $e,
			] );
		}
	}

	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ) {
		if ( !$editResult->isNullEdit() ) {
			$this->tracker->trackEdit( $user, $wikiPage->getNamespace(), $this->getPerformerRequest( $user ) );
		}
	}

	public function onChangeTagsAfterUpdateTags(
		$addedTags, $removedTags, $prevTags, $rc_id, $rev_id, $log_id, $params, $rc, $user
	) {
		if ( !$addedTags ) {
			return;
		}

		$performer = $user ?? ( $rc instanceof RecentChange ? $rc->getPerformerIdentity() : null );
		$this->tracker->trackTags( $addedTags, $performer, $this->getPerformerRequest( $performer ) );
	}

	/**
	 * @param ManualLogEntry $logEntry
	 * @return bool|void
	 */
	public function onManualLogEntryBeforePublish( $logEntry ) {
		$performer = $logEntry->getPerformerIdentity();
		$this->tracker->trackLog(
			$logEntry->getType(),
			$logEntry->getSubtype(),
			$performer,
			$this->getPerformerRequest( $performer )
		);
	}

	public function onSpecialPageBeforeExecute( $special, $subPage ) {
		$this->tracker->trackSpecialPage( $special->getName(), $special->getUser(), $special->getRequest() );
	}

	/**
	 * @param ApiBase $module
	 */
	public function onApiCheckCanExecute( $module, $user, &$message ) {
		$this->tracker->trackApiModule( $module->getModulePath(), $user, $module->getRequest() );
		return true;
	}

	private function getPerformerRequest( ?UserIdentity $performer ): ?WebRequest {
		if ( !$performer || MW_ENTRY_POINT === 'cli' ) {
			return null;
		}

		$request = RequestContext::getMain()->getRequest();
		return $request->getSession()->getUser()->getName() === $performer->getName() ? $request : null;
	}

	/**
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = dirname( __DIR__, 2 ) . '/sql/' . $updater->getDB()->getType();
		foreach ( [
			'wo_experiment_state',
			ExperimentDataStore::UNIT_TABLE,
			ExperimentDataStore::EVENT_TABLE,
			ExperimentDataStore::DAILY_TABLE,
			ExperimentDataStore::EXTENSION_TABLE,
			ExperimentDataStore::SYNC_TABLE,
		] as $table ) {
			$updater->addExtensionUpdateOnVirtualDomain( [
				ExperimentStateStore::VIRTUAL_DOMAIN,
				'addTable',
				$table,
				"$dir/$table.sql",
				true,
			] );
		}
	}
}
