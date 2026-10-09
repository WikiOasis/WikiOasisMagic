<?php

namespace WikiOasis\WikiOasisMagic\Specials;

use MediaWiki\Exception\PermissionsError;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentAdmin;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentDataStore;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentManager;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentPresenter;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentStateStore;
use WikiOasis\WikiOasisMagic\Experiments\WikiFarm;

class SpecialExperiments extends SpecialPage {

	public function __construct(
		private readonly ExperimentManager $manager,
		private readonly ExperimentPresenter $presenter,
		private readonly ExperimentStateStore $stateStore,
		private readonly ExperimentDataStore $dataStore,
		private readonly WikiFarm $farm,
	) {
		parent::__construct( 'Experiments' );
	}

	public function getRestriction(): string {
		return ExperimentAdmin::RIGHT;
	}

	/** @return bool */
	public function isRestricted() {
		return true;
	}

	/** @return bool */
	public function userCanExecute( User $user ) {
		return $user->isAllowed( ExperimentAdmin::RIGHT );
	}

	public function execute( $subPage ): void {
		$this->setHeaders();
		if ( !$this->userCanExecute( $this->getUser() ) ) {
			throw new PermissionsError( ExperimentAdmin::RIGHT );
		}
		if ( $subPage === null || $subPage === '' ) {
			$this->outputHeader();
		}

		$out = $this->getOutput();
		$out->addModuleStyles( [ 'ext.wikioasismagic.experiments.styles' ] );

		$config = [
			'isCentral' => $this->farm->isCentralWiki(),
			'centralUrl' => $this->farm->getCentralUrl( $this->getPageTitle( $subPage )->getPrefixedText() ),
			'stateAvailable' => $this->stateStore->isAvailable(),
			'dataAvailable' => $this->dataStore->isAvailable(),
			'listUrl' => $this->getPageTitle()->getLocalURL(),
			'overrides' => $this->manager->getActiveOverrides( $this->getRequest() ),
			'resetOverridesUrl' => $this->getPageTitle( $subPage )->getLocalURL( [
				ExperimentManager::OVERRIDE_PARAM => 'reset',
			] ),
			'wiki' => $this->farm->getCurrentWiki(),
		];

		if ( $subPage !== null && $subPage !== '' ) {
			$experiment = $this->manager->getExperiment( $subPage );
			if ( !$experiment ) {
				$out->addHTML( Html::errorBox(
					$this->msg( 'wikioasismagic-experiments-error-unknown', wfEscapeWikiText( $subPage ) )->parse()
				) );
				$out->addReturnTo( $this->getPageTitle() );
				return;
			}

			$detail = $this->presenter->detail( $experiment, $this->getContext() );
			$out->setHTMLTitle( $this->msg( 'pagetitle',
				$this->msg( 'wikioasismagic-experiments-detail-title', $detail['label'] )->text()
			) );
			$config += [ 'view' => 'detail', 'experiment' => $detail ];
			$fallback = $this->renderDetailFallback( $detail );
		} else {
			$summaries = [];
			foreach ( $this->manager->getExperiments() as $experiment ) {
				$summaries[] = $this->presenter->summary( $experiment, $this->getContext() );
			}
			$config += [ 'view' => 'list', 'experiments' => $summaries ];
			$fallback = $this->renderListFallback( $summaries );
		}

		$out->addJsConfigVars( 'wgWikiOasisExperiments', $config );
		$out->addModules( 'ext.wikioasismagic.experiments' );
		$out->addHTML( Html::rawElement( 'div', [ 'id' => 'wo-experiments-app', 'class' => 'wo-exp-root' ], $fallback ) );
	}

	private function renderListFallback( array $summaries ): string {
		if ( !$summaries ) {
			return Html::noticeBox( $this->msg( 'wikioasismagic-experiments-empty' )->parse(), '' );
		}

		$rows = '';
		foreach ( $summaries as $summary ) {
			$rows .= Html::rawElement( 'tr', [],
				Html::rawElement( 'td', [], Html::element( 'a', [ 'href' => $summary['url'] ], $summary['label'] ) ) .
				Html::element( 'td', [], $this->msg( 'wikioasismagic-experiments-status-' . $summary['status'] )->text() ) .
				Html::element( 'td', [], $this->msg( 'wikioasismagic-experiments-unit-' . $summary['unit'] )->text() ) .
				Html::element( 'td', [], $this->getLanguage()->formatNum( $summary['rollout'] ) . '%' )
			);
		}

		return Html::rawElement( 'table', [ 'class' => 'wikitable wo-exp-fallback' ],
			Html::rawElement( 'tr', [],
				Html::element( 'th', [], $this->msg( 'wikioasismagic-experiments-column-experiment' )->text() ) .
				Html::element( 'th', [], $this->msg( 'wikioasismagic-experiments-column-status' )->text() ) .
				Html::element( 'th', [], $this->msg( 'wikioasismagic-experiments-column-unit' )->text() ) .
				Html::element( 'th', [], $this->msg( 'wikioasismagic-experiments-column-rollout' )->text() )
			) . $rows
		);
	}

	private function renderDetailFallback( array $detail ): string {
		$items = [
			'wikioasismagic-experiments-column-status' =>
				$this->msg( 'wikioasismagic-experiments-status-' . $detail['status'] )->text(),
			'wikioasismagic-experiments-column-unit' =>
				$this->msg( 'wikioasismagic-experiments-unit-' . $detail['unit'] )->text(),
			'wikioasismagic-experiments-column-rollout' =>
				$this->getLanguage()->formatNum( $detail['rollout'] ) . '%',
			'wikioasismagic-experiments-column-default' => $detail['default'],
		];

		$html = $detail['description'] !== '' ? Html::element( 'p', [], $detail['description'] ) : '';
		$list = '';
		foreach ( $items as $label => $value ) {
			$list .= Html::element( 'dt', [], $this->msg( $label )->text() ) . Html::element( 'dd', [], $value );
		}

		return $html . Html::rawElement( 'dl', [ 'class' => 'wo-exp-fallback' ], $list ) .
			Html::rawElement( 'p', [ 'class' => 'wo-exp-fallback' ],
				$this->msg( 'wikioasismagic-experiments-nojs' )->escaped()
			);
	}

	protected function getGroupName(): string {
		return 'wiki';
	}
}
