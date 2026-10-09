<?php

namespace WikiOasis\WikiOasisMagic\Experiments;

use Wikimedia\Timestamp\ConvertibleTimestamp;
use WikiOasis\WikiOasisMagic\Onboarding\SurveyText;
use function array_diff;
use function array_filter;
use function array_key_exists;
use function array_key_first;
use function array_keys;
use function array_map;
use function array_merge;
use function array_sum;
use function array_unique;
use function array_values;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_numeric;
use function is_string;
use function max;
use function min;
use function preg_match;

class Experiment {

	public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

	public const WIKI_CENTRAL = '@central';

	public const WIKI_LOCAL = '@local';

	private const WIKI_PATTERN = '/^(@central|@local|[a-z0-9_]{1,64})$/';
	private const CONFIG_VARIABLE_PATTERN = '/^wg[A-Za-z0-9_]+$/';

	public const POPULATION_ALL = 'all';
	public const POPULATION_NEW = 'new';
	public const POPULATION_EXISTING = 'existing';
	public const POPULATIONS = [ self::POPULATION_ALL, self::POPULATION_NEW, self::POPULATION_EXISTING ];

	public const STATUS_OFF = 'off';
	public const STATUS_SCHEDULED = 'scheduled';
	public const STATUS_RUNNING = 'running';
	public const STATUS_ENDED = 'ended';

	public const STATE_FIELDS = [
		'active', 'rollout', 'default', 'weights', 'population', 'start', 'end', 'wikis', 'excludeWikis',
	];

