<?php

namespace WikiOasis\WikiOasisMagic\Tests\Experiments;

use MediaWiki\Config\HashConfig;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\MainConfigNames;
use MediaWiki\Request\WebRequest;
use MediaWiki\Session\Session;
use MediaWikiUnitTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use WikiOasis\WikiOasisMagic\Experiments\ConfigNames;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentManager;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentStateStore;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentUnit;
use WikiOasis\WikiOasisMagic\Experiments\UnitFactory;
use WikiOasis\WikiOasisMagic\Experiments\WikiFarm;

/**
 * @covers \WikiOasis\WikiOasisMagic\Experiments\ExperimentManager
 */
class ExperimentManagerTest extends MediaWikiUnitTestCase {

	private const EXPERIMENT = [
		'onboarding' => [
			'active' => true,
			'rollout' => 100,
			'default' => 'control',
			'variants' => [ 'control' => 50, 'treatment' => 50 ],
		],
	];

	private function newManager( array $experiments, array $state = [], bool $central = false ): ExperimentManager {
		$store = $this->createMock( ExperimentStateStore::class );
		$store->method( 'getState' )->willReturnCallback(
			static fn ( string $name ) => $state[$name] ?? []
		);

		$farm = $this->createMock( WikiFarm::class );
		$farm->method( 'getCurrentWiki' )->willReturn( $central ? 'metawiki' : 'foowiki' );
		$farm->method( 'getCentralWiki' )->willReturn( 'metawiki' );
		$farm->method( 'isCentralWiki' )->willReturnCallback(
			static fn ( ?string $wiki = null ) => $wiki === null ? $central : $wiki === 'metawiki'
		);

		return new ExperimentManager(
			new ServiceOptions(
				ExperimentManager::CONSTRUCTOR_OPTIONS,
				new HashConfig( [
					ConfigNames::EXPERIMENTS => $experiments,
					ConfigNames::HASH_SECRET => 'test-secret',
					MainConfigNames::SecretKey => '',
				] )
			),
			$store,
			$farm,
			new UnitFactory( $farm, 'test-secret' )
		);
	}

	private static function user( string $name, ?string $created = null ): ExperimentUnit {
		return new ExperimentUnit( ExperimentUnit::USER, $name, $created );
	}

	public function testBucketIsDeterministicAndInRange(): void {
		$a = ExperimentManager::bucket( 'salt', 'Alice' );
		$this->assertSame( $a, ExperimentManager::bucket( 'salt', 'Alice' ) );
		$this->assertNotSame( $a, ExperimentManager::bucket( 'other-salt', 'Alice' ) );

		for ( $i = 0; $i < 1000; $i++ ) {
			$point = ExperimentManager::bucket( 'salt', "unit-$i" );
			$this->assertGreaterThanOrEqual( 0, $point );
			$this->assertLessThan( 100, $point );
		}
	}

