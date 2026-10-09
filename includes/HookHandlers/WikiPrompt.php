<?php

namespace WikiOasis\WikiOasisMagic\HookHandlers;

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\User\UserIdentity;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Throwable;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentDataStore;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentManager;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentTracker;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentUnit;
use WikiOasis\WikiOasisMagic\Experiments\WikiFarm;
use function sha1;

class WikiPrompt implements BeforePageDisplayHook {

	public const EXPERIMENT = 'wiki_prompt';

	public const MODULE = 'ext.wikioasismagic.wikiprompt';

	public const CONFIG_VAR = 'wgWikiOasisWikiPrompt';

	public const TARGET = 'Special:RequestWiki';

	public const NEW_ACCOUNT_SECONDS = 3600;

	public function __construct(
		private readonly ExperimentManager $manager,
		private readonly ExperimentTracker $tracker,
		private readonly ExperimentDataStore $dataStore,
		private readonly WikiFarm $farm,
		private readonly WANObjectCache $cache,
		private readonly ?CreateWikiDatabaseUtils $createWikiDatabaseUtils = null,
	) {
	}

	/**
	 * @param OutputPage $out
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		if ( !$this->isPromptableView( $out ) ) {
			return;
		}

		$experiment = $this->manager->getExperiment( self::EXPERIMENT );
		$url = $experiment ? $this->farm->getCentralUrl( self::TARGET ) : null;
		if ( !$experiment || $url === null ) {
			return;
		}

		$user = $out->getUser();
		$assignment = $this->manager->assignUser( self::EXPERIMENT, $user, $out->getRequest() );
		if ( $assignment->forced ) {
			if ( !$assignment->isLegacy() ) {
				$this->addPrompt( $out, $url, true );
			}
			return;
		}

		$unit = $assignment->unit;
		if ( !$unit || !$unit->exists() ) {
			return;
		}

		$counted = $assignment->isCounted() && $this->manager->isRunning( $experiment, $unit );
		if ( !$counted && $assignment->isLegacy() ) {
			return;
		}

		if ( !$this->dataStore->isAvailable() || $this->isNewAccount( $unit ) ) {
			return;
		}

		if ( $this->dataStore->getUnit( self::EXPERIMENT, $unit->getStorageKey() ) !== null ) {
			return;
		}

		$requested = $this->hasRequestedWiki( $user );
		if ( $requested === null ) {
			return;
		}

		$this->tracker->expose( $assignment, !$requested );
		if ( !$requested && !$assignment->isLegacy() ) {
			$this->addPrompt( $out, $url, false );
		}
	}

	private function isPromptableView( OutputPage $out ): bool {
		$user = $out->getUser();
		if ( !$user->isNamed() || $user->isBot() ) {
			return false;
		}

		$title = $out->getTitle();
		if ( !$title || !$title->canExist() || $out->getActionName() !== 'view' || $out->isPrintable() ) {
			return false;
		}

		$request = $out->getRequest();
		foreach ( [ 'diff', 'veaction', 'redirect' ] as $param ) {
			if ( $request->getRawVal( $param ) !== null ) {
				return false;
			}
		}

		return $request->getRawVal( 'source' ) !== 'signup' &&
			$request->getRawVal( 'display' ) !== 'popup';
	}

	private function isNewAccount( ExperimentUnit $unit ): bool {
		$created = $unit->getCreated();
		$registered = $created !== null ? ConvertibleTimestamp::convert( TS_UNIX, $created ) : false;
		return $registered !== false &&
			(int)$registered > (int)ConvertibleTimestamp::now( TS_UNIX ) - self::NEW_ACCOUNT_SECONDS;
	}

	protected function hasRequestedWiki( UserIdentity $user ): ?bool {
		$databaseUtils = $this->createWikiDatabaseUtils;
		if ( !$databaseUtils ) {
			return false;
		}

		$requested = $this->cache->getWithSetCallback(
			$this->cache->makeGlobalKey( 'wikioasismagic-wikiprompt-requested', sha1( $user->getName() ) ),
			WANObjectCache::TTL_HOUR,
			static function ( $old, &$ttl ) use ( $user, $databaseUtils ) {
				try {
					$found = $databaseUtils->getCentralWikiReplicaDB()->newSelectQueryBuilder()
						->select( 'log_id' )
						->from( 'logging' )
						->join( 'actor', null, 'actor_id = log_actor' )
						->where( [
							'actor_name' => $user->getName(),
							'log_type' => 'farmer',
							'log_action' => 'requestwiki',
						] )
						->limit( 1 )
						->caller( self::class . '::hasRequestedWiki' )
						->fetchField();
				} catch ( Throwable $e ) {
					LoggerFactory::getInstance( 'WikiOasisMagic' )->warning(
						'Could not check whether a user requested a wiki: {message}',
						[ 'message' => $e->getMessage(), 'exception' => $e ]
					);
					return false;
				}

				if ( $found !== false ) {
					$ttl = WANObjectCache::TTL_MONTH;
					return 1;
				}
				return 0;
			}
		);

		return $requested === false ? null : $requested === 1;
	}

	private function addPrompt( OutputPage $out, string $url, bool $preview ): void {
		$out->addModules( self::MODULE );
		$out->addJsConfigVars( self::CONFIG_VAR, [
			'experiment' => self::EXPERIMENT,
			'url' => $url,
			'preview' => $preview,
		] );
	}
}
