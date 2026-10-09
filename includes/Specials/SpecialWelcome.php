<?php

namespace WikiOasis\WikiOasisMagic\Specials;

use MediaWiki\Config\Config;
use MediaWiki\Html\Html;
use MediaWiki\Json\FormatJson;
use MediaWiki\Language\LanguageFallback;
use MediaWiki\Language\LanguageNameUtils;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\LinkBatchFactory;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use Miraheze\CreateWiki\ConfigNames as CreateWikiConfigNames;
use Wikimedia\Message\MessageSpecifier;
use WikiOasis\WikiOasisMagic\Experiments\Assignment;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentAdmin;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentManager;
use WikiOasis\WikiOasisMagic\Onboarding\ConfigNames;
use WikiOasis\WikiOasisMagic\Onboarding\OnboardingEnvironment;
use WikiOasis\WikiOasisMagic\Onboarding\OnboardingIcons;
use WikiOasis\WikiOasisMagic\Onboarding\OnboardingMetrics;
use WikiOasis\WikiOasisMagic\Onboarding\SurveyLoader;
use WikiOasis\WikiOasisMagic\Onboarding\SurveyResponses;
use WikiOasis\WikiOasisMagic\Onboarding\SurveyText;
use WikiOasis\WikiOasisMagic\Onboarding\SurveyValidator;
use WikiOasis\WikiOasisMagic\Onboarding\WikiRequestSubmitter;
use function array_filter;
use function array_map;
use function in_array;
use function is_string;
use function ksort;
use function preg_match;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;

class SpecialWelcome extends SpecialPage {

	private const RTL_LANGUAGES = [
		'ar', 'arc', 'arz', 'azb', 'bcc', 'bqi', 'ckb', 'dv', 'fa', 'glk', 'he', 'khw', 'lki',
		'lrc', 'luz', 'mzn', 'pnb', 'ps', 'sd', 'sdh', 'ug', 'ur', 'yi',
	];

	private Assignment $assignment;
	private array $survey;
	private string $context;
	private SurveyText $text;

	public function __construct(
		private readonly OnboardingEnvironment $environment,
		private readonly ExperimentManager $experimentManager,
		private readonly OnboardingMetrics $metrics,
		private readonly SurveyLoader $surveyLoader,
		private readonly SurveyResponses $responses,
		private readonly OnboardingIcons $icons,
		private readonly LanguageNameUtils $languageNameUtils,
		private readonly LanguageFallback $languageFallback,
		private readonly LinkBatchFactory $linkBatchFactory,
	) {
		parent::__construct( 'Welcome' );
	}

	public function execute( $subPage ): void {
		$this->setHeaders();
		$this->requireNamedUser( 'wikioasismagic-onboarding-login' );

		$out = $this->getOutput();
		$request = $this->getRequest();
		$user = $this->getUser();

		if ( !$this->environment->isEnabled() && !$this->getAuthority()->isAllowed( ExperimentAdmin::RIGHT ) ) {
			$out->addWikiMsg( 'wikioasismagic-onboarding-disabled' );
			$out->returnToMain();
			return;
		}

		$out->setPageTitleMsg( $this->msg( 'wikioasismagic-onboarding-title' ) );
		$out->setRobotPolicy( 'noindex,nofollow' );

		$this->assignment = $this->experimentManager->assignUser(
			OnboardingEnvironment::ONBOARDING_EXPERIMENT,
			$user,
			$request
		);
		$this->context = $this->environment->getContext();
		$loaded = $this->surveyLoader->getSurvey( $this->assignment );
		$this->survey = $loaded['survey'];
		$this->text = new SurveyText(
			$this->getContext(),
			[ $this->getLanguage()->getCode(), ...$this->languageFallback->getAll( $this->getLanguage()->getCode() ) ],
			$this->getConfig()->get( 'Sitename' )
		);

		$canRequestWikis = $this->environment->canRequestWikis();
		$submitter = $canRequestWikis ? $this->getSubmitter() : null;
		$blocker = $submitter?->getBlocker( $user );

		$step = 'survey';
		$previous = $this->responses->get( $user );
		$answers = $previous['answers'] ?? [];
		$freeText = $previous['freeText'] ?? [];
		$wantsWiki = false;

		if ( $request->wasPosted() && $request->getCheck( 'wpSurveyForm' ) &&
			$this->getContext()->getCsrfTokenSet()->matchTokenField()
		) {
			$skipped = $request->getCheck( 'wpSkip' );
			$clean = $this->responses->clean(
				$this->survey,
				$request->getArray( 'answers', [] ),
				$request->getArray( 'freetext', [] )
			);
			if ( !$user->pingLimiter( 'wikioasisonboarding-submit' ) ) {
				$this->responses->record( $user, $this->assignment, $this->context, $loaded['name'], $clean, $skipped );
				$this->metrics->completion( $this->assignment, $this->context, 'finish' );
			}

			$answers = $skipped ? [] : $clean['answers'];
			$freeText = $skipped ? [] : $clean['freeText'];
			$wantsWiki = $canRequestWikis && (
				$this->responses->wants( $this->survey, $answers, SurveyValidator::ACTION_CREATE_WIKI ) ||
				$this->responses->wants( $this->survey, $answers, SurveyValidator::ACTION_IMPORT_WIKI )
			);
			$step = 'finish';
		}

		$initialStep = $step;
		if ( $subPage === 'wiki' && $canRequestWikis && !$request->wasPosted() ) {
			$initialStep = $blocker ? 'wiki-blocked' : 'wiki-name';
		}

		$cards = $this->prepareCards();
		$returnUrl = $this->getReturnUrl();

		$out->addModuleStyles( [
			'ext.wikioasismagic.codex.styles',
			'ext.wikioasismagic.onboarding.styles',
		] );
		$out->addModules( 'ext.wikioasismagic.onboarding' );
		$out->addJsConfigVars( 'wgWikiOasisOnboarding', [
			'context' => $this->context,
			'variant' => $this->assignment->variant,
			'survey' => $loaded['name'],
			'initialStep' => $initialStep,
			'canRequestWiki' => $canRequestWikis && !$blocker,
			'wikiBlocked' => $canRequestWikis && $blocker !== null,
			'questions' => $this->getQuestionsForClient(),
			'cards' => array_map( static fn ( $card ) => $card['when'], $cards ),
			'reasonMinLength' => $canRequestWikis ?
				(int)$this->getCreateWikiConfig()->get( CreateWikiConfigNames::RequestWikiMinimumLength ) :
				0,
			'subdomainSuffix' => $canRequestWikis ?
				'.' . $this->getCreateWikiConfig()->get( CreateWikiConfigNames::Subdomain ) :
				'',
			'hasEmail' => $user->canReceiveEmail(),
			'returnUrl' => $returnUrl,
			'mainPage' => $this->msg( 'mainpage' )->inContentLanguage()->text(),
			'rtlLanguages' => self::RTL_LANGUAGES,
		] );

		$main = $this->renderSurvey( $answers, $freeText, $initialStep === 'survey' );
		if ( $canRequestWikis ) {
			$main .= $blocker ?
				$this->renderWikiBlocked( $blocker, $initialStep === 'wiki-blocked' ) :
				$this->renderWikiSteps( $initialStep === 'wiki-name' ) . $this->renderDone();
		}
		$main .= $this->renderFinish( $cards, $answers, $returnUrl, $wantsWiki, $initialStep === 'finish' );

		if ( str_starts_with( $initialStep, 'wiki-' ) ) {
			$main .= Html::rawElement( 'div', [ 'class' => 'wo-nojs-only' ],
				$this->message( 'notice', $this->msg( 'wikioasismagic-onboarding-wiki-nojs' )->parse() )
			);
		}

		$out->addHTML( Html::rawElement( 'div', [
			'class' => 'wo-onboarding',
			'id' => 'wo-onboarding',
			'data-context' => $this->context,
		], Html::rawElement( 'div', [ 'class' => 'wo-shell', 'id' => 'wo-shell' ],
			Html::rawElement( 'nav', [
				'class' => 'wo-rail',
				'aria-label' => $this->msg( 'wikioasismagic-onboarding-rail-label' )->text(),
			], Html::element( 'ol', [ 'id' => 'wo-rail' ] ) ) .
			Html::rawElement( 'div', [ 'class' => 'wo-main' ],
				Html::rawElement( 'div', [ 'class' => 'wo-mobile-progress', 'aria-hidden' => 'true' ],
					Html::element( 'div', [ 'class' => 'wo-mobile-progress__txt', 'id' => 'wo-mp-txt' ] ) .
					Html::rawElement( 'div', [ 'class' => 'wo-mobile-progress__bar' ],
						Html::element( 'span', [ 'id' => 'wo-mp-bar' ] )
					)
				) . $main
			) .
			( $canRequestWikis && !$blocker ? $this->renderPreview() : '' )
		) ) );
	}

