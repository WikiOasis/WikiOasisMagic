<?php

namespace WikiOasis\WikiOasisMagic\Onboarding;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\CentralAuth\CentralAuthServices;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\SiteStats\SiteStats;
use MediaWiki\User\UserIdentity;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Psr\Log\LoggerInterface;
use Throwable;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use WikiOasis\WikiOasisMagic\Experiments\WikiFarm;

class OnboardingEnvironment {

	public const CONSTRUCTOR_OPTIONS = [
		ConfigNames::ATTRIBUTION_DAYS,
		ConfigNames::ENABLE_NEW_ONBOARDING,
	];

	public const CONTEXT_CENTRAL = 'central';
	public const CONTEXT_LOCAL = 'local';

	public const SIGNUP_EXPERIMENT = 'signup';
	public const ONBOARDING_EXPERIMENT = 'onboarding';

	public function __construct(
		private readonly ServiceOptions $options,
		private readonly WikiFarm $farm,
		private readonly ExtensionRegistry $extensionRegistry,
		private readonly WANObjectCache $cache,
		private readonly LoggerInterface $logger,
		private readonly ?CreateWikiDatabaseUtils $createWikiDatabaseUtils,
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	public function isEnabled(): bool {
		return (bool)$this->options->get( ConfigNames::ENABLE_NEW_ONBOARDING );
	}

	public function getCurrentWiki(): string {
		return $this->farm->getCurrentWiki();
	}

	public function getCentralWiki(): string {
		return $this->farm->getCentralWiki();
	}

	public function isCentralWiki(): bool {
		return $this->farm->isCentralWiki();
	}

	public function getContext(): string {
		return $this->isCentralWiki() ? self::CONTEXT_CENTRAL : self::CONTEXT_LOCAL;
	}

	public function canRequestWikis(): bool {
		return $this->isCentralWiki() && $this->extensionRegistry->isLoaded( 'CreateWiki' );
	}

	public function getCentralUrl( string $page, array $query = [] ): ?string {
		return $this->farm->getCentralUrl( $page, $query );
	}

	public function getRegistration( UserIdentity $user ): ?string {
		return $this->farm->getRegistration( $user );
	}

	public function isNewcomer( UserIdentity $user ): bool {
		if ( !$user->isRegistered() ) {
			return false;
		}

		$registration = $this->getRegistration( $user );
		return $registration !== null && $this->isWithinAttributionWindow( $registration );
	}

	public function isWithinAttributionWindow( string $registration ): bool {
		$registered = ConvertibleTimestamp::convert( TS_UNIX, $registration );
		if ( $registered === false ) {
			return false;
		}

		$days = (int)$this->options->get( ConfigNames::ATTRIBUTION_DAYS );
		return (int)$registered >= (int)ConvertibleTimestamp::now( TS_UNIX ) - $days * 86400;
	}

	/**
	 * @return array{wikis:int|null,users:int|null}
	 */
	public function getFarmStats(): array {
		return $this->cache->getWithSetCallback(
			$this->cache->makeGlobalKey( 'wikioasismagic-onboarding-farmstats', 'v1' ),
			WANObjectCache::TTL_HOUR * 6,
			function ( $old, &$ttl ) {
				$stats = [ 'wikis' => $this->countWikis(), 'users' => $this->countUsers() ];
				if ( $stats['wikis'] === null || $stats['users'] === null ) {
					$ttl = WANObjectCache::TTL_MINUTE * 10;
				}
				return $stats;
			},
			[ 'lockTSE' => 60, 'pcTTL' => WANObjectCache::TTL_PROC_LONG ]
		);
	}

	private function countWikis(): ?int {
		if ( !$this->createWikiDatabaseUtils ) {
			return null;
		}

		try {
			return (int)$this->createWikiDatabaseUtils->getGlobalReplicaDB()->newSelectQueryBuilder()
				->select( 'COUNT(*)' )
				->from( 'cw_wikis' )
				->where( [ 'wiki_deleted' => 0 ] )
				->caller( __METHOD__ )
				->fetchField();
		} catch ( Throwable $e ) {
			$this->logger->info( 'Could not count wikis: {message}', [ 'message' => $e->getMessage() ] );
			return null;
		}
	}

	private function countUsers(): ?int {
		try {
			if ( $this->extensionRegistry->isLoaded( 'CentralAuth' ) ) {
				return (int)CentralAuthServices::getDatabaseManager()->getCentralReplicaDB()
					->newSelectQueryBuilder()
					->select( 'COUNT(*)' )
					->from( 'globaluser' )
					->caller( __METHOD__ )
					->fetchField();
			}

			return (int)SiteStats::users();
		} catch ( Throwable $e ) {
			$this->logger->info( 'Could not count users: {message}', [ 'message' => $e->getMessage() ] );
			return null;
		}
	}
}
