<?php

namespace WikiOasis\WikiOasisMagic\Experiments;

use MediaWiki\Config\Config;
use MediaWiki\Config\SiteConfiguration;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\Json\FormatJson;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
use Miraheze\ManageWiki\Helpers\Factories\DataStoreFactory;
use Miraheze\ManageWiki\Helpers\Factories\ModuleFactory;
use Psr\Log\LoggerInterface;
use Throwable;
use Wikimedia\Rdbms\ILBFactory;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use WikiOasis\WikiOasisMagic\Jobs\ExperimentLogJob;
use WikiOasis\WikiOasisMagic\Jobs\ExperimentSyncJob;
use function array_diff;
use function array_intersect;
use function array_key_exists;
use function array_keys;
use function array_unique;
use function array_values;
use function in_array;
use function is_array;
use function is_string;
use function ksort;
use function sha1;

class WikiRollout {

	public const OUTCOME_UNCHANGED = 'unchanged';
	public const OUTCOME_STORED = 'stored';
	public const OUTCOME_ENABLED = 'enabled';
	public const OUTCOME_DISABLED = 'disabled';
	public const OUTCOME_REBUILT = 'rebuilt';
	public const OUTCOME_FAILED = 'failed';
	public const OUTCOME_SKIPPED = 'skipped';

	public function __construct(
		private readonly ExperimentManager $manager,
		private readonly UnitFactory $unitFactory,
		private readonly ExperimentTracker $tracker,
		private readonly ExperimentDataStore $dataStore,
		private readonly WikiFarm $farm,
		private readonly Config $mainConfig,
		private readonly ExtensionRegistry $extensionRegistry,
		private readonly JobQueueGroupFactory $jobQueueGroupFactory,
		private readonly ILBFactory $lbFactory,
		private readonly LoggerInterface $logger,
		private readonly ?SiteConfiguration $siteConfiguration = null,
	) {
	}

	public function hasManageWiki(): bool {
		return $this->extensionRegistry->isLoaded( 'ManageWiki' );
	}

	public function isInScope( Experiment $experiment, ExperimentUnit $unit ): bool {
		return $experiment->appliesToWiki( $unit->id, $this->farm->isCentralWiki( $unit->id ) ) &&
			$experiment->matchesPopulation( $unit->getCreated() );
	}

	public function applyToCache( string $dbname, array &$cacheArray ): void {
		$ownSettings = (array)( $cacheArray['settings'] ?? [] );
		$enabled = $this->namesToKeys( (array)( $cacheArray['extensions'] ?? [] ) );
		$created = is_string( $cacheArray['created'] ?? null ) ? $cacheArray['created'] : false;
		$owned = null;

		foreach ( $this->manager->getExperimentsByUnit( ExperimentUnit::WIKI ) as $experiment ) {
			try {
				$unit = $this->unitFactory->forWiki( $dbname, $created );
				if ( !$this->isInScope( $experiment, $unit ) ) {
					continue;
				}

				$ownKeys = $enabled;
				if ( $experiment->getAllExtensions() ) {
					$owned ??= $this->dataStore->getOwnedExtensions( $dbname );
					$ownKeys = $this->getOwnKeys( $experiment, $enabled, $owned );
				}

				$assignment = $this->manager->assign( $experiment->name, $unit );
				$eligible = $this->isEligible( $experiment, $ownKeys, $ownSettings, $dbname );
				if ( !$experiment->getAllExtensions() ) {
					$this->tracker->expose( $assignment, $eligible );
				}

				if ( $eligible ) {
					foreach ( $experiment->getConfig( $assignment->variant ) as $variable => $value ) {
						$cacheArray['settings'][$variable] = $value;
					}
				}
			} catch ( Throwable $e ) {
				$this->logger->error( 'Could not apply experiment {experiment} to {wiki}: {message}', [
					'experiment' => $experiment->name,
					'wiki' => $dbname,
					'message' => $e->getMessage(),
					'exception' => $e,
				] );
			}
		}
	}

