<?php

namespace WikiOasis\WikiOasisMagic\Onboarding;

use Wikimedia\Stats\StatsFactory;
use WikiOasis\WikiOasisMagic\Experiments\Assignment;
use function preg_replace;
use function strtolower;
use function substr;
use function trim;

class OnboardingMetrics {

	private readonly StatsFactory $stats;

	public function __construct( StatsFactory $statsFactory ) {
		$this->stats = $statsFactory->withComponent( 'WikiOasisMagic' );
	}

	public function signupView( Assignment $assignment, bool $retry ): void {
		if ( !$assignment->isCounted() ) {
			return;
		}

		$this->stats->getCounter( 'onboarding_signup_views_total' )
			->setLabel( 'variant', self::label( $assignment->variant ) )
			->setLabel( 'enrolled', self::bool( $assignment->enrolled ) )
			->setLabel( 'retry', self::bool( $retry ) )
			->increment();
	}

	public function signup( Assignment $assignment ): void {
		if ( !$assignment->isCounted() ) {
			return;
		}

		$this->stats->getCounter( 'onboarding_signups_total' )
			->setLabel( 'variant', self::label( $assignment->variant ) )
			->setLabel( 'enrolled', self::bool( $assignment->enrolled ) )
			->increment();
	}

	public function assignment( Assignment $assignment, string $context ): void {
		if ( !$assignment->isCounted() ) {
			return;
		}

		$this->stats->getCounter( 'onboarding_assignments_total' )
			->setLabel( 'variant', self::label( $assignment->variant ) )
			->setLabel( 'enrolled', self::bool( $assignment->enrolled ) )
			->setLabel( 'context', self::label( $context ) )
			->increment();
	}

	public function stepView( Assignment $assignment, string $context, string $step ): void {
		if ( !$assignment->isCounted() ) {
			return;
		}

		$this->stats->getCounter( 'onboarding_step_views_total' )
			->setLabel( 'variant', self::label( $assignment->getFunnelLabel() ) )
			->setLabel( 'context', self::label( $context ) )
			->setLabel( 'step', self::label( $step ) )
			->increment();
	}

	public function stepDuration( Assignment $assignment, string $context, string $step, float $seconds ): void {
		if ( !$assignment->isCounted() ) {
			return;
		}

		$this->stats->getTiming( 'onboarding_step_duration_seconds' )
			->setLabel( 'variant', self::label( $assignment->getFunnelLabel() ) )
			->setLabel( 'context', self::label( $context ) )
			->setLabel( 'step', self::label( $step ) )
			->observeSeconds( $seconds );
	}

	public function survey( Assignment $assignment, string $context, string $outcome ): void {
		if ( !$assignment->isCounted() ) {
			return;
		}

		$this->stats->getCounter( 'onboarding_survey_total' )
			->setLabel( 'variant', self::label( $assignment->getFunnelLabel() ) )
			->setLabel( 'context', self::label( $context ) )
			->setLabel( 'outcome', self::label( $outcome ) )
			->increment();
	}

	public function surveyAnswer( Assignment $assignment, string $context, string $question, string $answer ): void {
		if ( !$assignment->isCounted() ) {
			return;
		}

		$this->stats->getCounter( 'onboarding_survey_answers_total' )
			->setLabel( 'variant', self::label( $assignment->getFunnelLabel() ) )
			->setLabel( 'context', self::label( $context ) )
			->setLabel( 'question', self::label( $question ) )
			->setLabel( 'answer', self::label( $answer ) )
			->increment();
	}

	public function cardClick( Assignment $assignment, string $context, string $card ): void {
		if ( !$assignment->isCounted() ) {
			return;
		}

		$this->stats->getCounter( 'onboarding_card_clicks_total' )
			->setLabel( 'variant', self::label( $assignment->getFunnelLabel() ) )
			->setLabel( 'context', self::label( $context ) )
			->setLabel( 'card', self::label( $card ) )
			->increment();
	}

	public function completion( Assignment $assignment, string $context, string $path ): void {
		if ( !$assignment->isCounted() ) {
			return;
		}

		$this->stats->getCounter( 'onboarding_completions_total' )
			->setLabel( 'variant', self::label( $assignment->getFunnelLabel() ) )
			->setLabel( 'context', self::label( $context ) )
			->setLabel( 'path', self::label( $path ) )
			->increment();
	}

	/**
	 * @param Assignment $assignment
	 * @param string $source
	 * @param string $outcome
	 */
	public function wikiRequest( Assignment $assignment, string $source, string $outcome ): void {
		if ( !$assignment->isCounted() ) {
			return;
		}

		$this->stats->getCounter( 'onboarding_wiki_requests_total' )
			->setLabel( 'variant', self::label( $assignment->variant ) )
			->setLabel( 'enrolled', self::bool( $assignment->enrolled ) )
			->setLabel( 'source', self::label( $source ) )
			->setLabel( 'outcome', self::label( $outcome ) )
			->increment();
	}

	public function newcomerEdit( Assignment $assignment, string $context, bool $first ): void {
		if ( !$assignment->isCounted() ) {
			return;
		}

		$this->stats->getCounter( 'onboarding_newcomer_edits_total' )
			->setLabel( 'variant', self::label( $assignment->variant ) )
			->setLabel( 'enrolled', self::bool( $assignment->enrolled ) )
			->setLabel( 'context', self::label( $context ) )
			->setLabel( 'first', self::bool( $first ) )
			->increment();
	}

	public function error( string $kind ): void {
		$this->stats->getCounter( 'onboarding_errors_total' )
			->setLabel( 'kind', self::label( $kind ) )
			->increment();
	}

	private static function label( string $value ): string {
		$value = trim( (string)preg_replace( '/[^a-z0-9]+/', '_', strtolower( $value ) ), '_' );
		return $value === '' ? 'unknown' : substr( $value, 0, 40 );
	}

	private static function bool( bool $value ): string {
		return $value ? 'yes' : 'no';
	}
}