	/**
	 * @param string $name
	 * @param string $unit
	 * @param bool $active
	 * @param string $defaultVariant
	 * @param float $rollout
	 * @param array<string,array{weight:float,params:array,extensions:string[],config:array}> $variants
	 * @param string $population
	 * @param string|null $start
	 * @param string|null $end
	 * @param string[] $wikis
	 * @param string[] $excludeWikis
	 * @param string $salt
	 * @param int|null $window
	 * @param array<string,Metric> $metrics
	 * @param string|null $primaryMetric
	 * @param array $info
	 * @param string[] $problems
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $unit,
		public readonly bool $active,
		public readonly string $defaultVariant,
		public readonly float $rollout,
		public readonly array $variants,
		public readonly string $population,
		public readonly ?string $start,
		public readonly ?string $end,
		public readonly array $wikis,
		public readonly array $excludeWikis,
		public readonly string $salt,
		public readonly ?int $window = null,
		public readonly array $metrics = [],
		public readonly ?string $primaryMetric = null,
		public readonly array $info = [],
		public readonly array $problems = [],
	) {
	}

	public static function newFromArray( string $name, array $spec ): self {
		$problems = [];

		$unit = $spec['unit'] ?? ExperimentUnit::USER;
		if ( !in_array( $unit, ExperimentUnit::TYPES, true ) ) {
			$problems[] = '"unit" must be one of ' . implode( ', ', ExperimentUnit::TYPES ) . ', using "user"';
			$unit = ExperimentUnit::USER;
		}

		$variants = [];
		foreach ( (array)( $spec['variants'] ?? [] ) as $variant => $variantSpec ) {
			if ( !is_string( $variant ) || !preg_match( self::NAME_PATTERN, $variant ) ) {
				$problems[] = "variant name \"$variant\" is invalid and was ignored";
				continue;
			}

			if ( is_numeric( $variantSpec ) ) {
				$variantSpec = [ 'weight' => $variantSpec ];
			}

			if ( !is_array( $variantSpec ) ) {
				$problems[] = "variant \"$variant\" must be a weight or an array";
				continue;
			}

			$variants[$variant] = self::normaliseVariant( $variant, $variantSpec, $unit, $problems );
		}

		$default = $spec['default'] ?? 'control';
		if ( !is_string( $default ) || !preg_match( self::NAME_PATTERN, $default ) ) {
			$problems[] = 'default variant is invalid, using "control"';
			$default = 'control';
		}

		if ( !array_key_exists( $default, $variants ) ) {
			$variants[$default] = [ 'weight' => 0.0, 'params' => [], 'extensions' => [], 'config' => [] ];
		}

		if ( array_sum( array_map( static fn ( $v ) => $v['weight'], $variants ) ) <= 0 ) {
			$problems[] = 'no variant has a positive weight, so enrolled units get the default';
		}

		$active = $spec['active'] ?? false;
		if ( !is_bool( $active ) ) {
			$problems[] = '"active" must be true or false';
			$active = (bool)$active;
		}

		$population = self::normalisePopulation( $spec['population'] ?? self::POPULATION_ALL, $unit, $problems );
		$start = self::normaliseTimestamp( $spec['start'] ?? null, 'start', $problems );
		if ( $population !== self::POPULATION_ALL && $start === null ) {
			$problems[] = "population \"$population\" needs a start date to compare with, so every unit counts";
		}

		$window = $spec['window'] ?? null;
		if ( $window !== null && ( !is_int( $window ) || $window < 1 ) ) {
			$problems[] = '"window" must be a number of days or null';
			$window = null;
		}

		$metrics = [];
		foreach ( (array)( $spec['metrics'] ?? [] ) as $metricName => $metricSpec ) {
			$metric = is_string( $metricName ) ? Metric::newFromArray( $metricName, $metricSpec, $problems ) : null;
			if ( $metric ) {
				$metrics[$metricName] = $metric;
			}
		}

		$primary = $spec['primary'] ?? null;
		if ( $primary !== null && !isset( $metrics[$primary] ) ) {
			$problems[] = "primary metric \"$primary\" is not one of the experiment's metrics";
			$primary = null;
		}

		$info = [];
		foreach ( [ 'label', 'description' ] as $field ) {
			if ( !isset( $spec[$field] ) ) {
				continue;
			}
			$error = SurveyText::validate( $spec[$field] );
			if ( $error !== null ) {
				$problems[] = "\"$field\" is invalid and was ignored: $error";
				continue;
			}
			$info[$field] = $spec[$field];
		}

		foreach ( [ 'owner', 'task', 'preview' ] as $field ) {
			if ( !isset( $spec[$field] ) ) {
				continue;
			}
			if ( !is_string( $spec[$field] ) || $spec[$field] === '' ) {
				$problems[] = "\"$field\" must be a string and was ignored";
				continue;
			}
			$info[$field] = $spec[$field];
		}

		return new self(
			name: $name,
			unit: $unit,
			active: $active,
			defaultVariant: $default,
			rollout: self::clampPercentage( $spec['rollout'] ?? 100 ),
			variants: $variants,
			population: $population,
			start: $start,
			end: self::normaliseTimestamp( $spec['end'] ?? null, 'end', $problems ),
			wikis: self::normaliseWikiList( $spec['wikis'] ?? [], 'wikis', $problems ),
			excludeWikis: self::normaliseWikiList( $spec['excludeWikis'] ?? [], 'excludeWikis', $problems ),
			salt: is_string( $spec['salt'] ?? null ) && $spec['salt'] !== '' ? $spec['salt'] : $name,
			window: $window,
			metrics: $metrics,
			primaryMetric: $primary ?? array_key_first( $metrics ),
			info: $info,
			problems: $problems,
		);
	}

	public function withState( array $state ): self {
		$variants = $this->variants;
		foreach ( (array)( $state['weights'] ?? [] ) as $variant => $weight ) {
			if ( isset( $variants[$variant] ) && is_numeric( $weight ) ) {
				$variants[$variant]['weight'] = max( 0.0, (float)$weight );
			}
		}

		$default = $state['default'] ?? null;
		if ( !is_string( $default ) || !isset( $variants[$default] ) ) {
			$default = $this->defaultVariant;
		}

		$ignored = [];
		$population = isset( $state['population'] ) ?
			self::normalisePopulation( $state['population'], $this->unit, $ignored ) :
			$this->population;

		return new self(
			name: $this->name,
			unit: $this->unit,
			active: is_bool( $state['active'] ?? null ) ? $state['active'] : $this->active,
			defaultVariant: $default,
			rollout: isset( $state['rollout'] ) && is_numeric( $state['rollout'] ) ?
				self::clampPercentage( $state['rollout'] ) :
				$this->rollout,
			variants: $variants,
			population: $population,
			start: array_key_exists( 'start', $state ) ?
				self::normaliseTimestamp( $state['start'], 'start', $ignored ) :
				$this->start,
			end: array_key_exists( 'end', $state ) ?
				self::normaliseTimestamp( $state['end'], 'end', $ignored ) :
				$this->end,
			wikis: array_key_exists( 'wikis', $state ) ?
				self::normaliseWikiList( $state['wikis'], 'wikis', $ignored ) :
				$this->wikis,
			excludeWikis: array_key_exists( 'excludeWikis', $state ) ?
				self::normaliseWikiList( $state['excludeWikis'], 'excludeWikis', $ignored ) :
				$this->excludeWikis,
			salt: $this->salt,
			window: $this->window,
			metrics: $this->metrics,
			primaryMetric: $this->primaryMetric,
			info: $this->info,
			problems: $this->problems,
		);
	}

	/**
	 * @return array{active:bool,rollout:float,default:string,weights:array<string,float>,population:string,start:?string,end:?string,wikis:string[],excludeWikis:string[]}
	 */
	public function getState(): array {
		return [
			'active' => $this->active,
			'rollout' => $this->rollout,
			'default' => $this->defaultVariant,
			'weights' => array_map( static fn ( $v ) => $v['weight'], $this->variants ),
			'population' => $this->population,
			'start' => $this->start,
			'end' => $this->end,
			'wikis' => $this->wikis,
			'excludeWikis' => $this->excludeWikis,
		];
	}

