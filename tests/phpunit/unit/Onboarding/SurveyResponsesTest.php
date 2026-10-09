<?php

namespace WikiOasis\WikiOasisMagic\Tests\Onboarding;

use MediaWiki\User\Options\UserOptionsManager;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;
use Wikimedia\Stats\StatsFactory;
use WikiOasis\WikiOasisMagic\Experiments\Assignment;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentTracker;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentUnit;
use WikiOasis\WikiOasisMagic\Onboarding\OnboardingMetrics;
use WikiOasis\WikiOasisMagic\Onboarding\SurveyResponses;
use WikiOasis\WikiOasisMagic\Onboarding\SurveyValidator;

/**
 * @covers \WikiOasis\WikiOasisMagic\Onboarding\SurveyResponses
 */
class SurveyResponsesTest extends MediaWikiUnitTestCase {

	private function survey(): array {
		return ( new SurveyValidator() )->validate( [
			'questions' => [
				[
					'id' => 'goal',
					'type' => 'multi',
					'label' => 'Goal',
					'options' => [
						[ 'id' => 'create', 'label' => 'Create', 'action' => 'createwiki' ],
						[ 'id' => 'edit', 'label' => 'Edit' ],
						[ 'id' => 'other', 'label' => 'Other', 'freeText' => true ],
					],
				],
				[
					'id' => 'experience',
					'type' => 'single',
					'label' => 'Experience',
					'options' => [ [ 'id' => 'new', 'label' => 'New' ], [ 'id' => 'old', 'label' => 'Old' ] ],
				],
			],
		], true )->getValue();
	}

	private function newResponses(): SurveyResponses {
		return new SurveyResponses(
			$this->createMock( UserOptionsManager::class ),
			new OnboardingMetrics( StatsFactory::newNull() )
		);
	}

	public function testCleanKeepsOnlyKnownAnswers(): void {
		$clean = $this->newResponses()->clean(
			$this->survey(),
			[
				'goal' => [ 'edit', 'edit', 'hack', 'other' ],
				'experience' => [ 'new', 'old' ],
				'unknown' => [ 'x' ],
			],
			[ 'goal' => '  my own reason  ', 'experience' => 'ignored' ]
		);

		$this->assertSame( [ 'goal' => [ 'edit', 'other' ], 'experience' => [ 'new' ] ], $clean['answers'] );
		$this->assertSame( [ 'goal' => 'my own reason' ], $clean['freeText'] );
	}

	public function testFreeTextNeedsItsOption(): void {
		$clean = $this->newResponses()->clean( $this->survey(), [ 'goal' => [ 'edit' ] ], [ 'goal' => 'text' ] );
		$this->assertSame( [], $clean['freeText'] );
	}

	public function testCleanToleratesGarbage(): void {
		$clean = $this->newResponses()->clean( $this->survey(), 'nonsense', 42 );
		$this->assertSame( [ 'answers' => [], 'freeText' => [] ], $clean );
	}

	public function testWants(): void {
		$responses = $this->newResponses();
		$this->assertTrue( $responses->wants( $this->survey(), [ 'goal' => [ 'create' ] ], 'createwiki' ) );
		$this->assertFalse( $responses->wants( $this->survey(), [ 'goal' => [ 'edit' ] ], 'createwiki' ) );
	}

	public function testSelectCardsPutsMatchesFirstAndCaps(): void {
		$card = static fn ( string $id, array $when = [] ) => [ 'id' => $id, 'when' => $when ];
		$cards = [
			$card( 'always1' ),
			$card( 'edit', [ 'goal' => [ 'edit' ] ] ),
			$card( 'create', [ 'goal' => [ 'create' ] ] ),
			$card( 'always2' ),
			$card( 'never', [ '_never' => [] ] ),
			$card( 'always3' ),
			$card( 'always4' ),
			$card( 'always5' ),
			$card( 'always6' ),
		];

		$ids = array_column( SurveyResponses::selectCards( $cards, [ 'goal' => [ 'edit' ] ] ), 'id' );
		$this->assertSame( [ 'edit', 'always1', 'always2', 'always3', 'always4', 'always5' ], $ids );
	}

	public static function provideCountedOnce(): iterable {
		yield 'two submissions count once' => [ [ false, false ], 1 ];
		yield 'a skip, then a submission, counts the submission' => [ [ true, false ], 1 ];
		yield 'a submission, then a skip, counts nothing more' => [ [ false, true ], 1 ];
		yield 'skips are never survey events' => [ [ true, true ], 0 ];
	}

	/**
	 * @dataProvider provideCountedOnce
	 */
	public function testSurveyEventsAreCountedOncePerUser( array $skips, int $events ): void {
		$stored = '';
		$options = $this->createMock( UserOptionsManager::class );
		$options->method( 'getOption' )->willReturnCallback( static function () use ( &$stored ) {
			return $stored;
		} );
		$options->method( 'setOption' )->willReturnCallback( static function ( $user, $name, $value ) use ( &$stored ) {
			$stored = $value;
		} );

		$tracker = $this->createMock( ExperimentTracker::class );
		$tracker->expects( $this->exactly( $events ) )->method( 'recordEvent' );

		$responses = new SurveyResponses(
			$options,
			new OnboardingMetrics( StatsFactory::newNull() ),
			$tracker
		);

		$user = new UserIdentityValue( 1, 'Alice' );
		$assignment = new Assignment( 'onboarding', 'treatment', true, false, [], new ExperimentUnit( 'user', 'Alice' ) );
		$clean = $responses->clean( $this->survey(), [ 'goal' => [ 'edit' ] ], [] );
		foreach ( $skips as $skipped ) {
			$responses->record( $user, $assignment, 'local', 'local', $clean, $skipped );
		}
	}
}
