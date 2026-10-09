<?php

namespace WikiOasis\WikiOasisMagic\Tests\Experiments;

use MediaWikiUnitTestCase;
use WikiOasis\WikiOasisMagic\Experiments\Assignment;
use WikiOasis\WikiOasisMagic\Experiments\Experiment;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentUnit;

/**
 * @covers \WikiOasis\WikiOasisMagic\Experiments\Experiment
 * @covers \WikiOasis\WikiOasisMagic\Experiments\Assignment
 */
class ExperimentTest extends MediaWikiUnitTestCase {

	private function newExperiment( array $spec = [] ): Experiment {
		return Experiment::newFromArray( 'onboarding', $spec + [
			'active' => true,
			'rollout' => 50,
			'default' => 'control',
			'variants' => [ 'control' => 50, 'treatment' => [ 'weight' => 50, 'params' => [ 'survey' => 'short' ] ] ],
		] );
	}

	public function testNormalisesConfiguration(): void {
		$experiment = $this->newExperiment();

		$this->assertTrue( $experiment->active );
		$this->assertSame( ExperimentUnit::USER, $experiment->unit );
		$this->assertSame( 50.0, $experiment->rollout );
		$this->assertSame( [ 'control', 'treatment' ], $experiment->getVariantNames() );
		$this->assertSame( [ 'survey' => 'short' ], $experiment->getParams( 'treatment' ) );
		$this->assertSame( 'onboarding', $experiment->salt );
		$this->assertSame( Experiment::POPULATION_ALL, $experiment->population );
		$this->assertSame( [], $experiment->getProblems() );
	}

	public function testRepairsBadConfigurationInsteadOfThrowing(): void {
		$experiment = Experiment::newFromArray( 'onboarding', [
			'unit' => 'planet',
			'active' => 'yes',
			'rollout' => 250,
			'default' => 'Not Valid',
			'variants' => [ 'BAD NAME' => 10, 'treatment' => 'heavy' ],
			'start' => 'not a date',
			'population' => 'old',
			'wikis' => [ 'Bad Wiki', 'goodwiki' ],
			'metrics' => [ 'edits' => [ 'type' => 'nonsense' ] ],
		] );

		$this->assertSame( ExperimentUnit::USER, $experiment->unit );
		$this->assertTrue( $experiment->active );
		$this->assertSame( 100.0, $experiment->rollout );
		$this->assertSame( 'control', $experiment->defaultVariant );
		$this->assertTrue( $experiment->hasVariant( 'control' ) );
		$this->assertFalse( $experiment->hasVariant( 'BAD NAME' ) );
		$this->assertNull( $experiment->start );
		$this->assertSame( Experiment::POPULATION_ALL, $experiment->population );
		$this->assertSame( [ 'goodwiki' ], $experiment->wikis );
		$this->assertSame( [], $experiment->metrics );
		$this->assertNotEmpty( $experiment->getProblems() );
	}

	public function testExtensionsAndConfigOnlyForWikiExperiments(): void {
		$variants = [
			'control' => 50,
			'treatment' => [
				'weight' => 50,
				'extensions' => [ 'discussiontools' ],
				'config' => [ 'wgWikiOasisMagicEnableNewOnboarding' => true, 'notAVariable' => 1 ],
			],
		];

		$wiki = $this->newExperiment( [ 'unit' => 'wiki', 'variants' => $variants ] );
		$this->assertSame( [ 'discussiontools' ], $wiki->getExtensions( 'treatment' ) );
		$this->assertSame( [ 'wgWikiOasisMagicEnableNewOnboarding' => true ], $wiki->getConfig( 'treatment' ) );
		$this->assertTrue( $wiki->changesWikiConfig() );
		$this->assertSame( [ 'wgWikiOasisMagicEnableNewOnboarding' ], $wiki->getAllConfigVariables() );

		$user = $this->newExperiment( [ 'unit' => 'user', 'variants' => $variants ] );
		$this->assertSame( [], $user->getExtensions( 'treatment' ) );
		$this->assertSame( [], $user->getConfig( 'treatment' ) );
		$this->assertFalse( $user->changesWikiConfig() );
		$this->assertNotEmpty( $user->getProblems() );
	}

