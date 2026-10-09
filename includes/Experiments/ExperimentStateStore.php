<?php

namespace WikiOasis\WikiOasisMagic\Experiments;

use MediaWiki\Json\FormatJson;
use Psr\Log\LoggerInterface;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\DBError;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDBAccessObject;
use Wikimedia\Rdbms\IMaintainableDatabase;
use Wikimedia\Rdbms\IReadableDatabase;
use function is_array;
use function sha1;
use function substr;

class ExperimentStateStore {

	public const VIRTUAL_DOMAIN = 'virtual-wikioasismagic';

	private const TABLE = 'wo_experiment_state';
	private const CACHE_TTL = 300;

	/** @var array<string,array>|null */
	private ?array $rows = null;
	private bool $available = true;

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		private readonly WANObjectCache $cache,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @return array<string,array>
	 */
	public function getAll(): array {
		if ( $this->rows !== null ) {
			return $this->rows;
		}

		$cached = $this->cache->getWithSetCallback(
			$this->getCacheKey(),
			self::CACHE_TTL,
			function ( $old, &$ttl ) {
				$rows = $this->load( $this->connectionProvider->getReplicaDatabase( self::VIRTUAL_DOMAIN ) );
				if ( $rows === null ) {
					$ttl = WANObjectCache::TTL_MINUTE;
					return [ 'available' => false, 'rows' => [] ];
				}
				return [ 'available' => true, 'rows' => $rows ];
			},
			[
				'checkKeys' => [ $this->getCheckKey() ],
				'pcTTL' => WANObjectCache::TTL_PROC_SHORT,
				'lockTSE' => 30,
			]
		);

		$this->available = (bool)( $cached['available'] ?? false );
		$this->rows = (array)( $cached['rows'] ?? [] );
		return $this->rows;
	}

	public function getState( string $experiment ): array {
		return $this->getAll()[$experiment]['state'] ?? [];
	}

	/** @return array{state:array,user:string,timestamp:string,version:string}|null */
	public function getEntry( string $experiment ): ?array {
		return $this->getAll()[$experiment] ?? null;
	}

	public function isAvailable(): bool {
		$this->getAll();
		return $this->available;
	}

	public function save( string $experiment, array $state, string $userName ): void {
		$dbw = $this->connectionProvider->getPrimaryDatabase( self::VIRTUAL_DOMAIN );
		$dbw->newReplaceQueryBuilder()
			->replaceInto( self::TABLE )
			->uniqueIndexFields( [ 'woes_experiment' ] )
			->row( [
				'woes_experiment' => $experiment,
				'woes_state' => FormatJson::encode( $state, false, FormatJson::ALL_OK ),
				'woes_user_name' => $userName,
				'woes_timestamp' => $dbw->timestamp(),
			] )
			->caller( __METHOD__ )
			->execute();

		$this->purge();
	}

	public function clear( string $experiment ): void {
		$dbw = $this->connectionProvider->getPrimaryDatabase( self::VIRTUAL_DOMAIN );
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( self::TABLE )
			->where( [ 'woes_experiment' => $experiment ] )
			->caller( __METHOD__ )
			->execute();

		$this->purge();
	}

	/**
	 * @return array{state:array,user:string,timestamp:string,version:string}|null
	 */
	public function getLatestEntry( string $experiment ): ?array {
		$rows = $this->load(
			$this->connectionProvider->getPrimaryDatabase( self::VIRTUAL_DOMAIN ),
			IDBAccessObject::READ_LATEST
		);
		return $rows[$experiment] ?? null;
	}

	public function reload(): void {
		$rows = $this->load(
			$this->connectionProvider->getPrimaryDatabase( self::VIRTUAL_DOMAIN ),
			IDBAccessObject::READ_LATEST
		);
		$this->available = $rows !== null;
		$this->rows = $rows ?? [];
	}

	private function purge(): void {
		$this->cache->touchCheckKey( $this->getCheckKey() );
		$rows = $this->load(
			$this->connectionProvider->getPrimaryDatabase( self::VIRTUAL_DOMAIN ),
			IDBAccessObject::READ_LATEST
		);
		$this->available = $rows !== null;
		$this->rows = $rows ?? [];
	}

	/** @return array<string,array>|null */
	private function load( IReadableDatabase $db, int $flags = IDBAccessObject::READ_NORMAL ): ?array {
		try {
			if ( $db instanceof IMaintainableDatabase && !$db->tableExists( self::TABLE, __METHOD__ ) ) {
				$this->logger->warning(
					'Experiment state table is missing on {domain}; using configuration only. ' .
					'Map virtual-wikioasismagic and run update.php.',
					[ 'domain' => $db->getDomainID() ]
				);
				return null;
			}

			$res = $db->newSelectQueryBuilder()
				->select( [ 'woes_experiment', 'woes_state', 'woes_user_name', 'woes_timestamp' ] )
				->from( self::TABLE )
				->recency( $flags )
				->caller( __METHOD__ )
				->fetchResultSet();
		} catch ( DBError $e ) {
			$this->logger->warning(
				'Experiment state is unavailable, using configuration only: {message}',
				[ 'message' => $e->getMessage(), 'exception' => $e ]
			);
			return null;
		}

		$rows = [];
		foreach ( $res as $row ) {
			$state = FormatJson::decode( $row->woes_state, true );
			$rows[$row->woes_experiment] = [
				'state' => is_array( $state ) ? $state : [],
				'user' => $row->woes_user_name,
				'timestamp' => $row->woes_timestamp,
				'version' => substr( sha1( $row->woes_timestamp . "\n" . $row->woes_user_name . "\n" . $row->woes_state ), 0, 16 ),
			];
		}

		return $rows;
	}

	private function getCacheKey(): string {
		return $this->cache->makeGlobalKey( 'wikioasismagic-experiment-state', 'v2' );
	}

	private function getCheckKey(): string {
		return $this->cache->makeGlobalKey( 'wikioasismagic-experiment-state-check' );
	}
}
