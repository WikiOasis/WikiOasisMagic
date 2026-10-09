<?php

namespace WikiOasis\WikiOasisMagic\HookHandlers;

use MediaWiki\Auth\AuthManager;
use MediaWiki\Auth\Hook\LocalUserCreatedHook;
use MediaWiki\Content\Content;
use MediaWiki\Content\TextContent;
use MediaWiki\Context\IContextSource;
use MediaWiki\Context\RequestContext;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Hook\EditFilterMergedContentHook;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Html\Html;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\PageReference;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Preferences\Hook\GetPreferencesHook;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Request\WebRequest;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\SpecialPage\Hook\AuthChangeFormFieldsHook;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserEditTracker;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Psr\Log\LoggerInterface;
use Throwable;
use Wikimedia\Rdbms\IDBAccessObject;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentManager;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentTracker;
use WikiOasis\WikiOasisMagic\Onboarding\OnboardingEnvironment;
use WikiOasis\WikiOasisMagic\Onboarding\OnboardingMetrics;
use WikiOasis\WikiOasisMagic\Onboarding\SurveyLoader;
use WikiOasis\WikiOasisMagic\Onboarding\SurveyResponses;
use WikiOasis\WikiOasisMagic\Onboarding\WikiRequestSubmitter;
use function is_numeric;
use function str_starts_with;
use function strlen;
use function substr;
use function wfArrayToCgi;
use function wfCgiToArray;

