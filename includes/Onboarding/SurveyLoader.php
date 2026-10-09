<?php

namespace WikiOasis\WikiOasisMagic\Onboarding;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Content\TextContent;
use MediaWiki\Json\FormatJson;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use Psr\Log\LoggerInterface;
use StatusValue;
use Wikimedia\ObjectCache\WANObjectCache;
use WikiOasis\WikiOasisMagic\Experiments\Assignment;
use function array_keys;
use function is_array;
use function is_string;

class SurveyLoader {

	public const CONSTRUCTOR_OPTIONS = [
		ConfigNames::ALLOW_LOCAL_SURVEY,
		ConfigNames::SURVEYS,
	];

	public const PAGE = 'WikiOasisOnboarding.json';

	public const SOURCE_VARIANT = 'variant';
	public const SOURCE_PAGE = 'page';
	public const SOURCE_CONFIG = 'config';

	/** @var array<string,StatusValue> */
	private array $validated = [];

	public function __construct(
		private readonly ServiceOptions $options,
		private readonly OnboardingEnvironment $environment,
		private readonly SurveyValidator $validator,
		private readonly RevisionLookup $revisionLookup,
		private readonly WANObjectCache $cache,
		private readonly OnboardingMetrics $metrics,
		private readonly LoggerInterface $logger,
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	/**
	 * @return array{name:string,source:string,survey:array}
	 */
	public function getSurvey( Assignment $assignment ): array {
		$context = $this->environment->getContext();

		$variantSurvey = $assignment->getParam( 'survey' );
		if ( is_array( $variantSurvey ) ) {
			$variantSurvey = $variantSurvey[$context] ?? null;
		}
		if ( is_string( $variantSurvey ) ) {
			$survey = $this->getConfiguredSurvey( $variantSurvey );
			if ( $survey !== null ) {
				return [ 'name' => $variantSurvey, 'source' => self::SOURCE_VARIANT, 'survey' => $survey ];
			}
		}

		$page = $this->getPageSurvey();
		if ( $page !== null && $page->isOK() ) {
			return [ 'name' => 'page', 'source' => self::SOURCE_PAGE, 'survey' => $page->getValue() ];
		}

		return [
			'name' => $context,
			'source' => self::SOURCE_CONFIG,
			'survey' => $this->getConfiguredSurvey( $context ) ?? $this->getFallbackSurvey(),
		];
	}

	public function getConfiguredSurvey( string $name ): ?array {
		$status = $this->getConfiguredSurveyStatus( $name );
		return $status && $status->isOK() ? $status->getValue() : null;
	}

	public function getConfiguredSurveyStatus( string $name ): ?StatusValue {
		$surveys = $this->options->get( ConfigNames::SURVEYS );
		if ( !isset( $surveys[$name] ) ) {
			return null;
		}

		if ( !isset( $this->validated[$name] ) ) {
			$this->validated[$name] = $this->validator->validate(
				$surveys[$name],
				$this->environment->canRequestWikis()
			);

			if ( !$this->validated[$name]->isOK() ) {
				$this->metrics->error( 'survey_config_invalid' );
				$this->logger->error( 'Configured onboarding survey "{name}" is invalid: {errors}', [
					'name' => $name,
					'errors' => (string)$this->validated[$name],
				] );
			}
		}

		return $this->validated[$name];
	}

	/** @return string[] */
	public function getConfiguredSurveyNames(): array {
		return array_keys( (array)$this->options->get( ConfigNames::SURVEYS ) );
	}

	public function getPageSurvey(): ?StatusValue {
		if ( !$this->options->get( ConfigNames::ALLOW_LOCAL_SURVEY ) ) {
			return null;
		}

		$data = $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'wikioasismagic-onboarding-survey-page', 'v1' ),
			WANObjectCache::TTL_DAY,
			fn () => [ 'json' => $this->loadPageText() ],
			[ 'checkKeys' => [ $this->getPageCheckKey() ], 'pcTTL' => WANObjectCache::TTL_PROC_LONG ]
		);

		if ( !is_string( $data['json'] ?? null ) ) {
			return null;
		}

		if ( !isset( $this->validated['@page'] ) ) {
			$this->validated['@page'] = $this->validateJson( $data['json'] );
			if ( !$this->validated['@page']->isOK() ) {
				$this->metrics->error( 'survey_page_invalid' );
			}
		}

		return $this->validated['@page'];
	}

	public function validateJson( string $json ): StatusValue {
		$decoded = FormatJson::parse( $json, FormatJson::FORCE_ASSOC );
		if ( !$decoded->isOK() ) {
			return StatusValue::newFatal( 'wikioasismagic-onboarding-survey-invalid', '(root)', 'is not valid JSON' );
		}

		return $this->validator->validate( $decoded->getValue(), $this->environment->canRequestWikis() );
	}

	public function purgePageCache(): void {
		$this->cache->touchCheckKey( $this->getPageCheckKey() );
		unset( $this->validated['@page'] );
	}

	private function loadPageText(): ?string {
		$revision = $this->revisionLookup->getRevisionByTitle( Title::makeTitle( NS_MEDIAWIKI, self::PAGE ) );
		$content = $revision?->getContent( SlotRecord::MAIN );
		return $content instanceof TextContent ? $content->getText() : null;
	}

	private function getPageCheckKey(): string {
		return $this->cache->makeKey( 'wikioasismagic-onboarding-survey-page-check' );
	}

	private function getFallbackSurvey(): array {
		return [
			'questions' => [
				'experience' => [
					'id' => 'experience',
					'type' => 'single',
					'style' => 'list',
					'label' => [ 'msg' => 'wikioasismagic-onboarding-q-experience' ],
					'help' => null,
					'options' => [
						'new' => [
							'id' => 'new',
							'label' => [ 'msg' => 'wikioasismagic-onboarding-o-experience-new' ],
							'description' => null, 'icon' => null, 'action' => null,
							'freeText' => false, 'tips' => true,
						],
						'edited' => [
							'id' => 'edited',
							'label' => [ 'msg' => 'wikioasismagic-onboarding-o-experience-edited' ],
							'description' => null, 'icon' => null, 'action' => null,
							'freeText' => false, 'tips' => false,
						],
					],
				],
			],
			'cards' => [],
			'finish' => [ 'title' => null, 'lead' => null ],
		];
	}
}
