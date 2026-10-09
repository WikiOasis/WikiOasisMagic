<?php

namespace WikiOasis\WikiOasisMagic\HookHandlers;

use MediaWiki\Extension\Notifications\AttributeManager;
use MediaWiki\Extension\Notifications\Hooks\BeforeCreateEchoEventHook;
use MediaWiki\Extension\Notifications\UserLocator;
use WikiOasis\WikiOasisMagic\FandomImport\FandomImportNotifier;
use WikiOasis\WikiOasisMagic\Notifications\FandomImportPresentationModel;

/**
 * Kept apart from the other Fandom import hooks so nothing loads Echo's
 * interfaces on a wiki without Echo.
 */
class FandomImportNotifications implements BeforeCreateEchoEventHook {

	/**
	 * @param array &$notifications
	 * @param array &$notificationCategories
	 * @param array &$icons
	 */
	public function onBeforeCreateEchoEvent(
		array &$notifications,
		array &$notificationCategories,
		array &$icons
	): void {
		$notificationCategories['fandom-import'] = [
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-fandom-import',
		];

		$groups = [
			FandomImportNotifier::DONE => 'positive',
			FandomImportNotifier::FAILED => 'negative',
			FandomImportNotifier::NEEDS_DUMP => 'interactive',
			FandomImportNotifier::DECLINED => 'negative',
		];

		foreach ( $groups as $type => $group ) {
			$notifications[$type] = [
				AttributeManager::ATTR_LOCATORS => [
					[ [ UserLocator::class, 'locateEventAgent' ] ],
				],
				'category' => 'fandom-import',
				'group' => $group,
				'section' => 'alert',
				'canNotifyAgent' => true,
				'presentation-model' => FandomImportPresentationModel::class,
				'immediate' => true,
			];
		}
	}
}
