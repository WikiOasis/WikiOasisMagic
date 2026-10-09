<?php

namespace WikiOasis\WikiOasisMagic\FandomImport;

use Psr\Log\LoggerInterface;
use stdClass;
use Wikimedia\Rdbms\DBError;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IMaintainableDatabase;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentStateStore;
use function in_array;
use function json_encode;

/**
 * wo_fandom_import, on the central wiki. Every reader and writer runs in the
 * central wiki's context (the special page, its jobs, the worker's callback),
 * so the table lives wherever virtual-wikioasismagic points there.
 */
class FandomImportStore {

	public const TABLE = 'wo_fandom_import';
	public const VIRTUAL_DOMAIN = ExperimentStateStore::VIRTUAL_DOMAIN;

	private const TIMESTAMPS = [ 'files_expire', 'created', 'updated' ];

	private ?bool $available = null;

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		private readonly LoggerInterface $logger,
	) {
	}

	public function isAvailable(): bool {
		if ( $this->available !== null ) {
			return $this->available;
		}

		try {
			$dbr = $this->getReplica();
			$this->available = !$dbr instanceof IMaintainableDatabase || $dbr->tableExists( self::TABLE, __METHOD__ );
		} catch ( DBError $e ) {
			$this->logger->warning( 'Fandom import table check failed: {message}', [
				'message' => $e->getMessage(),
				'exception' => $e,
			] );
			$this->available = false;
		}

		if ( !$this->available ) {
			$this->logger->warning( 'The wo_fandom_import table is missing; run update.php on the central wiki.' );
		}

		return $this->available;
	}

	/**
	 * @param array<string,mixed> $fields Columns without the wofi_ prefix
	 * @return int The new import's ID
	 */
	public function insert( array $fields ): int {
		$now = ConvertibleTimestamp::now();
		$dbw = $this->getPrimary();
		$dbw->newInsertQueryBuilder()
			->insertInto( self::TABLE )
			->row( $this->prefix( $dbw, $fields + [
				'reviewer' => 0,
				'stage' => '',
				'progress' => [],
				'info' => [],
				'error' => '',
				'files_expire' => null,
				'created' => $now,
				'updated' => $now,
			] ) )
			->caller( __METHOD__ )
			->execute();

		return $dbw->insertId();
	}

	public function get( int $id, bool $latest = false ): ?FandomImportRequest {
		$row = $this->newSelect( $latest ? $this->getPrimary() : $this->getReplica() )
			->where( [ 'wofi_id' => $id ] )
			->caller( __METHOD__ )
			->fetchRow();

		return $row instanceof stdClass ? FandomImportRequest::newFromRow( $row ) : null;
	}

	public function getLatestForSource( FandomSource $source, bool $latest = false ): ?FandomImportRequest {
		$row = $this->newSelect( $latest ? $this->getPrimary() : $this->getReplica() )
			->where( [ 'wofi_source' => $source->getKey() ] )
			->orderBy( 'wofi_id', SelectQueryBuilder::SORT_DESC )
			->caller( __METHOD__ )
			->fetchRow();

		return $row instanceof stdClass ? FandomImportRequest::newFromRow( $row ) : null;
	}

	public function hasActiveForSource( FandomSource $source ): bool {
		return (bool)$this->getPrimary()->newSelectQueryBuilder()
			->select( 'wofi_id' )
			->from( self::TABLE )
			->where( [
				'wofi_source' => $source->getKey(),
				'wofi_status' => FandomImportStatus::ACTIVE,
			] )
			->caller( __METHOD__ )
			->fetchField();
	}

	public function hasActiveForDbname( string $dbname ): bool {
		return (bool)$this->getPrimary()->newSelectQueryBuilder()
			->select( 'wofi_id' )
			->from( self::TABLE )
			->where( [
				'wofi_dbname' => $dbname,
				'wofi_status' => FandomImportStatus::ACTIVE,
			] )
			->caller( __METHOD__ )
			->fetchField();
	}

	/**
	 * @param string[] $statuses
	 * @param int $limit
	 * @param int|null $requesterId
	 * @return FandomImportRequest[]
	 */
	public function list( array $statuses, int $limit, ?int $requesterId = null ): array {
		$conds = [];
		if ( $statuses ) {
			$conds['wofi_status'] = $statuses;
		}
		if ( $requesterId !== null ) {
			$conds['wofi_requester'] = $requesterId;
		}

		$res = $this->newSelect( $this->getReplica() )
			->where( $conds )
			->orderBy( 'wofi_updated', SelectQueryBuilder::SORT_DESC )
			->limit( $limit )
			->caller( __METHOD__ )
			->fetchResultSet();

		$requests = [];
		foreach ( $res as $row ) {
			$request = $row instanceof stdClass ? FandomImportRequest::newFromRow( $row ) : null;
			if ( $request ) {
				$requests[] = $request;
			}
		}

		return $requests;
	}

	/**
	 * Update an import, but only while it is still in one of $from, so two
	 * reviewers clicking at once, or a late report from the worker, cannot
	 * move it out of a state it already left.
	 *
	 * @param int $id
	 * @param array<string,mixed> $fields Columns without the wofi_ prefix
	 * @param string[]|null $from Statuses the import must be in, or null for any
	 * @return bool Whether a row was changed
	 */
	public function update( int $id, array $fields, ?array $from = null ): bool {
		$dbw = $this->getPrimary();
		$conds = [ 'wofi_id' => $id ];
		if ( $from !== null ) {
			$conds['wofi_status'] = $from;
		}

		$dbw->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( $this->prefix( $dbw, $fields + [ 'updated' => ConvertibleTimestamp::now() ] ) )
			->where( $conds )
			->caller( __METHOD__ )
			->execute();

		return $dbw->affectedRows() > 0;
	}

	private function newSelect( IReadableDatabase $db ): SelectQueryBuilder {
		return $db->newSelectQueryBuilder()
			->select( [
				'wofi_id', 'wofi_source', 'wofi_dbname', 'wofi_sitename', 'wofi_language', 'wofi_category',
				'wofi_mode', 'wofi_reason', 'wofi_fandom_user', 'wofi_requester', 'wofi_reviewer',
				'wofi_status', 'wofi_stage', 'wofi_progress', 'wofi_info', 'wofi_error',
				'wofi_files_expire', 'wofi_created', 'wofi_updated',
			] )
			->from( self::TABLE );
	}

	/**
	 * Add the column prefix, encode the JSON columns and put timestamps
	 * (given in any format ConvertibleTimestamp reads) in the database's own.
	 *
	 * @param IDatabase $dbw
	 * @param array<string,mixed> $fields
	 * @return array<string,mixed>
	 */
	private function prefix( IDatabase $dbw, array $fields ): array {
		$row = [];
		foreach ( $fields as $name => $value ) {
			if ( $name === 'progress' || $name === 'info' ) {
				$value = json_encode( $value ?: (object)[] );
			} elseif ( in_array( $name, self::TIMESTAMPS, true ) && $value !== null ) {
				$value = $dbw->timestamp( $value );
			}
			$row["wofi_$name"] = $value;
		}
		return $row;
	}

	private function getPrimary(): IDatabase {
		return $this->connectionProvider->getPrimaryDatabase( self::VIRTUAL_DOMAIN );
	}

	private function getReplica(): IReadableDatabase {
		return $this->connectionProvider->getReplicaDatabase( self::VIRTUAL_DOMAIN );
	}
}
