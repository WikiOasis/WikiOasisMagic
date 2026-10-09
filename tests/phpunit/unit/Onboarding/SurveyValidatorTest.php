<?php

namespace WikiOasis\WikiOasisMagic\Tests\Onboarding;

use MediaWikiUnitTestCase;
use WikiOasis\WikiOasisMagic\Onboarding\SurveyValidator;

/**
 * @covers \WikiOasis\WikiOasisMagic\Onboarding\SurveyValidator
 * @covers \WikiOasis\WikiOasisMagic\Onboarding\SurveyText
 */
class SurveyValidatorTest extends MediaWikiUnitTestCase {

	private function survey( array $overrides = [] ): array {
		return $overrides + [
			'questions' => [ [
				'id' => 'goal',
				'type' => 'multi',
				'label' => 'What brings you here?',
				'options' => [
					[ 'id' => 'create', 'label' => 'Start a wiki', 'action' => 'createwiki', 'icon' => 'cdxIconAdd' ],
					[ 'id' => 'edit', 'label' => [ 'msg' => 'some-message' ] ],
					[ 'id' => 'other', 'label' => [ 'en' => 'Other', 'de' => 'Andere' ], 'freeText' => true ],
				],
			] ],
			'cards' => [ [
				'id' => 'random',
				'title' => 'Random page',
				'page' => 'Special:Random',
				'when' => [ 'goal' => [ 'edit' ] ],
			] ],
		];
	}

	public function testValidSurveyIsNormalised(): void {
		$status = ( new SurveyValidator() )->validate( $this->survey(), true );

		$this->assertStatusGood( $status );
		$survey = $status->getValue();
		$this->assertSame( [ 'goal' ], array_keys( $survey['questions'] ) );
		$this->assertSame( 'cards', $survey['questions']['goal']['style'] );
		$this->assertSame( 'createwiki', $survey['questions']['goal']['options']['create']['action'] );
		$this->assertTrue( $survey['questions']['goal']['options']['other']['freeText'] );
		$this->assertSame( [ 'goal' => [ 'edit' ] ], $survey['cards']['random']['when'] );
	}

	public function testWikiRequestOptionsAreDroppedOutsideTheCentralWiki(): void {
		$status = ( new SurveyValidator() )->validate( $this->survey(), false );

		$this->assertStatusOK( $status );
		$this->assertArrayNotHasKey( 'create', $status->getValue()['questions']['goal']['options'] );
		$this->assertStatusWarning( 'wikioasismagic-onboarding-survey-dropped', $status );
	}

	public function testQuestionWithOnlyWikiRequestOptionsIsDroppedOutsideTheCentralWiki(): void {
		$survey = $this->survey();
		$survey['questions'][] = [
			'id' => 'wiki',
			'label' => 'Which?',
			'options' => [ [ 'id' => 'create', 'label' => 'Create', 'action' => 'createwiki' ] ],
		];

		$status = ( new SurveyValidator() )->validate( $survey, false );
		$this->assertStatusOK( $status );
		$this->assertArrayNotHasKey( 'wiki', $status->getValue()['questions'] );
	}

	public static function provideInvalidSurveys(): iterable {
		yield 'not an object' => [ [ 'a', 'b' ] ];
		yield 'no questions' => [ [ 'questions' => [] ] ];
		yield 'bad question id' => [ [ 'questions' => [ [ 'id' => 'Goal!', 'label' => 'x', 'options' => [ [ 'id' => 'a', 'label' => 'a' ] ] ] ] ] ];
		yield 'bad type' => [ [ 'questions' => [ [ 'id' => 'q', 'type' => 'many', 'label' => 'x', 'options' => [ [ 'id' => 'a', 'label' => 'a' ] ] ] ] ] ];
		yield 'empty label' => [ [ 'questions' => [ [ 'id' => 'q', 'label' => '  ', 'options' => [ [ 'id' => 'a', 'label' => 'a' ] ] ] ] ] ];
		yield 'duplicate option' => [ [ 'questions' => [ [ 'id' => 'q', 'label' => 'x', 'options' => [
			[ 'id' => 'a', 'label' => 'a' ], [ 'id' => 'a', 'label' => 'b' ],
		] ] ] ] ];
		yield 'unknown action' => [ [ 'questions' => [ [ 'id' => 'q', 'label' => 'x', 'options' => [
			[ 'id' => 'a', 'label' => 'a', 'action' => 'deletewiki' ],
		] ] ] ] ];
		yield 'bad icon' => [ [ 'questions' => [ [ 'id' => 'q', 'label' => 'x', 'options' => [
			[ 'id' => 'a', 'label' => 'a', 'icon' => '<script>' ],
		] ] ] ] ];
		yield 'bad language code' => [ [ 'questions' => [ [ 'id' => 'q', 'label' => [ 'Not A Code' => 'x' ], 'options' => [
			[ 'id' => 'a', 'label' => 'a' ],
		] ] ] ] ];
		yield 'card with page and url' => [ [
			'questions' => [ [ 'id' => 'q', 'label' => 'x', 'options' => [ [ 'id' => 'a', 'label' => 'a' ] ] ] ],
			'cards' => [ [ 'id' => 'c', 'title' => 't', 'page' => 'Main Page', 'url' => 'https://example.org' ] ],
		] ];
		yield 'card with javascript url' => [ [
			'questions' => [ [ 'id' => 'q', 'label' => 'x', 'options' => [ [ 'id' => 'a', 'label' => 'a' ] ] ] ],
			'cards' => [ [ 'id' => 'c', 'title' => 't', 'url' => 'javascript:alert(1)' ] ],
		] ];
		yield 'too many questions' => [ [ 'questions' => array_map(
			static fn ( $i ) => [ 'id' => "q$i", 'label' => 'x', 'options' => [ [ 'id' => 'a', 'label' => 'a' ] ] ],
			range( 1, SurveyValidator::MAX_QUESTIONS + 1 )
		) ] ];
	}

	/**
	 * @dataProvider provideInvalidSurveys
	 */
	public function testInvalidSurveysAreRejected( array $survey ): void {
		$status = ( new SurveyValidator() )->validate( $survey, true );
		$this->assertStatusNotOK( $status );
	}

	public function testCardConditionsOnMissingOptionsNeverShow(): void {
		$survey = $this->survey();
		$survey['cards'][0]['when'] = [ 'goal' => [ 'nope' ] ];

		$status = ( new SurveyValidator() )->validate( $survey, true );
		$this->assertStatusOK( $status );
		$this->assertSame( [ '_never' => [] ], $status->getValue()['cards']['random']['when'] );
	}
}
