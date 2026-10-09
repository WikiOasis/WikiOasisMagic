<?php

namespace WikiOasis\WikiOasisMagic\Maintenance;

use MediaWiki\Json\FormatJson;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use WikiOasis\WikiOasisMagic\Experiments\Experiment;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentDataStore;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentManager;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentUnit;
use WikiOasis\WikiOasisMagic\Experiments\UnitFactory;
use WikiOasis\WikiOasisMagic\Experiments\WikiFarm;
use WikiOasis\WikiOasisMagic\Experiments\WikiRollout;
use function is_string;
use function round;
use function sprintf;

class Experiments extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Inspect experiments, look up assignments, simulate splits and sync wiki experiments.' );
		$this->addOption( 'experiment', 'Experiment name', false, true );
		$this->addOption( 'user', 'Look up the variant of this user', false, true );
		$this->addOption( 'target-wiki', 'Look up the variant of this wiki (database name)', false, true );
		$this->addOption( 'unit', 'Look up the variant of this raw unit id, such as a browser token', false, true );
		$this->addOption( 'simulate', 'Assign this many synthetic units and print the split', false, true );
		$this->addOption( 'preview', 'Print a link that forces this variant for two days', false, true );
		$this->addOption( 'sync', 'Bring every wiki in line with a wiki experiment now, without the job queue' );
		$this->addOption( 'rebuild', 'With --sync, rebuild the ManageWiki cache of every eligible wiki' );
		$this->addOption( 'sweep', 'Queue syncs for every wiki experiment whose rollout changed since it was last applied' );
		$this->addOption( 'delete-data', 'Forget everything measured for the experiment' );
		$this->addOption( 'release', 'Forget which wikis the experiment switched extensions on for, leaving them on' );
		$this->addOption( 'json', 'Print JSON' );
		$this->requireExtension( 'WikiOasisMagic' );
	}

	public function execute(): void {
		$services = $this->getServiceContainer();
		/** @var ExperimentManager $manager */
		$manager = $services->get( 'WikiOasisMagic.ExperimentManager' );
		$name = $this->getOption( 'experiment' );

		if ( $this->hasOption( 'sweep' ) ) {
			/** @var WikiRollout $rollout */
			$rollout = $services->get( 'WikiOasisMagic.WikiRollout' );
			foreach ( $rollout->sweep() as $swept => $action ) {
				$this->output( "$swept: $action\n" );
			}
			return;
		}

		if ( $name === null ) {
			$all = [];
			foreach ( $manager->getExperiments() as $experiment ) {
				$all[$experiment->name] = $this->describe( $manager, $experiment );
			}
			$this->output( FormatJson::encode( $all, true ) . "\n" );
			return;
		}

		if ( $this->hasOption( 'delete-data' ) ) {
			/** @var ExperimentDataStore $dataStore */
			$dataStore = $services->get( 'WikiOasisMagic.ExperimentDataStore' );
			$dataStore->deleteExperimentData( $name );
			$this->output( "Deleted everything measured for \"$name\".\n" );
			return;
		}

		if ( $this->hasOption( 'release' ) ) {
			/** @var ExperimentDataStore $dataStore */
			$dataStore = $services->get( 'WikiOasisMagic.ExperimentDataStore' );
			$released = $dataStore->releaseExperiment( $name );
			$this->output( "Released $released extensions; they stay switched on and belong to their wikis now.\n" );
			return;
		}

		$experiment = $manager->getExperiment( $name );
		if ( !$experiment ) {
			$this->fatalError( "No experiment called \"$name\"." );
		}

		$preview = $this->getOption( 'preview' );
		if ( $preview !== null ) {
			if ( !$experiment->hasVariant( $preview ) ) {
				$this->fatalError( "\"$name\" has no variant called \"$preview\"." );
			}
			$page = $experiment->info['preview'] ?? null;
			$title = ( is_string( $page ) ? Title::newFromText( $page ) : null ) ??
				SpecialPage::getTitleFor( 'Experiments', $name );
			$this->output( $title->getFullURL( $manager->makeOverrideQuery( "$name:$preview" ) ) . "\n" );
			return;
		}

		if ( $this->hasOption( 'sync' ) ) {
			$this->sync( $experiment );
			return;
		}

		$unit = $this->getUnit( $experiment );
		if ( $unit !== null ) {
			$this->printAssignment( $manager, $experiment, $unit );
			return;
		}

		$samples = (int)( $this->getOption( 'simulate' ) ?? 0 );
		if ( $samples > 0 ) {
			$this->printSimulation( $manager, $experiment, $samples );
			return;
		}

		$this->output( FormatJson::encode( $this->describe( $manager, $experiment ), true ) . "\n" );
	}

	private function getUnit( Experiment $experiment ): ?ExperimentUnit {
		/** @var UnitFactory $unitFactory */
		$unitFactory = $this->getServiceContainer()->get( 'WikiOasisMagic.UnitFactory' );

		$user = $this->getOption( 'user' );
		if ( $user !== null ) {
			$identity = $this->getServiceContainer()->getUserIdentityLookup()->getUserIdentityByName( $user );
			if ( !$identity ) {
				$this->fatalError( "No user called \"$user\"." );
			}
			return $unitFactory->forUser( $identity );
		}

		$wiki = $this->getOption( 'target-wiki' );
		if ( $wiki !== null ) {
			return $unitFactory->forWiki( $wiki );
		}

		$raw = $this->getOption( 'unit' );
		return $raw !== null ? new ExperimentUnit( $experiment->unit, $raw ) : null;
	}

	private function printAssignment( ExperimentManager $manager, Experiment $experiment, ExperimentUnit $unit ): void {
		$assignment = $manager->assign( $experiment->name, $unit );
		$result = [
			'experiment' => $experiment->name,
			'unit' => $unit->type . ':' . $unit->id,
			'created' => $unit->getCreated(),
			'variant' => $assignment->variant,
			'enrolled' => $assignment->enrolled,
			'params' => $assignment->params,
			'extensions' => $experiment->getExtensions( $assignment->variant ),
			'config' => $experiment->getConfig( $assignment->variant ),
			'enrolmentPoint' => round( ExperimentManager::bucket( $experiment->salt . ':enrol', $unit->id ), 4 ),
			'variantPoint' => round( ExperimentManager::bucket( $experiment->salt . ':variant', $unit->id ), 4 ),
		];

		if ( $this->hasOption( 'json' ) ) {
			$this->output( FormatJson::encode( $result, true ) . "\n" );
			return;
		}

		$this->output( "{$result['unit']} gets \"{$assignment->variant}\"" .
			( $assignment->enrolled ? ' (enrolled)' : ' (not enrolled: the default variant)' ) . "\n" );
	}

	private function printSimulation( ExperimentManager $manager, Experiment $experiment, int $samples ): void {
		$counts = $manager->simulate( $experiment, $samples );
		if ( $this->hasOption( 'json' ) ) {
			$this->output( FormatJson::encode( $counts, true ) . "\n" );
			return;
		}

		$this->output( sprintf( "%-20s %10s %10s %8s\n", 'variant', 'enrolled', 'default', 'share' ) );
		foreach ( $counts as $variant => $count ) {
			$total = $count['enrolled'] + $count['default'];
			$this->output( sprintf( "%-20s %10d %10d %7.2f%%\n",
				$variant, $count['enrolled'], $count['default'], $total / $samples * 100 ) );
		}
	}

	private function sync( Experiment $experiment ): void {
		if ( $experiment->unit !== ExperimentUnit::WIKI ) {
			$this->fatalError( 'Only wiki experiments are synced.' );
		}

		$services = $this->getServiceContainer();
		/** @var WikiFarm $farm */
		$farm = $services->get( 'WikiOasisMagic.WikiFarm' );
		/** @var WikiRollout $rollout */
		$rollout = $services->get( 'WikiOasisMagic.WikiRollout' );

		/** @var ExperimentDataStore $dataStore */
		$dataStore = $services->get( 'WikiOasisMagic.ExperimentDataStore' );
		$previous = $dataStore->getSyncRecords()[$experiment->name]['state'] ?? null;
		$force = $this->hasOption( 'rebuild' ) || ( $previous === null && $experiment->getAllConfigVariables() );

		$after = '';
		$outcomes = [];
		do {
			$batch = $farm->listWikis( $after, 100 );
			foreach ( $batch as $wiki => $created ) {
				$outcome = $rollout->syncAndApply( $experiment, $previous, $wiki, $created, $force );
				$outcomes[$outcome] = ( $outcomes[$outcome] ?? 0 ) + 1;
				$this->output( sprintf( "%-30s %s\n", $wiki, $outcome ) );
				$after = $wiki;
			}
			$this->waitForReplication();
		} while ( $batch );

		$rollout->markApplied( $experiment );

		$this->output( FormatJson::encode( $outcomes ) . "\n" );
	}

	private function describe( ExperimentManager $manager, Experiment $experiment ): array {
		return [
			'unit' => $experiment->unit,
			'status' => $experiment->getStatus( ConvertibleTimestamp::now( TS_MW ) ),
			'runningHere' => $manager->isRunning( $experiment ),
			'configured' => $manager->getConfiguredExperiment( $experiment->name )?->getState(),
			'effective' => $experiment->getState(),
			'problems' => $experiment->getProblems(),
		];
	}
}

return Experiments::class;
