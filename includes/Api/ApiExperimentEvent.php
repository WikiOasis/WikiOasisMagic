<?php

namespace WikiOasis\WikiOasisMagic\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use Wikimedia\ParamValidator\ParamValidator;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentManager;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentTracker;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentUnit;
use WikiOasis\WikiOasisMagic\Experiments\UnitFactory;

class ApiExperimentEvent extends ApiBase {

	public const RATE_LIMIT = 'wikioasisexperiment-event';

	public function __construct(
		ApiMain $main,
		string $moduleName,
		private readonly ExperimentManager $manager,
		private readonly ExperimentTracker $tracker,
		private readonly UnitFactory $unitFactory,
	) {
		parent::__construct( $main, $moduleName );
	}

	public function execute(): void {
		$params = $this->extractRequestParams();

		$experiment = $this->manager->getExperiment( $params['experiment'] );
		$metric = $experiment?->getMetric( $params['metric'] );
		if ( !$experiment || !$metric || !$metric->isClientEvent() ) {
			$this->dieWithError( 'wikioasismagic-experiments-error-badevent', 'badevent' );
		}

		if ( $this->getUser()->pingLimiter( self::RATE_LIMIT ) ) {
			$this->dieWithError( 'apierror-ratelimited', 'ratelimited' );
		}

		$request = $this->getRequest();
		$unit = match ( $experiment->unit ) {
			ExperimentUnit::USER => $this->unitFactory->forUser( $this->getUser() ),
			ExperimentUnit::BROWSER => $this->unitFactory->forBrowser( $request, false ),
			default => $this->unitFactory->forWiki(),
		};

		$this->tracker->recordEvent( $this->manager->assign( $experiment->name, $unit, $request ), $metric->name );
		$this->getResult()->addValue( null, $this->getModuleName(), [ 'result' => 'success' ] );
	}

	public function getAllowedParams(): array {
		return [
			'experiment' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'metric' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	public function needsToken(): string {
		return 'csrf';
	}

	public function mustBePosted(): bool {
		return true;
	}

	public function isWriteMode(): bool {
		return true;
	}

	public function isInternal(): bool {
		return true;
	}
}