	/**
	 * @return string[]
	 */
	public static function diffState( array $base, array $state ): array {
		$changed = [];
		foreach ( self::STATE_FIELDS as $field ) {
			if ( !array_key_exists( $field, $state ) ) {
				continue;
			}

			$old = $base[$field] ?? null;
			$new = $state[$field];
			if ( $field === 'weights' || $field === 'rollout' ) {
				$old = is_array( $old ) ? array_map( 'floatval', $old ) : (float)$old;
				$new = is_array( $new ) ? array_map( 'floatval', $new ) : (float)$new;
			}

			if ( $old !== $new ) {
				$changed[] = $field;
			}
		}

		return $changed;
	}

	public function toSpec(): array {
		return [
			'unit' => $this->unit,
			'active' => $this->active,
			'rollout' => $this->rollout,
			'default' => $this->defaultVariant,
			'variants' => $this->variants,
			'population' => $this->population,
			'start' => $this->start,
			'end' => $this->end,
			'wikis' => $this->wikis,
			'excludeWikis' => $this->excludeWikis,
			'salt' => $this->salt,
		];
	}

	public function getStatus( string $now ): string {
		if ( !$this->active ) {
			return self::STATUS_OFF;
		}

		if ( $this->start !== null && $now < $this->start ) {
			return self::STATUS_SCHEDULED;
		}

		return $this->end !== null && $now >= $this->end ? self::STATUS_ENDED : self::STATUS_RUNNING;
	}

	public function isWithinWindow( string $now ): bool {
		return $this->getStatus( $now ) === self::STATUS_RUNNING;
	}

	public function appliesToWiki( string $dbname, bool $isCentral ): bool {
		$matches = static function ( array $list ) use ( $dbname, $isCentral ): bool {
			return in_array( $dbname, $list, true ) ||
				( $isCentral && in_array( self::WIKI_CENTRAL, $list, true ) ) ||
				( !$isCentral && in_array( self::WIKI_LOCAL, $list, true ) );
		};

		if ( $this->wikis && !$matches( $this->wikis ) ) {
			return false;
		}

		return !$matches( $this->excludeWikis );
	}

	/**
	 * @param string|null $created
	 */
	public function matchesPopulation( ?string $created ): bool {
		if ( $this->population === self::POPULATION_ALL || $this->start === null ) {
			return true;
		}

		if ( $created === null ) {
			return $this->population === self::POPULATION_EXISTING;
		}

		return $this->population === self::POPULATION_NEW ? $created >= $this->start : $created < $this->start;
	}

	/** @return string[] */
	public function getVariantNames(): array {
		return array_keys( $this->variants );
	}

	public function hasVariant( string $variant ): bool {
		return isset( $this->variants[$variant] );
	}

	public function getParams( string $variant ): array {
		return $this->variants[$variant]['params'] ?? [];
	}

	/** @return string[] */
	public function getExtensions( string $variant ): array {
		return $this->variants[$variant]['extensions'] ?? [];
	}

	/** @return array<string,mixed> */
	public function getConfig( string $variant ): array {
		return $this->variants[$variant]['config'] ?? [];
	}

	/** @return string[] */
	public function getAllExtensions(): array {
		return array_values( array_unique( array_merge( [], ...array_map(
			static fn ( $v ) => $v['extensions'],
			array_values( $this->variants )
		) ) ) );
	}

