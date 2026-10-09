<?php

namespace WikiOasis\WikiOasisMagic\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\Json\FormatJson;
use Wikimedia\ParamValidator\ParamValidator;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentAdmin;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentManager;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentPresenter;
use WikiOasis\WikiOasisMagic\Experiments\WikiFarm;
use function is_array;

class ApiExperimentSave extends ApiBase {

	public function __construct(
		ApiMain $main,
		string $moduleName,
		private readonly ExperimentManager $manager,
		private readonly ExperimentAdmin $admin,
		private readonly ExperimentPresenter $presenter,
		private readonly WikiFarm $farm,
	) {
		parent::__construct( $main, $moduleName );
	}

	public function execute(): void {
		$this->checkUserRightsAny( ExperimentAdmin::RIGHT );

		if ( !$this->farm->isCentralWiki() ) {
			$this->dieWithError( 'wikioasismagic-experiments-error-notcentral', 'notcentral' );
		}

		$params = $this->extractRequestParams();
		$this->requireOnlyOneParameter( $params, 'state', 'reset' );

		if ( !$this->manager->getExperiment( $params['experiment'] ) ) {
			$this->dieWithError(
				[ 'wikioasismagic-experiments-error-unknown', wfEscapeWikiText( $params['experiment'] ) ],
				'unknownexperiment'
			);
		}

		if ( $params['reset'] ) {
			$status = $this->admin->reset(
				$params['experiment'],
				$params['reason'],
				$this->getUser(),
				$params['baseversion']
			);
		} else {
			$state = FormatJson::decode( $params['state'], true );
			if ( !is_array( $state ) ) {
				$this->dieWithError( [ 'apierror-badparameter', 'state' ], 'badstate' );
			}

			$status = $this->admin->update(
				$params['experiment'],
				$state,
				$params['reason'],
				$this->getUser(),
				$params['baseversion']
			);
		}

		if ( !$status->isOK() ) {
			$this->dieStatus( $status );
		}

		$value = $status->getValue();
		$this->getResult()->addValue( null, $this->getModuleName(), [
			'result' => $value['changes'] ? 'success' : 'nochange',
			'changes' => $value['changes'],
			'detail' => $this->presenter->detail( $value['experiment'], $this->getContext() ),
		] );
	}

	public function getAllowedParams(): array {
		return [
			'experiment' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'state' => [
				ParamValidator::PARAM_TYPE => 'text',
			],
			'reset' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false,
			],
			'reason' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => '',
			],
			'baseversion' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => '',
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
