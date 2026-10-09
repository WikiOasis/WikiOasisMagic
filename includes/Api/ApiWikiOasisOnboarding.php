<?php

namespace WikiOasis\WikiOasisMagic\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\Json\FormatJson;
use MediaWiki\MediaWikiServices;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;
use WikiOasis\WikiOasisMagic\Experiments\Assignment;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentAdmin;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentManager;
use WikiOasis\WikiOasisMagic\Onboarding\OnboardingEnvironment;
use WikiOasis\WikiOasisMagic\Onboarding\OnboardingMetrics;
use WikiOasis\WikiOasisMagic\Onboarding\SurveyLoader;
use WikiOasis\WikiOasisMagic\Onboarding\SurveyResponses;
use WikiOasis\WikiOasisMagic\Onboarding\WikiRequestSubmitter;
use function in_array;
use function is_array;
use function max;
use function min;

class ApiWikiOasisOnboarding extends ApiBase {

	private const STEPS = [
		'survey', 'wiki-name', 'wiki-purpose', 'wiki-settings', 'wiki-review', 'wiki-blocked', 'done', 'finish',
	];

	private const EVENTS = [ 'stepview', 'stepduration', 'cardclick', 'complete' ];

	private const PATHS = [ 'wiki_request', 'finish' ];

	private const MAX_STEP_SECONDS = 3600;

	public function __construct(
		ApiMain $main,
		string $moduleName,
		private readonly OnboardingEnvironment $environment,
		private readonly ExperimentManager $experimentManager,
		private readonly OnboardingMetrics $metrics,
		private readonly SurveyLoader $surveyLoader,
		private readonly SurveyResponses $responses,
	) {
		parent::__construct( $main, $moduleName );
	}

	public function execute(): void {
		$user = $this->getUser();
		if ( !$user->isNamed() ) {
			$this->dieWithError( 'apierror-mustbeloggedin-generic', 'notloggedin' );
		}

		if ( !$this->environment->isEnabled() && !$this->getAuthority()->isAllowed( ExperimentAdmin::RIGHT ) ) {
			$this->dieWithError( 'wikioasismagic-onboarding-api-disabled', 'disabled' );
		}

		$params = $this->extractRequestParams();
		$assignment = $this->experimentManager->assignUser(
			OnboardingEnvironment::ONBOARDING_EXPERIMENT,
			$user,
			$this->getRequest()
		);
		$context = $this->environment->getContext();

		$result = match ( $params['do'] ) {
			'submit' => $this->submit( $params, $assignment, $context ),
			'event' => $this->event( $params, $assignment, $context ),
			'checksubdomain' => $this->checkSubdomain( $params ),
			'requestwiki' => $this->requestWiki( $params, $assignment, $context ),
		};

		$this->getResult()->addValue( null, $this->getModuleName(), $result );
	}

	private function submit( array $params, Assignment $assignment, string $context ): array {
		if ( $this->getUser()->pingLimiter( 'wikioasisonboarding-submit' ) ) {
			$this->dieWithError( 'apierror-ratelimited', 'ratelimited' );
		}

		$loaded = $this->surveyLoader->getSurvey( $assignment );
		$clean = $this->responses->clean(
			$loaded['survey'],
			$this->decode( $params['answers'], 'answers' ),
			$this->decode( $params['freetext'], 'freetext' )
		);

		$this->responses->record(
			$this->getUser(),
			$assignment,
			$context,
			$loaded['name'],
			$clean,
			$params['skipped']
		);

		return [ 'result' => 'success', 'answers' => $clean['answers'] ];
	}

	private function event( array $params, Assignment $assignment, string $context ): array {
		if ( $this->getUser()->pingLimiter( 'wikioasisonboarding-event' ) ) {
			return [ 'result' => 'throttled' ];
		}

		$step = $params['step'] ?? null;
		switch ( $params['event'] ) {
			case 'stepview':
				$this->requireStep( $step );
				$this->metrics->stepView( $assignment, $context, $step );
				break;

			case 'stepduration':
				$this->requireStep( $step );
				$seconds = (int)( $params['ms'] ?? 0 ) / 1000;
				$this->metrics->stepDuration(
					$assignment,
					$context,
					$step,
					max( 0.0, min( (float)self::MAX_STEP_SECONDS, $seconds ) )
				);
				break;

			case 'cardclick':
				$card = $params['card'] ?? '';
				$survey = $this->surveyLoader->getSurvey( $assignment )['survey'];
				if ( !isset( $survey['cards'][$card] ) ) {
					$this->dieWithError( [ 'apierror-badparameter', 'card' ], 'badcard' );
				}
				$this->metrics->cardClick( $assignment, $context, $card );
				break;

			case 'complete':
				$path = $params['path'] ?? '';
				if ( !in_array( $path, self::PATHS, true ) ) {
					$this->dieWithError( [ 'apierror-badparameter', 'path' ], 'badpath' );
				}
				$this->metrics->completion( $assignment, $context, $path );
				break;
		}

		return [ 'result' => 'success' ];
	}