	private function renderSurvey( array $answers, array $freeText, bool $active ): string {
		$user = $this->getUser();
		$body = '';
		foreach ( $this->survey['questions'] as $question ) {
			$body .= $this->renderQuestion( $question, $answers[$question['id']] ?? [], $freeText[$question['id']] ?? '' );
		}

		$hidden = Html::hidden( 'wpEditToken', $this->getContext()->getCsrfTokenSet()->getToken()->toString() ) .
			Html::hidden( 'wpSurveyForm', '1' );
		foreach ( [ 'returnto', 'returntoquery' ] as $param ) {
			$value = $this->getRequest()->getRawVal( $param );
			if ( $value !== null ) {
				$hidden .= Html::hidden( $param, $value );
			}
		}

		return Html::rawElement( 'form', [
			'class' => 'wo-step' . ( $active ? ' is-active' : '' ),
			'data-step' => 'survey',
			'id' => 'wo-st-survey',
			'method' => 'post',
			'action' => $this->getPageTitle()->getLocalURL(),
			'novalidate' => true,
		],
			Html::element( 'p', [ 'class' => 'wo-kicker' ], $this->msg( 'wikioasismagic-onboarding-survey-kicker' )->text() ) .
			Html::element( 'h2', [ 'class' => 'wo-h1' ],
				$this->msg( 'wikioasismagic-onboarding-survey-title', $user->getName() )->text()
			) .
			Html::element( 'p', [ 'class' => 'wo-lead' ],
				$this->msg( "wikioasismagic-onboarding-survey-lead-{$this->context}" )->text()
			) .
			Html::rawElement( 'div', [ 'class' => 'wo-body' ], $body ) .
			$hidden .
			Html::rawElement( 'div', [ 'class' => 'wo-actions' ],
				Html::element( 'button', [
					'class' => 'cdx-button cdx-button--weight-quiet',
					'type' => 'submit',
					'name' => 'wpSkip',
					'value' => '1',
					'data-skip' => true,
				], $this->msg( 'wikioasismagic-onboarding-skip' )->text() ) .
				Html::rawElement( 'div', [ 'class' => 'wo-actions__right' ],
					Html::element( 'button', [
						'class' => 'cdx-button cdx-button--action-progressive cdx-button--weight-primary',
						'type' => 'submit',
						'id' => 'wo-survey-next',
					], $this->msg( 'wikioasismagic-onboarding-continue' )->text() )
				)
			)
		);
	}

