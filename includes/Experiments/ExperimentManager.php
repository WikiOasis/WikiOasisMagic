<?php

namespace WikiOasis\WikiOasisMagic\Experiments;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MainConfigNames;
use MediaWiki\Request\WebRequest;
use MediaWiki\User\UserIdentity;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use function array_filter;
use function ctype_digit;
use function explode;
use function hash;
use function hash_equals;
use function hash_hmac;
use function hexdec;
use function is_array;
use function is_string;
use function preg_match;
use function str_contains;
use function substr;
use function trim;

class ExperimentManager {

	public const CONSTRUCTOR_OPTIONS = [
		ConfigNames::EXPERIMENTS,
		ConfigNames::HASH_SECRET,
		MainConfigNames::SecretKey,
	];

	public const OVERRIDE_PARAM = 'woexperiment';

	public const OVERRIDE_TOKEN_PARAM = 'woexperimenttoken';

	private const OVERRIDE_TTL = 2 * 86400;

	private const OVERRIDE_SESSION_KEY = 'wikioasismagic-experiment-overrides';

	/** @var array<string,Experiment>|null */
	private ?array $experiments = null;

	public function __construct(
		private readonly ServiceOptions $options,
		private readonly ExperimentStateStore $stateStore,
		private readonly WikiFarm $farm,
		private readonly UnitFactory $unitFactory,
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	/**
	 * @return array<string,Experiment>
	 */
	public function getExperiments(): array {
		if ( $this->experiments !== null ) {
			return $this->experiments;
		}

		$this->experiments = [];
		foreach ( (array)$this->options->get( ConfigNames::EXPERIMENTS ) as $name => $spec ) {
			if ( !is_string( $name ) || !preg_match( Experiment::NAME_PATTERN, $name ) || !is_array( $spec ) ) {
				continue;
			}

			$experiment = Experiment::newFromArray( $name, $spec );
			$state = $this->stateStore->getState( $name );
			$this->experiments[$name] = $state ? $experiment->withState( $state ) : $experiment;
		}

		return $this->experiments;
	}

	/**
	 * @param string $unit
	 * @return array<string,Experiment>
	 */
	public function getExperimentsByUnit( string $unit ): array {
		return array_filter( $this->getExperiments(), static fn ( Experiment $e ) => $e->unit === $unit );
	}

	public function getExperiment( string $name ): ?Experiment {
		return $this->getExperiments()[$name] ?? null;
	}

	public function getConfiguredExperiment( string $name ): ?Experiment {
		$spec = $this->options->get( ConfigNames::EXPERIMENTS )[$name] ?? null;
		return preg_match( Experiment::NAME_PATTERN, $name ) && is_array( $spec ) ?
			Experiment::newFromArray( $name, $spec ) :
			null;
	}

	public function clearCache(): void {
		$this->experiments = null;
	}

	public function isRunning( Experiment $experiment, ?ExperimentUnit $unit = null ): bool {
		if ( !$experiment->isWithinWindow( ConvertibleTimestamp::now( TS_MW ) ) ) {
			return false;
		}

		$wiki = $unit && $unit->type === ExperimentUnit::WIKI ? $unit->id : $this->farm->getCurrentWiki();
		if ( !$experiment->appliesToWiki( $wiki, $this->farm->isCentralWiki( $wiki ) ) ) {
			return false;
		}

		return !$unit || $experiment->matchesPopulation( $unit->getCreated() );
	}

	/**
	 * @param string $name
	 * @param ExperimentUnit $unit
	 * @param WebRequest|null $request
	 */
	public function assign( string $name, ExperimentUnit $unit, ?WebRequest $request = null ): Assignment {
		$experiment = $this->getExperiment( $name );

		if ( !$experiment ) {
			return new Assignment( $name, 'default', false, false, [ 'legacy' => false ] );
		}

		$forced = $request ? $this->getOverride( $request, $experiment ) : null;
		if ( $forced !== null ) {
			return new Assignment( $name, $forced, true, true, $experiment->getParams( $forced ), $unit );
		}

		if ( $unit->type !== $experiment->unit || !$unit->exists() || !$this->isRunning( $experiment, $unit ) ) {
			return $this->defaultAssignment( $experiment, $unit );
		}

		if ( self::bucket( $experiment->salt . ':enrol', $unit->id ) >= $experiment->rollout ) {
			return $this->defaultAssignment( $experiment, $unit );
		}

		$variant = $experiment->pickVariant( self::bucket( $experiment->salt . ':variant', $unit->id ) );
		return new Assignment( $name, $variant, true, false, $experiment->getParams( $variant ), $unit );
	}

	public function assignUser( string $name, UserIdentity $user, ?WebRequest $request = null ): Assignment {
		return $this->assign( $name, $this->unitFactory->forUser( $user ), $request );
	}

	public function assignBrowser( string $name, WebRequest $request, bool $create = true ): Assignment {
		return $this->assign( $name, $this->unitFactory->forBrowser( $request, $create ), $request );
	}

	public function assignWiki( string $name, ?string $dbname = null, ?WebRequest $request = null ): Assignment {
		return $this->assign( $name, $this->unitFactory->forWiki( $dbname ), $request );
	}

	public static function bucket( string $key, string $unit ): float {
		$hash = hash( 'sha256', $key . ':' . $unit );
		return hexdec( substr( $hash, 0, 13 ) ) / 0x10000000000000 * 100;
	}

	/**
	 * @return array<string,array{enrolled:int,default:int}>
	 */
	public function simulate( Experiment $experiment, int $samples ): array {
		$counts = [];
		foreach ( $experiment->getVariantNames() as $variant ) {
			$counts[$variant] = [ 'enrolled' => 0, 'default' => 0 ];
		}

		for ( $i = 0; $i < $samples; $i++ ) {
			$unit = 'simulated-' . $i;
			if ( self::bucket( $experiment->salt . ':enrol', $unit ) >= $experiment->rollout ) {
				$counts[$experiment->defaultVariant]['default']++;
				continue;
			}

			$counts[$experiment->pickVariant( self::bucket( $experiment->salt . ':variant', $unit ) )]['enrolled']++;
		}

		return $counts;
	}

	private function getOverride( WebRequest $request, Experiment $experiment ): ?string {
		$session = $request->getSession();
		$param = $request->getRawVal( self::OVERRIDE_PARAM );

		if ( $param !== null && $param !== 'reset' &&
			!$this->isValidOverrideToken( $param, $request->getRawVal( self::OVERRIDE_TOKEN_PARAM ) )
		) {
			$param = null;
		}

		if ( $param !== null ) {
			$overrides = [];
			if ( $param !== 'reset' ) {
				foreach ( explode( ',', $param ) as $pair ) {
					if ( !str_contains( $pair, ':' ) ) {
						continue;
					}
					[ $experimentName, $variant ] = explode( ':', $pair, 2 );
					$overrides[trim( $experimentName )] = trim( $variant );
				}
			}

			if ( $overrides || $session->get( self::OVERRIDE_SESSION_KEY ) !== null ) {
				$session->set( self::OVERRIDE_SESSION_KEY, $overrides ?: null );
			}
		} else {
			$overrides = $session->get( self::OVERRIDE_SESSION_KEY ) ?? [];
		}

		$variant = is_array( $overrides ) ? ( $overrides[$experiment->name] ?? null ) : null;
		return is_string( $variant ) && $experiment->hasVariant( $variant ) ? $variant : null;
	}

	/**
	 * @return array<string,string>
	 */
	public function makeOverrideQuery( string $overrides ): array {
		$expiry = (int)ConvertibleTimestamp::now( TS_UNIX ) + self::OVERRIDE_TTL;
		return [
			self::OVERRIDE_PARAM => $overrides,
			self::OVERRIDE_TOKEN_PARAM => $expiry . '.' . $this->signOverride( $overrides, $expiry ),
		];
	}

	private function isValidOverrideToken( string $overrides, ?string $token ): bool {
		if ( $token === null || !str_contains( $token, '.' ) ) {
			return false;
		}

		[ $expiry, $signature ] = explode( '.', $token, 2 );
		return ctype_digit( $expiry ) &&
			(int)$expiry >= (int)ConvertibleTimestamp::now( TS_UNIX ) &&
			hash_equals( $this->signOverride( $overrides, (int)$expiry ), $signature );
	}

	private function signOverride( string $overrides, int $expiry ): string {
		$secret = (string)( $this->options->get( ConfigNames::HASH_SECRET ) ?:
			$this->options->get( MainConfigNames::SecretKey ) );
		return substr( hash_hmac( 'sha256', "woexperiment:$overrides:$expiry", $secret ), 0, 32 );
	}

	/**
	 * @return array<string,string>
	 */
	public function getActiveOverrides( WebRequest $request ): array {
		$active = [];
		foreach ( $this->getExperiments() as $experiment ) {
			$variant = $this->getOverride( $request, $experiment );
			if ( $variant !== null ) {
				$active[$experiment->name] = $variant;
			}
		}
		return $active;
	}

	private function defaultAssignment( Experiment $experiment, ExperimentUnit $unit ): Assignment {
		return new Assignment(
			$experiment->name,
			$experiment->defaultVariant,
			false,
			false,
			$experiment->getParams( $experiment->defaultVariant ),
			$unit
		);
	}
}
