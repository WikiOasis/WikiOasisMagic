<?php

namespace WikiOasis\WikiOasisMagic\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\Title\Title;
use Wikimedia\ParamValidator\ParamValidator;
use WikiOasis\WikiOasisMagic\EditPrompt\EditPromptGate;

class ApiEditPrompt extends ApiBase {

	public const RATE_LIMIT = 'wikioasisexperiment-assign';

	public function __construct(
		ApiMain $main,
		string $moduleName,
		private readonly EditPromptGate $gate,
	) {
		parent::__construct( $main, $moduleName );
	}

	public function execute(): void {
		$params = $this->extractRequestParams();

		$title = Title::newFromText( $params['title'] );
		if ( !$title ) {
			$this->dieWithError( [ 'apierror-invalidtitle', wfEscapeWikiText( $params['title'] ) ] );
		}

		if ( $this->getUser()->pingLimiter( self::RATE_LIMIT ) ) {
			$this->dieWithError( 'apierror-ratelimited', 'ratelimited' );
		}

		$this->getResult()->addValue(
			null,
			$this->getModuleName(),
			$this->gate->assign( $this->getUser(), $title, $this->getRequest() )
		);
	}

	public function getAllowedParams(): array {
		return [
			'title' => [
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