	private function renderQuestion( array $question, array $picked, string $freeText ): string {
		$id = $question['id'];
		$inputType = $question['type'] === 'single' ? 'radio' : 'checkbox';
		$name = $question['type'] === 'single' ? "answers[$id]" : "answers[$id][]";
		$dir = $this->getLanguage()->getDir();

		$options = '';
		$freeTextOption = null;
		foreach ( $question['options'] as $option ) {
			$inputId = "wo-q-$id-{$option['id']}";
			$checked = in_array( $option['id'], $picked, true );
			$attribs = [
				'class' => "cdx-{$inputType}__input",
				'type' => $inputType,
				'name' => $name,
				'value' => $option['id'],
				'id' => $inputId,
				'checked' => $checked,
				'data-action' => $option['action'],
				'data-freetext' => $option['freeText'] ? '1' : null,
				'data-tips' => $option['tips'] ? '1' : null,
			];
			if ( $option['freeText'] ) {
				$freeTextOption = $option['id'];
			}

			$label = $this->text->get( $option['label'] );
			$description = $this->text->get( $option['description'] );

			if ( $question['style'] === 'cards' ) {
				$options .= Html::rawElement( 'label', [ 'class' => 'wo-choice', 'for' => $inputId ],
					Html::rawElement( 'span', [ 'class' => "cdx-{$inputType}__wrapper" ],
						Html::element( 'input', $attribs ) .
						Html::element( 'span', [ 'class' => "cdx-{$inputType}__icon" ] )
					) .
					Html::rawElement( 'span', [],
						Html::element( 'span', [ 'class' => 'wo-choice__title' ], $label ) .
						( $description !== '' ?
							Html::element( 'span', [ 'class' => 'wo-choice__desc' ], $description ) : '' )
					) .
					$this->icons->render( $option['icon'], $dir )
				);
			} else {
				$options .= $this->checkOrRadio( $inputType, $attribs, $label, $description );
			}
		}

		$other = '';
		if ( $freeTextOption !== null ) {
			$other = Html::rawElement( 'div', [ 'class' => 'wo-other', 'data-other-for' => $freeTextOption ],
				Html::rawElement( 'div', [ 'class' => 'cdx-text-input' ],
					Html::element( 'input', [
						'class' => 'cdx-text-input__input',
						'name' => "freetext[$id]",
						'maxlength' => 200,
						'value' => $freeText,
						'placeholder' => $this->msg( 'wikioasismagic-onboarding-other-placeholder' )->text(),
						'aria-label' => $this->text->get( $question['options'][$freeTextOption]['label'] ),
					] )
				)
			);
		}

		$help = $this->text->get( $question['help'] );
		return Html::rawElement( 'fieldset', [
			'class' => 'wo-fieldset',
			'data-question' => $id,
			'data-type' => $question['type'],
		],
			Html::element( 'legend', [ 'class' => 'wo-q' ], $this->text->get( $question['label'] ) ) .
			( $help !== '' ? Html::element( 'p', [ 'class' => 'wo-q-help' ], $help ) : '' ) .
			( $question['style'] === 'cards' ?
				Html::rawElement( 'div', [ 'class' => 'wo-choices' ], $options ) :
				Html::rawElement( 'div', [ 'class' => 'wo-list' ], $options ) ) .
			$other
		);
	}

	private function getQuestionsForClient(): array {
		$questions = [];
		foreach ( $this->survey['questions'] as $question ) {
			$options = [];
			foreach ( $question['options'] as $option ) {
				$options[$option['id']] = [
					'action' => $option['action'],
					'freeText' => $option['freeText'],
					'tips' => $option['tips'],
				];
			}
			$questions[$question['id']] = [ 'type' => $question['type'], 'options' => $options ];
		}
		return $questions;
	}

	private function renderWikiBlocked( MessageSpecifier $blocker, bool $active ): string {
		return $this->step( 'wiki-blocked', $active,
			Html::element( 'p', [ 'class' => 'wo-kicker' ], $this->msg( 'wikioasismagic-onboarding-wiki-kicker' )->text() ) .
			Html::element( 'h2', [ 'class' => 'wo-h1' ], $this->msg( 'wikioasismagic-onboarding-wiki-blocked-title' )->text() ) .
			Html::rawElement( 'div', [ 'class' => 'wo-body' ],
				$this->message( 'warning', $this->msg( $blocker )->parse() )
			) .
			$this->actions( true, Html::element( 'button', [
				'class' => 'cdx-button cdx-button--action-progressive cdx-button--weight-primary',
				'type' => 'button',
				'data-goto' => 'finish',
			], $this->msg( 'wikioasismagic-onboarding-continue' )->text() ) )
		);
	}

