<?php

namespace WikiOasis\WikiOasisMagic\Tests\Experiments;

use MediaWiki\Config\HashConfig;
use MediaWiki\Config\SiteConfiguration;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWikiUnitTestCase;
use Psr\Log\NullLogger;
use Wikimedia\Rdbms\ILBFactory;
use WikiOasis\WikiOasisMagic\Experiments\Experiment;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentDataStore;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentManager;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentTracker;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentUnit;
use WikiOasis\WikiOasisMagic\Experiments\UnitFactory;
use WikiOasis\WikiOasisMagic\Experiments\WikiFarm;
use WikiOasis\WikiOasisMagic\Experiments\WikiRollout;

/**
 * @covers \WikiOasis\WikiOasisMagic\Experiments\WikiRollout
 */
class WikiRolloutTest extends MediaWikiUnitTestCase {

	private const EXTENSIONS = [
		'discussiontools' => [
			'name' => 'DiscussionTools',
			'conflicts' => false,
			'requires' => [ 'extensions' => [ 'visualeditor', 'linter' ] ],
		],
		'linter' => [ 'name' => 'Linter', 'conflicts' => false, 'requires' => [] ],
		'visualeditor' => [ 'name' => 'VisualEditor', 'conflicts' => false, 'requires' => [] ],
		'poem' => [ 'name' => 'Poem', 'conflicts' => 'verse', 'requires' => [] ],
		'verse' => [ 'name' => 'Verse', 'conflicts' => false, 'requires' => [] ],
	];

	private array $pushed = [];
	private array $saved = [];
	private array $deleted = [];

	private function newRollout( array $experiments = [], array $records = [], array $siteSettings = [] ): WikiRollout {
		$farm = $this->createMock( WikiFarm::class );
		$farm->method( 'getCurrentWiki' )->willReturn( 'metawiki' );
		$farm->method( 'getCentralWiki' )->willReturn( 'metawiki' );
		$farm->method( 'isCentralWiki' )->willReturnCallback(
			static fn ( ?string $wiki = null ) => $wiki === null || $wiki === 'metawiki'
		);

		$manager = $this->createMock( ExperimentManager::class );
		$manager->method( 'getExperimentsByUnit' )->willReturn( $experiments );
		$manager->method( 'getExperiment' )->willReturnCallback(
			static fn ( string $name ) => $experiments[$name] ?? null
		);

		$dataStore = $this->createMock( ExperimentDataStore::class );
		$dataStore->method( 'isAvailable' )->willReturn( true );
		$dataStore->method( 'getSyncRecords' )->willReturn( $records );
		$dataStore->method( 'saveSyncRecord' )->willReturnCallback( function ( $name, $fingerprint, $state ) {
			$this->saved[$name] = $fingerprint;
		} );
		$dataStore->method( 'deleteSyncRecord' )->willReturnCallback( function ( $name ) {
			$this->deleted[] = $name;
		} );

		$queue = $this->createMock( JobQueueGroup::class );
		$queue->method( 'push' )->willReturnCallback( function ( JobSpecification $job ) {
			$this->pushed[] = $job->getParams();
		} );
		$queueFactory = $this->createMock( JobQueueGroupFactory::class );
		$queueFactory->method( 'makeJobQueueGroup' )->willReturn( $queue );

		$registry = $this->createMock( ExtensionRegistry::class );
		$registry->method( 'isLoaded' )->willReturn( true );

		$conf = new SiteConfiguration();
		$conf->settings = $siteSettings;

		return new WikiRollout(
			$manager,
			new UnitFactory( $farm, 'secret' ),
			$this->createMock( ExperimentTracker::class ),
			$dataStore,
			$farm,
			new HashConfig( [ 'ManageWikiExtensions' => self::EXTENSIONS ] ),
			$registry,
			$queueFactory,
			$this->createMock( ILBFactory::class ),
			new NullLogger(),
			$conf
		);
	}

	private static function experiment( array $spec = [] ): Experiment {
		return Experiment::newFromArray( 'dt', $spec + [
			'unit' => 'wiki',
			'active' => true,
			'rollout' => 100,
			'variants' => [
				'control' => 50,
				'treatment' => [ 'weight' => 50, 'extensions' => [ 'discussiontools', 'linter' ] ],
			],
		] );
	}

	public static function provideEligibility(): iterable {
		yield 'has VisualEditor, gets the rest' => [ [ 'visualeditor' ], [], true ];
		yield 'misses a requirement' => [ [], [], false ];
		yield 'already has the extension' => [ [ 'visualeditor', 'discussiontools' ], [], false ];
		yield 'another experiment owns or opted out of it' => [ [ 'visualeditor', 'linter' ], [], false ];
		yield 'set the variable in ManageWiki' => [ [ 'visualeditor' ], [ 'wgFoo' => 1 ], false ];
	}

