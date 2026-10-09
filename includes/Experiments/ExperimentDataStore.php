<?php

namespace WikiOasis\WikiOasisMagic\Experiments;

use MediaWiki\Json\FormatJson;
use Psr\Log\LoggerInterface;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\DBError;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IMaintainableDatabase;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use function array_keys;
use function count;
use function is_array;
use function substr;

class ExperimentDataStore {

	public const UNIT_TABLE = 'wo_experiment_unit';
	public const EVENT_TABLE = 'wo_experiment_event';
	public const DAILY_TABLE = 'wo_experiment_daily';
	public const EXTENSION_TABLE = 'wo_experiment_extension';
	public const SYNC_TABLE = 'wo_experiment_sync';

	private const UNIT_TTL = 600;

	private ?bool $available = null;

	/** @var array<string,array<string,string|null>> */
	private array $claims = [];

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		private readonly BagOStuff $localCache,
		private readonly WANObjectCache $cache,
		private readonly LoggerInterface $logger,
	) {
	}

	public function isAvailable(): bool {
		if ( $this->available !== null ) {
			return $this->available;
		}

		$this->available = (bool)$this->localCache->getWithSetCallback(
			$this->localCache->makeGlobalKey( 'wikioasismagic-experiment-data', 'available' ),
			BagOStuff::TTL_MINUTE * 5,
			function ( &$ttl ) {
				try {
					$db = $this->getReplica();
					if ( !$db instanceof IMaintainableDatabase || $db->tableExists( self::UNIT_TABLE, self::class . '::isAvailable' ) ) {
						return 1;
					}
					$ttl = BagOStuff::TTL_MINUTE;
					return 0;
				} catch ( DBError $e ) {
					$ttl = BagOStuff::TTL_MINUTE;
					$this->logger->warning( 'Experiment data is unavailable: {message}', [
						'message' => $e->getMessage(),
					] );
					return 0;
				}
			}
		);

		return $this->available;
	}

	/**
	 * @return array{variant:string,enrolled:bool,eligible:bool,wiki:string,firstSeen:string}|null
	 */
	public function getUnit( string $experiment, string $unit, bool $latest = false ): ?array {
		if ( !$this->isAvailable() ) {
			return null;
		}

		if ( $latest ) {
			return $this->loadUnit( $experiment, $unit, true );
		}

		$cached = $this->cache->getWithSetCallback(
			$this->getUnitCacheKey( $experiment, $unit ),
			self::UNIT_TTL,
			function ( $old, &$ttl ) use ( $experiment, $unit ) {
				$row = $this->loadUnit( $experiment, $unit, false );
				if ( $row === null ) {
					$ttl = WANObjectCache::TTL_MINUTE;
					return [ 'none' => true ];
				}
				return $row;
			}
		);

		return isset( $cached['variant'] ) ? $cached : null;
	}

	public function saveUnit(
		string $experiment,
		string $unit,
		string $variant,
		bool $enrolled,
		bool $eligible,
		string $wiki
	): void {
		$dbw = $this->getPrimary();
		$now = $dbw->timestamp();
		$stored = $this->loadUnit( $experiment, $unit, true );
		$moved = $stored !== null && ( $stored['variant'] !== $variant || $stored['enrolled'] !== $enrolled );

		$set = [
			'wou_variant' => $variant,
			'wou_enrolled' => (int)$enrolled,
			'wou_eligible' => (int)$eligible,
			'wou_updated' => $now,
		];
		if ( $moved ) {
			$set['wou_first_seen'] = $now;
		}

		$dbw->newInsertQueryBuilder()
			->insertInto( self::UNIT_TABLE )
			->row( [
				'wou_experiment' => $experiment,
				'wou_unit' => $unit,
				'wou_variant' => $variant,
				'wou_enrolled' => (int)$enrolled,
				'wou_eligible' => (int)$eligible,
				'wou_wiki' => $wiki,
				'wou_first_seen' => $now,
				'wou_updated' => $now,
			] )
			->onDuplicateKeyUpdate()
			->uniqueIndexFields( [ 'wou_experiment', 'wou_unit' ] )
			->set( $set )
			->caller( __METHOD__ )
			->execute();

		if ( $moved ) {
			$dbw->newDeleteQueryBuilder()
				->deleteFrom( self::EVENT_TABLE )
				->where( [ 'woev_experiment' => $experiment, 'woev_unit' => $unit ] )
				->caller( __METHOD__ )
				->execute();
		}

		$this->cache->delete( $this->getUnitCacheKey( $experiment, $unit ) );
	}

	/**
	 * @return array<string,string>
	 */
	public function getOwnedExtensions( string $wiki, bool $latest = false ): array {
		if ( !$this->isAvailable() ) {
			return [];
		}

		$db = $latest ? $this->getPrimary() : $this->getReplica();
		$res = $db->newSelectQueryBuilder()
			->select( [ 'woee_extension', 'woee_experiment' ] )
			->from( self::EXTENSION_TABLE )
			->where( [ 'woee_wiki' => $wiki ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$owned = [];
		foreach ( $res as $row ) {
			$owned[$row->woee_extension] = $row->woee_experiment;
		}

		foreach ( $this->claims[$wiki] ?? [] as $extension => $experiment ) {
			if ( $experiment === null ) {
				unset( $owned[$extension] );
			} else {
				$owned[$extension] = $experiment;
			}
		}
		return $owned;
	}

	public function claimExtension( string $wiki, string $extension, string $experiment ): void {
		$dbw = $this->getPrimary();
		$dbw->newInsertQueryBuilder()
			->insertInto( self::EXTENSION_TABLE )
			->ignore()
			->row( [
				'woee_wiki' => $wiki,
				'woee_extension' => $extension,
				'woee_experiment' => $experiment,
				'woee_timestamp' => $dbw->timestamp(),
			] )
			->caller( __METHOD__ )
			->execute();

		$this->claims[$wiki][$extension] = $experiment;
	}

	public function releaseExtension( string $wiki, string $extension, string $experiment ): void {
		$this->getPrimary()->newDeleteQueryBuilder()
			->deleteFrom( self::EXTENSION_TABLE )
			->where( [ 'woee_wiki' => $wiki, 'woee_extension' => $extension, 'woee_experiment' => $experiment ] )
			->caller( __METHOD__ )
			->execute();

		if ( ( $this->claims[$wiki][$extension] ?? $experiment ) === $experiment ) {
			$this->claims[$wiki][$extension] = null;
		}
	}

	public function clearClaims(): void {
		$this->claims = [];
	}

	public function markIneligible( string $experiment, string $unit ): void {
		$this->getPrimary()->newUpdateQueryBuilder()
			->update( self::UNIT_TABLE )
			->set( [ 'wou_eligible' => 0 ] )
			->where( [ 'wou_experiment' => $experiment, 'wou_unit' => $unit ] )
			->caller( __METHOD__ )
			->execute();

		$this->cache->delete( $this->getUnitCacheKey( $experiment, $unit ) );
	}

	/**
	 * @return array<string,array{fingerprint:string,state:array}>
	 */
	public function getSyncRecords(): array {
		if ( !$this->isAvailable() ) {
			return [];
		}

		$res = $this->getPrimary()->newSelectQueryBuilder()
			->select( [ 'wosy_experiment', 'wosy_fingerprint', 'wosy_state' ] )
			->from( self::SYNC_TABLE )
			->caller( __METHOD__ )
			->fetchResultSet();

		$records = [];
		foreach ( $res as $row ) {
			$state = FormatJson::decode( $row->wosy_state, true );
			$records[$row->wosy_experiment] = [
				'fingerprint' => $row->wosy_fingerprint,
				'state' => is_array( $state ) ? $state : [],
			];
		}
		return $records;
	}

	public function saveSyncRecord( string $experiment, string $fingerprint, array $state ): void {
		$dbw = $this->getPrimary();
		$dbw->newReplaceQueryBuilder()
			->replaceInto( self::SYNC_TABLE )
			->uniqueIndexFields( [ 'wosy_experiment' ] )
			->row( [
				'wosy_experiment' => $experiment,
				'wosy_fingerprint' => $fingerprint,
				'wosy_state' => FormatJson::encode( $state, false, FormatJson::ALL_OK ),
				'wosy_timestamp' => $dbw->timestamp(),
			] )
			->caller( __METHOD__ )
			->execute();
	}

	public function deleteSyncRecord( string $experiment ): void {
		$this->getPrimary()->newDeleteQueryBuilder()
			->deleteFrom( self::SYNC_TABLE )
			->where( [ 'wosy_experiment' => $experiment ] )
			->caller( __METHOD__ )
			->execute();
	}

	public function releaseExperiment( string $experiment ): int {
		$dbw = $this->getPrimary();
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( self::EXTENSION_TABLE )
			->where( [ 'woee_experiment' => $experiment ] )
			->caller( __METHOD__ )
			->execute();
		$released = $dbw->affectedRows();

		foreach ( $this->claims as $wiki => $extensions ) {
			foreach ( $extensions as $extension => $owner ) {
				if ( $owner === $experiment ) {
					$this->claims[$wiki][$extension] = null;
				}
			}
		}

		return $released;
	}

	public function countOwnedWikis( string $experiment ): int {
		if ( !$this->isAvailable() ) {
			return 0;
		}

		return (int)$this->getReplica()->newSelectQueryBuilder()
			->select( 'COUNT(DISTINCT woee_wiki)' )
			->from( self::EXTENSION_TABLE )
			->where( [ 'woee_experiment' => $experiment ] )
			->caller( __METHOD__ )
			->fetchField();
	}

	public function recordEvent(
		string $experiment,
		string $unit,
		string $metric,
		string $variant,
		bool $enrolled,
		?string $timestamp = null
	): void {
		$dbw = $this->getPrimary();
		$timestamp = $dbw->timestamp( $timestamp ?? ConvertibleTimestamp::now() );

		$dbw->newInsertQueryBuilder()
			->insertInto( self::EVENT_TABLE )
			->row( [
				'woev_experiment' => $experiment,
				'woev_metric' => $metric,
				'woev_unit' => $unit,
				'woev_count' => 1,
				'woev_first' => $timestamp,
				'woev_last' => $timestamp,
			] )
			->onDuplicateKeyUpdate()
			->uniqueIndexFields( [ 'woev_experiment', 'woev_metric', 'woev_unit' ] )
			->set( [ 'woev_count = woev_count + 1', 'woev_last' => $timestamp ] )
			->caller( __METHOD__ )
			->execute();

		$dbw->newInsertQueryBuilder()
			->insertInto( self::DAILY_TABLE )
			->row( [
				'woed_experiment' => $experiment,
				'woed_metric' => $metric,
				'woed_day' => substr( ConvertibleTimestamp::convert( TS_MW, $timestamp ), 0, 8 ),
				'woed_variant' => $variant,
				'woed_enrolled' => (int)$enrolled,
				'woed_count' => 1,
			] )
			->onDuplicateKeyUpdate()
			->uniqueIndexFields( [ 'woed_experiment', 'woed_metric', 'woed_day', 'woed_variant', 'woed_enrolled' ] )
			->set( [ 'woed_count = woed_count + 1' ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @param string $experiment
	 * @param string|null $since
	 * @return array<int,array{variant:string,enrolled:bool,eligible:bool,units:int}>
	 */
	public function getUnitCounts( string $experiment, ?string $since = null ): array {
		$db = $this->getReplica();
		$res = $db->newSelectQueryBuilder()
			->select( [ 'wou_variant', 'wou_enrolled', 'wou_eligible', 'units' => 'COUNT(*)' ] )
			->from( self::UNIT_TABLE )
			->where( [ 'wou_experiment' => $experiment ] )
			->andWhere( $this->sinceCondition( $db, 'wou_first_seen', $since ) )
			->groupBy( [ 'wou_variant', 'wou_enrolled', 'wou_eligible' ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$rows = [];
		foreach ( $res as $row ) {
			$rows[] = [
				'variant' => $row->wou_variant,
				'enrolled' => (bool)$row->wou_enrolled,
				'eligible' => (bool)$row->wou_eligible,
				'units' => (int)$row->units,
			];
		}
		return $rows;
	}

	/**
	 * @return array<int,array{metric:string,variant:string,enrolled:bool,adopters:int,events:int}>
	 */
	public function getAdoption( string $experiment, ?string $since = null ): array {
		$db = $this->getReplica();
		$res = $this->newEventJoin( $db, $experiment, $since )
			->select( [
				'woev_metric', 'wou_variant', 'wou_enrolled',
				'adopters' => 'COUNT(*)',
				'events' => 'SUM(woev_count)',
			] )
			->groupBy( [ 'woev_metric', 'wou_variant', 'wou_enrolled' ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$rows = [];
		foreach ( $res as $row ) {
			$rows[] = [
				'metric' => $row->woev_metric,
				'variant' => $row->wou_variant,
				'enrolled' => (bool)$row->wou_enrolled,
				'adopters' => (int)$row->adopters,
				'events' => (int)$row->events,
			];
		}
		return $rows;
	}

	/**
	 * @return array<int,array{day:string,variant:string,enrolled:bool,count:int}>
	 */
	public function getExposureSeries( string $experiment, ?string $since = null ): array {
		$db = $this->getReplica();
		$day = $this->dayExpression( $db, 'wou_first_seen' );
		$res = $db->newSelectQueryBuilder()
			->select( [ 'day' => $day, 'wou_variant', 'wou_enrolled', 'units' => 'COUNT(*)' ] )
			->from( self::UNIT_TABLE )
			->where( [ 'wou_experiment' => $experiment, 'wou_eligible' => 1 ] )
			->andWhere( $this->sinceCondition( $db, 'wou_first_seen', $since ) )
			->groupBy( [ $day, 'wou_variant', 'wou_enrolled' ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$rows = [];
		foreach ( $res as $row ) {
			$rows[] = [
				'day' => (string)$row->day,
				'variant' => $row->wou_variant,
				'enrolled' => (bool)$row->wou_enrolled,
				'count' => (int)$row->units,
			];
		}
		return $rows;
	}

	/**
	 * @return array<int,array{day:string,variant:string,enrolled:bool,count:int}>
	 */
	public function getAdopterSeries( string $experiment, string $metric, ?string $since = null ): array {
		$db = $this->getReplica();
		$day = $this->dayExpression( $db, 'woev_first' );
		$res = $this->newEventJoin( $db, $experiment, $since )
			->select( [ 'day' => $day, 'wou_variant', 'wou_enrolled', 'units' => 'COUNT(*)' ] )
			->andWhere( [ 'woev_metric' => $metric ] )
			->groupBy( [ $day, 'wou_variant', 'wou_enrolled' ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$rows = [];
		foreach ( $res as $row ) {
			$rows[] = [
				'day' => (string)$row->day,
				'variant' => $row->wou_variant,
				'enrolled' => (bool)$row->wou_enrolled,
				'count' => (int)$row->units,
			];
		}
		return $rows;
	}

	/**
	 * @param string $experiment
	 * @param string $metric
	 * @param string|null $since
	 * @return array<int,array{day:string,variant:string,enrolled:bool,count:int}>
	 */
	public function getDailyEvents( string $experiment, string $metric, ?string $since = null ): array {
		$db = $this->getReplica();
		$query = $db->newSelectQueryBuilder()
			->select( [ 'woed_day', 'woed_variant', 'woed_enrolled', 'woed_count' ] )
			->from( self::DAILY_TABLE )
			->where( [ 'woed_experiment' => $experiment, 'woed_metric' => $metric ] )
			->orderBy( 'woed_day' )
			->caller( __METHOD__ );

		if ( $since !== null ) {
			$query->andWhere( $db->expr( 'woed_day', '>=', substr( $since, 0, 8 ) ) );
		}

		$rows = [];
		foreach ( $query->fetchResultSet() as $row ) {
			$rows[] = [
				'day' => $row->woed_day,
				'variant' => $row->woed_variant,
				'enrolled' => (bool)$row->woed_enrolled,
				'count' => (int)$row->woed_count,
			];
		}
		return $rows;
	}

	/**
	 * @return array<int,array{unit:string,variant:string,enrolled:bool,count:int,first:string,last:string}>
	 */
	public function getTopUnits( string $experiment, string $metric, int $limit, ?string $since = null ): array {
		$db = $this->getReplica();
		$res = $this->newEventJoin( $db, $experiment, $since )
			->select( [ 'woev_unit', 'wou_variant', 'wou_enrolled', 'woev_count', 'woev_first', 'woev_last' ] )
			->andWhere( [ 'woev_metric' => $metric ] )
			->orderBy( [ 'woev_count', 'woev_last' ], SelectQueryBuilder::SORT_DESC )
			->limit( $limit )
			->caller( __METHOD__ )
			->fetchResultSet();

		$rows = [];
		foreach ( $res as $row ) {
			$rows[] = [
				'unit' => $row->woev_unit,
				'variant' => $row->wou_variant,
				'enrolled' => (bool)$row->wou_enrolled,
				'count' => (int)$row->woev_count,
				'first' => ConvertibleTimestamp::convert( TS_MW, $row->woev_first ),
				'last' => ConvertibleTimestamp::convert( TS_MW, $row->woev_last ),
			];
		}
		return $rows;
	}

	/**
	 * @param string $cutoff
	 * @param string[] $experiments
	 * @param int $batchSize
	 */
	public function pruneInactiveUnits( string $cutoff, array $experiments, int $batchSize ): int {
		if ( !$experiments ) {
			return 0;
		}

		$dbw = $this->getPrimary();
		$cutoff = $dbw->timestamp( $cutoff );

		$recentEvent = $dbw->newSelectQueryBuilder()
			->select( '1' )
			->from( self::EVENT_TABLE )
			->where( [
				'woev_experiment = wou_experiment',
				'woev_unit = wou_unit',
				$dbw->expr( 'woev_last', '>=', $cutoff ),
			] )
			->caller( __METHOD__ )
			->getSQL();

		$res = $dbw->newSelectQueryBuilder()
			->select( [ 'wou_experiment', 'wou_unit' ] )
			->from( self::UNIT_TABLE )
			->where( [
				'wou_experiment' => $experiments,
				$dbw->expr( 'wou_updated', '<', $cutoff ),
				"NOT EXISTS ($recentEvent)",
			] )
			->limit( $batchSize )
			->caller( __METHOD__ )
			->fetchResultSet();

		$units = [];
		foreach ( $res as $row ) {
			$units[$row->wou_experiment][] = $row->wou_unit;
		}

		$deleted = 0;
		foreach ( $units as $name => $keys ) {
			$dbw->newDeleteQueryBuilder()
				->deleteFrom( self::EVENT_TABLE )
				->where( [ 'woev_experiment' => $name, 'woev_unit' => $keys ] )
				->caller( __METHOD__ )
				->execute();
			$dbw->newDeleteQueryBuilder()
				->deleteFrom( self::UNIT_TABLE )
				->where( [ 'wou_experiment' => $name, 'wou_unit' => $keys ] )
				->caller( __METHOD__ )
				->execute();
			$deleted += count( $keys );
			foreach ( $keys as $key ) {
				$this->cache->delete( $this->getUnitCacheKey( $name, $key ) );
			}
		}

		return $deleted;
	}

	/**
	 * @param string $cutoff
	 * @param string[] $experiments
	 */
	public function pruneDaily( string $cutoff, array $experiments ): int {
		if ( !$experiments ) {
			return 0;
		}

		$dbw = $this->getPrimary();
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( self::DAILY_TABLE )
			->where( [
				'woed_experiment' => $experiments,
				$dbw->expr( 'woed_day', '<', substr( $cutoff, 0, 8 ) ),
			] )
			->caller( __METHOD__ )
			->execute();
		return $dbw->affectedRows();
	}

	/**
	 * @return string[]
	 */
	public function getStoredExperiments(): array {
		$db = $this->getPrimary();
		$names = [];
		foreach ( [
			self::UNIT_TABLE => 'wou_experiment',
			self::EVENT_TABLE => 'woev_experiment',
			self::DAILY_TABLE => 'woed_experiment',
		] as $table => $field ) {
			foreach ( $db->newSelectQueryBuilder()
				->select( $field )
				->distinct()
				->from( $table )
				->caller( __METHOD__ )
				->fetchFieldValues() as $name
			) {
				$names[$name] = true;
			}
		}
		return array_keys( $names );
	}

	public function deleteExperimentData( string $experiment ): void {
		$dbw = $this->getPrimary();
		foreach ( [
			self::UNIT_TABLE => 'wou_experiment',
			self::EVENT_TABLE => 'woev_experiment',
			self::DAILY_TABLE => 'woed_experiment',
		] as $table => $field ) {
			$dbw->newDeleteQueryBuilder()
				->deleteFrom( $table )
				->where( [ $field => $experiment ] )
				->caller( __METHOD__ )
				->execute();
		}
	}

	private function newEventJoin( IReadableDatabase $db, string $experiment, ?string $since ): SelectQueryBuilder {
		return $db->newSelectQueryBuilder()
			->from( self::EVENT_TABLE )
			->join( self::UNIT_TABLE, null, [ 'wou_experiment = woev_experiment', 'wou_unit = woev_unit' ] )
			->where( [ 'woev_experiment' => $experiment, 'wou_eligible' => 1 ] )
			->andWhere( $this->sinceCondition( $db, 'wou_first_seen', $since ) );
	}

	private function sinceCondition( IReadableDatabase $db, string $field, ?string $since ): array {
		return $since === null ? [] : [ $db->expr( $field, '>=', $db->timestamp( $since ) ) ];
	}

	private function loadUnit( string $experiment, string $unit, bool $latest ): ?array {
		$db = $latest ? $this->getPrimary() : $this->getReplica();
		$row = $db->newSelectQueryBuilder()
			->select( [ 'wou_variant', 'wou_enrolled', 'wou_eligible', 'wou_wiki', 'wou_first_seen' ] )
			->from( self::UNIT_TABLE )
			->where( [ 'wou_experiment' => $experiment, 'wou_unit' => $unit ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( !$row ) {
			return null;
		}

		return [
			'variant' => $row->wou_variant,
			'enrolled' => (bool)$row->wou_enrolled,
			'eligible' => (bool)$row->wou_eligible,
			'wiki' => $row->wou_wiki,
			'firstSeen' => ConvertibleTimestamp::convert( TS_MW, $row->wou_first_seen ),
		];
	}

	private function dayExpression( IReadableDatabase $db, string $field ): string {
		return $db->getType() === 'postgres' ?
			"to_char($field AT TIME ZONE 'UTC', 'YYYYMMDD')" :
			$db->buildSubstring( $field, 1, 8 );
	}

	private function getUnitCacheKey( string $experiment, string $unit ): string {
		return $this->cache->makeGlobalKey( 'wikioasismagic-experiment-unit', $experiment, $unit );
	}

	private function getReplica(): IReadableDatabase {
		return $this->connectionProvider->getReplicaDatabase( ExperimentStateStore::VIRTUAL_DOMAIN );
	}

	private function getPrimary() {
		return $this->connectionProvider->getPrimaryDatabase( ExperimentStateStore::VIRTUAL_DOMAIN );
	}
}