	private function renderWikiSteps( bool $nameActive ): string {
		$config = $this->getCreateWikiConfig();
		$kicker = Html::element( 'p', [ 'class' => 'wo-kicker' ], $this->msg( 'wikioasismagic-onboarding-wiki-kicker' )->text() );

		$languages = $this->languageNameUtils->getLanguageNames();
		ksort( $languages );
		$userLanguage = $this->getLanguage()->getCode();
		$languageOptions = '';
		foreach ( $languages as $code => $name ) {
			$languageOptions .= Html::element( 'option', [
				'value' => $code,
				'selected' => $code === ( isset( $languages[$userLanguage] ) ? $userLanguage : 'en' ),
			], "$code - $name" );
		}

		$categoryField = '';
		$categories = (array)$config->get( CreateWikiConfigNames::Categories );
		if ( $categories ) {
			$categoryOptions = Html::element( 'option', [ 'value' => '', 'selected' => true, 'disabled' => true ],
				$this->msg( 'wikioasismagic-onboarding-category-choose' )->text() );
			foreach ( $categories as $label => $value ) {
				$categoryOptions .= Html::element( 'option', [ 'value' => $value ], (string)$label );
			}
			$categoryField = $this->field( 'wo-category', 'wikioasismagic-onboarding-category-label', null,
				Html::rawElement( 'select', [ 'class' => 'cdx-select', 'id' => 'wo-category', 'name' => 'category' ],
					$categoryOptions ),
				Html::element( 'div', [ 'class' => 'cdx-field__help-text' ],
					$this->msg( 'wikioasismagic-onboarding-category-help' )->text() )
			);
		}

		$suffix = '.' . $config->get( CreateWikiConfigNames::Subdomain );
		$name = $this->step( 'wiki-name', $nameActive,
			$kicker .
			$this->heading( 'wiki-name' ) .
			Html::rawElement( 'div', [ 'class' => 'wo-body' ],
				$this->field( 'wo-sitename', 'wikioasismagic-onboarding-sitename-label',
					'wikioasismagic-onboarding-sitename-desc',
					$this->textInput( [
						'id' => 'wo-sitename', 'name' => 'sitename', 'maxlength' => 128, 'autocomplete' => 'off',
						'placeholder' => $this->msg( 'wikioasismagic-onboarding-sitename-placeholder' )->text(),
					] ),
					Html::rawElement( 'div', [ 'class' => 'cdx-field__help-text wo-tip' ],
						$this->msg( 'wikioasismagic-onboarding-sitename-tip' )
							->rawParams( Html::element( 'span', [ 'class' => 'wo-mono js-ns' ], 'Your_wiki:About' ) )
							->escaped()
					)
				) .
				$this->field( 'wo-subdomain', 'wikioasismagic-onboarding-subdomain-label',
					'wikioasismagic-onboarding-subdomain-desc',
					Html::rawElement( 'div', [ 'class' => 'wo-affix' ],
						$this->textInput( [
							'id' => 'wo-subdomain', 'name' => 'subdomain', 'maxlength' => 64 -
								strlen( (string)$config->get( CreateWikiConfigNames::DatabaseSuffix ) ),
							'autocapitalize' => 'off', 'spellcheck' => 'false', 'autocomplete' => 'off',
							'placeholder' => $this->msg( 'wikioasismagic-onboarding-subdomain-placeholder' )->text(),
						] ) .
						Html::element( 'span', [ 'class' => 'wo-affix__suffix' ], $suffix )
					),
					Html::rawElement( 'div', [ 'class' => 'cdx-field__help-text wo-tip' ],
						$this->msg( 'wikioasismagic-onboarding-subdomain-tip' )->parse() )
				) .
				Html::rawElement( 'div', [ 'class' => 'wo-two' ],
					$this->field( 'wo-language', 'wikioasismagic-onboarding-language-label', null,
						Html::rawElement( 'select', [ 'class' => 'cdx-select', 'id' => 'wo-language', 'name' => 'language' ],
							$languageOptions )
					) .
					$categoryField
				)
			) .
			$this->actions( true, $this->primaryButton( 'wikioasismagic-onboarding-continue' ) )
		);

		$purposeField = '';
		$purposes = (array)$config->get( CreateWikiConfigNames::Purposes );
		if ( $purposes ) {
			$purposeOptions = Html::element( 'option', [ 'value' => '', 'selected' => true, 'disabled' => true ],
				$this->msg( 'wikioasismagic-onboarding-purpose-choose' )->text() );
			foreach ( $purposes as $label => $value ) {
				$purposeOptions .= Html::element( 'option', [ 'value' => $value ], (string)$label );
			}
			$purposeField = $this->field( 'wo-purpose', 'wikioasismagic-onboarding-purpose-label', null,
				Html::rawElement( 'select', [ 'class' => 'cdx-select', 'id' => 'wo-purpose', 'name' => 'purpose' ],
					$purposeOptions )
			);
		}

		$prompts = '';
		foreach ( [ 'about', 'audience', 'pages', 'editors' ] as $prompt ) {
			$prompts .= Html::rawElement( 'li', [],
				Html::rawElement( 'button', [
					'type' => 'button',
					'data-prompt' => $this->msg( "wikioasismagic-onboarding-prompt-$prompt-text" )->text(),
				],
					$this->icons->render( 'cdxIconAdd' ) .
					Html::element( 'span', [], $this->msg( "wikioasismagic-onboarding-prompt-$prompt" )->text() )
				)
			);
		}

		$directory = $this->getDirectoryUrl();
		$purpose = $this->step( 'wiki-purpose', false,
			$kicker .
			$this->heading( 'wiki-purpose' ) .
			Html::rawElement( 'div', [ 'class' => 'wo-body' ],
				$purposeField .
				Html::rawElement( 'div', [ 'class' => 'cdx-field', 'id' => 'wo-f-reason' ],
					Html::rawElement( 'div', [ 'class' => 'cdx-label' ],
						Html::rawElement( 'label', [ 'class' => 'cdx-label__label', 'for' => 'wo-reason' ],
							Html::element( 'span', [ 'class' => 'cdx-label__label__text' ],
								$this->msg( 'wikioasismagic-onboarding-reason-label' )->text() )
						) .
						Html::element( 'span', [ 'class' => 'cdx-label__description' ],
							$this->msg( 'wikioasismagic-onboarding-reason-desc' )->text() )
					) .
					Html::rawElement( 'ul', [
						'class' => 'wo-prompts',
						'aria-label' => $this->msg( 'wikioasismagic-onboarding-prompts-label' )->text(),
					], $prompts ) .
					Html::rawElement( 'div', [ 'class' => 'cdx-field__control' ],
						Html::rawElement( 'div', [ 'class' => 'cdx-text-area' ],
							Html::element( 'textarea', [
								'class' => 'cdx-text-area__textarea',
								'id' => 'wo-reason',
								'name' => 'reason',
								'rows' => 7,
								'maxlength' => 4096,
								'aria-describedby' => 'wo-reason-count',
							] )
						)
					) .
					Html::rawElement( 'div', [ 'class' => 'wo-counter', 'id' => 'wo-reason-count', 'aria-live' => 'polite' ],
						Html::element( 'span', [ 'id' => 'wo-reason-txt' ] ) .
						Html::rawElement( 'span', [ 'class' => 'wo-meter', 'id' => 'wo-reason-meter' ],
							Html::element( 'span' ) )
					) .
					$this->status( 'wo-reason-status' )
				) .
				$this->message( 'notice', $directory ?
					$this->msg( 'wikioasismagic-onboarding-duplicate-notice-directory', $directory )->parse() :
					$this->msg( 'wikioasismagic-onboarding-duplicate-notice' )->parse()
				)
			) .
			$this->actions( true, $this->primaryButton( 'wikioasismagic-onboarding-continue' ) )
		);

		$visibility = '';
		if ( $config->get( CreateWikiConfigNames::UsePrivateWikis ) ) {
			$visibility = Html::rawElement( 'fieldset', [ 'class' => 'wo-fieldset' ],
				Html::element( 'legend', [ 'class' => 'wo-q' ], $this->msg( 'wikioasismagic-onboarding-visibility-label' )->text() ) .
				Html::rawElement( 'div', [ 'class' => 'wo-options', 'role' => 'radiogroup' ],
					$this->optionCard( 'radio', 'private', '0', 'wo-vis-public', true,
						'wikioasismagic-onboarding-public', 'cdxIconGlobe' ) .
					$this->optionCard( 'radio', 'private', '1', 'wo-vis-private', false,
						'wikioasismagic-onboarding-private', 'cdxIconLock' )
				)
			);
		}

		$bio = $config->get( CreateWikiConfigNames::ShowBiographicalOption ) ?
			$this->checkOrRadio( 'checkbox', [ 'id' => 'wo-bio', 'name' => 'bio', 'value' => '1' ],
				$this->msg( 'wikioasismagic-onboarding-bio-label' )->text(),
				$this->msg( 'wikioasismagic-onboarding-bio-desc' )->text() ) :
			'';

		$settings = $this->step( 'wiki-settings', false,
			$kicker .
			$this->heading( 'wiki-settings' ) .
			Html::rawElement( 'div', [ 'class' => 'wo-body' ],
				$visibility .
				Html::rawElement( 'fieldset', [ 'class' => 'wo-fieldset' ],
					Html::element( 'legend', [ 'class' => 'wo-q' ], $this->msg( 'wikioasismagic-onboarding-content-label' )->text() ) .
					Html::element( 'p', [ 'class' => 'wo-q-help' ], $this->msg( 'wikioasismagic-onboarding-content-help' )->text() ) .
					$bio .
					$this->checkOrRadio( 'checkbox', [ 'id' => 'wo-nsfw', 'name' => 'nsfw', 'value' => '1' ],
						$this->msg( 'wikioasismagic-onboarding-nsfw-label' )->text(),
						$this->msg( 'wikioasismagic-onboarding-nsfw-desc' )->text() ) .
					Html::rawElement( 'div', [ 'class' => 'wo-reveal-block', 'id' => 'wo-nsfw-block', 'data-reveal-for' => 'wo-nsfw' ],
						$this->field( 'wo-nsfwtext', 'wikioasismagic-onboarding-nsfwtext-label', null,
							$this->textInput( [ 'id' => 'wo-nsfwtext', 'name' => 'nsfwtext', 'maxlength' => 255 ] )
						) .
						$this->checkOrRadio( 'checkbox', [ 'id' => 'wo-nsfw-primary', 'name' => 'nsfw-primary', 'value' => '1' ],
							$this->msg( 'wikioasismagic-onboarding-nsfw-primary-label' )->text(), '' )
					)
				) .
				Html::rawElement( 'fieldset', [ 'class' => 'wo-fieldset' ],
					Html::element( 'legend', [ 'class' => 'wo-q' ], $this->msg( 'wikioasismagic-onboarding-existing-label' )->text() ) .
					Html::rawElement( 'span', [ 'class' => 'cdx-toggle-switch' ],
						Html::element( 'input', [
							'id' => 'wo-migrate',
							'class' => 'cdx-toggle-switch__input',
							'type' => 'checkbox',
							'role' => 'switch',
							'name' => 'source',
							'value' => '1',
							'aria-controls' => 'wo-migrate-block',
						] ) .
						Html::rawElement( 'span', [ 'class' => 'cdx-toggle-switch__switch' ],
							Html::element( 'span', [ 'class' => 'cdx-toggle-switch__switch__grip' ] ) ) .
						Html::rawElement( 'div', [ 'class' => 'cdx-toggle-switch__label cdx-label' ],
							Html::rawElement( 'label', [ 'for' => 'wo-migrate', 'class' => 'cdx-label__label' ],
								Html::element( 'span', [ 'class' => 'cdx-label__label__text' ],
									$this->msg( 'wikioasismagic-onboarding-migrate-label' )->text() ) ) )
					) .
					Html::rawElement( 'div', [ 'class' => 'wo-reveal-block', 'id' => 'wo-migrate-block', 'data-reveal-for' => 'wo-migrate' ],
						$this->field( 'wo-sourceurl', 'wikioasismagic-onboarding-sourceurl-label', null,
							$this->textInput( [
								'id' => 'wo-sourceurl', 'name' => 'sourceurl', 'type' => 'url', 'inputmode' => 'url',
								'placeholder' => $this->msg( 'wikioasismagic-onboarding-sourceurl-placeholder' )->text(),
							] )
						) .
						Html::rawElement( 'div', [ 'class' => 'wo-inline-radios' ],
							$this->checkOrRadio( 'radio', [ 'id' => 'wo-mv-move', 'name' => 'sourcetype', 'value' => 'move', 'checked' => true ],
								$this->msg( 'wikioasismagic-onboarding-move-label' )->text(), '' ) .
							$this->checkOrRadio( 'radio', [ 'id' => 'wo-mv-fork', 'name' => 'sourcetype', 'value' => 'fork' ],
								$this->msg( 'wikioasismagic-onboarding-fork-label' )->text(), '' )
						) .
						Html::element( 'p', [ 'class' => 'cdx-field__help-text' ],
							$this->msg( 'wikioasismagic-onboarding-migrate-help' )->text() )
					)
				)
			) .
			$this->actions( true, $this->primaryButton( 'wikioasismagic-onboarding-review-button' ) )
		);

		$agreement = Html::rawElement( 'div', [ 'class' => 'cdx-checkbox', 'id' => 'wo-f-agreement' ],
			Html::rawElement( 'div', [ 'class' => 'cdx-checkbox__wrapper' ],
				Html::element( 'input', [
					'id' => 'wo-agreement', 'class' => 'cdx-checkbox__input', 'type' => 'checkbox',
					'name' => 'agreement', 'value' => '1',
				] ) .
				Html::element( 'span', [ 'class' => 'cdx-checkbox__icon' ] ) .
				Html::rawElement( 'div', [ 'class' => 'cdx-checkbox__label cdx-label' ],
					Html::rawElement( 'label', [ 'for' => 'wo-agreement', 'class' => 'cdx-label__label' ],
						Html::rawElement( 'span', [ 'class' => 'cdx-label__label__text' ],
							$this->msg( 'requestwiki-label-agreement' )->parse() ) ) )
			) .
			$this->status( 'wo-agreement-status' )
		);

		$review = $this->step( 'wiki-review', false,
			$kicker .
			$this->heading( 'wiki-review' ) .
			Html::rawElement( 'div', [ 'class' => 'wo-body' ],
				Html::element( 'div', [ 'class' => 'wo-review', 'id' => 'wo-review-box' ] ) .
				Html::rawElement( 'div', [ 'class' => 'wo-submit-error', 'id' => 'wo-submit-error', 'role' => 'alert', 'hidden' => true ],
					$this->message( 'error', '' ) ) .
				$agreement
			) .
			$this->actions( true, Html::element( 'button', [
				'class' => 'cdx-button cdx-button--action-progressive cdx-button--weight-primary',
				'type' => 'submit',
				'id' => 'wo-submit-request',
			], $this->msg( 'wikioasismagic-onboarding-send' )->text() ) )
		);

		return $name . $purpose . $settings . $review;
	}

