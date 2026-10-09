<?php

namespace WikiOasis\WikiOasisMagic\Tests\HookHandlers;

use MediaWiki\Config\HashConfig;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\MainConfigNames;
use MediaWiki\Output\OutputPage;
use MediaWiki\Request\WebRequest;
use MediaWiki\Session\Session;
use MediaWiki\Skin\Skin;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use MediaWikiUnitTestCase;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use WikiOasis\WikiOasisMagic\Experiments\Assignment;
use WikiOasis\WikiOasisMagic\Experiments\ConfigNames;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentDataStore;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentManager;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentStateStore;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentTracker;
use WikiOasis\WikiOasisMagic\Experiments\UnitFactory;
use WikiOasis\WikiOasisMagic\Experiments\WikiFarm;
use WikiOasis\WikiOasisMagic\HookHandlers\WikiPrompt;

/**
 * @covers \WikiOasis\WikiOasisMagic\HookHandlers\WikiPrompt
 */
class WikiPromptTest extends MediaWikiUnitTestCase {

	private const NOW = '20261009120000';

	private const URL = 'https://meta.example.org/wiki/Special:RequestWiki';

	private const EXPERIMENT = [
		'unit' => 'user',
		'active' => true,
		'rollout' => 100,
		'default' => 'control',
		'variants' => [ 'control' => 0, 'treatment' => 100 ],
	];

	private const DEFAULTS = [
		'experiment' => self::EXPERIMENT,
		'named' => true,
		'bot' => false,
		'canExist' => true,
		'action' => 'view',
		'printable' => false,
		'params' => [],
		'registration' => '20250101000000',
		'available' => true,
		'stored' => null,
		'requested' => false,
		'url' => self::URL,
		'override' => null,
	];

	/** @var array[] */
	private array $exposed;

	/** @var string[] */
	private array $modules;

	/** @var array */
	private array $vars;

	private bool $lookedUp;

	protected function setUp(): void {
		parent::setUp();
		ConvertibleTimestamp::setFakeTime( self::NOW );
	}

	private function runHook( array $options = [] ): void {
		$options += self::DEFAULTS;
		$this->exposed = [];
		$this->modules = [];
		$this->vars = [];
		$this->lookedUp = false;

		$farm = $this->createMock( WikiFarm::class );
		$farm->method( 'getCurrentWiki' )->willReturn( 'foowiki' );
		$farm->method( 'getCentralWiki' )->willReturn( 'metawiki' );
		$farm->method( 'isCentralWiki' )->willReturn( false );
		$farm->method( 'getCentralUrl' )->willReturn( $options['url'] );
		$farm->method( 'getRegistration' )->willReturn( $options['registration'] );

		$stateStore = $this->createMock( ExperimentStateStore::class );
		$stateStore->method( 'getState' )->willReturn( [] );

		$manager = new ExperimentManager(
			new ServiceOptions( ExperimentManager::CONSTRUCTOR_OPTIONS, new HashConfig( [
				ConfigNames::EXPERIMENTS => $options['experiment'] ? [ WikiPrompt::EXPERIMENT => $options['experiment'] ] : [],
				ConfigNames::HASH_SECRET => 'test-secret',
				MainConfigNames::SecretKey => '',
			] ) ),
			$stateStore,
			$farm,
			new UnitFactory( $farm, 'test-secret' )
		);

		$tracker = $this->createMock( ExperimentTracker::class );
		$tracker->method( 'expose' )->willReturnCallback( function ( Assignment $assignment, bool $eligible = true ) {
			$this->exposed[] = [ $assignment->variant, $eligible ];
		} );

		$dataStore = $this->createMock( ExperimentDataStore::class );
		$dataStore->method( 'isAvailable' )->willReturn( $options['available'] );
		$dataStore->method( 'getUnit' )->willReturnCallback( function () use ( $options ) {
			$this->lookedUp = true;
			return $options['stored'];
		} );

		$params = $options['params'];
		if ( $options['override'] !== null ) {
			$params += $manager->makeOverrideQuery( WikiPrompt::EXPERIMENT . ':' . $options['override'] );
		}

		$handler = new class( $manager, $tracker, $dataStore, $farm, WANObjectCache::newEmpty(), $options['requested'] )
			extends WikiPrompt {

			/**
			 * @param ExperimentManager $manager
			 * @param ExperimentTracker $tracker
			 * @param ExperimentDataStore $dataStore
			 * @param WikiFarm $farm
			 * @param WANObjectCache $cache
			 * @param bool|null $requested
			 */
			public function __construct( $manager, $tracker, $dataStore, $farm, $cache, private readonly ?bool $requested ) {
				parent::__construct( $manager, $tracker, $dataStore, $farm, $cache );
			}

			protected function hasRequestedWiki( UserIdentity $user ): ?bool {
				return $this->requested;
			}
		};

		$handler->onBeforePageDisplay( $this->newOutput( $options, $params ), $this->createMock( Skin::class ) );
	}

