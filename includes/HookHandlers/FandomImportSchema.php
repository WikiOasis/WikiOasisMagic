<?php

namespace WikiOasis\WikiOasisMagic\HookHandlers;

use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;
use WikiOasis\WikiOasisMagic\FandomImport\FandomImportStore;
use function dirname;

/**
 * Without services: update.php runs this before they can be relied on.
 */
class FandomImportSchema implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = dirname( __DIR__, 2 ) . '/sql/' . $updater->getDB()->getType();
		$updater->addExtensionUpdateOnVirtualDomain( [
			FandomImportStore::VIRTUAL_DOMAIN,
			'addTable',
			FandomImportStore::TABLE,
			"$dir/" . FandomImportStore::TABLE . '.sql',
			true,
		] );
	}
}
