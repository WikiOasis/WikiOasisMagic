<?php

namespace WikiOasis\WikiOasisMagic\Onboarding;

use MediaWiki\Config\Config;
use MediaWiki\Language\LanguageNameUtils;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;
use MessageLocalizer;
use Miraheze\CreateWiki\ConfigNames as CreateWikiConfigNames;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\CreateWikiValidator;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Psr\Log\LoggerInterface;
use Throwable;
use Wikimedia\Message\MessageSpecifier;
use Wikimedia\Rdbms\ReadOnlyMode;
use function in_array;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function parse_url;
use function strlen;
use function trim;

class WikiRequestSubmitter {

	private const PENDING_STATUSES = [ 'inreview', 'onhold', 'moredetails' ];

	private const MAX_SITENAME = 128;
	private const MAX_REASON = 4096;
	private const MAX_SHORT_TEXT = 255;

	private bool $submitting = false;

	public function __construct(
		private readonly Config $createWikiConfig,
		private readonly CreateWikiDatabaseUtils $databaseUtils,
		private readonly CreateWikiValidator $validator,
		private readonly WikiRequestManager $wikiRequestManager,
		private readonly LanguageNameUtils $languageNameUtils,
		private readonly ReadOnlyMode $readOnlyMode,
		private readonly LoggerInterface $logger,
	) {
	}

	public function isSubmitting(): bool {
		return $this->submitting;
	}

	public function getBlocker( User $user ): ?MessageSpecifier {
		if ( !$user->isNamed() ) {
			return Message::newFromKey( 'requestwiki-notloggedin' );
		}

		$block = $user->getBlock();
		if ( $block && ( $block->isSitewide() || $block->appliesToRight( 'requestwiki' ) ) ) {
			return Message::newFromKey( 'wikioasismagic-onboarding-wiki-blocked' );
		}

		if ( !$user->isAllowed( 'requestwiki' ) ) {
			return Message::newFromKey( 'wikioasismagic-onboarding-wiki-norights' );
		}

		if (
			$this->createWikiConfig->get( CreateWikiConfigNames::RequestWikiConfirmEmail ) &&
			!$user->isEmailConfirmed()
		) {
			return Message::newFromKey( 'wikioasismagic-onboarding-wiki-confirmemail' );
		}

		if ( $this->readOnlyMode->isReadOnly() ) {
			return Message::newFromKey( 'readonlytext' )->params( $this->readOnlyMode->getReason() );
		}

		return null;
	}

	public function checkSubdomain( string $subdomain ): ?MessageSpecifier {
		$valid = $this->validator->validateSubdomain( $subdomain, [] );
		if ( $valid !== true ) {
			return $valid;
		}

		$dbname = $this->validator->getValidSubdomain( $subdomain ) .
			$this->createWikiConfig->get( CreateWikiConfigNames::DatabaseSuffix );

		$pending = $this->databaseUtils->getCentralWikiReplicaDB()->newSelectQueryBuilder()
			->select( 'cw_id' )
			->from( 'cw_requests' )
			->where( [
				'cw_dbname' => $dbname,
				'cw_status' => self::PENDING_STATUSES,
				'cw_visibility' => WikiRequestManager::VISIBILITY_PUBLIC,
			] )
			->caller( __METHOD__ )
			->fetchField();

		return $pending ? Message::newFromKey( 'wikioasismagic-onboarding-subdomain-pending' ) : null;
	}

	/**
	 * @param User $user
	 * @param array $input
	 * @param MessageLocalizer $localizer
	 * @return array{ok:bool,id?:int,url?:string,errors:array<string,MessageSpecifier>}
	 */
	public function submit( User $user, array $input, MessageLocalizer $localizer ): array {
		$blocker = $this->getBlocker( $user );
		if ( $blocker ) {
			return [ 'ok' => false, 'errors' => [ '' => $blocker ] ];
		}

		[ $data, $extra, $errors ] = $this->validate( $input, $localizer );
		if ( $errors ) {
			return [ 'ok' => false, 'errors' => $errors ];
		}

		if ( $user->pingLimiter( 'requestwiki' ) ) {
			return [ 'ok' => false, 'errors' => [ '' => Message::newFromKey( 'actionthrottledtext' ) ] ];
		}

		if ( $this->wikiRequestManager->isDuplicateRequest( $data['sitename'] ) ) {
			return [ 'ok' => false, 'errors' => [ 'sitename' => Message::newFromKey( 'requestwiki-error-patient' ) ] ];
		}

		$this->submitting = true;
		try {
			$this->wikiRequestManager->createNewRequestAndLog( $data, $extra, $user );
		} catch ( Throwable $e ) {
			$this->logger->error( 'Filing a wiki request from onboarding failed: {message}', [
				'message' => $e->getMessage(),
				'exception' => $e,
			] );
			return [ 'ok' => false, 'errors' => [ '' => Message::newFromKey( 'wikioasismagic-onboarding-wiki-failed' ) ] ];
		} finally {
			$this->submitting = false;
		}

		$id = $this->wikiRequestManager->getId();
		return [
			'ok' => true,
			'id' => $id,
			'url' => SpecialPage::getTitleFor( 'RequestWikiQueue', (string)$id )->getFullURL(),
			'errors' => [],
		];
	}