	public function testPickVariantFollowsWeights(): void {
		$experiment = $this->newExperiment( [ 'variants' => [ 'control' => 1, 'treatment' => 3 ] ] );

		$this->assertSame( 'control', $experiment->pickVariant( 0.0 ) );
		$this->assertSame( 'control', $experiment->pickVariant( 24.9 ) );
		$this->assertSame( 'treatment', $experiment->pickVariant( 25.0 ) );
		$this->assertSame( 'treatment', $experiment->pickVariant( 99.999 ) );
	}

	public function testZeroWeightVariantsAreNeverPicked(): void {
		$experiment = $this->newExperiment( [ 'variants' => [ 'control' => 0, 'treatment' => 1 ] ] );

		foreach ( [ 0.0, 10.0, 50.0, 99.9 ] as $point ) {
			$this->assertSame( 'treatment', $experiment->pickVariant( $point ) );
		}
	}

	public function testStateChangesTheRolloutButNotTheVariants(): void {
		$experiment = $this->newExperiment( [ 'unit' => 'wiki', 'wikis' => [ '@central' ] ] )->withState( [
			'active' => false,
			'rollout' => 5,
			'default' => 'treatment',
			'weights' => [ 'control' => 10, 'treatment' => 90, 'unknown' => 50 ],
			'population' => 'new',
			'start' => '20261010000000',
			'wikis' => [ 'foowiki' ],
		] );

		$this->assertFalse( $experiment->active );
		$this->assertSame( 5.0, $experiment->rollout );
		$this->assertSame( 'treatment', $experiment->defaultVariant );
		$this->assertSame( 90.0, $experiment->getWeight( 'treatment' ) );
		$this->assertFalse( $experiment->hasVariant( 'unknown' ) );
		$this->assertSame( Experiment::POPULATION_NEW, $experiment->population );
		$this->assertSame( '20261010000000', $experiment->start );
		$this->assertSame( [ 'foowiki' ], $experiment->wikis );
	}

	public function testStateWithUnknownDefaultIsIgnored(): void {
		$experiment = $this->newExperiment()->withState( [ 'default' => 'nope' ] );
		$this->assertSame( 'control', $experiment->defaultVariant );
	}

	public function testDiffState(): void {
		$experiment = $this->newExperiment();
		$changed = $experiment->withState( [ 'rollout' => '50', 'weights' => [ 'treatment' => 80 ], 'end' => '20270101000000' ] );

		$this->assertSame( [ 'weights', 'end' ], Experiment::diffState( $experiment->getState(), $changed->getState() ) );
		$this->assertSame( [], Experiment::diffState( $experiment->getState(), $experiment->getState() ) );
	}

	public function testStatus(): void {
		$experiment = $this->newExperiment( [ 'start' => '20261010000000', 'end' => '20261020000000' ] );

		$this->assertSame( Experiment::STATUS_SCHEDULED, $experiment->getStatus( '20261009235959' ) );
		$this->assertSame( Experiment::STATUS_RUNNING, $experiment->getStatus( '20261010000000' ) );
		$this->assertSame( Experiment::STATUS_RUNNING, $experiment->getStatus( '20261019235959' ) );
		$this->assertSame( Experiment::STATUS_ENDED, $experiment->getStatus( '20261020000000' ) );
		$this->assertSame( Experiment::STATUS_OFF, $experiment->withState( [ 'active' => false ] )->getStatus( '20261015000000' ) );
	}

