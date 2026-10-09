<?php

namespace WikiOasis\WikiOasisMagic\Onboarding;

use MediaWiki\Json\FormatJson;
use MediaWiki\User\Options\UserOptionsManager;
use MediaWiki\User\UserIdentity;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use WikiOasis\WikiOasisMagic\Experiments\Assignment;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentTracker;
use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function mb_substr;
use function trim;

class SurveyResponses {

	public const OPTION = 'wikioasismagic-onboarding';

	private const MAX_FREE_TEXT = 200;
	private const MAX_CARDS_SHOWN = 6;

	public function __construct(
		private readonly UserOptionsManager $userOptionsManager,
		private readonly OnboardingMetrics $metrics,
		private readonly ?ExperimentTracker $tracker = null,
	) {
	}

	/**
	 * @param UserIdentity $user
	 * @param Assignment $assignment
	 * @param string $context
	 * @param string $surveyName
	 * @param array{answers:array<string,string[]>,freeText:array<string,string>} $clean
	 * @param bool $skipped
	 */
	public function record(
		UserIdentity $user,
		Assignment $assignment,
		string $context,
		string $surveyName,
		array $clean,
		bool $skipped
	): void {
		$previous = $this->get( $user );
		$wasSkipped = $previous !== null && ( $previous['skipped'] ?? false );
		$wasSubmitted = $previous !== null && !$wasSkipped;

		$this->save( $user, [
			'survey' => $surveyName,
			'variant' => $assignment->variant,
			'answers' => $skipped ? [] : $clean['answers'],
			'freeText' => $skipped ? [] : $clean['freeText'],
			'skipped' => $skipped,
		] );

		if ( $wasSubmitted || ( $skipped && $wasSkipped ) ) {
			return;
		}

		$this->metrics->survey( $assignment, $context, $skipped ? 'skipped' : 'submitted' );
		if ( $skipped ) {
			return;
		}

		$this->tracker?->recordEvent( $assignment, 'survey' );

		foreach ( $clean['answers'] as $questionId => $optionIds ) {
			foreach ( $optionIds as $optionId ) {
				$this->metrics->surveyAnswer( $assignment, $context, $questionId, $optionId );
			}
		}
	}

	/**
	 * @param array $survey
	 * @param mixed $answers
	 * @param mixed $freeText
	 * @return array{answers:array<string,string[]>,freeText:array<string,string>}
	 */
	public function clean( array $survey, mixed $answers, mixed $freeText ): array {
		$cleanAnswers = [];
		$cleanText = [];
		$answers = is_array( $answers ) ? $answers : [];
		$freeText = is_array( $freeText ) ? $freeText : [];

		foreach ( $survey['questions'] as $questionId => $question ) {
			$picked = $answers[$questionId] ?? [];
			$picked = is_array( $picked ) ? $picked : [ $picked ];

			$valid = [];
			foreach ( $picked as $optionId ) {
				if ( is_string( $optionId ) && isset( $question['options'][$optionId] ) &&
					!in_array( $optionId, $valid, true )
				) {
					$valid[] = $optionId;
				}
			}

			if ( $question['type'] === 'single' ) {
				$valid = array_slice( $valid, 0, 1 );
			}

			if ( !$valid ) {
				continue;
			}

			$cleanAnswers[$questionId] = $valid;

			foreach ( $valid as $optionId ) {
				$text = $freeText[$questionId] ?? null;
				if ( $question['options'][$optionId]['freeText'] && is_string( $text ) && trim( $text ) !== '' ) {
					$cleanText[$questionId] = mb_substr( trim( $text ), 0, self::MAX_FREE_TEXT );
				}
			}
		}

		return [ 'answers' => $cleanAnswers, 'freeText' => $cleanText ];
	}

	/**
	 * @param array $survey
	 * @param array<string,string[]> $answers
	 */
	public function wants( array $survey, array $answers, string $action ): bool {
		foreach ( $answers as $questionId => $optionIds ) {
			foreach ( $optionIds as $optionId ) {
				if ( ( $survey['questions'][$questionId]['options'][$optionId]['action'] ?? null ) === $action ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * @param array $cards
	 * @param array<string,string[]> $answers
	 * @return array
	 */
	public static function selectCards( array $cards, array $answers ): array {
		$matched = [];
		$always = [];
		foreach ( $cards as $card ) {
			if ( !$card['when'] ) {
				$always[] = $card;
				continue;
			}

			foreach ( $card['when'] as $questionId => $optionIds ) {
				foreach ( $optionIds as $optionId ) {
					if ( in_array( $optionId, $answers[$questionId] ?? [], true ) ) {
						$matched[] = $card;
						continue 3;
					}
				}
			}
		}

		return array_slice( [ ...$matched, ...$always ], 0, self::MAX_CARDS_SHOWN );
	}

	public function get( UserIdentity $user ): ?array {
		$value = $this->userOptionsManager->getOption( $user, self::OPTION );
		if ( !is_string( $value ) || $value === '' ) {
			return null;
		}

		$decoded = FormatJson::decode( $value, true );
		return is_array( $decoded ) ? self::normalise( $decoded ) : null;
	}

	private static function normalise( array $response ): array {
		$answers = [];
		foreach ( (array)( $response['answers'] ?? [] ) as $question => $options ) {
			if ( is_string( $question ) && is_array( $options ) ) {
				$answers[$question] = array_values( array_filter( $options, 'is_string' ) );
			}
		}

		$freeText = [];
		foreach ( (array)( $response['freeText'] ?? [] ) as $question => $text ) {
			if ( is_string( $question ) && is_string( $text ) ) {
				$freeText[$question] = $text;
			}
		}

		return [
			'v' => 1,
			'survey' => is_string( $response['survey'] ?? null ) ? $response['survey'] : null,
			'variant' => is_string( $response['variant'] ?? null ) ? $response['variant'] : null,
			'answers' => $answers,
			'freeText' => $freeText,
			'skipped' => (bool)( $response['skipped'] ?? false ),
			'timestamp' => is_string( $response['timestamp'] ?? null ) ? $response['timestamp'] : null,
			'requests' => array_values( array_filter( (array)( $response['requests'] ?? [] ), 'is_int' ) ),
		];
	}

	/**
	 * @param UserIdentity $user
	 * @param array $response
	 */
	public function save( UserIdentity $user, array $response ): void {
		$previous = $this->get( $user ) ?? [];

		$this->store( $user, [
			'v' => 1,
			'survey' => $response['survey'] ?? null,
			'variant' => $response['variant'] ?? null,
			'answers' => $response['answers'] ?? [],
			'freeText' => $response['freeText'] ?? [],
			'skipped' => (bool)( $response['skipped'] ?? false ),
			'timestamp' => ConvertibleTimestamp::now( TS_MW ),
			'requests' => $previous['requests'] ?? [],
		] );
	}

	public function addRequest( UserIdentity $user, int $requestId ): void {
		$response = $this->get( $user ) ?? [ 'v' => 1 ];
		$requests = $response['requests'] ?? [];
		$requests[] = $requestId;
		$response['requests'] = array_values( array_slice( $requests, -10 ) );
		$this->store( $user, $response );
	}

	public function hasResponded( UserIdentity $user ): bool {
		$response = $this->get( $user );
		return $response !== null &&
			( ( $response['skipped'] ?? false ) || count( $response['answers'] ?? [] ) > 0 );
	}

	private function store( UserIdentity $user, array $response ): void {
		$this->userOptionsManager->setOption(
			$user,
			self::OPTION,
			FormatJson::encode( $response, false, FormatJson::ALL_OK )
		);
		$this->userOptionsManager->saveOptions( $user );
	}
}