	/**
	 * @return array{0:array,1:array,2:array<string,MessageSpecifier>}
	 */
	private function validate( array $input, MessageLocalizer $localizer ): array {
		$errors = [];
		$text = static fn ( string $key ): string => is_string( $input[$key] ?? null ) ? trim( $input[$key] ) : '';
		$flag = static fn ( string $key ): bool => !empty( $input[$key] ) && $input[$key] !== 'false';

		$subdomain = $text( 'subdomain' );
		$subdomainError = $this->checkSubdomain( $subdomain );
		if ( $subdomainError ) {
			$errors['subdomain'] = $subdomainError;
		}

		$sitename = $text( 'sitename' );
		if ( $sitename === '' ) {
			$errors['sitename'] = Message::newFromKey( 'wikioasismagic-onboarding-err-sitename' );
		} elseif ( mb_strlen( $sitename ) > self::MAX_SITENAME ) {
			$errors['sitename'] = Message::newFromKey( 'wikioasismagic-onboarding-err-toolong' )
				->numParams( self::MAX_SITENAME );
		}

		$language = $text( 'language' ) ?: 'en';
		if ( !$this->languageNameUtils->isKnownLanguageTag( $language ) ||
			!isset( $this->languageNameUtils->getLanguageNames()[$language] )
		) {
			$errors['language'] = Message::newFromKey( 'wikioasismagic-onboarding-err-language' );
		}

		$data = [
			'subdomain' => $subdomain,
			'sitename' => $sitename,
			'language' => $language,
		];

		$categories = (array)$this->createWikiConfig->get( CreateWikiConfigNames::Categories );
		if ( $categories ) {
			$category = $text( 'category' );
			if ( !in_array( $category, $categories, true ) ) {
				$errors['category'] = Message::newFromKey( 'wikioasismagic-onboarding-err-category' );
			}
			$data['category'] = $category;
		}

		$purposes = (array)$this->createWikiConfig->get( CreateWikiConfigNames::Purposes );
		if ( $purposes ) {
			$purpose = $text( 'purpose' );
			if ( !in_array( $purpose, $purposes, true ) ) {
				$errors['purpose'] = Message::newFromKey( 'wikioasismagic-onboarding-err-purpose' );
			}
			$data['purpose'] = $purpose;
		}

		$reason = $text( 'reason' );
		$reasonValid = $this->validator->validateReason( $reason, [] );
		if ( $reasonValid !== true ) {
			$errors['reason'] = $reasonValid;
		} elseif ( strlen( $reason ) > self::MAX_REASON ) {
			$errors['reason'] = Message::newFromKey( 'wikioasismagic-onboarding-err-toolong' )
				->numParams( self::MAX_REASON );
		}
		$data['reason'] = $reason;

		if ( $this->createWikiConfig->get( CreateWikiConfigNames::UsePrivateWikis ) ) {
			$data['private'] = $flag( 'private' );
		}

		if ( $this->createWikiConfig->get( CreateWikiConfigNames::ShowBiographicalOption ) ) {
			$data['bio'] = $flag( 'bio' );
		}

		if ( $this->createWikiConfig->get( CreateWikiConfigNames::RequestWikiConfirmAgreement ) ) {
			$agreement = $this->validator->validateAgreement( $flag( 'agreement' ) );
			if ( $agreement !== true ) {
				$errors['agreement'] = $agreement;
			}
			$data['agreement'] = true;
		}

		$nsfw = $flag( 'nsfw' );
		$source = $flag( 'source' );
		$extra = [
			'nsfw' => $nsfw,
			'nsfwtext' => $nsfw ? mb_substr( $text( 'nsfwtext' ), 0, self::MAX_SHORT_TEXT ) : '',
			'nsfw-primary' => $nsfw && $flag( 'nsfw-primary' ),
			'source' => $source,
			'sourceurl' => '',
			'onboarding' => true,
		];

		if ( $source ) {
			$sourceUrl = $text( 'sourceurl' );
			$scheme = parse_url( $sourceUrl, PHP_URL_SCHEME );
			if ( !in_array( $scheme, [ 'http', 'https' ], true ) || !parse_url( $sourceUrl, PHP_URL_HOST ) ) {
				$errors['sourceurl'] = Message::newFromKey( 'wikioasismagic-onboarding-err-sourceurl' );
			}
			$extra['sourceurl'] = mb_substr( $sourceUrl, 0, self::MAX_SHORT_TEXT );
			$sourceType = $text( 'sourcetype' );
			$extra['sourcetype'] = $sourceType === 'fork' ? 'fork' : 'move';
		}

		foreach ( $errors as $field => $error ) {
			if ( !$error instanceof Message ) {
				$errors[$field] = $localizer->msg( $error );
			}
		}

		return [ $data, $extra, $errors ];
	}
}