	public function testPopulation(): void {
		$new = $this->newExperiment( [ 'unit' => 'wiki', 'population' => 'new', 'start' => '20261010000000' ] );
		$this->assertTrue( $new->matchesPopulation( '20261011000000' ) );
		$this->assertFalse( $new->matchesPopulation( '20261001000000' ) );
		$this->assertFalse( $new->matchesPopulation( null ) );

		$existing = $new->withState( [ 'population' => 'existing' ] );
		$this->assertFalse( $existing->matchesPopulation( '20261011000000' ) );
		$this->assertTrue( $existing->matchesPopulation( '20261001000000' ) );
		$this->assertTrue( $existing->matchesPopulation( null ) );

		$browsers = $this->newExperiment( [ 'unit' => 'browser', 'population' => 'new' ] );
		$this->assertSame( Experiment::POPULATION_ALL, $browsers->population );
	}

	public static function provideWikiTargeting(): iterable {
		yield 'no list targets everything' => [ [], [], 'foowiki', false, true ];
		yield 'central only, on central' => [ [ '@central' ], [], 'metawiki', true, true ];
		yield 'central only, elsewhere' => [ [ '@central' ], [], 'foowiki', false, false ];
		yield 'local only, elsewhere' => [ [ '@local' ], [], 'foowiki', false, true ];
		yield 'local only, on central' => [ [ '@local' ], [], 'metawiki', true, false ];
		yield 'named wiki' => [ [ 'foowiki' ], [], 'foowiki', false, true ];
		yield 'excluded wiki' => [ [], [ 'foowiki' ], 'foowiki', false, false ];
		yield 'exclusion wins' => [ [ '@local' ], [ 'foowiki' ], 'foowiki', false, false ];
	}

	/**
	 * @dataProvider provideWikiTargeting
	 */
	public function testWikiTargeting( array $wikis, array $exclude, string $dbname, bool $central, bool $expected ): void {
		$experiment = $this->newExperiment( [ 'wikis' => $wikis, 'excludeWikis' => $exclude ] );
		$this->assertSame( $expected, $experiment->appliesToWiki( $dbname, $central ) );
	}

	public function testLegacyFollowsVariantNameUnlessParamsSayOtherwise(): void {
		$this->assertTrue( ( new Assignment( 'x', 'control', true ) )->isLegacy() );
		$this->assertFalse( ( new Assignment( 'x', 'treatment', true ) )->isLegacy() );
		$this->assertFalse( ( new Assignment( 'x', 'control', true, false, [ 'legacy' => false ] ) )->isLegacy() );
		$this->assertTrue( ( new Assignment( 'x', 'holdout', true, false, [ 'legacy' => true ] ) )->isLegacy() );
	}

	public function testOnlyRealUnassistedAssignmentsAreCounted(): void {
		$unit = new ExperimentUnit( ExperimentUnit::USER, 'Alice' );
		$this->assertTrue( ( new Assignment( 'x', 'control', true, false, [], $unit ) )->isCounted() );
		$this->assertFalse( ( new Assignment( 'x', 'control', true, true, [], $unit ) )->isCounted() );
		$this->assertFalse( ( new Assignment( 'x', 'control', true ) )->isCounted() );
		$this->assertFalse( ( new Assignment( 'x', 'control', true, false, [], new ExperimentUnit( 'user', '' ) ) )->isCounted() );
		$this->assertSame( 'none', ( new Assignment( 'x', 'treatment', false ) )->getFunnelLabel() );
		$this->assertSame( 'treatment', ( new Assignment( 'x', 'treatment', true ) )->getFunnelLabel() );
	}

	public function testStorageKeys(): void {
		$this->assertSame( 'foowiki', ( new ExperimentUnit( 'wiki', 'foowiki', null, 'secret' ) )->getStorageKey() );

		$salted = ( new ExperimentUnit( 'user', 'Alice', null, 'secret' ) )->getStorageKey();
		$this->assertSame( 32, strlen( $salted ) );
		$this->assertSame( $salted, ( new ExperimentUnit( 'user', 'Alice', null, 'secret' ) )->getStorageKey() );
		$this->assertNotSame( $salted, ( new ExperimentUnit( 'user', 'Alice', null, 'other' ) )->getStorageKey() );
		$this->assertNotSame( $salted, substr( hash( 'sha256', 'user:Alice' ), 0, 32 ) );
	}
}