class Onboarding implements
	AuthChangeFormFieldsHook,
	BeforePageDisplayHook,
	EditFilterMergedContentHook,
	GetPreferencesHook,
	LocalUserCreatedHook,
	PageDeleteCompleteHook,
	PageSaveCompleteHook
{

	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly OnboardingEnvironment $environment,
		private readonly ExperimentManager $experimentManager,
		private readonly ExperimentTracker $tracker,
		private readonly OnboardingMetrics $metrics,
		private readonly SurveyLoader $surveyLoader,
		private readonly UserEditTracker $userEditTracker,
		private readonly UserFactory $userFactory,
		private readonly HookContainer $hookContainer,
	) {
		$this->logger = LoggerFactory::getInstance( 'WikiOasisMagic' );
	}

	/**
	 * @param OutputPage $out
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		if ( !$this->environment->isEnabled() ) {
			return;
		}

		$title = $out->getTitle();
		$isSignup = $title && $title->isSpecial( 'CreateAccount' );
		$isLogin = $title && $title->isSpecial( 'Userlogin' );
		if ( ( !$isSignup && !$isLogin ) || ( $out->getUser()->isNamed() && !$this->isPreviewingSignup( $out->getRequest() ) ) ) {
			return;
		}

		$request = $out->getRequest();
		$assignment = $this->experimentManager->assignBrowser(
			OnboardingEnvironment::SIGNUP_EXPERIMENT,
			$request,
			$isSignup
		);

		if ( $isSignup ) {
			$this->metrics->signupView( $assignment, $request->wasPosted() );
			$this->tracker->expose( $assignment );
		}

		if ( $assignment->isLegacy() ) {
			return;
		}

		$returnQuery = $this->getPreservedParams( $request );

		$out->addBodyClasses( 'wo-signup-v2' );
		$out->addModuleStyles( 'ext.wikioasismagic.signup.styles' );
		$out->addModules( 'ext.wikioasismagic.signup' );
		$out->addJsConfigVars( 'wgWikiOasisSignup', [
			'mode' => $isSignup ? 'create' : 'login',
			'loginUrl' => SpecialPage::getTitleFor( 'Userlogin' )->getLocalURL( $returnQuery ),
			'createUrl' => SpecialPage::getTitleFor( 'CreateAccount' )->getLocalURL( $returnQuery ),
		] );
	}

	/**
	 * @param string|null &$html
	 * @param array $info
	 * @param array &$options
	 * @return bool|void
	 */
	public function onSpecialCreateAccountBenefits( ?string &$html, array $info, array &$options ) {
		if ( !$this->environment->isEnabled() ) {
			return;
		}

		/** @var IContextSource $context */
		$context = $info['context'];
		if ( $context->getUser()->isNamed() && !$this->isPreviewingSignup( $context->getRequest() ) ) {
			return;
		}
		$assignment = $this->experimentManager->assignBrowser(
			OnboardingEnvironment::SIGNUP_EXPERIMENT,
			$context->getRequest()
		);
		if ( $assignment->isLegacy() ) {
			return;
		}

		$stats = $this->environment->getFarmStats();
		$lead = $stats['wikis'] && $stats['users'] ?
			$context->msg( 'wikioasismagic-signup-lead' )
				->numParams( $stats['wikis'], $stats['users'] )->text() :
			$context->msg( 'wikioasismagic-signup-lead-nostats' )->text();

		$html = Html::rawElement( 'div', [ 'class' => 'wo-signup-intro' ],
			Html::element( 'p', [ 'class' => 'wo-signup-intro__lead' ], $lead )
		);
		$options['beforeForm'] = true;
		return false;
	}

	public function onAuthChangeFormFields( $requests, $fieldInfo, &$formDescriptor, $action ) {
		if ( $action !== AuthManager::ACTION_CREATE || !$this->environment->isEnabled() ) {
			return;
		}

		$context = RequestContext::getMain();
		if ( $context->getUser()->isNamed() && !$this->isPreviewingSignup( $context->getRequest() ) ) {
			return;
		}

		$assignment = $this->experimentManager->assignBrowser(
			OnboardingEnvironment::SIGNUP_EXPERIMENT,
			$context->getRequest()
		);
		if ( $assignment->isLegacy() ) {
			return;
		}

		if ( isset( $formDescriptor['username'] ) ) {
			$formDescriptor['username']['placeholder-message'] = 'wikioasismagic-signup-username-placeholder';
		}

		$realNameWeight = $formDescriptor['realname']['weight'] ?? null;
		unset( $formDescriptor['realname'] );

		if ( isset( $formDescriptor['email'] ) ) {
			$formDescriptor['email']['help-message'] = 'wikioasismagic-signup-email-help';
			$formDescriptor['email']['placeholder-message'] = 'wikioasismagic-signup-email-placeholder';
			if ( $realNameWeight !== null ) {
				$formDescriptor['email']['weight'] = $realNameWeight;
			}
		}

		if ( isset( $formDescriptor['retype'] ) ) {
			$formDescriptor['retype']['cssclass'] = ( $formDescriptor['retype']['cssclass'] ?? '' ) .
				' wo-signup-retype';
		}
	}

	public function onLocalUserCreated( $user, $autocreated ) {
		if ( $autocreated || !$this->environment->isEnabled() ) {
			return;
		}

		$assignment = $this->experimentManager->assignBrowser(
			OnboardingEnvironment::SIGNUP_EXPERIMENT,
			RequestContext::getMain()->getRequest(),
			false
		);
		$this->metrics->signup( $assignment );
		$this->tracker->recordEvent( $assignment, 'account' );
	}

	/**
	 * @param string &$returnTo
	 * @param string &$returnToQuery
	 * @param bool $stickHTTPS
	 * @param string $type
	 * @param string &$injectedHtml
	 * @return bool|void
	 */
	public function onCentralAuthPostLoginRedirect(
		string &$returnTo,
		string &$returnToQuery,
		bool $stickHTTPS,
		string $type,
		string &$injectedHtml
	) {
		if ( $type !== 'signup' ) {
			return;
		}

		$query = wfCgiToArray( $returnToQuery );
		if ( $this->redirectAfterSignup( $returnTo, $query ) ) {
			$returnToQuery = wfArrayToCgi( $query );
		}
	}

	/**
	 * @param string &$returnTo
	 * @param array &$returnToQuery
	 * @param string &$type
	 * @return bool|void
	 */
	public function onPostLoginRedirect( &$returnTo, &$returnToQuery, &$type ) {
		if ( $type !== 'signup' || ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' ) ) {
			return;
		}

		if ( $this->redirectAfterSignup( $returnTo, $returnToQuery ) ) {
			$type = 'successredirect';
		}
	}

	/**
	 * @return bool
	 */
	private function redirectAfterSignup( string &$returnTo, array &$returnToQuery ): bool {
		if ( !$this->environment->isEnabled() ) {
			return false;
		}

		$context = RequestContext::getMain();
		$user = $context->getUser();
		if ( !$user->isNamed() ) {
			return false;
		}

		$welcome = SpecialPage::getTitleFor( 'Welcome' );
		$target = Title::newFromText( $returnTo );
		if (
			$context->getRequest()->getRawVal( 'display' ) === 'popup' ||
			( $target && ( $target->isSpecial( 'Welcome' ) || $target->isSpecial( 'AuthenticationPopupSuccess' ) ) )
		) {
			return false;
		}

		$assignment = $this->experimentManager->assignUser(
			OnboardingEnvironment::ONBOARDING_EXPERIMENT,
			$user,
			$context->getRequest()
		);
		$this->metrics->assignment( $assignment, $this->environment->getContext() );
		$this->tracker->expose( $assignment );

		if ( $assignment->isLegacy() ) {
			return false;
		}

		$query = [ 'source' => 'signup' ];
		if ( $returnTo !== '' ) {
			$query['returnto'] = $returnTo;
		}
		if ( $returnToQuery ) {
			$query['returntoquery'] = wfArrayToCgi( $returnToQuery );
		}

		$returnTo = $welcome->getPrefixedText();
		$returnToQuery = $query;
		return true;
	}

	public function onGetPreferences( $user, &$preferences ) {
		$preferences[SurveyResponses::OPTION] = [ 'type' => 'api' ];
	}

	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ) {
		if ( $this->isSurveyPage( $wikiPage ) ) {
			$this->surveyLoader->purgePageCache();
		}

		if ( !$this->environment->isEnabled() || !$user->isRegistered() || $editResult->isNullEdit() ) {
			return;
		}

		$revisionTimestamp = $revisionRecord->getTimestamp();
		DeferredUpdates::addCallableUpdate( function () use ( $user, $revisionTimestamp ) {
			$this->countNewcomerEdit( $user, $revisionTimestamp );
		} );
	}

	private function countNewcomerEdit( UserIdentity $userIdentity, string $revisionTimestamp ): void {
		$user = $this->userFactory->newFromUserIdentity( $userIdentity );
		if ( !$user->isNamed() || $user->isBot() ) {
			return;
		}

		$localRegistration = $user->getRegistration();
		if ( $localRegistration && !$this->environment->isWithinAttributionWindow( $localRegistration ) ) {
			return;
		}

		if ( !$this->environment->isNewcomer( $user ) ) {
			return;
		}

		$firstEdit = $this->userEditTracker->getFirstEditTimestamp( $user, IDBAccessObject::READ_LATEST );
		$isFirst = $firstEdit === false || $firstEdit === null || $firstEdit === $revisionTimestamp;

		$this->metrics->newcomerEdit(
			$this->experimentManager->assignUser( OnboardingEnvironment::ONBOARDING_EXPERIMENT, $user ),
			$this->environment->getContext(),
			$isFirst
		);
	}

	/**
	 * @param ManualLogEntry $logEntry
	 * @return bool|void
	 */
	public function onManualLogEntryBeforePublish( $logEntry ) {
		if ( $logEntry->getType() !== 'farmer' || !$this->environment->isEnabled() ) {
			return;
		}

		try {
			match ( $logEntry->getSubtype() ) {
				'requestwiki' => $this->countWikiRequest( $logEntry->getPerformerIdentity(), 'submitted' ),
				'requestapprove' => $this->countWikiRequestOutcome( $logEntry, 'approved' ),
				'requestdecline' => $this->countWikiRequestOutcome( $logEntry, 'declined' ),
				default => null,
			};
		} catch ( Throwable $e ) {
			$this->logger->warning( 'Could not count a wiki request: {message}', [
				'message' => $e->getMessage(),
				'exception' => $e,
			] );
		}
	}

	private function countWikiRequest( UserIdentity $requester, string $outcome ): void {
		if ( !$this->environment->isNewcomer( $requester ) ) {
			return;
		}

		$source = 'requestwiki';
		$services = MediaWikiServices::getInstance();
		if ( $outcome === 'submitted' && $services->has( 'WikiOasisMagic.OnboardingWikiRequestSubmitter' ) ) {
			/** @var WikiRequestSubmitter $submitter */
			$submitter = $services->get( 'WikiOasisMagic.OnboardingWikiRequestSubmitter' );
			if ( $submitter->isSubmitting() ) {
				$source = 'onboarding';
			}
		}

		$assignment = $this->experimentManager->assignUser( OnboardingEnvironment::ONBOARDING_EXPERIMENT, $requester );
		$this->metrics->wikiRequest( $assignment, $source, $outcome );
		if ( $outcome === 'approved' ) {
			$this->tracker->recordEvent( $assignment, 'wiki_approved' );
		}
	}

	private function countWikiRequestOutcome( ManualLogEntry $logEntry, string $outcome ): void {
		$id = $logEntry->getTarget()->getText();
		$id = str_starts_with( $id, 'RequestWikiQueue/' ) ? substr( $id, strlen( 'RequestWikiQueue/' ) ) : '';
		if ( !is_numeric( $id ) ) {
			return;
		}

		/** @var CreateWikiDatabaseUtils $databaseUtils */
		$databaseUtils = MediaWikiServices::getInstance()->get( 'CreateWikiDatabaseUtils' );
		$requesterId = $databaseUtils->getCentralWikiPrimaryDB()->newSelectQueryBuilder()
			->select( 'cw_user' )
			->from( 'cw_requests' )
			->where( [ 'cw_id' => (int)$id ] )
			->caller( __METHOD__ )
			->fetchField();

		if ( $requesterId ) {
			$this->countWikiRequest( $this->userFactory->newFromId( (int)$requesterId ), $outcome );
		}
	}

	public function onEditFilterMergedContent(
		IContextSource $context,
		Content $content,
		Status $status,
		$summary,
		User $user,
		$minoredit
	) {
		$title = $context->getTitle();
		if ( !$title || !$this->isSurveyPage( $title ) || !$content instanceof TextContent ) {
			return true;
		}

		$result = $this->surveyLoader->validateJson( $content->getText() );
		if ( $result->isOK() ) {
			return true;
		}

		$problems = '';
		foreach ( $result->getMessages( 'error' ) as $message ) {
			$problems .= '* ' . $context->msg( $message )->plain() . "\n";
		}

		$status->fatal( 'wikioasismagic-onboarding-survey-save-invalid', $problems );
		return false;
	}

	public function onPageDeleteComplete(
		ProperPageIdentity $page,
		Authority $deleter,
		string $reason,
		int $pageID,
		RevisionRecord $deletedRev,
		ManualLogEntry $logEntry,
		int $archivedRevisionCount
	) {
		if ( $this->isSurveyPage( $page ) ) {
			$this->surveyLoader->purgePageCache();
		}
	}

	/**
	 * @return array<string,string>
	 */
	private function getPreservedParams( WebRequest $request ): array {
		$params = [];
		foreach ( [ 'uselang', 'variant', 'display', 'returnto', 'returntoquery', 'returntoanchor' ] as $param ) {
			$params[$param] = $request->getRawVal( $param );
		}
		$this->hookContainer->run( 'AuthPreserveQueryParams', [ &$params, [ 'request' => $request, 'reset' => true ] ] );

		$preserved = [];
		foreach ( $params as $name => $value ) {
			if ( $value !== null && $value !== '' ) {
				$preserved[$name] = $value;
			}
		}
		return $preserved;
	}

	private function isPreviewingSignup( WebRequest $request ): bool {
		return isset( $this->experimentManager->getActiveOverrides( $request )[OnboardingEnvironment::SIGNUP_EXPERIMENT] );
	}

	private function isSurveyPage( PageReference $page ): bool {
		return $page->getNamespace() === NS_MEDIAWIKI && $page->getDBkey() === SurveyLoader::PAGE;
	}
}