	/** @return string[] */
	public function getAllConfigVariables(): array {
		return array_values( array_unique( array_merge( [], ...array_map(
			static fn ( $v ) => array_keys( $v['config'] ),
			array_values( $this->variants )
		) ) ) );
	}

	public function changesWikiConfig(): bool {
		return $this->getAllExtensions() !== [] || $this->getAllConfigVariables() !== [];
	}

	public function getWeight( string $variant ): float {
		return $this->variants[$variant]['weight'] ?? 0.0;
	}

	public function getTotalWeight(): float {
		return array_sum( array_map( static fn ( $v ) => $v['weight'], $this->variants ) );
	}

	public function getMetric( string $name ): ?Metric {
		return $this->metrics[$name] ?? null;
	}

	public function pickVariant( float $point ): string {
		$total = $this->getTotalWeight();
		if ( $total <= 0 ) {
			return $this->defaultVariant;
		}

		$target = $point / 100 * $total;
		$cumulative = 0.0;
		$last = $this->defaultVariant;
		foreach ( $this->variants as $variant => $spec ) {
			if ( $spec['weight'] <= 0 ) {
				continue;
			}

			$cumulative += $spec['weight'];
			$last = $variant;
			if ( $target < $cumulative ) {
				return $variant;
			}
		}

		return $last;
	}

	/** @return string[] */
	public function getProblems(): array {
		return $this->problems;
	}

	private static function normaliseVariant( string $variant, array $spec, string $unit, array &$problems ): array {
		$extensions = array_values( array_filter(
			(array)( $spec['extensions'] ?? [] ),
			static fn ( $item ) => is_string( $item ) && $item !== ''
		) );

		$config = [];
		foreach ( (array)( $spec['config'] ?? [] ) as $variable => $value ) {
			if ( !is_string( $variable ) || !preg_match( self::CONFIG_VARIABLE_PATTERN, $variable ) ) {
				$problems[] = "variant \"$variant\" sets \"$variable\", which is not a \$wg configuration variable";
				continue;
			}
			$config[$variable] = $value;
		}

		if ( ( $extensions || $config ) && $unit !== ExperimentUnit::WIKI ) {
			$problems[] = "variant \"$variant\" can only switch extensions or configuration in a wiki experiment";
			$extensions = [];
			$config = [];
		}

		return [
			'weight' => max( 0.0, (float)( $spec['weight'] ?? 0 ) ),
			'params' => is_array( $spec['params'] ?? null ) ? $spec['params'] : [],
			'extensions' => $extensions,
			'config' => $config,
		];
	}

	private static function normalisePopulation( mixed $population, string $unit, array &$problems ): string {
		if ( !in_array( $population, self::POPULATIONS, true ) ) {
			$problems[] = '"population" must be one of ' . implode( ', ', self::POPULATIONS ) . ', using "all"';
			return self::POPULATION_ALL;
		}

		if ( $population !== self::POPULATION_ALL && $unit === ExperimentUnit::BROWSER ) {
			$problems[] = 'browsers have no age, so "population" is "all"';
			return self::POPULATION_ALL;
		}

		return $population;
	}

	private static function clampPercentage( mixed $value ): float {
		return is_numeric( $value ) ? min( 100.0, max( 0.0, (float)$value ) ) : 0.0;
	}

	private static function normaliseTimestamp( mixed $value, string $field, array &$problems ): ?string {
		if ( $value === null || $value === '' ) {
			return null;
		}

		if ( ( !is_string( $value ) && !is_int( $value ) ) || ( is_numeric( $value ) && (int)$value <= 0 ) ) {
			$problems[] = "\"$field\" is not a timestamp MediaWiki understands and was ignored";
			return null;
		}

		$timestamp = ConvertibleTimestamp::convert( TS_MW, $value );
		if ( $timestamp === false ) {
			$problems[] = "\"$field\" is not a timestamp MediaWiki understands and was ignored";
			return null;
		}

		return $timestamp;
	}

	/** @return string[] */
	private static function normaliseWikiList( mixed $list, string $field, array &$problems ): array {
		$wikis = array_values( array_unique( array_filter( (array)$list, 'is_string' ) ) );
		$invalid = array_filter( $wikis, static fn ( $wiki ) => !preg_match( self::WIKI_PATTERN, $wiki ) );
		if ( $invalid ) {
			$problems[] = "\"$field\" has invalid entries, which were ignored: " . implode( ', ', $invalid );
		}

		return array_values( array_diff( $wikis, $invalid ) );
	}
}