	/**
	 * @param Experiment $experiment
	 * @param array|null $previous
	 * @param string $dbname
	 * @param string|null $created
	 * @param bool $force
	 * @return string
	 */
	public function syncAndApply(
		Experiment $experiment,
		?array $previous,
		string $dbname,
		?string $created,
		bool $force = false
	): string {
		try {
			$outcome = $this->syncWiki( $experiment, $previous, $dbname, $created, $force );
			$this->lbFactory->commitPrimaryChanges( __METHOD__ );
		} catch ( Throwable $e ) {
			$this->lbFactory->rollbackPrimaryChanges( __METHOD__ );
			$this->dataStore->clearClaims();
			throw $e;
		}

		if ( in_array( $outcome, [ self::OUTCOME_ENABLED, self::OUTCOME_DISABLED, self::OUTCOME_REBUILT ], true ) ) {
			$this->rebuildCache( $dbname );
			$this->lbFactory->commitPrimaryChanges( __METHOD__ );
		}

		$this->dataStore->clearClaims();
		return $outcome;
	}

	/**
	 * @param array $previous
	 * @param string $dbname
	 * @param string|null $created
	 * @return string
	 */
	public function cleanUpAndApply( array $previous, string $dbname, ?string $created ): string {
		$experiment = $this->fromRecord( $previous );
		if ( !$experiment || !$experiment->getAllConfigVariables() ) {
			return self::OUTCOME_UNCHANGED;
		}

		$unit = $this->unitFactory->forWiki( $dbname, $created );
		if ( $this->describeAppliedConfig( $experiment, (string)( $previous['status'] ?? '' ), $unit ) === [] ) {
			return self::OUTCOME_UNCHANGED;
		}

		$this->rebuildCache( $dbname );
		$this->lbFactory->commitPrimaryChanges( __METHOD__ );
		return self::OUTCOME_REBUILT;
	}

	/**
	 * @param Experiment $experiment
	 * @param array|null $previous
	 * @param string $dbname
	 * @param string|null $created
	 * @param bool $force
	 * @return string
	 */
	public function syncWiki(
		Experiment $experiment,
		?array $previous,
		string $dbname,
		?string $created,
		bool $force = false
	): string {
		$unit = $this->unitFactory->forWiki( $dbname, $created );
		$inScope = $this->isInScope( $experiment, $unit );
		$assignment = $this->manager->assign( $experiment->name, $unit );

		$own = [ 'extensions' => [], 'settings' => [] ];
		if ( $experiment->changesWikiConfig() ) {
			$own = $this->getOwnConfiguration( $dbname );
			if ( $own === null ) {
				return self::OUTCOME_SKIPPED;
			}
		}

		$owned = $experiment->getAllExtensions() ? $this->dataStore->getOwnedExtensions( $dbname, true ) : [];
		$mine = array_keys( $owned, $experiment->name, true );
		$eligible = $inScope && $this->isEligible(
			$experiment,
			$this->getOwnKeys( $experiment, $own['extensions'], $owned ),
			$own['settings'],
			$dbname
		);

		$outcome = self::OUTCOME_UNCHANGED;
		if ( $experiment->getAllExtensions() ) {
			$desired = $eligible ? array_keys( $this->getExtensions( $experiment, $assignment->variant ) ) : [];
			$reconciled = $this->reconcileExtensions( $experiment, $dbname, $desired, $own['extensions'], $mine );
			if ( $reconciled === self::OUTCOME_FAILED ) {
				$eligible = false;
			}
			$outcome = $reconciled ?? $outcome;
		}

		if ( $inScope ) {
			if ( $this->tracker->storeExposure( $assignment, $eligible, $dbname, true ) && $outcome === self::OUTCOME_UNCHANGED ) {
				$outcome = self::OUTCOME_STORED;
			}
		} else {
			$stored = $this->dataStore->getUnit( $experiment->name, $unit->getStorageKey(), true );
			if ( $stored && $stored['eligible'] ) {
				$this->dataStore->markIneligible( $experiment->name, $unit->getStorageKey() );
				$outcome = $outcome === self::OUTCOME_UNCHANGED ? self::OUTCOME_STORED : $outcome;
			}
		}

		if ( !$experiment->getAllConfigVariables() || $outcome === self::OUTCOME_FAILED ) {
			return $outcome;
		}

		$now = $eligible ?
			$this->describeAppliedConfig( $experiment, $experiment->getStatus( ConvertibleTimestamp::now( TS_MW ) ), $unit ) :
			[];
		$before = null;
		$previousExperiment = $previous ? $this->fromRecord( $previous ) : null;
		if ( $previousExperiment ) {
			$before = $eligible ?
				$this->describeAppliedConfig( $previousExperiment, (string)( $previous['status'] ?? '' ), $unit ) :
				[];
		}

		$changed = $force || ( $before !== null && $now !== $before );
		if ( $changed && !in_array( $outcome, [ self::OUTCOME_ENABLED, self::OUTCOME_DISABLED ], true ) ) {
			return self::OUTCOME_REBUILT;
		}

		return $outcome;
	}

