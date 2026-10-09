<?php

namespace WikiOasis\WikiOasisMagic\Jobs;

use MediaWiki\JobQueue\GenericParameterJob;
use MediaWiki\JobQueue\Job;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Throwable;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentDataStore;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentManager;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentStateStore;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentUnit;
use WikiOasis\WikiOasisMagic\Experiments\WikiFarm;
use WikiOasis\WikiOasisMagic\Experiments\WikiRollout;
use function array_key_last;
use function array_values;
use function count;
use function is_array;

class ExperimentSyncJob extends Job implements GenericParameterJob {

	public const COMMAND = 'wikiOasisExperimentSync';

	private const BATCH_SIZE = 50;

	private const MAX_RETRIES = 3;

	public function __construct( array $params ) {
		parent::__construct( self::COMMAND, $params );
		$this->executionFlags |= self::JOB_NO_EXPLICIT_TRX_ROUND;
	}

	/**
	 * @param string $experiment
	 * @param string[] $wikis
	 * @param array|null $previous
	 * @param bool $force
	 */
	public static function newSpec(
		string $experiment,
		array $wikis,
		?array $previous,
		bool $force
	): JobSpecification {
		return new JobSpecification( self::COMMAND, [
			'experiment' => $experiment,
			'wikis' => array_values( $wikis ),
			'previous' => $previous,
			'force' => $force,
			'cleanup' => false,
			'after' => '',
		], [ 'removeDuplicates' => true ] );
	}

	public static function newCleanupSpec( string $experiment, array $previous ): JobSpecification {
		return new JobSpecification( self::COMMAND, [
			'experiment' => $experiment,
			'wikis' => [],
			'previous' => $previous,
			'force' => false,
			'cleanup' => true,
			'after' => '',
		], [ 'removeDuplicates' => true ] );
	}

	public function run(): bool {
		$services = MediaWikiServices::getInstance();
		/** @var ExperimentManager $manager */
		$manager = $services->get( 'WikiOasisMagic.ExperimentManager' );
		/** @var ExperimentStateStore $stateStore */
		$stateStore = $services->get( 'WikiOasisMagic.ExperimentStateStore' );
		/** @var ExperimentDataStore $dataStore */
		$dataStore = $services->get( 'WikiOasisMagic.ExperimentDataStore' );
		/** @var WikiRollout $rollout */
		$rollout = $services->get( 'WikiOasisMagic.WikiRollout' );
		/** @var WikiFarm $farm */
		$farm = $services->get( 'WikiOasisMagic.WikiFarm' );
		$logger = LoggerFactory::getInstance( 'WikiOasisMagic' );

		$stateStore->reload();
		$manager->clearCache();
		$dataStore->clearClaims();

		$name = (string)$this->params['experiment'];
		$previous = is_array( $this->params['previous'] ?? null ) ? $this->params['previous'] : null;
		$cleanup = (bool)( $this->params['cleanup'] ?? false );
		$after = (string)( $this->params['after'] ?? '' );

		if ( $cleanup ) {
			if ( $after === '' ) {
				$dataStore->releaseExperiment( $name );
			}
			if ( !$previous || !$rollout->fromRecord( $previous )?->getAllConfigVariables() ) {
				return true;
			}
		} else {
			$experiment = $manager->getExperiment( $name );
			if ( !$experiment || $experiment->unit !== ExperimentUnit::WIKI ) {
				return true;
			}
		}

		$wikis = (array)( $this->params['wikis'] ?? [] );
		if ( $wikis ) {
			$batch = [];
			foreach ( $wikis as $wiki ) {
				$batch[$wiki] = $farm->getWikiCreation( $wiki );
			}
		} else {
			$batch = $farm->listWikis( $after, self::BATCH_SIZE );
		}

		$outcomes = [];
		$failed = [];
		foreach ( $batch as $wiki => $created ) {
			try {
				$outcome = $cleanup ?
					$rollout->cleanUpAndApply( $previous, $wiki, $created ) :
					$rollout->syncAndApply( $experiment, $previous, $wiki, $created, (bool)$this->params['force'] );
			} catch ( Throwable $e ) {
				$logger->error( 'Could not sync {wiki} with experiment {experiment}: {message}', [
					'wiki' => $wiki,
					'experiment' => $name,
					'message' => $e->getMessage(),
					'exception' => $e,
				] );
				$outcome = WikiRollout::OUTCOME_FAILED;
			}
			$outcomes[$outcome] = ( $outcomes[$outcome] ?? 0 ) + 1;
			if ( $outcome === WikiRollout::OUTCOME_FAILED ) {
				$failed[] = (string)$wiki;
			}
		}

		$retries = (int)( $this->params['retries'] ?? 0 );
		if ( $failed && $retries < self::MAX_RETRIES ) {
			$services->getJobQueueGroup()->push( new JobSpecification(
				self::COMMAND,
				[ 'wikis' => $failed, 'after' => '', 'retries' => $retries + 1 ] + $this->params,
				[ 'removeDuplicates' => true ]
			) );
		}

		$logger->info( 'Synced {count} wikis with experiment {experiment}', [
			'count' => count( $batch ),
			'experiment' => $name,
			'outcomes' => $outcomes,
		] );

		if ( !$wikis && count( $batch ) === self::BATCH_SIZE ) {
			$services->getJobQueueGroup()->push( new JobSpecification(
				self::COMMAND,
				[ 'after' => (string)array_key_last( $batch ) ] + $this->params,
				[ 'removeDuplicates' => true ]
			) );
		}

		return true;
	}
}