	private function checkSubdomain( array $params ): array {
		if ( $this->getUser()->pingLimiter( 'wikioasisonboarding-event' ) ) {
			$this->dieWithError( 'apierror-ratelimited', 'ratelimited' );
		}

		$submitter = $this->getSubmitter();
		$problem = $submitter->checkSubdomain( (string)( $params['subdomain'] ?? '' ) );

		return $problem === null ?
			[ 'result' => 'available' ] :
			[ 'result' => 'unavailable', 'message' => $this->msg( $problem )->parse() ];
	}

	private function requestWiki( array $params, Assignment $assignment, string $context ): array {
		$submitter = $this->getSubmitter();
		$input = $this->decode( $params['request'], 'request' );

		$outcome = $submitter->submit( $this->getUser(), $input, $this );
		if ( !$outcome['ok'] ) {
			$errors = [];
			foreach ( $outcome['errors'] as $field => $message ) {
				$errors[$field === '' ? 'general' : $field] = $this->msg( $message )->parse();
			}

			$this->metrics->wikiRequest( $assignment, 'onboarding', 'failed' );
			if ( isset( $errors['general'] ) ) {
				$this->metrics->error( 'wiki_request_failed' );
			}

			return [ 'result' => 'failure', 'errors' => $errors ];
		}

		$id = (int)( $outcome['id'] ?? 0 );
		$this->responses->addRequest( $this->getUser(), $id );
		$this->metrics->completion( $assignment, $context, 'wiki_request' );

		return [ 'result' => 'success', 'id' => $id, 'url' => (string)( $outcome['url'] ?? '' ) ];
	}

	private function getSubmitter(): WikiRequestSubmitter {
		if ( !$this->environment->canRequestWikis() ) {
			$this->dieWithError( 'wikioasismagic-onboarding-api-notcentral', 'notcentral' );
		}

		return MediaWikiServices::getInstance()->get( 'WikiOasisMagic.OnboardingWikiRequestSubmitter' );
	}

	private function requireStep( ?string $step ): void {
		if ( !in_array( $step, self::STEPS, true ) ) {
			$this->dieWithError( [ 'apierror-badparameter', 'step' ], 'badstep' );
		}
	}

	/**
	 * @return array
	 */
	private function decode( ?string $json, string $param ): array {
		if ( $json === null || $json === '' ) {
			return [];
		}

		$decoded = FormatJson::decode( $json, true );
		if ( !is_array( $decoded ) ) {
			$this->dieWithError( [ 'apierror-badparameter', $param ], 'badjson' );
		}

		return $decoded;
	}

	public function getAllowedParams(): array {
		return [
			'do' => [
				ParamValidator::PARAM_TYPE => [ 'submit', 'event', 'checksubdomain', 'requestwiki' ],
				ParamValidator::PARAM_REQUIRED => true,
			],
			'answers' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'freetext' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'skipped' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false,
			],
			'event' => [
				ParamValidator::PARAM_TYPE => self::EVENTS,
			],
			'step' => [
				ParamValidator::PARAM_TYPE => self::STEPS,
			],
			'ms' => [
				ParamValidator::PARAM_TYPE => 'integer',
				IntegerDef::PARAM_MIN => 0,
				IntegerDef::PARAM_MAX => self::MAX_STEP_SECONDS * 1000,
				ApiBase::PARAM_RANGE_ENFORCE => false,
			],
			'card' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'path' => [
				ParamValidator::PARAM_TYPE => self::PATHS,
			],
			'subdomain' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'request' => [
				ParamValidator::PARAM_TYPE => 'string',
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
