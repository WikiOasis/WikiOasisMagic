<?php

namespace WikiOasis\WikiOasisMagic\Experiments;

use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserIdentity;
use StatusValue;
use Throwable;
use Wikimedia\Rdbms\DBError;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use function array_diff;
use function array_filter;
use function array_flip;
use function array_intersect_key;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function array_sum;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_numeric;
use function is_string;
use function json_encode;
use function round;
use function str_contains;

class ExperimentAdmin {

	public const RIGHT = 'experiments-manage';

	public const LOG_TYPE = 'experiment';

	private const MAX_WIKIS = 500;
	private const MAX_WEIGHT = 1000000;

	public function __construct(
		private readonly ExperimentManager $manager,
		private readonly ExperimentStateStore $stateStore,
		private readonly WikiRollout $rollout,
	) {
	}

	/**
	 * @param string $name
	 * @param array $input
	 * @param string $reason
	 * @param UserIdentity $performer
	 * @param string $baseVersion
	 * @return StatusValue
	 */
	public function update(
		string $name,
		array $input,
		string $reason,
		UserIdentity $performer,
		string $baseVersion
	): StatusValue {
		$status = $this->checkCanSave( $name, $baseVersion );
		if ( !$status->isOK() ) {
			return $status;
		}

		$configured = $this->manager->getConfiguredExperiment( $name );
		if ( !$configured ) {
			return StatusValue::newFatal( 'wikioasismagic-experiments-error-unknown', $name );
		}
		$current = $configured->withState( $status->getValue()['state'] ?? [] );

		$validation = $this->validateState( $current, $input );
		if ( !$validation->isOK() ) {
			return $validation;
		}

		$state = array_merge( $current->getState(), $validation->getValue() );
		if ( $state['population'] !== Experiment::POPULATION_ALL && $state['start'] === null ) {
			$state['start'] = ConvertibleTimestamp::now( TS_MW );
		}

		$updated = $configured->withState( $state );
		$changes = $this->describeChanges( $current, $updated );
		if ( !$changes ) {
			return StatusValue::newGood( [ 'experiment' => $current, 'changes' => [] ] );
		}

		$stored = array_intersect_key(
			$updated->getState(),
			array_flip( Experiment::diffState( $configured->getState(), $updated->getState() ) )
		);

		try {
			if ( $stored ) {
				$this->stateStore->save( $name, $stored, $performer->getName() );
			} else {
				$this->stateStore->clear( $name );
			}
		} catch ( DBError ) {
			return StatusValue::newFatal( 'wikioasismagic-experiments-error-unavailable' );
		}

		$this->afterChange( 'update', $current, $reason, $performer, $changes );
		return StatusValue::newGood( [ 'experiment' => $this->manager->getExperiment( $name ), 'changes' => $changes ] );
	}

	/**
	 * @return StatusValue
	 */
	public function reset( string $name, string $reason, UserIdentity $performer, string $baseVersion ): StatusValue {
		$status = $this->checkCanSave( $name, $baseVersion );
		if ( !$status->isOK() ) {
			return $status;
		}

		$configured = $this->manager->getConfiguredExperiment( $name );
		if ( !$configured ) {
			return StatusValue::newFatal( 'wikioasismagic-experiments-error-unknown', $name );
		}
		$current = $configured->withState( $status->getValue()['state'] ?? [] );

		try {
			$this->stateStore->clear( $name );
		} catch ( DBError ) {
			return StatusValue::newFatal( 'wikioasismagic-experiments-error-unavailable' );
		}

		$changes = $this->describeChanges( $current, $configured );
		$this->afterChange( 'reset', $current, $reason, $performer, $changes );
		return StatusValue::newGood( [ 'experiment' => $this->manager->getExperiment( $name ), 'changes' => $changes ] );
	}

