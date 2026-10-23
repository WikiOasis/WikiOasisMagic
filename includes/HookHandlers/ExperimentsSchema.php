<?php

namespace WikiOasis\WikiOasisMagic\HookHandlers;

use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentDataStore;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentStateStore;
use function dirname;

/**
 * Without services: update.php runs this before they can be relied on.
 */
class ExperimentsSchema implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = dirname( __DIR__, 2 ) . '/sql/' . $updater->getDB()->getType();
		foreach ( [
			'wo_experiment_state',
			ExperimentDataStore::UNIT_TABLE,
			ExperimentDataStore::EVENT_TABLE,
			ExperimentDataStore::DAILY_TABLE,
			ExperimentDataStore::EXTENSION_TABLE,
			ExperimentDataStore::SYNC_TABLE,
		] as $table ) {
			$updater->addExtensionUpdateOnVirtualDomain( [
				ExperimentStateStore::VIRTUAL_DOMAIN,
				'addTable',
				$table,
				"$dir/$table.sql",
				true,
			] );
		}
	}
}
