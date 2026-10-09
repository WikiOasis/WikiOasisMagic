<?php

namespace WikiOasis\WikiOasisMagic\Experiments;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Context\IContextSource;
use MediaWiki\Languages\LanguageFallback;
use MediaWiki\MainConfigNames;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use Throwable;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use WikiOasis\WikiOasisMagic\Onboarding\SurveyText;
use function array_keys;
use function array_map;
use function array_merge;
use function array_values;
use function count;
use function is_bool;
use function is_string;
use function str_replace;
use function urlencode;

class ExperimentPresenter {

	public const CONSTRUCTOR_OPTIONS = [
		ConfigNames::DASHBOARD_URL,
		MainConfigNames::Sitename,
	];

	private const TOP_UNITS = 15;

	public function __construct(
		private readonly ServiceOptions $options,
		private readonly ExperimentManager $manager,
		private readonly ExperimentStateStore $stateStore,
		private readonly ExperimentDataStore $dataStore,
		private readonly WikiFarm $farm,
		private readonly WikiRollout $rollout,
		private readonly UnitFactory $unitFactory,
		private readonly LanguageFallback $languageFallback,
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	public function summary( Experiment $experiment, IContextSource $context ): array {
		$text = $this->newText( $context );
		$now = ConvertibleTimestamp::now( TS_MW );

		$variants = [];
		foreach ( $experiment->variants as $name => $variant ) {
			$variants[] = [
				'name' => $name,
				'weight' => $variant['weight'],
				'extensions' => array_values( array_map(
					static fn ( $info ) => $info['name'],
					$this->rollout->getExtensions( $experiment, $name )
				) ),
				'config' => array_map( 'strval', array_keys( $variant['config'] ) ),
			];
		}

		$exposed = null;
		if ( $this->dataStore->isAvailable() ) {
			try {
				$exposed = 0;
				foreach ( $this->dataStore->getUnitCounts( $experiment->name ) as $group ) {
					$exposed += $group['eligible'] ? $group['units'] : 0;
				}
			} catch ( Throwable ) {
				$exposed = null;
			}
		}

		return [
			'name' => $experiment->name,
			'label' => $text->get( $experiment->info['label'] ?? null ) ?: $experiment->name,
			'description' => $text->get( $experiment->info['description'] ?? null ),
			'owner' => is_string( $experiment->info['owner'] ?? null ) ? $experiment->info['owner'] : null,
			'unit' => $experiment->unit,
			'status' => $experiment->getStatus( $now ),
			'rollout' => $experiment->rollout,
			'default' => $experiment->defaultVariant,
			'population' => $experiment->population,
			'variants' => $variants,
			'problems' => count( $this->getProblems( $experiment ) ),
			'overridden' => $this->stateStore->getEntry( $experiment->name ) !== null,
			'exposed' => $exposed,
			'url' => SpecialPage::getTitleFor( 'Experiments', $experiment->name )->getLocalURL(),
		];
	}

	public function detail( Experiment $experiment, IContextSource $context ): array {
		$text = $this->newText( $context );
		$configured = $this->manager->getConfiguredExperiment( $experiment->name ) ?? $experiment;
		$entry = $this->stateStore->getEntry( $experiment->name );

		$variants = [];
		foreach ( $experiment->variants as $name => $variant ) {
			$extensions = [];
			foreach ( $this->rollout->getExtensions( $experiment, $name ) as $key => $info ) {
				$extensions[] = [ 'key' => $key, 'name' => $info['name'] ];
			}

			$legacy = $variant['params']['legacy'] ?? null;
			$variants[] = [
				'name' => $name,
				'weight' => $variant['weight'],
				'extensions' => $extensions,
				'config' => $variant['config'],
				'params' => $variant['params'],
				'legacy' => is_bool( $legacy ) ? $legacy : $name === Assignment::CONTROL,
				'preview' => $this->getPreviewUrl( $experiment, $name ),
			];
		}

		$metrics = [];
		foreach ( $experiment->metrics as $metric ) {
			$metrics[] = [
				'name' => $metric->name,
				'label' => $text->get( $metric->label ) ?: $metric->name,
				'description' => $text->get( $metric->description ),
				'type' => $metric->type,
				'filter' => $metric->filter,
			];
		}

		return array_merge( $this->summary( $experiment, $context ), [
			'task' => is_string( $experiment->info['task'] ?? null ) ? $experiment->info['task'] : null,
			'state' => $this->exportState( $experiment->getState() ),
			'configured' => $this->exportState( $configured->getState() ),
			'overrides' => Experiment::diffState( $configured->getState(), $experiment->getState() ),
			'lastChange' => $entry ? [
				'user' => $entry['user'],
				'timestamp' => $entry['timestamp'],
				'version' => $entry['version'] ?? '',
				'iso' => ConvertibleTimestamp::convert( TS_ISO_8601, $entry['timestamp'] ),
			] : null,
			'variants' => $variants,
			'metrics' => $metrics,
			'primary' => $experiment->primaryMetric,
			'window' => $experiment->window,
			'salt' => $experiment->salt,
			'changesWikiConfig' => $experiment->changesWikiConfig(),
			'extensionWikis' => $experiment->getAllExtensions() ?
				$this->dataStore->countOwnedWikis( $experiment->name ) :
				null,
			'problems' => $this->getProblems( $experiment ),
			'mine' => $this->getOwnAssignment( $experiment, $context ),
			'unitTotal' => $experiment->unit === ExperimentUnit::WIKI ? $this->farm->countWikis( $experiment ) : null,
			'dashboardUrl' => $this->getDashboardUrl( $experiment ),
			'logUrl' => SpecialPage::getTitleFor( 'Log', 'experiment' )->getLocalURL( [
				'page' => SpecialPage::getTitleFor( 'Experiments', $experiment->name )->getPrefixedText(),
			] ),
		] );
	}

	/**
	 * @param Experiment $experiment
	 * @param string|null $since
	 */
	public function results( Experiment $experiment, ?string $since ): array {
		if ( !$this->dataStore->isAvailable() ) {
			return [ 'available' => false ];
		}

		$series = [];
		$top = [];
		foreach ( $experiment->metrics as $metric ) {
			$series[$metric->name] = [
				'adopters' => $this->dataStore->getAdopterSeries( $experiment->name, $metric->name, $since ),
				'events' => $this->dataStore->getDailyEvents( $experiment->name, $metric->name, $since ),
			];

			if ( $experiment->unit === ExperimentUnit::WIKI ) {
				$top[$metric->name] = array_map( fn ( $row ) => [
					'unit' => $row['unit'],
					'url' => $this->farm->getWikiUrl( $row['unit'] ),
					'variant' => $row['variant'],
					'enrolled' => $row['enrolled'],
					'count' => $row['count'],
					'first' => ConvertibleTimestamp::convert( TS_ISO_8601, $row['first'] ),
					'last' => ConvertibleTimestamp::convert( TS_ISO_8601, $row['last'] ),
				], $this->dataStore->getTopUnits( $experiment->name, $metric->name, self::TOP_UNITS, $since ) );
			}
		}

		return [
			'available' => true,
			'since' => $since === null ? null : ConvertibleTimestamp::convert( TS_ISO_8601, $since ),
			'groups' => $this->dataStore->getUnitCounts( $experiment->name, $since ),
			'adoption' => $this->dataStore->getAdoption( $experiment->name, $since ),
			'exposures' => $this->dataStore->getExposureSeries( $experiment->name, $since ),
			'series' => $series,
			'top' => $top,
		];
	}

	/** @return string[] */
	public function getProblems( Experiment $experiment ): array {
		return array_merge( $experiment->getProblems(), $this->rollout->getProblems( $experiment ) );
	}

	private function exportState( array $state ): array {
		foreach ( [ 'start', 'end' ] as $field ) {
			$state[$field] = $state[$field] === null ? null :
				ConvertibleTimestamp::convert( TS_ISO_8601, $state[$field] );
		}
		return $state;
	}

	private function getOwnAssignment( Experiment $experiment, IContextSource $context ): ?array {
		$request = $context->getRequest();
		$unit = match ( $experiment->unit ) {
			ExperimentUnit::WIKI => $this->unitFactory->forWiki(),
			ExperimentUnit::USER => $this->unitFactory->forUser( $context->getUser() ),
			ExperimentUnit::BROWSER => $this->unitFactory->forBrowser( $request, false ),
		};

		if ( !$unit->exists() ) {
			return null;
		}

		$assignment = $this->manager->assign( $experiment->name, $unit, $request );
		return [
			'unit' => $experiment->unit === ExperimentUnit::WIKI ? $unit->id : null,
			'variant' => $assignment->variant,
			'enrolled' => $assignment->enrolled,
			'forced' => $assignment->forced,
		];
	}

	private function getPreviewUrl( Experiment $experiment, string $variant ): ?string {
		if ( $experiment->unit === ExperimentUnit::WIKI ) {
			return null;
		}

		$page = $experiment->info['preview'] ?? null;
		$title = is_string( $page ) ? Title::newFromText( $page ) : null;
		$title ??= SpecialPage::getTitleFor( 'Experiments', $experiment->name );

		return $title->getLocalURL( $this->manager->makeOverrideQuery( "{$experiment->name}:$variant" ) );
	}

	private function getDashboardUrl( Experiment $experiment ): ?string {
		$url = $this->options->get( ConfigNames::DASHBOARD_URL );
		return $url ? str_replace( '{experiment}', urlencode( $experiment->name ), $url ) : null;
	}

	private function newText( IContextSource $context ): SurveyText {
		$code = $context->getLanguage()->getCode();
		return new SurveyText(
			$context,
			[ $code, ...$this->languageFallback->getAll( $code ) ],
			$this->options->get( MainConfigNames::Sitename )
		);
	}
}