	private function newOutput( array $options, array $params ): OutputPage {
		$user = $this->createMock( User::class );
		$user->method( 'getName' )->willReturn( 'Alice' );
		$user->method( 'isRegistered' )->willReturn( true );
		$user->method( 'isNamed' )->willReturn( $options['named'] );
		$user->method( 'isBot' )->willReturn( $options['bot'] );

		$title = $this->createMock( Title::class );
		$title->method( 'canExist' )->willReturn( $options['canExist'] );

		$sessionData = [];
		$session = $this->createMock( Session::class );
		$session->method( 'get' )->willReturnCallback( static function ( $key ) use ( &$sessionData ) {
			return $sessionData[$key] ?? null;
		} );
		$session->method( 'set' )->willReturnCallback( static function ( $key, $value ) use ( &$sessionData ) {
			$sessionData[$key] = $value;
		} );

		$request = $this->createMock( WebRequest::class );
		$request->method( 'getRawVal' )->willReturnCallback( static fn ( $name ) => $params[$name] ?? null );
		$request->method( 'getSession' )->willReturn( $session );

		$out = $this->createMock( OutputPage::class );
		$out->method( 'getUser' )->willReturn( $user );
		$out->method( 'getTitle' )->willReturn( $title );
		$out->method( 'getActionName' )->willReturn( $options['action'] );
		$out->method( 'isPrintable' )->willReturn( $options['printable'] );
		$out->method( 'getRequest' )->willReturn( $request );
		$out->method( 'addModules' )->willReturnCallback( function ( $modules ) {
			$this->modules = array_merge( $this->modules, (array)$modules );
		} );
		$out->method( 'addJsConfigVars' )->willReturnCallback( function ( $name, $value ) {
			$this->vars[$name] = $value;
		} );
		return $out;
	}

	private function assertPrompt( bool $preview ): void {
		$this->assertSame( [ WikiPrompt::MODULE ], $this->modules );
		$this->assertSame(
			[ 'experiment' => WikiPrompt::EXPERIMENT, 'url' => self::URL, 'preview' => $preview ],
			$this->vars[WikiPrompt::CONFIG_VAR] ?? null
		);
	}

	private function assertNoPrompt(): void {
		$this->assertSame( [], $this->modules );
		$this->assertSame( [], $this->vars );
	}

	public function testFirstVisitInTreatmentShowsThePromptAndExposes(): void {
		$this->runHook();

		$this->assertPrompt( false );
		$this->assertSame( [ [ 'treatment', true ] ], $this->exposed );
	}

	public function testFirstVisitInControlExposesWithoutAPrompt(): void {
		$this->runHook( [ 'experiment' => [ 'variants' => [ 'control' => 100, 'treatment' => 0 ] ] + self::EXPERIMENT ] );

		$this->assertNoPrompt();
		$this->assertSame( [ [ 'control', true ] ], $this->exposed );
	}

	public function testUsersWhoRequestedAWikiAreIneligible(): void {
		$this->runHook( [ 'requested' => true ] );

		$this->assertNoPrompt();
		$this->assertSame( [ [ 'treatment', false ] ], $this->exposed );
	}

