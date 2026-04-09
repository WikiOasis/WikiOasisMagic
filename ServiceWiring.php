<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MediaWikiServices;
use WikiOasis\WikiOasisMagic\Services\GarageManager;

return [
	'WikiOasisMagic.GarageManager' => static function ( MediaWikiServices $services ): GarageManager {
		return new GarageManager(
			new ServiceOptions(
				GarageManager::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			),
			$services->getHttpRequestFactory()
		);
	},
];
