<?php

namespace WikiOasis\WikiOasisMagic\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use Wikimedia\ParamValidator\ParamValidator;
use WikiOasis\WikiOasisMagic\FandomImport\FandomImportManager;
use WikiOasis\WikiOasisMagic\FandomImport\FandomImportPresenter;
use WikiOasis\WikiOasisMagic\FandomImport\FandomImportRequest;
use WikiOasis\WikiOasisMagic\FandomImport\FandomImportStore;
use WikiOasis\WikiOasisMagic\FandomImport\FandomSource;

/**
 * Reads for Special:FandomImport's app: an import's status, a look at a
 * Fandom wiki before requesting it, whether an address is free, and
 * whether the requester is an admin on Fandom.
 */
class ApiFandomImport extends ApiBase {

	public const RATE_LIMIT = 'wikioasisfandomimport-lookup';

	public function __construct(
		ApiMain $main,
		string $moduleName,
		private readonly FandomImportManager $manager,
		private readonly FandomImportStore $store,
		private readonly FandomImportPresenter $presenter,
	) {
		parent::__construct( $main, $moduleName );
	}

	public function execute(): void {
		$unavailable = $this->manager->getUnavailableReason();
		if ( $unavailable !== null ) {
			$this->dieWithError( $unavailable, 'unavailable' );
		}

		$params = $this->extractRequestParams();
		$result = match ( $params['prop'] ) {
			'detail' => [ 'detail' => $this->presenter->detail( $this->getImport( $params ), $this->getContext() ) ],
			'lookup' => $this->lookup( $params ),
			'subdomain' => $this->checkSubdomain( $params ),
			'account' => [ 'account' => $this->checkAccount( $params ) ],
		};

		$this->getResult()->addValue( null, $this->getModuleName(), $result );
	}

	private function lookup( array $params ): array {
		$this->requireAtLeastOneParameter( $params, 'source' );
		$source = FandomSource::newFromSubpage( (string)$params['source'] ) ??
			FandomSource::newFromInput( (string)$params['source'] );
		if ( !$source ) {
			$this->dieWithError( 'wikioasismagic-fandomimport-error-badinput', 'badsource' );
		}

		$existing = $this->store->getLatestForSource( $source );
		if ( $existing ) {
			return [
				'source' => $this->presenter->source( $source ),
				'existing' => $this->presenter->summary( $existing, $this->getContext() ),
			];
		}

		$this->limit();
		return $this->presenter->preview( $source, $this->getContext() ) + [ 'existing' => null ];
	}

	private function checkSubdomain( array $params ): array {
		$this->requireAtLeastOneParameter( $params, 'subdomain' );
		$subdomain = (string)$params['subdomain'];
		$error = $this->manager->checkSubdomain( $subdomain );

		return [
			'valid' => $error === null,
			'error' => $error ? $this->msg( $error )->text() : null,
			'dbname' => $this->manager->getDbname( $subdomain ),
		];
	}

	private function checkAccount( array $params ): array {
		$request = $this->getImport( $params );
		$this->limit();
		return $this->presenter->account( $request );
	}

	private function getImport( array $params ): FandomImportRequest {
		$this->requireAtLeastOneParameter( $params, 'id' );
		$request = $this->store->get( (int)$params['id'] );
		if ( !$request ) {
			$this->dieWithError( [ 'wikioasismagic-fandomimport-error-noimport', (int)$params['id'] ], 'noimport' );
		}
		return $request;
	}

	/**
	 * Lookups make requests to Fandom, so nobody gets to make many.
	 */
	private function limit(): void {
		if ( $this->getUser()->pingLimiter( self::RATE_LIMIT ) ) {
			$this->dieWithError( 'apierror-ratelimited' );
		}
	}

	public function getAllowedParams(): array {
		return [
			'prop' => [
				ParamValidator::PARAM_TYPE => [ 'detail', 'lookup', 'subdomain', 'account' ],
				ParamValidator::PARAM_DEFAULT => 'detail',
			],
			'id' => [
				ParamValidator::PARAM_TYPE => 'integer',
			],
			'source' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'subdomain' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
		];
	}

	protected function getExamplesMessages(): array {
		return [
			'action=wikioasisfandomimport&prop=detail&id=1' => 'apihelp-wikioasisfandomimport-example-detail',
			'action=wikioasisfandomimport&prop=lookup&source=muppetwiki' => 'apihelp-wikioasisfandomimport-example-lookup',
		];
	}

	public function isInternal(): bool {
		return true;
	}
}