	public function testFailedRequestLookupDecidesNothing(): void {
		$this->runHook( [ 'requested' => null ] );

		$this->assertNoPrompt();
		$this->assertSame( [], $this->exposed );
	}

	public function testOnlyTheFirstVisitShowsThePrompt(): void {
		$this->runHook( [ 'stored' => [
			'variant' => 'treatment',
			'enrolled' => true,
			'eligible' => true,
			'wiki' => 'barwiki',
			'firstSeen' => '20261001000000',
		] ] );

		$this->assertNoPrompt();
		$this->assertSame( [], $this->exposed );
	}

	public function testNewAccountsWaitUntilAfterSignup(): void {
		$this->runHook( [ 'registration' => '20261009113000' ] );

		$this->assertNoPrompt();
		$this->assertSame( [], $this->exposed );
		$this->assertFalse( $this->lookedUp );

		$this->runHook( [ 'registration' => '20261009105500' ] );
		$this->assertPrompt( false );
	}

	public function testNothingHappensWithoutExperimentData(): void {
		$this->runHook( [ 'available' => false ] );

		$this->assertNoPrompt();
		$this->assertSame( [], $this->exposed );
	}

	public function testNothingHappensWhenTheExperimentIsNotConfigured(): void {
		$this->runHook( [ 'experiment' => null ] );

		$this->assertNoPrompt();
		$this->assertSame( [], $this->exposed );
	}

	public function testNothingHappensWithoutACentralWikiUrl(): void {
		$this->runHook( [ 'url' => null ] );

		$this->assertNoPrompt();
		$this->assertSame( [], $this->exposed );
	}

	public function testInactiveControlDoesNoWork(): void {
		$this->runHook( [ 'experiment' => [ 'active' => false ] + self::EXPERIMENT ] );

		$this->assertNoPrompt();
		$this->assertSame( [], $this->exposed );
		$this->assertFalse( $this->lookedUp );
	}

	public function testPreviewAlwaysShowsTheTreatment(): void {
		$this->runHook( [
			'experiment' => [ 'active' => false ] + self::EXPERIMENT,
			'override' => 'treatment',
			'registration' => '20261009115900',
			'stored' => [
				'variant' => 'control',
				'enrolled' => true,
				'eligible' => true,
				'wiki' => 'barwiki',
				'firstSeen' => '20261001000000',
			],
			'requested' => true,
		] );

		$this->assertPrompt( true );
		$this->assertSame( [], $this->exposed );
		$this->assertFalse( $this->lookedUp );
	}

	public function testPreviewOfControlShowsNothing(): void {
		$this->runHook( [ 'override' => 'control' ] );

		$this->assertNoPrompt();
		$this->assertSame( [], $this->exposed );
	}

	public static function provideSkippedViews(): array {
		return [
			'logged out or temporary' => [ [ 'named' => false ] ],
			'bot' => [ [ 'bot' => true ] ],
			'special page' => [ [ 'canExist' => false ] ],
			'editing' => [ [ 'action' => 'edit' ] ],
			'history' => [ [ 'action' => 'history' ] ],
			'printable' => [ [ 'printable' => true ] ],
			'diff' => [ [ 'params' => [ 'diff' => 'prev' ] ] ],
			'visual editor' => [ [ 'params' => [ 'veaction' => 'edit' ] ] ],
			'redirect' => [ [ 'params' => [ 'redirect' => 'no' ] ] ],
			'right after signup' => [ [ 'params' => [ 'source' => 'signup' ] ] ],
			'popup' => [ [ 'params' => [ 'display' => 'popup' ] ] ],
		];
	}

	/**
	 * @dataProvider provideSkippedViews
	 */
	public function testSkippedViews( array $options ): void {
		$this->runHook( $options );

		$this->assertNoPrompt();
		$this->assertSame( [], $this->exposed );
		$this->assertFalse( $this->lookedUp );
	}

	/**
	 * @dataProvider provideSkippedViews
	 */
	public function testSkippedViewsAlsoSkipPreviews( array $options ): void {
		$this->runHook( $options + [ 'override' => 'treatment' ] );

		$this->assertNoPrompt();
	}
}