	/**
	 * @return StatusValue
	 */
	public function validateState( Experiment $experiment, array $input ): StatusValue {
		$state = [];
		$unknown = array_diff( array_keys( $input ), Experiment::STATE_FIELDS );
		if ( $unknown ) {
			return StatusValue::newFatal( 'wikioasismagic-experiments-error-field', implode( ', ', $unknown ) );
		}

		if ( array_key_exists( 'active', $input ) ) {
			if ( !is_bool( $input['active'] ) ) {
				return StatusValue::newFatal( 'wikioasismagic-experiments-error-field', 'active' );
			}
			$state['active'] = $input['active'];
		}

		if ( array_key_exists( 'rollout', $input ) ) {
			if ( !is_numeric( $input['rollout'] ) || $input['rollout'] < 0 || $input['rollout'] > 100 ) {
				return StatusValue::newFatal( 'wikioasismagic-experiments-error-rollout' );
			}
			$state['rollout'] = round( (float)$input['rollout'], 2 );
		}

		if ( array_key_exists( 'default', $input ) ) {
			if ( !is_string( $input['default'] ) || !$experiment->hasVariant( $input['default'] ) ) {
				return StatusValue::newFatal( 'wikioasismagic-experiments-error-field', 'default' );
			}
			$state['default'] = $input['default'];
		}

		if ( array_key_exists( 'weights', $input ) ) {
			if ( !is_array( $input['weights'] ) ) {
				return StatusValue::newFatal( 'wikioasismagic-experiments-error-field', 'weights' );
			}

			$weights = [];
			foreach ( $input['weights'] as $variant => $weight ) {
				if (
					!is_string( $variant ) || !$experiment->hasVariant( $variant ) ||
					!is_numeric( $weight ) || $weight < 0 || $weight > self::MAX_WEIGHT
				) {
					return StatusValue::newFatal( 'wikioasismagic-experiments-error-weights' );
				}
				$weights[$variant] = round( (float)$weight, 2 );
			}

			$weights += $experiment->getState()['weights'];
			if ( array_sum( $weights ) <= 0 ) {
				return StatusValue::newFatal( 'wikioasismagic-experiments-error-weights' );
			}
			$state['weights'] = $weights;
		}

		if ( array_key_exists( 'population', $input ) ) {
			$population = $input['population'];
			if (
				!in_array( $population, Experiment::POPULATIONS, true ) ||
				( $experiment->unit === ExperimentUnit::BROWSER && $population !== Experiment::POPULATION_ALL )
			) {
				return StatusValue::newFatal( 'wikioasismagic-experiments-error-field', 'population' );
			}
			$state['population'] = $population;
		}

		foreach ( [ 'start', 'end' ] as $field ) {
			if ( !array_key_exists( $field, $input ) ) {
				continue;
			}

			$value = $input[$field];
			if ( $value === null || $value === '' ) {
				$state[$field] = null;
				continue;
			}

			$timestamp = is_string( $value ) ? ConvertibleTimestamp::convert( TS_MW, $value ) : false;
			if ( $timestamp === false ) {
				return StatusValue::newFatal( 'wikioasismagic-experiments-error-field', $field );
			}
			$state[$field] = $timestamp;
		}

		$start = array_key_exists( 'start', $state ) ? $state['start'] : $experiment->start;
		$end = array_key_exists( 'end', $state ) ? $state['end'] : $experiment->end;
		if ( $start !== null && $end !== null && $end <= $start ) {
			return StatusValue::newFatal( 'wikioasismagic-experiments-error-dates' );
		}

		foreach ( [ 'wikis', 'excludeWikis' ] as $field ) {
			if ( !array_key_exists( $field, $input ) ) {
				continue;
			}

			$list = $input[$field];
			if ( !is_array( $list ) || count( $list ) > self::MAX_WIKIS ) {
				return StatusValue::newFatal( 'wikioasismagic-experiments-error-field', $field );
			}

			$normalised = Experiment::newFromArray( $experiment->name, [ $field => $list ] );
			$problems = array_filter(
				$normalised->getProblems(),
				static fn ( $problem ) => str_contains( $problem, "\"$field\"" )
			);
			if ( $problems ) {
				return StatusValue::newFatal( 'wikioasismagic-experiments-error-wikis' );
			}
			$state[$field] = $field === 'wikis' ? $normalised->wikis : $normalised->excludeWikis;
		}

		return StatusValue::newGood( $state );
	}

	/**
	 * @return array<int,array{field:string,old:mixed,new:mixed}>
	 */
	public function describeChanges( Experiment $before, Experiment $after ): array {
		$old = $before->getState();
		$new = $after->getState();

		$changes = [];
		foreach ( Experiment::diffState( $old, $new ) as $field ) {
			$changes[] = [ 'field' => $field, 'old' => $old[$field], 'new' => $new[$field] ];
		}
		return $changes;
	}

	private function checkCanSave( string $name, string $baseVersion ): StatusValue {
		if ( !$this->stateStore->isAvailable() ) {
			return StatusValue::newFatal( 'wikioasismagic-experiments-error-unavailable' );
		}

		try {
			$latest = $this->stateStore->getLatestEntry( $name );
		} catch ( Throwable ) {
			return StatusValue::newFatal( 'wikioasismagic-experiments-error-unavailable' );
		}

		if ( ( $latest['version'] ?? '' ) !== $baseVersion ) {
			return $latest ?
				StatusValue::newFatal( 'wikioasismagic-experiments-error-conflict', $latest['user'] ) :
				StatusValue::newFatal( 'wikioasismagic-experiments-error-conflict-reset' );
		}

		return StatusValue::newGood( [ 'state' => $latest['state'] ?? [] ] );
	}

	private function afterChange(
		string $action,
		Experiment $before,
		string $reason,
		UserIdentity $performer,
		array $changes
	): void {
		$this->manager->clearCache();

		$after = $this->manager->getExperiment( $before->name );
		if ( $after ) {
			$this->rollout->syncExperiment( $after );
		}

		$entry = new ManualLogEntry( self::LOG_TYPE, $action );
		$entry->setPerformer( $performer );
		$entry->setTarget( SpecialPage::getTitleFor( 'Experiments', $before->name ) );
		$entry->setComment( $reason );
		$entry->setParameters( [
			'4::summary' => self::summarise( $changes ),
			'changes' => $changes,
		] );
		$entry->insert();
	}

	public static function summarise( array $changes ): string {
		$parts = [];
		foreach ( $changes as $change ) {
			$parts[] = $change['field'] . ': ' . self::formatValue( $change['old'] ) . ' → ' .
				self::formatValue( $change['new'] );
		}
		return implode( '; ', $parts );
	}

	private static function formatValue( mixed $value ): string {
		if ( $value === null ) {
			return '—';
		}

		if ( is_bool( $value ) ) {
			return $value ? 'on' : 'off';
		}

		if ( is_array( $value ) ) {
			if ( $value === [] ) {
				return '—';
			}
			return implode( ', ', array_map(
				static fn ( $key, $item ) => is_string( $key ) ? "$key $item" : (string)$item,
				array_keys( $value ),
				$value
			) );
		}

		return is_string( $value ) || is_numeric( $value ) ? (string)$value : (string)json_encode( $value );
	}
}
