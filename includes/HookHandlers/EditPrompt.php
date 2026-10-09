<?php

namespace WikiOasis\WikiOasisMagic\HookHandlers;

use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserEditTracker;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentManager;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentTracker;
use function max;

class EditPrompt implements BeforePageDisplayHook {

	public const EXPERIMENT = 'edit_prompt';

	public const MODULE = 'ext.wikioasismagic.editprompt';

	public const CONFIG_VAR = 'wgWikiOasisEditPrompt';

	public const MAX_SHOWS = 3;

	public function __construct(
		private readonly ExperimentManager $manager,
		private readonly ExperimentTracker $tracker,
		private readonly NamespaceInfo $namespaceInfo,
		private readonly PermissionManager $permissionManager,
		private readonly UserEditTracker $userEditTracker,
	) {
	}

	/**
	 * @param OutputPage $out
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$title = $out->getTitle();
		if ( !$title || !$this->isPlainView( $out, $title ) ) {
			return;
		}

		$experiment = $this->manager->getExperiment( self::EXPERIMENT );
		if ( !$experiment || !$this->isReader( $out->getUser(), $title ) ) {
			return;
		}

		$running = $this->manager->isRunning( $experiment );
		$assignment = $this->manager->assignBrowser( self::EXPERIMENT, $out->getRequest(), $running );
		$this->tracker->expose( $assignment );

		$prompt = !$assignment->isLegacy();
		if ( !$prompt && !$running ) {
			return;
		}

		$out->addModules( self::MODULE );
		$out->addJsConfigVars( self::CONFIG_VAR, [
			'experiment' => self::EXPERIMENT,
			'prompt' => $prompt,
			'track' => $running,
			'maxShows' => max( 0, (int)$assignment->getParam( 'maxShows', self::MAX_SHOWS ) ),
		] );
	}

	private function isPlainView( OutputPage $out, Title $title ): bool {
		$request = $out->getRequest();
		return $out->getActionName() === 'view' &&
			$out->isArticle() &&
			!$out->isPrintable() &&
			$out->isRevisionCurrent() &&
			$request->getRawVal( 'diff' ) === null &&
			$request->getRawVal( 'oldid' ) === null &&
			$this->namespaceInfo->isContent( $title->getNamespace() ) &&
			$title->exists() &&
			!$title->isRedirect() &&
			$title->getContentModel() === CONTENT_MODEL_WIKITEXT;
	}

	private function isReader( User $user, Title $title ): bool {
		if ( $user->isRegistered() &&
			( $user->isBot() || $this->userEditTracker->getUserEditCount( $user ) !== 0 )
		) {
			return false;
		}

		return $user->probablyCan( 'edit', $title ) &&
			!$this->permissionManager->isBlockedFrom( $user, $title, true );
	}
}
