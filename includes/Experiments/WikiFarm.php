<?php

namespace WikiOasis\WikiOasisMagic\Experiments;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\MainConfigNames;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Psr\Log\LoggerInterface;
use Throwable;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\IDBAccessObject;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use function array_diff;
use function array_values;
use function in_array;
use function wfAppendQuery;

class WikiFarm {

	public const CONSTRUCTOR_OPTIONS = [
		ConfigNames::CENTRAL_WIKI,
		MainConfigNames::DBname,
	];

	private ?string $centralWiki = null;

	public function __construct(
		private readonly ServiceOptions $options,
		private readonly ExtensionRegistry $extensionRegistry,
		private readonly UserFactory $userFactory,
		private readonly WANObjectCache $cache,
		private readonly LoggerInterface $logger,
		private readonly ?CreateWikiDatabaseUtils $createWikiDatabaseUtils,
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	public function getCurrentWiki(): string {
		return $this->options->get( MainConfigNames::DBname );
	}

	public function getCentralWiki(): string {
		if ( $this->centralWiki !== null ) {
			return $this->centralWiki;
		}

		$configured = $this->options->get( ConfigNames::CENTRAL_WIKI );
		if ( $configured ) {
			$this->centralWiki = $configured;
		} elseif ( $this->createWikiDatabaseUtils ) {
			try {
				$this->centralWiki = $this->createWikiDatabaseUtils->getCentralWikiID();
			} catch ( Throwable $e ) {
				$this->logger->warning( 'Could not ask CreateWiki for the central wiki: {message}', [
					'message' => $e->getMessage(),
				] );
				$this->centralWiki = $this->getCurrentWiki();
			}
		} else {
			$this->centralWiki = $this->getCurrentWiki();
		}

		return $this->centralWiki;
	}

	public function isCentralWiki( ?string $dbname = null ): bool {
		if ( $dbname !== null && $dbname !== $this->getCurrentWiki() ) {
			return $dbname === $this->getCentralWiki();
		}

		return $this->getCurrentWiki() === $this->getCentralWiki() ||
			WikiMap::isCurrentWikiDbDomain( $this->getCentralWiki() );
	}

	public function hasCreateWiki(): bool {
		return $this->createWikiDatabaseUtils !== null;
	}

	public function getWikiUrl( string $dbname, string $page = '', array $query = [] ): ?string {
		$url = WikiMap::getForeignURL( $dbname, $page );
		if ( $url === false ) {
			return null;
		}

		return $query ? wfAppendQuery( $url, $query ) : $url;
	}

	public function getCentralUrl( string $page, array $query = [] ): ?string {
		return $this->getWikiUrl( $this->getCentralWiki(), $page, $query );
	}

	/**
	 * @return string|null
	 */
	public function getRegistration( UserIdentity $user ): ?string {
		if ( !$user->isRegistered() ) {
			return null;
		}

		if ( $this->extensionRegistry->isLoaded( 'CentralAuth' ) ) {
			$centralUser = CentralAuthUser::getInstanceByName( $user->getName() );
			if ( $centralUser->exists() ) {
				$registration = $centralUser->getRegistration();
				return $registration ? ( ConvertibleTimestamp::convert( TS_MW, $registration ) ?: null ) : null;
			}
		}

		$registration = $this->userFactory->newFromUserIdentity( $user )->getRegistration();
		return $registration ? ( ConvertibleTimestamp::convert( TS_MW, $registration ) ?: null ) : null;
	}

	/**
	 * @return string|null
	 */
	public function getWikiCreation( string $dbname ): ?string {
		if ( !$this->createWikiDatabaseUtils ) {
			return null;
		}

		$created = $this->cache->getWithSetCallback(
			$this->cache->makeGlobalKey( 'wikioasismagic-wiki-creation', $dbname ),
			WANObjectCache::TTL_WEEK,
			function ( $old, &$ttl ) use ( $dbname ) {
				try {
					$created = $this->createWikiDatabaseUtils->getGlobalReplicaDB()->newSelectQueryBuilder()
						->select( 'wiki_creation' )
						->from( 'cw_wikis' )
						->where( [ 'wiki_dbname' => $dbname ] )
						->caller( self::class . '::getWikiCreation' )
						->fetchField();
				} catch ( Throwable $e ) {
					$this->logger->info( 'Could not look up when {wiki} was created: {message}', [
						'wiki' => $dbname,
						'message' => $e->getMessage(),
					] );
					$ttl = WANObjectCache::TTL_MINUTE;
					return '';
				}

				if ( !$created ) {
					$ttl = WANObjectCache::TTL_MINUTE;
					return '';
				}

				return ConvertibleTimestamp::convert( TS_MW, $created ) ?: '';
			}
		);

		return $created !== '' ? $created : null;
	}

	/**
	 * @return array<string,string|null>
	 */
	public function listWikis( string $after, int $limit ): array {
		if ( !$this->createWikiDatabaseUtils ) {
			$current = $this->getCurrentWiki();
			return $after === '' || $after < $current ? [ $current => null ] : [];
		}

		$dbr = $this->createWikiDatabaseUtils->getGlobalReplicaDB();
		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'wiki_dbname', 'wiki_creation' ] )
			->from( 'cw_wikis' )
			->where( [ 'wiki_deleted' => 0, $dbr->expr( 'wiki_dbname', '>', $after ) ] )
			->orderBy( 'wiki_dbname' )
			->limit( $limit )
			->recency( IDBAccessObject::READ_NORMAL )
			->caller( __METHOD__ )
			->fetchResultSet();

