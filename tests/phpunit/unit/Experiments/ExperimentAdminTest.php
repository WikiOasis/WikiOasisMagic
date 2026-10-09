<?php

namespace WikiOasis\WikiOasisMagic\Tests\Experiments;

use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;
use WikiOasis\WikiOasisMagic\Experiments\Experiment;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentAdmin;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentManager;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentStateStore;
use WikiOasis\WikiOasisMagic\Experiments\WikiRollout;

/**
 * @covers \WikiOasis\WikiOasisMagic\Experiments\ExperimentAdmin
 */
class ExperimentAdminTest extends MediaWikiUnitTestCase {

	private function newAdmin( ?array $latest = null ): ExperimentAdmin {
		$configured = Experiment::newFromArray( 'onboarding', [
			'active' => false,
			'variants' => [ 'control' => 50, 'treatment' => 50 ],
		] );

		$manager = $this->createMock( ExperimentManager::class );
		$manager->method( 'getConfiguredExperiment' )->willReturn( $configured );
		$manager->method( 'getExperiment' )->willReturn( $configured );

		$store = $this->createMock( ExperimentStateStore::class );
		$store->method( 'isAvailable' )->willReturn( true );
		$store->method( 'getLatestEntry' )->willReturn( $latest );

		return new ExperimentAdmin( $manager, $store, $this->createMock( WikiRollout::class ) );
	}

	private static function experiment(): Experiment {
		return Experiment::newFromArray( 'onboarding', [ 'variants' => [ 'control' => 50, 'treatment' => 50 ] ] );
	}

	public function testPartialWeightsAreCheckedAgainstTheWholeSplit(): void {
		$status = $this->newAdmin()->validateState( self::experiment(), [ 'weights' => [ 'treatment' => 0 ] ] );
		$this->assertTrue( $status->isOK() );
		$this->assertSame( [ 'treatment' => 0.0, 'control' => 50.0 ], $status->getValue()['weights'] );

		$this->assertFalse( $this->newAdmin()->validateState(
			self::experiment(),
			[ 'weights' => [ 'treatment' => 0, 'control' => 0 ] ]
		)->isOK() );
	}

	public function testRejectsUnknownFieldsAndBadValues(): void {
		$admin = $this->newAdmin();
		$this->assertFalse( $admin->validateState( self::experiment(), [ 'salt' => 'x' ] )->isOK() );
		$this->assertFalse( $admin->validateState( self::experiment(), [ 'rollout' => 101 ] )->isOK() );
		$this->assertFalse( $admin->validateState( self::experiment(), [ 'default' => 'nope' ] )->isOK() );
		$this->assertFalse( $admin->validateState( self::experiment(), [ 'wikis' => [ 'Bad Wiki' ] ] )->isOK() );
		$this->assertFalse( $admin->validateState(
			self::experiment(),
			[ 'start' => '2026-10-10T00:00:00Z', 'end' => '2026-10-09T00:00:00Z' ]
		)->isOK() );
	}

	public function testConflictsAreDetectedByVersion(): void {
		$user = new UserIdentityValue( 1, 'Alice' );
		$latest = [ 'state' => [ 'active' => true ], 'user' => 'Bob', 'timestamp' => '20261008000000', 'version' => 'abc' ];

		$stale = $this->newAdmin( $latest )->update( 'onboarding', [ 'rollout' => 10 ], '', $user, 'old' );
		$this->assertTrue( $stale->hasMessage( 'wikioasismagic-experiments-error-conflict' ) );

		$reset = $this->newAdmin( null )->update( 'onboarding', [ 'rollout' => 10 ], '', $user, 'abc' );
		$this->assertTrue( $reset->hasMessage( 'wikioasismagic-experiments-error-conflict-reset' ) );
	}
}
