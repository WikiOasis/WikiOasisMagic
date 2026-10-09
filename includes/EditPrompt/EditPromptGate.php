<?php

namespace WikiOasis\WikiOasisMagic\EditPrompt;

use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Request\WebRequest;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserEditTracker;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentManager;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentTracker;
use function max;

class EditPromptGate {

	public const EXPERIMENT = 'edit_prompt';

	public const MAX_SHOWS = 3;

	public function __construct(
		private readonly ExperimentManager $manager,
		private readonly ExperimentTracker $tracker,
		private readonly NamespaceInfo $namespaceInfo,
		private readonly PermissionManager $permissionManager,
		private readonly UserEditTracker $userEditTracker,
	) {
	}

	public function isOffered( User $user, Title $title, WebRequest $request ): bool {
		$experiment = $this->manager->getExperiment( self::EXPERIMENT );
		if ( !$experiment || !$this->isEditablePage( $title ) || !$this->isReader( $user, $title, $user->isRegistered() ) ) {
			return false;
		}

		return $this->manager->isRunning( $experiment ) ||
			!$this->manager->assignBrowser( self::EXPERIMENT, $request, false )->isLegacy();
	}

	/**
	 * @return array{prompt:bool,track:bool,maxShows:int}
	 */
	public function assign( User $user, Title $title, WebRequest $request ): array {
		$experiment = $this->manager->getExperiment( self::EXPERIMENT );
		if ( !$experiment || !$this->isEditablePage( $title ) || !$this->isReader( $user, $title, true ) ) {
			return [ 'prompt' => false, 'track' => false, 'maxShows' => 0 ];
		}

		$running = $this->manager->isRunning( $experiment );
		$assignment = $this->manager->assignBrowser( self::EXPERIMENT, $request, $running );
		$this->tracker->expose( $assignment );

		return [
			'prompt' => !$assignment->isLegacy(),
			'track' => $running,
			'maxShows' => max( 0, (int)$assignment->getParam( 'maxShows', self::MAX_SHOWS ) ),
		];
	}

	private function isEditablePage( Title $title ): bool {
		return $this->namespaceInfo->isContent( $title->getNamespace() ) &&
			$title->exists() &&
			!$title->isRedirect() &&
			$title->getContentModel() === CONTENT_MODEL_WIKITEXT;
	}

	private function isReader( User $user, Title $title, bool $checkBlock ): bool {
		if ( $user->isRegistered() &&
			( $user->isBot() || $this->userEditTracker->getUserEditCount( $user ) !== 0 )
		) {
			return false;
		}

		return $user->probablyCan( 'edit', $title ) &&
			( !$checkBlock || !$this->permissionManager->isBlockedFrom( $user, $title, true ) );
	}
}