		$wikis = [];
		foreach ( $res as $row ) {
			$wikis[$row->wiki_dbname] = $row->wiki_creation ?
				( ConvertibleTimestamp::convert( TS_MW, $row->wiki_creation ) ?: null ) :
				null;
		}
		return $wikis;
	}

	public function countWikis( Experiment $experiment ): ?int {
		if ( !$this->createWikiDatabaseUtils ) {
			return null;
		}

		try {
			$dbr = $this->createWikiDatabaseUtils->getGlobalReplicaDB();
			$query = $dbr->newSelectQueryBuilder()
				->select( 'COUNT(*)' )
				->from( 'cw_wikis' )
				->where( [ 'wiki_deleted' => 0 ] )
				->caller( __METHOD__ );

			if ( $experiment->population !== Experiment::POPULATION_ALL && $experiment->start !== null ) {
				$query->andWhere( $dbr->expr(
					'wiki_creation',
					$experiment->population === Experiment::POPULATION_NEW ? '>=' : '<',
					$dbr->timestamp( $experiment->start )
				) );
			}

			if ( $experiment->wikis ) {
				$query->andWhere( $dbr->orExpr( $this->wikiListConditions( $dbr, $experiment->wikis, false ) ) );
			}

			if ( $experiment->excludeWikis ) {
				$query->andWhere( $dbr->andExpr( $this->wikiListConditions( $dbr, $experiment->excludeWikis, true ) ) );
			}

			return (int)$query->fetchField();
		} catch ( Throwable $e ) {
			$this->logger->info( 'Could not count wikis: {message}', [ 'message' => $e->getMessage() ] );
			return null;
		}
	}

	/**
	 * @param IReadableDatabase $dbr
	 * @param string[] $list
	 * @param bool $negate
	 * @return IExpression[]
	 */
	private function wikiListConditions( IReadableDatabase $dbr, array $list, bool $negate ): array {
		$central = $this->getCentralWiki();
		$is = $negate ? '!=' : '=';
		$isNot = $negate ? '=' : '!=';
		$conditions = [];
		$names = array_values( array_diff( $list, [ Experiment::WIKI_CENTRAL, Experiment::WIKI_LOCAL ] ) );
		if ( $names ) {
			$conditions[] = $dbr->expr( 'wiki_dbname', $is, $names );
		}
		if ( in_array( Experiment::WIKI_CENTRAL, $list, true ) ) {
			$conditions[] = $dbr->expr( 'wiki_dbname', $is, $central );
		}
		if ( in_array( Experiment::WIKI_LOCAL, $list, true ) ) {
			$conditions[] = $dbr->expr( 'wiki_dbname', $isNot, $central );
		}
		return $conditions;
	}
}