	public function testAssignmentIsStable(): void {
		$manager = $this->newManager( self::EXPERIMENT );
		$first = $manager->assign( 'onboarding', self::user( 'Alice' ) );

		$this->assertTrue( $first->enrolled );
		$this->assertFalse( $first->forced );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertSame( $first->variant, $manager->assign( 'onboarding', self::user( 'Alice' ) )->variant );
		}
	}

	public function testSplitIsRoughlyEven(): void {
		$manager = $this->newManager( self::EXPERIMENT );
		$counts = $manager->simulate( $manager->getExperiment( 'onboarding' ), 20000 );

		$this->assertEqualsWithDelta( 10000, $counts['treatment']['enrolled'], 400 );
	}

	public function testRampingUpNeverMovesEnrolledUnits(): void {
		$small = $this->newManager( self::EXPERIMENT, [ 'onboarding' => [ 'rollout' => 10 ] ] );
		$large = $this->newManager( self::EXPERIMENT, [ 'onboarding' => [ 'rollout' => 60 ] ] );

		$enrolledInSmall = 0;
		for ( $i = 0; $i < 2000; $i++ ) {
			$before = $small->assign( 'onboarding', self::user( "user-$i" ) );
			$after = $large->assign( 'onboarding', self::user( "user-$i" ) );
			if ( $before->enrolled ) {
				$enrolledInSmall++;
				$this->assertTrue( $after->enrolled );
				$this->assertSame( $before->variant, $after->variant );
			}
		}
		$this->assertEqualsWithDelta( 200, $enrolledInSmall, 60 );
	}

	public function testInactiveExperimentServesDefault(): void {
		$manager = $this->newManager( self::EXPERIMENT, [ 'onboarding' => [ 'active' => false, 'default' => 'treatment' ] ] );
		$assignment = $manager->assign( 'onboarding', self::user( 'Alice' ) );

		$this->assertSame( 'treatment', $assignment->variant );
		$this->assertFalse( $assignment->enrolled );
	}

	public function testOutsideDatesServesDefault(): void {
		ConvertibleTimestamp::setFakeTime( '20261001000000' );
		$manager = $this->newManager( [ 'onboarding' => self::EXPERIMENT['onboarding'] + [ 'start' => '20261010000000' ] ] );
		$this->assertFalse( $manager->assign( 'onboarding', self::user( 'Alice' ) )->enrolled );

		ConvertibleTimestamp::setFakeTime( '20261011000000' );
		$this->assertTrue( $manager->assign( 'onboarding', self::user( 'Alice' ) )->enrolled );
	}

	public function testWikiTargetingForUserExperiments(): void {
		$experiments = [ 'onboarding' => self::EXPERIMENT['onboarding'] + [ 'wikis' => [ '@central' ] ] ];

		$this->assertFalse( $this->newManager( $experiments, [], false )->assign( 'onboarding', self::user( 'Alice' ) )->enrolled );
		$this->assertTrue( $this->newManager( $experiments, [], true )->assign( 'onboarding', self::user( 'Alice' ) )->enrolled );
	}

	public function testWikiExperimentsTargetTheWikiBeingAssigned(): void {
		$manager = $this->newManager( [ 'rollout' => [
			'unit' => 'wiki',
			'active' => true,
			'rollout' => 100,
			'variants' => [ 'control' => 0, 'treatment' => 1 ],
			'excludeWikis' => [ '@central' ],
		] ] );

		$this->assertTrue( $manager->assign( 'rollout', new ExperimentUnit( 'wiki', 'barwiki' ) )->enrolled );
		$this->assertFalse( $manager->assign( 'rollout', new ExperimentUnit( 'wiki', 'metawiki' ) )->enrolled );
	}

	public function testPopulationLimitsEnrolment(): void {
		ConvertibleTimestamp::setFakeTime( '20261101000000' );
		$manager = $this->newManager( [ 'rollout' => [
			'unit' => 'wiki',
			'active' => true,
			'rollout' => 100,
			'population' => 'new',
			'start' => '20261010000000',
			'variants' => [ 'control' => 0, 'treatment' => 1 ],
		] ] );

		$this->assertTrue( $manager->assign( 'rollout', new ExperimentUnit( 'wiki', 'newwiki', '20261020000000' ) )->enrolled );
		$this->assertFalse( $manager->assign( 'rollout', new ExperimentUnit( 'wiki', 'oldwiki', '20250101000000' ) )->enrolled );
	}

	public function testUnitOfTheWrongTypeIsNeverEnrolled(): void {
		$manager = $this->newManager( self::EXPERIMENT );
		$this->assertFalse( $manager->assign( 'onboarding', new ExperimentUnit( 'wiki', 'foowiki' ) )->enrolled );
	}

	public function testRemovedExperimentMeansNewExperience(): void {
		$assignment = $this->newManager( [] )->assign( 'onboarding', self::user( 'Alice' ) );

		$this->assertFalse( $assignment->enrolled );
		$this->assertFalse( $assignment->isLegacy() );
	}

	public function testEmptyUnitIsNeverEnrolled(): void {
		$this->assertFalse( $this->newManager( self::EXPERIMENT )->assign( 'onboarding', self::user( '' ) )->enrolled );
	}

	private function newRequest( array $params ): WebRequest {
		$data = [];
		$session = $this->createMock( Session::class );
		$session->method( 'get' )->willReturnCallback( static function ( $key ) use ( &$data ) {
			return $data[$key] ?? null;
		} );
		$session->method( 'set' )->willReturnCallback( static function ( $key, $value ) use ( &$data ) {
			$data[$key] = $value;
		} );

		$request = $this->createMock( WebRequest::class );
		$request->method( 'getRawVal' )->willReturnCallback( static fn ( $name ) => $params[$name] ?? null );
		$request->method( 'getSession' )->willReturn( $session );
		return $request;
	}

	public function testOverridesNeedASignedToken(): void {
		$manager = $this->newManager( [ 'onboarding' => self::EXPERIMENT['onboarding'] + [ 'active' => false ] ] );

		$unsigned = $this->newRequest( [ ExperimentManager::OVERRIDE_PARAM => 'onboarding:treatment' ] );
		$this->assertFalse( $manager->assign( 'onboarding', self::user( 'Alice' ), $unsigned )->forced );

		$forged = $this->newRequest( [
			ExperimentManager::OVERRIDE_PARAM => 'onboarding:treatment',
			ExperimentManager::OVERRIDE_TOKEN_PARAM => '9999999999.0123456789abcdef0123456789abcdef',
		] );
		$this->assertFalse( $manager->assign( 'onboarding', self::user( 'Alice' ), $forged )->forced );

		$signed = $this->newRequest( $manager->makeOverrideQuery( 'onboarding:treatment' ) );
		$assignment = $manager->assign( 'onboarding', self::user( 'Alice' ), $signed );
		$this->assertTrue( $assignment->forced );
		$this->assertSame( 'treatment', $assignment->variant );

		$tampered = $this->newRequest( [
			ExperimentManager::OVERRIDE_PARAM => 'onboarding:control',
		] + $manager->makeOverrideQuery( 'onboarding:treatment' ) );
		$this->assertFalse( $manager->assign( 'onboarding', self::user( 'Bob' ), $tampered )->forced );

		ConvertibleTimestamp::setFakeTime( (int)ConvertibleTimestamp::now( TS_UNIX ) - 3 * 86400 );
		$expired = $manager->makeOverrideQuery( 'onboarding:treatment' );
		ConvertibleTimestamp::setFakeTime( false );
		$this->assertFalse( $manager->assign( 'onboarding', self::user( 'Carol' ), $this->newRequest( $expired ) )->forced );
	}
}