	private function renderDone(): string {
		$timeline = '';
		foreach ( [ 'review', 'live', 'customise', 'import', 'domain' ] as $item ) {
			$timeline .= Html::rawElement( 'li', $item === 'import' ? [ 'class' => 'js-import-step' ] : [],
				Html::element( 'strong', [], $this->msg( "wikioasismagic-onboarding-timeline-$item-title" )->text() ) .
				Html::rawElement( 'p', [],
					$this->msg( "wikioasismagic-onboarding-timeline-$item-desc" )
						->rawParams( Html::element( 'span', [ 'class' => 'wo-mono js-url' ], 'yourwiki' ) )
						->parse()
				)
			);
		}

		return $this->step( 'done', false,
			Html::rawElement( 'div', [ 'class' => 'wo-done-icon' ], $this->icons->render( 'cdxIconSuccess' ) ) .
			Html::rawElement( 'h2', [ 'class' => 'wo-h1' ],
				$this->msg( 'wikioasismagic-onboarding-done-title' )
					->rawParams( Html::element( 'span', [ 'class' => 'js-sitename' ], '' ) )->escaped()
			) .
			Html::rawElement( 'p', [ 'class' => 'wo-lead', 'id' => 'wo-done-lead' ], '' ) .
			Html::rawElement( 'div', [ 'class' => 'wo-body' ],
				Html::rawElement( 'div', [ 'id' => 'wo-noemail', 'hidden' => true ],
					$this->message( 'warning', $this->msg( 'wikioasismagic-onboarding-done-noemail' )->parse() )
				) .
				Html::rawElement( 'ol', [ 'class' => 'wo-timeline' ], $timeline ) .
				Html::rawElement( 'div', [ 'class' => 'wo-btnrow' ],
					Html::element( 'a', [
						'class' => 'cdx-button cdx-button--fake-button cdx-button--fake-button--enabled ' .
							'cdx-button--action-progressive cdx-button--weight-primary',
						'id' => 'wo-view-request',
						'href' => SpecialPage::getTitleFor( 'RequestWikiQueue' )->getLocalURL(),
					], $this->msg( 'wikioasismagic-onboarding-view-request' )->text() ) .
					Html::element( 'button', [
						'class' => 'cdx-button',
						'type' => 'button',
						'data-goto' => 'finish',
					], $this->msg( 'wikioasismagic-onboarding-explore' )->text() )
				)
			)
		);
	}