	/**
	 * @return array<string,string>
	 */
	public function sweep(): array {
		if ( !$this->dataStore->isAvailable() ) {
			return [];
		}

		$records = $this->dataStore->getSyncRecords();
		$actions = [];

		foreach ( $this->manager->getExperimentsByUnit( ExperimentUnit::WIKI ) as $name => $experiment ) {
			$stored = $records[$name] ?? null;
			if ( $stored && $stored['fingerprint'] === $this->fingerprint( $this->makeRecord( $experiment ) ) ) {
				continue;
			}

			$this->queueChange( $experiment, $stored );
			$actions[$name] = $stored ? 'changed' : 'new';
		}

		foreach ( $records as $name => $stored ) {
			if ( $this->manager->getExperiment( $name ) ) {
				continue;
			}

			$this->jobQueueGroupFactory->makeJobQueueGroup( $this->farm->getCentralWiki() )->push(
				ExperimentSyncJob::newCleanupSpec( $name, $stored['state'] )
			);
			$this->dataStore->deleteSyncRecord( $name );
			$actions[$name] = 'removed';
		}

		return $actions;
	}

	/**
	 * @param string $experiment
	 * @param string[] $wikis
	 * @param array|null $previous
	 * @param bool $force
	 */
	public function queueSync(
		string $experiment,
		array $wikis = [],
		?array $previous = null,
		bool $force = false
	): void {
		$this->jobQueueGroupFactory->makeJobQueueGroup( $this->farm->getCentralWiki() )->push(
			ExperimentSyncJob::newSpec( $experiment, $wikis, $previous, $force )
		);
	}

	public function onWikiCreated( string $dbname ): void {
		foreach ( $this->manager->getExperimentsByUnit( ExperimentUnit::WIKI ) as $experiment ) {
			if ( $experiment->getAllExtensions() ) {
				$this->queueSync( $experiment->name, [ $dbname ] );
			}
		}
	}

	public function syncExperiment( Experiment $experiment ): void {
		if ( $experiment->unit !== ExperimentUnit::WIKI || !$this->dataStore->isAvailable() ) {
			return;
		}

		$this->queueChange( $experiment, $this->dataStore->getSyncRecords()[$experiment->name] ?? null );
	}

	/**
	 * @param Experiment $experiment
	 * @param array|null $stored
	 */
	private function queueChange( Experiment $experiment, ?array $stored ): void {
		$this->queueSync(
			$experiment->name,
			[],
			$stored['state'] ?? null,
			$stored === null && $experiment->getAllConfigVariables() !== []
		);
		$this->markApplied( $experiment );
	}

	public function markApplied( Experiment $experiment ): void {
		$record = $this->makeRecord( $experiment );
		$this->dataStore->saveSyncRecord( $experiment->name, $this->fingerprint( $record ), $record );
	}

	private function fingerprint( array $record ): string {
		return sha1( FormatJson::encode( $record, false, FormatJson::ALL_OK ) );
	}

	public function makeRecord( Experiment $experiment ): array {
		return [
			'spec' => $experiment->toSpec(),
			'status' => $experiment->getStatus( ConvertibleTimestamp::now( TS_MW ) ),
		];
	}

	public function fromRecord( array $record ): ?Experiment {
		$spec = $record['spec'] ?? null;
		$name = $record['name'] ?? 'previous';
		return is_array( $spec ) ? Experiment::newFromArray( $name, $spec ) : null;
	}

	public function applyToGlobals(): void {
		if ( $this->hasManageWiki() ) {
			return;
		}

		foreach ( $this->manager->getExperimentsByUnit( ExperimentUnit::WIKI ) as $experiment ) {
			$unit = $this->unitFactory->forWiki();
			if ( !$this->isInScope( $experiment, $unit ) ) {
				continue;
			}

			$assignment = $this->manager->assign( $experiment->name, $unit );
			foreach ( $experiment->getConfig( $assignment->variant ) as $variable => $value ) {
				$GLOBALS[$variable] = $value;
			}
			$this->tracker->expose( $assignment );
		}
	}

