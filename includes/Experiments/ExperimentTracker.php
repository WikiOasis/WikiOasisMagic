<?php

namespace WikiOasis\WikiOasisMagic\Experiments;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Request\WebRequest;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use Psr\Log\LoggerInterface;
use Throwable;
use Wikimedia\Stats\StatsFactory;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use function preg_replace;
use function strtolower;
use function substr;

class ExperimentTracker {

	private readonly StatsFactory $stats;

	/** @var array<string,true> */
	private array $exposed = [];

	public function __construct(
		private readonly ExperimentManager $manager,
		private readonly UnitFactory $unitFactory,
		private readonly ExperimentDataStore $dataStore,
		private readonly WikiFarm $farm,
		private readonly UserFactory $userFactory,
		StatsFactory $statsFactory,
		private readonly LoggerInterface $logger,
	) {
		$this->stats = $statsFactory->withComponent( 'WikiOasisMagic' );
	}

	/**
	 * @param Assignment $assignment
	 * @param bool $eligible
	 */
	public function expose( Assignment $assignment, bool $eligible = true ): void {
		if ( !$this->shouldCount( $assignment ) ) {
			return;
		}

		$unit = $assignment->unit;
		$key = $assignment->experiment . ':' . $unit->getStorageKey();
		if ( isset( $this->exposed[$key] ) ) {
			return;
		}
		$this->exposed[$key] = true;

		$this->stats->getCounter( 'experiment_exposures_total' )
			->setLabel( 'experiment', $assignment->experiment )
			->setLabel( 'variant', self::label( $assignment->variant ) )
			->setLabel( 'enrolled', $assignment->enrolled ? 'yes' : 'no' )
			->increment();

		$wiki = $unit->type === ExperimentUnit::WIKI ? $unit->id : $this->farm->getCurrentWiki();
		DeferredUpdates::addCallableUpdate( function () use ( $assignment, $eligible, $wiki ) {
			$this->storeExposure( $assignment, $eligible, $wiki );
		} );
	}

	/**
	 * @param Assignment $assignment
	 * @param bool $eligible
	 * @param string $wiki
	 * @param bool $latest
	 * @return bool
	 */
	public function storeExposure( Assignment $assignment, bool $eligible, string $wiki, bool $latest = false ): bool {
		if ( !$this->shouldCount( $assignment ) || !$this->dataStore->isAvailable() ) {
			return false;
		}

		$key = $assignment->unit->getStorageKey();
		try {
			$stored = $this->dataStore->getUnit( $assignment->experiment, $key, $latest );
			if (
				$stored &&
				$stored['variant'] === $assignment->variant &&
				$stored['enrolled'] === $assignment->enrolled &&
				$stored['eligible'] === $eligible
			) {
				return false;
			}

			$this->dataStore->saveUnit(
				$assignment->experiment,
				$key,
				$assignment->variant,
				$assignment->enrolled,
				$eligible,
				$wiki
			);
			return true;
		} catch ( Throwable $e ) {
			$this->logger->warning( 'Could not store an exposure to {experiment}: {message}', [
				'experiment' => $assignment->experiment,
				'message' => $e->getMessage(),
				'exception' => $e,
			] );
			return false;
		}
	}

	public function recordEvent( Assignment $assignment, string $metric ): void {
		if ( !$assignment->isCounted() ) {
			return;
		}

		$definition = $this->manager->getExperiment( $assignment->experiment );
		if ( $definition && $definition->getMetric( $metric )?->type === Metric::EVENT ) {
			$this->record( $definition, $metric, $assignment->unit, null );
		}
	}

	public function trackEdit( UserIdentity $performer, int $namespace, ?WebRequest $request = null ): void {
		$this->dispatch(
			static fn ( Metric $metric ) => $metric->matchesEdit( $namespace ),
			$performer,
			$request
		);
	}

	/**
	 * @param string[] $tags
	 * @param UserIdentity|null $performer
	 * @param WebRequest|null $request
	 */
	public function trackTags( array $tags, ?UserIdentity $performer, ?WebRequest $request = null ): void {
		$this->dispatch(
			static fn ( Metric $metric ) => $metric->matchesTags( $tags ),
			$performer,
			$request
		);
	}