	private function renderPreview(): string {
		$chip = fn ( string $id, string $class, string $msg, ?string $icon = null ) =>
			Html::rawElement( 'span', [ 'class' => "wo-chip $class", 'id' => $id, 'hidden' => true ],
				( $icon ? $this->icons->render( $icon ) : '' ) . $this->msg( $msg )->escaped() );

		return Html::rawElement( 'aside', [
			'class' => 'wo-preview',
			'id' => 'wo-preview',
			'hidden' => true,
			'aria-label' => $this->msg( 'wikioasismagic-onboarding-preview-aria' )->text(),
		],
			Html::rawElement( 'div', [ 'class' => 'wo-preview__label' ],
				Html::element( 'span', [], $this->msg( 'wikioasismagic-onboarding-preview-label' )->text() ) .
				Html::element( 'span', [ 'id' => 'wo-pv-lang' ], 'en' )
			) .
			Html::rawElement( 'div', [ 'class' => 'wo-browser' ],
				Html::rawElement( 'div', [ 'class' => 'wo-browser__bar' ],
					Html::rawElement( 'span', [ 'class' => 'wo-browser__dots', 'aria-hidden' => 'true' ],
						'<i></i><i></i><i></i>' ) .
					Html::rawElement( 'span', [ 'class' => 'wo-browser__url' ],
						$this->icons->render( 'cdxIconLock' ) .
						Html::element( 'b', [ 'id' => 'wo-pv-sub' ], 'yourwiki' ) .
						Html::element( 'span', [], '.' . $this->getCreateWikiConfig()->get( CreateWikiConfigNames::Subdomain ) )
					)
				) .
				Html::rawElement( 'div', [ 'class' => 'wo-page', 'id' => 'wo-pv-page' ],
					Html::rawElement( 'div', [ 'class' => 'wo-page__rail', 'aria-hidden' => 'true' ], '<i></i><i></i><i></i>' ) .
					Html::rawElement( 'div', [ 'class' => 'wo-page__main' ],
						Html::rawElement( 'div', [ 'class' => 'wo-page__site' ],
							Html::element( 'b', [ 'id' => 'wo-pv-name' ], $this->msg( 'wikioasismagic-onboarding-preview-name' )->text() ) ) .
						Html::element( 'div', [ 'class' => 'wo-page__h', 'id' => 'wo-pv-main' ], $this->msg( 'mainpage' )->text() ) .
						Html::element( 'p', [ 'class' => 'wo-page__lead is-empty', 'id' => 'wo-pv-lead' ],
							$this->msg( 'wikioasismagic-onboarding-preview-lead-empty' )->text() ) .
						Html::rawElement( 'div', [ 'class' => 'wo-page__skel', 'aria-hidden' => 'true' ], '<i></i><i></i><i></i>' ) .
						Html::rawElement( 'div', [ 'class' => 'wo-page__chips' ],
							Html::element( 'span', [ 'class' => 'wo-chip', 'id' => 'wo-pv-cat', 'hidden' => true ] ) .
							$chip( 'wo-pv-priv', 'wo-chip--info', 'wikioasismagic-onboarding-preview-private', 'cdxIconLock' ) .
							$chip( 'wo-pv-nsfw', 'wo-chip--warn', 'wikioasismagic-onboarding-preview-nsfw' ) .
							$chip( 'wo-pv-bio', '', 'wikioasismagic-onboarding-preview-bio' ) .
							$chip( 'wo-pv-mig', '', 'wikioasismagic-onboarding-preview-migrating', 'cdxIconMove' )
						)
					)
				)
			) .
			Html::element( 'p', [ 'class' => 'wo-preview__note' ], $this->msg( 'wikioasismagic-onboarding-preview-note' )->text() )
		);
	}

