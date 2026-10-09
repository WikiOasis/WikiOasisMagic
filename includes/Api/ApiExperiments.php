<?php

namespace WikiOasis\WikiOasisMagic\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiResult;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentAdmin;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentManager;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentPresenter;
use function in_array;
use function wfEscapeWikiText;

class ApiExperiments extends ApiBase {

	public function __construct(
		ApiMain $main,
		string $moduleName,
		private readonly ExperimentManager $manager,
		private readonly ExperimentPresenter $presenter,
	) {
		parent::__construct( $main, $moduleName );
	}

	public function execute(): void {
		$this->checkUserRightsAny( ExperimentAdmin::RIGHT );

		$params = $this->extractRequestParams();
		$result = $this->getResult();

		if ( $params['experiment'] === null ) {
			$list = [];
			foreach ( $this->manager->getExperiments() as $experiment ) {
				$list[] = $this->presenter->summary( $experiment, $this->getContext() );
			}
			$result->addValue( null, $this->getModuleName(), [ 'experiments' => $list ] );
			return;
		}

		$experiment = $this->manager->getExperiment( $params['experiment'] );
		if ( !$experiment ) {
			$this->dieWithError(
				[ 'wikioasismagic-experiments-error-unknown', wfEscapeWikiText( $params['experiment'] ) ],
				'unknownexperiment'
			);
		}

		$data = [];
		if ( in_array( 'detail', $params['prop'], true ) ) {
			$data['detail'] = $this->presenter->detail( $experiment, $this->getContext() );
		}

		if ( in_array( 'results', $params['prop'], true ) ) {
			$since = $params['days'] > 0 ?
				ConvertibleTimestamp::convert( TS_MW, (int)ConvertibleTimestamp::now( TS_UNIX ) - $params['days'] * 86400 ) :
				null;
			$data['results'] = $this->presenter->results( $experiment, $since ?: null );
		}

		$result->addValue( null, $this->getModuleName(), $data, ApiResult::NO_SIZE_CHECK );
	}

	public function getAllowedParams(): array {
		return [
			'experiment' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'prop' => [
				ParamValidator::PARAM_TYPE => [ 'detail', 'results' ],
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_DEFAULT => 'detail',
			],
			'days' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_DEFAULT => 0,
				IntegerDef::PARAM_MIN => 0,
				IntegerDef::PARAM_MAX => 3650,
			],
		];
	}

	public function isInternal(): bool {
		return true;
	}
}
