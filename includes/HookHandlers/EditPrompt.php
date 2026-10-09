<?php

namespace WikiOasis\WikiOasisMagic\HookHandlers;

use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\OutputPage;
use WikiOasis\WikiOasisMagic\EditPrompt\EditPromptGate;

class EditPrompt implements BeforePageDisplayHook {

	public const MODULE = 'ext.wikioasismagic.editprompt';

	public const CONFIG_VAR = 'wgWikiOasisEditPrompt';

	public function __construct(
		private readonly EditPromptGate $gate,
	) {
	}

	/**
	 * @param OutputPage $out
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$title = $out->getTitle();
		if ( !$title || !$this->isPlainView( $out ) || !$this->gate->isOffered( $out->getUser(), $title, $out->getRequest() ) ) {
			return;
		}

		$out->addModules( self::MODULE );
		$out->addJsConfigVars( self::CONFIG_VAR, [ 'experiment' => EditPromptGate::EXPERIMENT ] );
	}

	private function isPlainView( OutputPage $out ): bool {
		$request = $out->getRequest();
		return $out->getActionName() === 'view' &&
			$out->isArticle() &&
			!$out->isPrintable() &&
			$out->isRevisionCurrent() &&
			$request->getRawVal( 'diff' ) === null &&
			$request->getRawVal( 'oldid' ) === null;
	}
}