	/**
	 * @return array<string,array{id:string,title:string,description:string,icon:?string,href:string,when:array}>
	 */
	private function prepareCards(): array {
		$titles = [];
		foreach ( $this->survey['cards'] as $card ) {
			if ( $card['page'] !== null && !$card['central'] ) {
				$title = Title::newFromText( $card['page'] );
				if ( $title && !$title->isExternal() ) {
					$titles[$card['id']] = $title;
				}
			}
		}
		$this->linkBatchFactory->newLinkBatch( array_filter( $titles, static fn ( $t ) => $t->canExist() ) )
			->setCaller( __METHOD__ )
			->execute();

		$cards = [];
		foreach ( $this->survey['cards'] as $card ) {
			$query = array_map( 'strval', $card['query'] );
			if ( $card['url'] !== null ) {
				$directory = $this->getDirectoryUrl();
				if ( str_contains( $card['url'], '{directory}' ) && $directory === null ) {
					continue;
				}
				$href = str_replace( '{directory}', $directory ?? '', $card['url'] );
				if ( !preg_match( '/^https?:\/\//', $href ) ) {
					continue;
				}
			} elseif ( $card['central'] ) {
				$href = $this->environment->getCentralUrl( $card['page'], $query );
				if ( $href === null ) {
					continue;
				}
			} else {
				$title = $titles[$card['id']] ?? null;
				if ( !$title || ( $card['requiresExists'] && !$title->isKnown() ) ) {
					continue;
				}
				$href = $title->getLocalURL( $query );
			}

			$cards[$card['id']] = [
				'id' => $card['id'],
				'title' => $this->text->get( $card['title'] ),
				'description' => $this->text->get( $card['description'] ),
				'icon' => $card['icon'],
				'href' => $href,
				'when' => $card['when'],
			];
		}

		return $cards;
	}

	private function renderFinish( array $cards, array $answers, string $returnUrl, bool $wantsWiki, bool $active ): string {
		$user = $this->getUser();
		$selected = SurveyResponses::selectCards( $cards, $answers );
		$selectedIds = array_map( static fn ( $card ) => $card['id'], $selected );

		$cardHtml = '';
		foreach ( $cards as $card ) {
			$cardHtml .= Html::rawElement( 'a', [
				'class' => 'cdx-card wo-card',
				'href' => $card['href'],
				'data-card' => $card['id'],
				'data-when' => $card['when'] ? FormatJson::encode( $card['when'] ) : null,
				'hidden' => !in_array( $card['id'], $selectedIds, true ),
			],
				Html::rawElement( 'span', [ 'class' => 'cdx-card__thumbnail cdx-thumbnail' ],
					Html::rawElement( 'span', [ 'class' => 'cdx-thumbnail__placeholder' ],
						$this->icons->render( $card['icon'] ?? 'cdxIconArticles', $this->getLanguage()->getDir() ) ) ) .
				Html::rawElement( 'span', [ 'class' => 'cdx-card__text' ],
					Html::element( 'span', [ 'class' => 'cdx-card__text__title' ], $card['title'] ) .
					( $card['description'] !== '' ?
						Html::element( 'span', [ 'class' => 'cdx-card__text__description' ], $card['description'] ) : '' )
				)
			);
		}

		$titleText = $this->text->get( $this->survey['finish']['title'] );
		$leadText = $this->text->get( $this->survey['finish']['lead'] );

		$buttons = Html::element( 'a', [
			'class' => 'cdx-button cdx-button--fake-button cdx-button--fake-button--enabled ' .
				'cdx-button--action-progressive cdx-button--weight-primary',
			'href' => $returnUrl,
			'id' => 'wo-finish-return',
		], $this->msg( $this->getRequest()->getRawVal( 'returnto' ) ?
			'wikioasismagic-onboarding-finish-return' :
			'wikioasismagic-onboarding-finish-mainpage' )->text() );

		if ( $this->environment->canRequestWikis() ) {
			if ( $wantsWiki ) {
				$buttons .= Html::element( 'a', [
					'class' => 'cdx-button cdx-button--fake-button cdx-button--fake-button--enabled',
					'href' => SpecialPage::getTitleFor( 'RequestWiki' )->getLocalURL(),
				], $this->msg( 'wikioasismagic-onboarding-finish-requestwiki' )->text() );
			} else {
				$buttons .= Html::rawElement( 'a', [
					'class' => 'cdx-button cdx-button--fake-button cdx-button--fake-button--enabled',
					'href' => $this->getPageTitle( 'wiki' )->getLocalURL(),
					'data-start-wiki' => true,
				],
					$this->icons->render( 'cdxIconAdd' ) .
					$this->msg( 'wikioasismagic-onboarding-finish-startwiki' )->escaped()
				);
			}
		}

		return $this->step( 'finish', $active,
			Html::element( 'h2', [ 'class' => 'wo-h1' ], $titleText !== '' ? $titleText :
				$this->msg( "wikioasismagic-onboarding-finish-title-{$this->context}", $user->getName() )->text() ) .
			Html::element( 'p', [ 'class' => 'wo-lead' ], $leadText !== '' ? $leadText :
				$this->msg( "wikioasismagic-onboarding-finish-lead-{$this->context}" )->text() ) .
			Html::rawElement( 'div', [ 'class' => 'wo-body' ],
				( $cardHtml !== '' ? Html::rawElement( 'div', [ 'class' => 'wo-cards', 'id' => 'wo-cards' ], $cardHtml ) : '' ) .
				Html::rawElement( 'div', [ 'class' => 'wo-btnrow' ], $buttons )
			)
		);
	}

