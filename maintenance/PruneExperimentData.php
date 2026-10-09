<?php

namespace WikiOasis\WikiOasisMagic\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use WikiOasis\WikiOasisMagic\Experiments\ConfigNames;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentDataStore;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentManager;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentStateStore;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentUnit;
use function array_diff;
use function array_keys;
use function implode;

class PruneExperimentData extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription(
			'Delete user and browser units (and their events) not seen and not active for a number of days, ' .
			'daily counts older than that, and with --orphaned everything stored for experiments ' .
			'that are no longer registered.'
		);
		$this->addOption( 'days', 'Keep this many days; defaults to $wgWikiOasisMagicExperimentsRetentionDays', false, true );
		$this->addOption( 'experiment', 'Only prune this experiment', false, true );
		$this->addOption( 'include-wikis', 'Also prune wiki experiments, which are skipped by default' );
		$this->addOption( 'orphaned', 'Also delete all data and runtime state of experiments no longer in configuration' );
		$this->setBatchSize( 500 );
		$this->requireExtension( 'WikiOasisMagic' );
	}

	public function execute(): void {
		$services = $this->getServiceContainer();
		/** @var ExperimentDataStore $dataStore */
		$dataStore = $services->get( 'WikiOasisMagic.ExperimentDataStore' );
		if ( !$dataStore->isAvailable() ) {
			$this->fatalError( 'The experiment tables are not available.' );
		}

		$days = (int)( $this->getOption( 'days' ) ??
			$services->getConfigFactory()->makeConfig( 'WikiOasisMagic' )->get( ConfigNames::RETENTION_DAYS ) );
		if ( $days < 1 ) {
			$this->fatalError( '--days must be at least 1.' );
		}

		$cutoff = ConvertibleTimestamp::convert( TS_MW, (int)ConvertibleTimestamp::now( TS_UNIX ) - $days * 86400 );
		$experiments = $this->getExperimentsToPrune();

		$units = 0;
		do {
			$deleted = $dataStore->pruneInactiveUnits( $cutoff, $experiments, $this->getBatchSize() );
			$units += $deleted;
			$this->waitForReplication();
		} while ( $deleted > 0 );

		$daily = $dataStore->pruneDaily( $cutoff, $experiments );
		$this->output( "Deleted $units units not seen since $cutoff, with their events, and $daily daily rows" .
			' (' . ( implode( ', ', $experiments ) ?: 'no experiments' ) . ").\n" );

		if ( $this->hasOption( 'orphaned' ) ) {
			$this->pruneOrphaned( $dataStore );
		}
	}

	/**
	 * @return string[]
	 */
	private function getExperimentsToPrune(): array {
		$experiment = $this->getOption( 'experiment' );
		if ( $experiment !== null ) {
			return [ $experiment ];
		}

		/** @var ExperimentManager $manager */
		$manager = $this->getServiceContainer()->get( 'WikiOasisMagic.ExperimentManager' );
		$names = [];
		foreach ( $manager->getExperiments() as $name => $definition ) {
			if ( $definition->unit !== ExperimentUnit::WIKI || $this->hasOption( 'include-wikis' ) ) {
				$names[] = $name;
			}
		}
		return $names;
	}

	private function pruneOrphaned( ExperimentDataStore $dataStore ): void {
		$services = $this->getServiceContainer();
		/** @var ExperimentManager $manager */
		$manager = $services->get( 'WikiOasisMagic.ExperimentManager' );
		/** @var ExperimentStateStore $stateStore */
		$stateStore = $services->get( 'WikiOasisMagic.ExperimentStateStore' );

		$registered = array_keys( $manager->getExperiments() );
		foreach ( array_diff( $dataStore->getStoredExperiments(), $registered ) as $name ) {
			$dataStore->deleteExperimentData( $name );
			$this->output( "Deleted the data of \"$name\", which is no longer registered.\n" );
		}

		foreach ( array_diff( array_keys( $stateStore->getAll() ), $registered ) as $name ) {
			$stateStore->clear( $name );
			$this->output( "Deleted the runtime state of \"$name\", which is no longer registered.\n" );
		}
	}
}

return PruneExperimentData::class;
