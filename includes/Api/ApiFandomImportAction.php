<?php

namespace WikiOasis\WikiOasisMagic\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\SpecialPage\SpecialPage;
use StatusValue;
use Wikimedia\ParamValidator\ParamValidator;
use WikiOasis\WikiOasisMagic\FandomImport\FandomClient;
use WikiOasis\WikiOasisMagic\FandomImport\FandomImportManager;
use WikiOasis\WikiOasisMagic\FandomImport\FandomImportPresenter;
use WikiOasis\WikiOasisMagic\FandomImport\FandomImportRequest;
use WikiOasis\WikiOasisMagic\FandomImport\FandomImportStore;
use WikiOasis\WikiOasisMagic\FandomImport\FandomSource;

/**
 * Changes from Special:FandomImport's app: request an import, or approve,
 * decline, retry or clean up one. The checks themselves are the manager's,
 * the same as for the page without JavaScript.
 */
class ApiFandomImportAction extends ApiBase {

	public function __construct(
		ApiMain $main,
		string $moduleName,
		private readonly FandomImportManager $manager,
		private readonly FandomImportStore $store,
		private readonly FandomImportPresenter $presenter,
		private readonly FandomClient $client,
	) {
		parent::__construct( $main, $moduleName );
	}

	public function execute(): void {
		$unavailable = $this->manager->getUnavailableReason();
		if ( $unavailable !== null ) {
			$this->dieWithError( $unavailable, 'unavailable' );
		}

		$params = $this->extractRequestParams();
		$user = $this->getUser();

		if ( $params['do'] === 'request' ) {
			$this->request( $params );
			return;
		}

		$request = $this->getImport( $params );
		$status = match ( $params['do'] ) {
			'approve' => $this->manager->approve(
				$request, $user, (string)$params['comment'], (bool)$params['acknowledge']
			),
			'decline' => $this->manager->decline( $request, $user, (string)$params['reason'] ),
			'retry' => $this->manager->retry( $request, $user ),
			'discard' => $this->manager->discardFiles( $request, $user ),
		};
		$this->dieIfFailed( $status );

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'result' => 'success',
			'detail' => $this->presenter->detail( $this->store->get( $request->id, true ) ?? $request, $this->getContext() ),
		] );
	}

	private function request( array $params ): void {
		$this->requireAtLeastOneParameter( $params, 'source' );
		if ( !$params['agreement'] ) {
			$this->dieWithError( 'wikioasismagic-fandomimport-error-agreement', 'agreement' );
		}

		$source = FandomSource::newFromSubpage( (string)$params['source'] );
		if ( !$source ) {
			$this->dieWithError( 'wikioasismagic-fandomimport-error-badinput', 'badsource' );
		}

		$lookup = $this->client->getWikiInfo( $source );
		if ( $lookup->hasMessage( 'wikioasismagic-fandomimport-lookup-missing' ) ) {
			$this->dieWithError( [ 'wikioasismagic-fandomimport-lookup-missing',
				$source->getBaseUrl(), $source->getHost() ], 'nosuchwiki' );
		}

		$status = $this->manager->submit( $this->getUser(), $source, $lookup->isGood() ? $lookup->getValue() : null, [
			'subdomain' => $params['subdomain'],
			'sitename' => $params['sitename'],
			'language' => $params['language'],
			'category' => $params['category'],
			'mode' => $params['mode'],
			'reason' => $params['reason'],
			'fandomuser' => $params['fandomuser'],
		] );
		$this->dieIfFailed( $status );

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'result' => 'success',
			'id' => $status->getValue(),
			'url' => SpecialPage::getTitleFor( 'FandomImport', $source->getSubpage() )->getFullURL(),
		] );
	}

	private function dieIfFailed( StatusValue $status ): void {
		if ( !$status->isOK() ) {
			$this->dieStatus( $status );
		}
	}

	private function getImport( array $params ): FandomImportRequest {
		$this->requireAtLeastOneParameter( $params, 'id' );
		$request = $this->store->get( (int)$params['id'], true );
		if ( !$request ) {
			$this->dieWithError( [ 'wikioasismagic-fandomimport-error-noimport', (int)$params['id'] ], 'noimport' );
		}
		return $request;
	}

	public function mustBePosted(): bool {
		return true;
	}

	public function isWriteMode(): bool {
		return true;
	}

	public function needsToken(): string {
		return 'csrf';
	}

	public function getAllowedParams(): array {
		$text = [ ParamValidator::PARAM_TYPE => 'string', ParamValidator::PARAM_DEFAULT => '' ];
		return [
			'do' => [
				ParamValidator::PARAM_TYPE => [ 'request', 'approve', 'decline', 'retry', 'discard' ],
				ParamValidator::PARAM_REQUIRED => true,
			],
			'id' => [
				ParamValidator::PARAM_TYPE => 'integer',
			],
			'source' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'subdomain' => $text,
			'sitename' => $text,
			'language' => $text,
			'category' => $text,
			'mode' => [
				ParamValidator::PARAM_TYPE => FandomImportManager::MODES,
				ParamValidator::PARAM_DEFAULT => 'move',
			],
			'fandomuser' => $text,
			'reason' => $text,
			'comment' => $text,
			'agreement' => [
				ParamValidator::PARAM_TYPE => 'boolean',
			],
			'acknowledge' => [
				ParamValidator::PARAM_TYPE => 'boolean',
			],
		];
	}

	protected function getExamplesMessages(): array {
		return [
			'action=wikioasisfandomimportaction&do=retry&id=1&token=123ABC'
				=> 'apihelp-wikioasisfandomimportaction-example-retry',
		];
	}

	public function isInternal(): bool {
		return true;
	}
}