	/**
	 * @dataProvider provideEligibility
	 */
	public function testEligibility( array $ownKeys, array $ownSettings, bool $expected ): void {
		$experiment = self::experiment( [ 'variants' => [
			'control' => 50,
			'treatment' => [ 'weight' => 50, 'extensions' => [ 'discussiontools', 'linter' ], 'config' => [ 'wgFoo' => 2 ] ],
		] ] );
		$this->assertSame( $expected, $this->newRollout()->isEligible( $experiment, $ownKeys, $ownSettings, 'foowiki' ) );
	}

	public function testConflictsInEitherDirection(): void {
		$rollout = $this->newRollout();
		$poem = self::experiment( [ 'variants' => [ 'control' => 1, 'treatment' => [ 'weight' => 1, 'extensions' => [ 'poem' ] ] ] ] );
		$verse = self::experiment( [ 'variants' => [ 'control' => 1, 'treatment' => [ 'weight' => 1, 'extensions' => [ 'verse' ] ] ] ] );

		$this->assertFalse( $rollout->isEligible( $poem, [ 'verse' ], [] ) );
		$this->assertFalse( $rollout->isEligible( $verse, [ 'poem' ], [] ) );
		$this->assertTrue( $rollout->isEligible( $poem, [], [] ) );
	}

	public function testPerWikiSiteConfigurationCountsAsTheWikisOwnChoice(): void {
		$experiment = self::experiment( [ 'variants' => [
			'control' => 1,
			'treatment' => [ 'weight' => 1, 'config' => [ 'wgWikiOasisMagicEnableNewOnboarding' => true ] ],
		] ] );
		$rollout = $this->newRollout( [], [], [
			'wgWikiOasisMagicEnableNewOnboarding' => [ 'default' => false, 'pinnedwiki' => true ],
		] );

		$this->assertFalse( $rollout->isEligible( $experiment, [], [], 'pinnedwiki' ) );
		$this->assertTrue( $rollout->isEligible( $experiment, [], [], 'otherwiki' ) );
	}

	public function testScopeFollowsTargetingAndPopulation(): void {
		$rollout = $this->newRollout();
		$experiment = self::experiment( [
			'excludeWikis' => [ '@central' ],
			'population' => 'new',
			'start' => '20261001000000',
		] );

		$this->assertTrue( $rollout->isInScope( $experiment, new ExperimentUnit( 'wiki', 'newwiki', '20261005000000' ) ) );
		$this->assertFalse( $rollout->isInScope( $experiment, new ExperimentUnit( 'wiki', 'oldwiki', '20250101000000' ) ) );
		$this->assertFalse( $rollout->isInScope( $experiment, new ExperimentUnit( 'wiki', 'metawiki', '20261005000000' ) ) );
	}

	public function testSweepQueuesOnlyWhatChanged(): void {
		$current = self::experiment();
		$probe = $this->newRollout( [ 'dt' => $current ] );
		$probe->sweep();
		$fingerprint = $this->saved['dt'];
		$this->assertCount( 1, $this->pushed );
		$this->assertNull( $this->pushed[0]['previous'] );

		$this->pushed = [];
		$this->saved = [];
		$record = $probe->makeRecord( $current );
		$unchanged = $this->newRollout( [ 'dt' => $current ], [ 'dt' => [ 'fingerprint' => $fingerprint, 'state' => $record ] ] );
		$this->assertSame( [], $unchanged->sweep() );
		$this->assertSame( [], $this->pushed );

		$ramped = $current->withState( [ 'rollout' => 50 ] );
		$changed = $this->newRollout( [ 'dt' => $ramped ], [ 'dt' => [ 'fingerprint' => $fingerprint, 'state' => $record ] ] );
		$this->assertSame( [ 'dt' => 'changed' ], $changed->sweep() );
		$this->assertSame( $record, $this->pushed[0]['previous'] );
	}

	public function testSweepCleansUpRemovedExperiments(): void {
		$record = [ 'spec' => self::experiment()->toSpec(), 'status' => 'running' ];
		$rollout = $this->newRollout( [], [ 'gone' => [ 'fingerprint' => 'x', 'state' => $record ] ] );

		$this->assertSame( [ 'gone' => 'removed' ], $rollout->sweep() );
		$this->assertTrue( $this->pushed[0]['cleanup'] );
		$this->assertSame( [ 'gone' ], $this->deleted );
	}

	public function testScheduledStartChangesTheFingerprint(): void {
		$scheduled = self::experiment( [ 'start' => '20991231000000' ] );
		$rollout = $this->newRollout();
		$this->assertSame( Experiment::STATUS_SCHEDULED, $rollout->makeRecord( $scheduled )['status'] );
		$this->assertSame( Experiment::STATUS_RUNNING, $rollout->makeRecord( self::experiment() )['status'] );
	}

	public function testSavingSyncsOnlyThatExperiment(): void {
		$saved = self::experiment();
		$other = self::experiment( [ 'rollout' => 10 ] );
		$this->newRollout( [ 'dt' => $saved, 'other' => $other ] )->syncExperiment( $saved );

		$this->assertCount( 1, $this->pushed );
		$this->assertSame( 'dt', $this->pushed[0]['experiment'] );
		$this->assertSame( [ 'dt' ], array_keys( $this->saved ) );
	}
}
