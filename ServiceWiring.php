<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Config\SiteConfiguration;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
use WikiOasis\WikiOasisMagic\EditPrompt\EditPromptGate;
use WikiOasis\WikiOasisMagic\Experiments\ConfigNames as ExperimentsConfigNames;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentAdmin;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentDataStore;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentManager;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentPresenter;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentStateStore;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentTracker;
use WikiOasis\WikiOasisMagic\Experiments\UnitFactory;
use WikiOasis\WikiOasisMagic\Experiments\WikiFarm;
use WikiOasis\WikiOasisMagic\Experiments\WikiRollout;
use WikiOasis\WikiOasisMagic\Onboarding\OnboardingEnvironment;
use WikiOasis\WikiOasisMagic\Onboarding\OnboardingIcons;
use WikiOasis\WikiOasisMagic\Onboarding\OnboardingMetrics;
use WikiOasis\WikiOasisMagic\Onboarding\SurveyLoader;
use WikiOasis\WikiOasisMagic\Onboarding\SurveyResponses;
use WikiOasis\WikiOasisMagic\Onboarding\SurveyValidator;
use WikiOasis\WikiOasisMagic\Onboarding\WikiRequestSubmitter;

/** @phpcs-require-sorted-array */
return [
	'WikiOasisMagic.EditPromptGate' => static function ( MediaWikiServices $services ): EditPromptGate {
		return new EditPromptGate(
			$services->get( 'WikiOasisMagic.ExperimentManager' ),
			$services->get( 'WikiOasisMagic.ExperimentTracker' ),
			$services->getNamespaceInfo(),
			$services->getPermissionManager(),
			$services->getUserEditTracker()
		);
	},

	'WikiOasisMagic.ExperimentAdmin' => static function ( MediaWikiServices $services ): ExperimentAdmin {
		return new ExperimentAdmin(
			$services->get( 'WikiOasisMagic.ExperimentManager' ),
			$services->get( 'WikiOasisMagic.ExperimentStateStore' ),
			$services->get( 'WikiOasisMagic.WikiRollout' )
		);
	},

	'WikiOasisMagic.ExperimentDataStore' => static function ( MediaWikiServices $services ): ExperimentDataStore {
		return new ExperimentDataStore(
			$services->getConnectionProvider(),
			$services->getObjectCacheFactory()->getLocalServerInstance( CACHE_ANYTHING ),
			$services->getMainWANObjectCache(),
			LoggerFactory::getInstance( 'WikiOasisMagic' )
		);
	},

	'WikiOasisMagic.ExperimentManager' => static function ( MediaWikiServices $services ): ExperimentManager {
		return new ExperimentManager(
			new ServiceOptions(
				ExperimentManager::CONSTRUCTOR_OPTIONS,
				$services->getConfigFactory()->makeConfig( 'WikiOasisMagic' )
			),
			$services->get( 'WikiOasisMagic.ExperimentStateStore' ),
			$services->get( 'WikiOasisMagic.WikiFarm' ),
			$services->get( 'WikiOasisMagic.UnitFactory' )
		);
	},

	'WikiOasisMagic.ExperimentPresenter' => static function ( MediaWikiServices $services ): ExperimentPresenter {
		return new ExperimentPresenter(
			new ServiceOptions(
				ExperimentPresenter::CONSTRUCTOR_OPTIONS,
				$services->getConfigFactory()->makeConfig( 'WikiOasisMagic' )
			),
			$services->get( 'WikiOasisMagic.ExperimentManager' ),
			$services->get( 'WikiOasisMagic.ExperimentStateStore' ),
			$services->get( 'WikiOasisMagic.ExperimentDataStore' ),
			$services->get( 'WikiOasisMagic.WikiFarm' ),
			$services->get( 'WikiOasisMagic.WikiRollout' ),
			$services->get( 'WikiOasisMagic.UnitFactory' ),
			$services->getLanguageFallback()
		);
	},

	'WikiOasisMagic.ExperimentStateStore' => static function ( MediaWikiServices $services ): ExperimentStateStore {
		return new ExperimentStateStore(
			$services->getConnectionProvider(),
			$services->getMainWANObjectCache(),
			LoggerFactory::getInstance( 'WikiOasisMagic' )
		);
	},

	'WikiOasisMagic.ExperimentTracker' => static function ( MediaWikiServices $services ): ExperimentTracker {
		return new ExperimentTracker(
			$services->get( 'WikiOasisMagic.ExperimentManager' ),
			$services->get( 'WikiOasisMagic.UnitFactory' ),
			$services->get( 'WikiOasisMagic.ExperimentDataStore' ),
			$services->get( 'WikiOasisMagic.WikiFarm' ),
			$services->getUserFactory(),
			$services->getStatsFactory(),
			LoggerFactory::getInstance( 'WikiOasisMagic' )
		);
	},

	'WikiOasisMagic.OnboardingEnvironment' => static function ( MediaWikiServices $services ): OnboardingEnvironment {
		$registry = ExtensionRegistry::getInstance();
		return new OnboardingEnvironment(
			new ServiceOptions(
				OnboardingEnvironment::CONSTRUCTOR_OPTIONS,
				$services->getConfigFactory()->makeConfig( 'WikiOasisMagic' )
			),
			$services->get( 'WikiOasisMagic.WikiFarm' ),
			$registry,
			$services->getMainWANObjectCache(),
			LoggerFactory::getInstance( 'WikiOasisMagic' ),
			$registry->isLoaded( 'CreateWiki' ) ? $services->get( 'CreateWikiDatabaseUtils' ) : null
		);
	},

	'WikiOasisMagic.OnboardingIcons' => static function ( MediaWikiServices $services ): OnboardingIcons {
		return new OnboardingIcons( $services->getMainConfig() );
	},

	'WikiOasisMagic.OnboardingMetrics' => static function ( MediaWikiServices $services ): OnboardingMetrics {
		return new OnboardingMetrics( $services->getStatsFactory() );
	},

	'WikiOasisMagic.OnboardingSurveyLoader' => static function ( MediaWikiServices $services ): SurveyLoader {
		return new SurveyLoader(
			new ServiceOptions(
				SurveyLoader::CONSTRUCTOR_OPTIONS,
				$services->getConfigFactory()->makeConfig( 'WikiOasisMagic' )
			),
			$services->get( 'WikiOasisMagic.OnboardingEnvironment' ),
			new SurveyValidator(),
			$services->getRevisionLookup(),
			$services->getMainWANObjectCache(),
			$services->get( 'WikiOasisMagic.OnboardingMetrics' ),
			LoggerFactory::getInstance( 'WikiOasisMagic' )
		);
	},

	'WikiOasisMagic.OnboardingSurveyResponses' => static function ( MediaWikiServices $services ): SurveyResponses {
		return new SurveyResponses(
			$services->getUserOptionsManager(),
			$services->get( 'WikiOasisMagic.OnboardingMetrics' ),
			$services->get( 'WikiOasisMagic.ExperimentTracker' )
		);
	},

	'WikiOasisMagic.OnboardingWikiRequestSubmitter' => static function (
		MediaWikiServices $services
	): WikiRequestSubmitter {
		return new WikiRequestSubmitter(
			$services->get( 'CreateWikiConfig' ),
			$services->get( 'CreateWikiDatabaseUtils' ),
			$services->get( 'CreateWikiValidator' ),
			$services->get( 'WikiRequestManager' ),
			$services->getLanguageNameUtils(),
			$services->getReadOnlyMode(),
			LoggerFactory::getInstance( 'WikiOasisMagic' )
		);
	},

	'WikiOasisMagic.UnitFactory' => static function ( MediaWikiServices $services ): UnitFactory {
		$config = $services->getConfigFactory()->makeConfig( 'WikiOasisMagic' );
		return new UnitFactory(
			$services->get( 'WikiOasisMagic.WikiFarm' ),
			(string)( $config->get( ExperimentsConfigNames::HASH_SECRET ) ?:
				$services->getMainConfig()->get( MainConfigNames::SecretKey ) )
		);
	},

	'WikiOasisMagic.WikiFarm' => static function ( MediaWikiServices $services ): WikiFarm {
		$registry = ExtensionRegistry::getInstance();
		return new WikiFarm(
			new ServiceOptions(
				WikiFarm::CONSTRUCTOR_OPTIONS,
				$services->getConfigFactory()->makeConfig( 'WikiOasisMagic' ),
				$services->getMainConfig()
			),
			$registry,
			$services->getUserFactory(),
			$services->getMainWANObjectCache(),
			LoggerFactory::getInstance( 'WikiOasisMagic' ),
			$registry->isLoaded( 'CreateWiki' ) ? $services->get( 'CreateWikiDatabaseUtils' ) : null
		);
	},

	'WikiOasisMagic.WikiRollout' => static function ( MediaWikiServices $services ): WikiRollout {
		return new WikiRollout(
			$services->get( 'WikiOasisMagic.ExperimentManager' ),
			$services->get( 'WikiOasisMagic.UnitFactory' ),
			$services->get( 'WikiOasisMagic.ExperimentTracker' ),
			$services->get( 'WikiOasisMagic.ExperimentDataStore' ),
			$services->get( 'WikiOasisMagic.WikiFarm' ),
			$services->getMainConfig(),
			ExtensionRegistry::getInstance(),
			$services->getJobQueueGroupFactory(),
			$services->getDBLoadBalancerFactory(),
			LoggerFactory::getInstance( 'WikiOasisMagic' ),
			( $GLOBALS['wgConf'] ?? null ) instanceof SiteConfiguration ? $GLOBALS['wgConf'] : null
		);
	},
];