	public function trackLog( string $type, string $action, UserIdentity $performer, ?WebRequest $request = null ): void {
		$this->dispatch(
			static fn ( Metric $metric ) => $metric->matchesLog( $type, $action ),
			$performer,
			$request
		);
	}

	public function trackSpecialPage( string $name, UserIdentity $user, WebRequest $request ): void {
		$this->dispatch(
			static fn ( Metric $metric ) => $metric->matchesSpecialPage( $name ),
			$user,
			$request
		);
	}

	public function trackApiModule( string $path, UserIdentity $user, WebRequest $request ): void {
		$this->dispatch(
			static fn ( Metric $metric ) => $metric->matchesApiModule( $path ),
			$user,
			$request
		);
	}

	/**
	 * @param callable(Metric):bool $matches
	 * @param UserIdentity|null $user
	 * @param WebRequest|null $request
	 */
	private function dispatch( callable $matches, ?UserIdentity $user, ?WebRequest $request ): void {
		$now = ConvertibleTimestamp::now( TS_MW );
		foreach ( $this->manager->getExperiments() as $experiment ) {
			if ( !$experiment->metrics || !$experiment->isWithinWindow( $now ) ) {
				continue;
			}

			foreach ( $experiment->metrics as $metric ) {
				if ( !$matches( $metric ) ) {
					continue;
				}

				$unit = match ( $experiment->unit ) {
					ExperimentUnit::WIKI => $this->unitFactory->forWiki(),
					ExperimentUnit::USER => $user ? $this->unitFactory->forUser( $user ) : null,
					ExperimentUnit::BROWSER => $request ? $this->unitFactory->forBrowser( $request, false ) : null,
				};

				if ( $unit && $unit->exists() ) {
					$this->record( $experiment, $metric->name, $unit, $user );
				}
			}
		}
	}

	private function record( Experiment $experiment, string $metric, ExperimentUnit $unit, ?UserIdentity $actor ): void {
		DeferredUpdates::addCallableUpdate( function () use ( $experiment, $metric, $unit, $actor ) {
			try {
				if ( $actor && $actor->isRegistered() && $this->userFactory->newFromUserIdentity( $actor )->isBot() ) {
					return;
				}

				if ( !$this->dataStore->isAvailable() || !$this->manager->isRunning( $experiment, $unit ) ) {
					return;
				}

				$stored = $this->dataStore->getUnit( $experiment->name, $unit->getStorageKey() );
				if ( !$stored || !$stored['eligible'] ) {
					return;
				}

				$now = (int)ConvertibleTimestamp::now( TS_UNIX );
				if (
					$experiment->window !== null &&
					(int)ConvertibleTimestamp::convert( TS_UNIX, $stored['firstSeen'] ) < $now - $experiment->window * 86400
				) {
					return;
				}

				$this->stats->getCounter( 'experiment_events_total' )
					->setLabel( 'experiment', $experiment->name )
					->setLabel( 'metric', $metric )
					->setLabel( 'variant', self::label( $stored['variant'] ) )
					->setLabel( 'enrolled', $stored['enrolled'] ? 'yes' : 'no' )
					->increment();

				$this->dataStore->recordEvent(
					$experiment->name,
					$unit->getStorageKey(),
					$metric,
					$stored['variant'],
					$stored['enrolled']
				);
			} catch ( Throwable $e ) {
				$this->logger->warning( 'Could not record {metric} for {experiment}: {message}', [
					'experiment' => $experiment->name,
					'metric' => $metric,
					'message' => $e->getMessage(),
					'exception' => $e,
				] );
			}
		} );
	}

	private function shouldCount( Assignment $assignment ): bool {
		if ( !$assignment->isCounted() ) {
			return false;
		}

		$experiment = $this->manager->getExperiment( $assignment->experiment );
		return $experiment !== null &&
			$experiment->unit === $assignment->unit->type &&
			$this->manager->isRunning( $experiment, $assignment->unit );
	}

	private static function label( string $value ): string {
		return substr( preg_replace( '/[^a-z0-9_]+/', '_', strtolower( $value ) ), 0, 64 ) ?: 'unknown';
	}
}