	/**
	 * @return array<string,array{name:string}>
	 */
	public function getExtensions( Experiment $experiment, string $variant ): array {
		$extensions = [];
		foreach ( $experiment->getExtensions( $variant ) as $extension ) {
			$key = $this->resolveExtensionKey( $extension );
			if ( $key !== null ) {
				$extensions[$key] = [ 'name' => $this->getManageWikiExtensions()[$key]['name'] ];
			}
		}
		return $extensions;
	}

	/**
	 * @return string[]
	 */
	public function getProblems( Experiment $experiment ): array {
		if ( !$experiment->getAllExtensions() ) {
			return [];
		}

		if ( !$this->hasManageWiki() ) {
			return [ 'Extensions are switched on through ManageWiki, which is not installed, so they are ignored.' ];
		}

		$problems = [];
		foreach ( $experiment->getAllExtensions() as $extension ) {
			if ( $this->resolveExtensionKey( $extension ) === null ) {
				$problems[] = "\"$extension\" is not in \$wgManageWikiExtensions, so it is ignored.";
			}
		}
		return $problems;
	}

	/**
	 * @param Experiment $experiment
	 * @param string[] $ownKeys
	 * @param array $ownSettings
	 * @param string|null $dbname
	 */
	public function isEligible( Experiment $experiment, array $ownKeys, array $ownSettings, ?string $dbname = null ): bool {
		foreach ( $experiment->getAllConfigVariables() as $variable ) {
			if ( array_key_exists( $variable, $ownSettings ) ) {
				return false;
			}
			if ( $dbname !== null && $this->isSetForWiki( $variable, $dbname ) ) {
				return false;
			}
		}

		$definitions = $this->getManageWikiExtensions();
		foreach ( $experiment->getAllExtensions() as $extension ) {
			$key = $this->resolveExtensionKey( $extension );
			if ( $key === null ) {
				continue;
			}

			if ( in_array( $key, $ownKeys, true ) ) {
				return false;
			}

			$conflicts = $definitions[$key]['conflicts'] ?? false;
			if ( is_string( $conflicts ) && in_array( $conflicts, $ownKeys, true ) ) {
				return false;
			}

			foreach ( $ownKeys as $ownKey ) {
				if ( ( $definitions[$ownKey]['conflicts'] ?? false ) === $key ) {
					return false;
				}
			}
		}

		foreach ( $experiment->getVariantNames() as $variant ) {
			$variantKeys = array_keys( $this->getExtensions( $experiment, $variant ) );
			foreach ( $variantKeys as $key ) {
				foreach ( (array)( $definitions[$key]['requires']['extensions'] ?? [] ) as $requirement ) {
					$alternatives = is_array( $requirement ) ? $requirement : [ $requirement ];
					if ( !array_intersect( $alternatives, [ ...$ownKeys, ...$variantKeys ] ) ) {
						return false;
					}
				}
			}
		}

		return true;
	}

	public function rebuildCache( string $dbname ): void {
		if ( !$this->hasManageWiki() ) {
			return;
		}

		/** @var DataStoreFactory $dataStoreFactory */
		$dataStoreFactory = MediaWikiServices::getInstance()->get( 'ManageWikiDataStoreFactory' );
		$dataStoreFactory->newInstance( $dbname )->resetWikiData( isNewChanges: true );
	}

	/**
	 * @param Experiment $experiment
	 * @param string[] $enabled
	 * @param array<string,string> $owned
	 * @return string[]
	 */
	private function getOwnKeys( Experiment $experiment, array $enabled, array $owned ): array {
		$mine = array_keys( $owned, $experiment->name, true );
		$others = array_keys( array_diff( $owned, [ $experiment->name ] ) );
		return array_values( array_unique( [ ...array_diff( $enabled, $mine ), ...$others ] ) );
	}