	private function getReturnUrl(): string {
		$request = $this->getRequest();
		$returnTo = Title::newFromText( (string)$request->getRawVal( 'returnto' ) );
		if ( !$returnTo || $returnTo->isExternal() || $returnTo->isSpecial( 'Welcome' ) ||
			$returnTo->isSpecial( 'Userlogin' ) || $returnTo->isSpecial( 'CreateAccount' )
		) {
			$returnTo = Title::newMainPage();
		}

		return $returnTo->getLocalURL( (string)$request->getRawVal( 'returntoquery' ) );
	}

	private function getDirectoryUrl(): ?string {
		$url = $this->getConfig()->get( ConfigNames::DIRECTORY_URL );
		return is_string( $url ) && preg_match( '/^https?:\/\//', $url ) ? $url : null;
	}

	private function getSubmitter(): WikiRequestSubmitter {
		return MediaWikiServices::getInstance()->get( 'WikiOasisMagic.OnboardingWikiRequestSubmitter' );
	}

	private function getCreateWikiConfig(): Config {
		return MediaWikiServices::getInstance()->get( 'CreateWikiConfig' );
	}

	/**
	 * @param string $step
	 * @param bool $active
	 * @param string $content
	 */
	private function step( string $step, bool $active, string $content ): string {
		$isForm = str_starts_with( $step, 'wiki-' ) && $step !== 'wiki-blocked';
		$jsOnly = $step !== 'finish';
		return Html::rawElement( $isForm ? 'form' : 'div', [
			'class' => 'wo-step' . ( $jsOnly ? ' wo-step--js' : '' ) . ( $active ? ' is-active' : '' ),
			'data-step' => $step,
			'id' => "wo-st-$step",
			'novalidate' => $isForm ? true : null,
		], $content );
	}

	private function heading( string $step ): string {
		return Html::element( 'h2', [ 'class' => 'wo-h1' ], $this->msg( "wikioasismagic-onboarding-$step-title" )->text() ) .
			Html::element( 'p', [ 'class' => 'wo-lead' ], $this->msg( "wikioasismagic-onboarding-$step-lead" )->text() );
	}

	private function actions( bool $back, string $primary ): string {
		return Html::rawElement( 'div', [ 'class' => 'wo-actions' ],
			( $back ? Html::element( 'button', [ 'class' => 'cdx-button', 'type' => 'button', 'data-back' => true ],
				$this->msg( 'wikioasismagic-onboarding-back' )->text() ) : '' ) .
			Html::rawElement( 'div', [ 'class' => 'wo-actions__right' ], $primary )
		);
	}

	private function primaryButton( string $msg ): string {
		return Html::element( 'button', [
			'class' => 'cdx-button cdx-button--action-progressive cdx-button--weight-primary',
			'type' => 'submit',
		], $this->msg( $msg )->text() );
	}

	private function field( string $id, string $labelMsg, ?string $descMsg, string $control, string $after = '' ): string {
		return Html::rawElement( 'div', [ 'class' => 'cdx-field', 'id' => "$id-field" ],
			Html::rawElement( 'div', [ 'class' => 'cdx-label' ],
				Html::rawElement( 'label', [ 'class' => 'cdx-label__label', 'for' => $id ],
					Html::element( 'span', [ 'class' => 'cdx-label__label__text' ], $this->msg( $labelMsg )->text() )
				) .
				( $descMsg ? Html::element( 'span', [ 'class' => 'cdx-label__description' ], $this->msg( $descMsg )->text() ) : '' )
			) .
			Html::rawElement( 'div', [ 'class' => 'cdx-field__control' ], $control ) .
			$after .
			$this->status( "$id-status" )
		);
	}

	private function textInput( array $attribs ): string {
		return Html::rawElement( 'div', [ 'class' => 'cdx-text-input' ],
			Html::element( 'input', [ 'class' => 'cdx-text-input__input' ] + $attribs )
		);
	}

	private function status( string $id ): string {
		return Html::element( 'div', [ 'class' => 'wo-status', 'id' => $id, 'aria-live' => 'polite' ] );
	}

	/**
	 * @param string $type
	 * @param string $html
	 */
	private function message( string $type, string $html ): string {
		return Html::rawElement( 'div', [ 'class' => "cdx-message cdx-message--block cdx-message--$type" ],
			Html::element( 'span', [ 'class' => 'cdx-message__icon' ] ) .
			Html::rawElement( 'div', [ 'class' => 'cdx-message__content' ], $html )
		);
	}

	private function checkOrRadio( string $type, array $attribs, string $label, string $description ): string {
		$attribs += [ 'class' => "cdx-{$type}__input", 'type' => $type ];
		return Html::rawElement( 'div', [ 'class' => "cdx-{$type}" ],
			Html::rawElement( 'div', [ 'class' => "cdx-{$type}__wrapper" ],
				Html::element( 'input', $attribs ) .
				Html::element( 'span', [ 'class' => "cdx-{$type}__icon" ] ) .
				Html::rawElement( 'div', [ 'class' => "cdx-{$type}__label cdx-label" ],
					Html::rawElement( 'label', [ 'for' => $attribs['id'], 'class' => 'cdx-label__label' ],
						Html::element( 'span', [ 'class' => 'cdx-label__label__text' ], $label ) ) .
					( $description !== '' ? Html::element( 'span', [ 'class' => 'cdx-label__description' ], $description ) : '' )
				)
			)
		);
	}

	private function optionCard(
		string $type, string $name, string $value, string $id, bool $checked, string $msg, string $icon
	): string {
		return Html::rawElement( 'label', [ 'class' => 'wo-choice', 'for' => $id ],
			Html::rawElement( 'span', [ 'class' => "cdx-{$type}__wrapper" ],
				Html::element( 'input', [
					'class' => "cdx-{$type}__input", 'type' => $type, 'name' => $name,
					'value' => $value, 'id' => $id, 'checked' => $checked,
				] ) .
				Html::element( 'span', [ 'class' => "cdx-{$type}__icon" ] )
			) .
			Html::rawElement( 'span', [],
				Html::element( 'span', [ 'class' => 'wo-choice__title' ], $this->msg( $msg )->text() ) .
				Html::element( 'span', [ 'class' => 'wo-choice__desc' ], $this->msg( "$msg-desc" )->text() )
			) .
			$this->icons->render( $icon )
		);
	}

	protected function getGroupName(): string {
		return 'login';
	}

	public function doesWrites(): bool {
		return true;
	}
}
