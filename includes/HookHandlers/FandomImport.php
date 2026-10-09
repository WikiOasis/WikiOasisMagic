<?php

namespace WikiOasis\WikiOasisMagic\HookHandlers;

use MediaWiki\Logger\LoggerFactory;
use Miraheze\CreateWiki\Hooks\CreateWikiAfterCreationWithExtraDataHook;
use Throwable;
use WikiOasis\WikiOasisMagic\FandomImport\FandomImportManager;
use function is_numeric;

class FandomImport implements CreateWikiAfterCreationWithExtraDataHook {

	public function __construct(
		private readonly FandomImportManager $manager,
	) {
	}

	/**
	 * CreateWiki calls this once a wiki is fully set up, its requester
	 * already promoted there. Ours carry the import's ID; every other wiki
	 * creation passes straight through.
	 *
	 * @param array $extraData
	 * @param string $dbname
	 */
	public function onCreateWikiAfterCreationWithExtraData( array $extraData, string $dbname ): void {
		$id = $extraData[FandomImportManager::EXTRA_KEY] ?? null;
		if ( !is_numeric( $id ) ) {
			return;
		}

		try {
			$this->manager->onWikiCreated( (int)$id, $dbname );
		} catch ( Throwable $e ) {
			LoggerFactory::getInstance( 'WikiOasisMagic' )->error(
				'Could not hand Fandom import {id} on {dbname} to the worker: {message}',
				[
					'id' => $id,
					'dbname' => $dbname,
					'message' => $e->getMessage(),
					'exception' => $e,
				]
			);
		}
	}
}