	/**
	 * @param Experiment $experiment
	 * @param string $dbname
	 * @param string[] $desired
	 * @param string[] $enabled
	 * @param string[] $mine
	 * @return string|null
	 */
	private function reconcileExtensions(
		Experiment $experiment,
		string $dbname,
		array $desired,
		array $enabled,
		array $mine
	): ?string {
		$add = array_values( array_diff( $desired, $enabled, $mine ) );
		$release = array_values( array_diff( $mine, $desired ) );
		$disable = array_values( array_intersect( $release, $enabled ) );

		if ( !$add && !$release ) {
			return null;
		}

		foreach ( $add as $key ) {
			$this->dataStore->claimExtension( $dbname, $key, $experiment->name );
		}

		if ( $add || $disable ) {
			$errors = [];
			try {
				/** @var ModuleFactory $moduleFactory */
				$moduleFactory = MediaWikiServices::getInstance()->get( 'ManageWikiModuleFactory' );
				$module = $moduleFactory->extensions( $dbname );
				$module->add( $add );
				$module->remove( $disable );
				$module->commit();
				$errors = $module->getErrors();
			} catch ( Throwable $e ) {
				$errors = [ $e->getMessage() ];
			}

			if ( $errors ) {
				foreach ( $add as $key ) {
					$this->dataStore->releaseExtension( $dbname, $key, $experiment->name );
				}
				$this->logger->error( 'ManageWiki refused experiment {experiment} on {wiki}', [
					'experiment' => $experiment->name,
					'wiki' => $dbname,
					'add' => $add,
					'remove' => $disable,
					'errors' => $errors,
				] );
				return self::OUTCOME_FAILED;
			}

			$this->jobQueueGroupFactory->makeJobQueueGroup( $dbname )->lazyPush(
				ExperimentLogJob::newSpec( $dbname, [ ...$add, ...$disable ] )
			);
		}

		foreach ( $release as $key ) {
			$this->dataStore->releaseExtension( $dbname, $key, $experiment->name );
		}

		return $add ? self::OUTCOME_ENABLED : self::OUTCOME_DISABLED;
	}

	/**
	 * @return array{extensions:string[],settings:array}|null
	 */
	private function getOwnConfiguration( string $dbname ): ?array {
		if ( !$this->hasManageWiki() ) {
			return [ 'extensions' => [], 'settings' => [] ];
		}

		try {
			/** @var ModuleFactory $moduleFactory */
			$moduleFactory = MediaWikiServices::getInstance()->get( 'ManageWikiModuleFactory' );
			return [
				'extensions' => $moduleFactory->isEnabled( 'extensions' ) ?
					$moduleFactory->extensions( $dbname )->list() : [],
				'settings' => $moduleFactory->isEnabled( 'settings' ) ?
					$moduleFactory->settings( $dbname )->listAll() : [],
			];
		} catch ( Throwable $e ) {
			$this->logger->warning( 'Could not read the ManageWiki configuration of {wiki}: {message}', [
				'wiki' => $dbname,
				'message' => $e->getMessage(),
			] );
			return null;
		}
	}

	private function isSetForWiki( string $variable, string $dbname ): bool {
		$settings = $this->siteConfiguration?->settings[$variable] ?? null;
		return is_array( $settings ) &&
			( array_key_exists( $dbname, $settings ) || array_key_exists( "+$dbname", $settings ) );
	}

	private function describeAppliedConfig( Experiment $experiment, string $status, ExperimentUnit $unit ): array {
		if ( !$this->isInScope( $experiment, $unit ) ) {
			return [];
		}

		$variant = $experiment->defaultVariant;
		if (
			$status === Experiment::STATUS_RUNNING &&
			ExperimentManager::bucket( $experiment->salt . ':enrol', $unit->id ) < $experiment->rollout
		) {
			$variant = $experiment->pickVariant( ExperimentManager::bucket( $experiment->salt . ':variant', $unit->id ) );
		}

		$config = $experiment->getConfig( $variant );
		ksort( $config );
		return $config;
	}

	/**
	 * @param string[] $names
	 * @return string[]
	 */
	private function namesToKeys( array $names ): array {
		$keys = [];
		foreach ( $this->getManageWikiExtensions() as $key => $definition ) {
			if ( in_array( $definition['name'] ?? null, $names, true ) ) {
				$keys[] = $key;
			}
		}
		return $keys;
	}

	private function resolveExtensionKey( string $extension ): ?string {
		$definitions = $this->getManageWikiExtensions();
		if ( isset( $definitions[$extension]['name'] ) ) {
			return $extension;
		}

		foreach ( $definitions as $key => $definition ) {
			if ( ( $definition['name'] ?? null ) === $extension ) {
				return $key;
			}
		}

		return null;
	}

	/**
	 * @return array<string,array>
	 */
	private function getManageWikiExtensions(): array {
		return $this->mainConfig->has( 'ManageWikiExtensions' ) ?
			(array)$this->mainConfig->get( 'ManageWikiExtensions' ) :
			[];
	}
}
