<?php

namespace WikiOasis\WikiOasisMagic\FandomImport;

use MediaWiki\Config\Config;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\Language\LanguageNameUtils;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Message\Message;
use MediaWiki\Permissions\Authority;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use Miraheze\CreateWiki\ConfigNames as CreateWikiConfigNames;
use Miraheze\CreateWiki\Services\CreateWikiValidator;
use Miraheze\CreateWiki\Services\WikiManagerFactory;
use Psr\Log\LoggerInterface;
use StatusValue;
use Throwable;
use Wikimedia\Message\MessageSpecifier;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use WikiOasis\WikiOasisMagic\Experiments\WikiFarm;
use WikiOasis\WikiOasisMagic\Jobs\FandomImportCreateWikiJob;
use WikiOasis\WikiOasisMagic\Jobs\FandomImportQueueJob;
use WikiOasis\WikiOasisMagic\Onboarding\WikiRequestSubmitter;
use function array_filter;
use function array_slice;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_encode;
use function mb_strlen;
use function mb_substr;
use function preg_match;
use function strip_tags;
use function strlen;
use function trim;

/**
 * Special:FandomImport's requests, from filing one to the worker's last report.
 *
 * Imports never touch CreateWiki's wiki requests: they live in
 * wo_fandom_import and create their wiki through WikiManagerFactory, the
 * way Special:CreateWiki does.
 */
class FandomImportManager {

	public const CONSTRUCTOR_OPTIONS = [
		ConfigNames::ENABLED,
		ConfigNames::LARGE_FILE_COUNT,
		ConfigNames::UPLOAD_USER,
		ConfigNames::USERNAME_PREFIX,
		ConfigNames::PREFER_FULL_HISTORY,
	];

	/** The same right as requesting a wiki. */
	public const RIGHT_REQUEST = 'requestwiki';

	/** Approving creates a wiki, so it takes the right to create wikis. */
	public const RIGHT_REVIEW = 'createwiki';

	/** The key CreateWiki hands back in CreateWikiAfterCreationWithExtraData. */
	public const EXTRA_KEY = 'fandomimport';

	public const MODES = [ 'move', 'fork' ];

	public const RATE_LIMIT = 'wikioasisfandomimport-submit';

	private const MAX_SITENAME = 128;
	private const MAX_REASON = 4096;
	private const MAX_FANDOM_USER = 85;
	private const MAX_PROGRESS_BYTES = 60000;
	private const MAX_FAILED_FILES = 100;
	private const MAX_ERROR = 1000;

	public function __construct(
		private readonly ServiceOptions $options,
		private readonly Config $createWikiConfig,
		private readonly FandomImportStore $store,
		private readonly FandomImportSpool $spool,
		private readonly FandomImportNotifier $notifier,
		private readonly WikiFarm $farm,
		private readonly WikiRequestSubmitter $requestSubmitter,
		private readonly CreateWikiValidator $validator,
		private readonly WikiManagerFactory $wikiManagerFactory,
		private readonly JobQueueGroupFactory $jobQueueGroupFactory,
		private readonly LanguageNameUtils $languageNameUtils,
		private readonly UserFactory $userFactory,
		private readonly LoggerInterface $logger,
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	/**
	 * @return string|null Why Special:FandomImport cannot be used here: a message key, or null if it can
	 */
	public function getUnavailableReason(): ?string {
		if ( !$this->options->get( ConfigNames::ENABLED ) ) {
			return 'wikioasismagic-fandomimport-disabled';
		}
		if ( !$this->farm->isCentralWiki() ) {
			return 'wikioasismagic-fandomimport-notcentral';
		}
		if ( !$this->store->isAvailable() ) {
			return 'wikioasismagic-fandomimport-notable';
		}
		return null;
	}

	public function getBlocker( User $user ): ?MessageSpecifier {
		return $this->requestSubmitter->getBlocker( $user );
	}

	public function canReview( Authority $performer ): bool {
		return $performer->isAllowed( self::RIGHT_REVIEW );
	}

	/**
	 * Whether a reviewer should confirm the size before approving: lots of
	 * files take days to fetch and room on the worker's disk. A wiki whose size
	 * we could not look up counts as large.
	 */
	public function isLarge( ?FandomWikiInfo $info ): bool {
		return !$info || $info->files >= (int)$this->options->get( ConfigNames::LARGE_FILE_COUNT );
	}

	public function getDbname( string $subdomain ): string {
		return $this->validator->getValidSubdomain( $subdomain ) .
			$this->createWikiConfig->get( CreateWikiConfigNames::DatabaseSuffix );
	}

	public function getWikiUrl( FandomImportRequest $request ): string {
		return $this->validator->getValidUrl( $request->dbname );
	}

	public function wikiExists( FandomImportRequest $request ): bool {
		return $this->validator->databaseExists( $request->dbname );
	}

	/**
	 * @return array<string,string> Labels to values, or [] if wikis have no categories
	 */
	public function getCategories(): array {
		return (array)$this->createWikiConfig->get( CreateWikiConfigNames::Categories );
	}

	public function checkSubdomain( string $subdomain ): ?MessageSpecifier {
		$error = $this->requestSubmitter->checkSubdomain( $subdomain );
		if ( $error ) {
			return $error;
		}

		if ( $this->store->hasActiveForDbname( $this->getDbname( $subdomain ) ) ) {
			return Message::newFromKey( 'wikioasismagic-fandomimport-error-subdomain-pending' );
		}

		return null;
	}

	/**
	 * File an import. $data holds subdomain, sitename, language, category,
	 * mode, reason and fandomuser, as Special:FandomImport's form sends them.
	 *
	 * @return StatusValue Good with the new import's ID
	 */
	public function submit( User $user, FandomSource $source, ?FandomWikiInfo $info, array $data ): StatusValue {
		$blocker = $this->getBlocker( $user );
		if ( $blocker ) {
			return StatusValue::newFatal( $blocker );
		}

		if ( $this->store->hasActiveForSource( $source ) ) {
			return StatusValue::newFatal( 'wikioasismagic-fandomimport-error-active' );
		}

		$text = static fn ( string $key ): string => is_string( $data[$key] ?? null ) ? trim( $data[$key] ) : '';
		$status = StatusValue::newGood();

		$subdomain = $text( 'subdomain' );
		$subdomainError = $this->checkSubdomain( $subdomain );
		if ( $subdomainError ) {
			$status->fatal( $subdomainError );
		}

		$sitename = $text( 'sitename' );
		if ( $sitename === '' || mb_strlen( $sitename ) > self::MAX_SITENAME ) {
			$status->fatal( 'wikioasismagic-fandomimport-error-sitename', self::MAX_SITENAME );
		}

		$language = $text( 'language' ) ?: 'en';
		if ( !$this->languageNameUtils->isKnownLanguageTag( $language ) ||
			!isset( $this->languageNameUtils->getLanguageNames()[$language] )
		) {
			$status->fatal( 'wikioasismagic-onboarding-err-language' );
		}

		$category = $text( 'category' );
		$categories = $this->getCategories();
		if ( $categories && !in_array( $category, $categories, true ) ) {
			$status->fatal( 'wikioasismagic-onboarding-err-category' );
		}

		$mode = $text( 'mode' );
		if ( !in_array( $mode, self::MODES, true ) ) {
			$mode = 'move';
		}

		$reason = $text( 'reason' );
		$reasonValid = $this->validator->validateReason( $reason, [] );
		if ( $reasonValid !== true ) {
			$status->fatal( $reasonValid );
		} elseif ( strlen( $reason ) > self::MAX_REASON ) {
			$status->fatal( 'wikioasismagic-onboarding-err-toolong', self::MAX_REASON );
		}

		$fandomUser = mb_substr( $text( 'fandomuser' ), 0, self::MAX_FANDOM_USER );

		if ( !$status->isOK() ) {
			return $status;
		}

		if ( $user->pingLimiter( self::RATE_LIMIT ) ) {
			return StatusValue::newFatal( 'actionthrottledtext' );
		}

		$id = $this->store->insert( [
			'source' => $source->getKey(),
			'dbname' => $this->getDbname( $subdomain ),
			'sitename' => $sitename,
			'language' => $language,
			'category' => $category,
			'mode' => $mode,
			'reason' => $reason,
			'fandom_user' => $fandomUser,
			'requester' => $user->getId(),
			'status' => FandomImportStatus::PENDING,
			'info' => $info?->toArray() ?? [],
		] );

		$request = $this->store->get( $id, true );
		if ( $request ) {
			$this->log( 'request', $request, $user, $reason );
		}

		return StatusValue::newGood( $id );
	}

	public function approve(
		FandomImportRequest $request,
		User $reviewer,
		string $comment,
		bool $acknowledgedSize
	): StatusValue {
		if ( !$this->canReview( $reviewer ) ) {
			return StatusValue::newFatal( 'wikioasismagic-fandomimport-error-noreview' );
		}

		if ( $this->isLarge( $request->getWikiInfo() ) && !$acknowledgedSize ) {
			return StatusValue::newFatal( 'wikioasismagic-fandomimport-error-acknowledge' );
		}

		if ( $this->wikiExists( $request ) ) {
			return StatusValue::newFatal( 'wikioasismagic-fandomimport-error-wikiexists', $request->dbname );
		}

		if ( !$this->store->update( $request->id, [
			'status' => FandomImportStatus::APPROVED,
			'stage' => FandomImportStatus::STAGE_CREATE,
			'reviewer' => $reviewer->getId(),
		], [ FandomImportStatus::PENDING ] ) ) {
			return StatusValue::newFatal( 'wikioasismagic-fandomimport-error-changed' );
		}

		$this->pushJob( FandomImportCreateWikiJob::newSpec( $request->id ) );
		$this->log( 'approve', $request, $reviewer, $comment );

		return StatusValue::newGood();
	}

	public function decline( FandomImportRequest $request, User $reviewer, string $reason ): StatusValue {
		if ( !$this->canReview( $reviewer ) ) {
			return StatusValue::newFatal( 'wikioasismagic-fandomimport-error-noreview' );
		}

		$reason = trim( $reason );
		if ( $reason === '' ) {
			return StatusValue::newFatal( 'htmlform-required' );
		}

		if ( !$this->store->update( $request->id, [
			'status' => FandomImportStatus::DECLINED,
			'reviewer' => $reviewer->getId(),
			'error' => mb_substr( $reason, 0, self::MAX_ERROR ),
		], [ FandomImportStatus::PENDING ] ) ) {
			return StatusValue::newFatal( 'wikioasismagic-fandomimport-error-changed' );
		}

		$this->notifier->notify( $request, FandomImportNotifier::DECLINED, $reason );
		$this->log( 'decline', $request, $reviewer, $reason );

		return StatusValue::newGood();
	}

	/**
	 * Start a failed import again: create the wiki if that is where it
	 * failed, otherwise hand it back to the worker, which carries on from the
	 * stage that failed if it still has the files.
	 */
	public function retry( FandomImportRequest $request, User $reviewer ): StatusValue {
		if ( !$this->canReview( $reviewer ) ) {
			return StatusValue::newFatal( 'wikioasismagic-fandomimport-error-noreview' );
		}

		$progress = [ 'attempt' => (int)$request->getProgress( 'attempt', 1 ) + 1 ] + $request->progress;
		$created = $this->wikiExists( $request );

		if ( !$this->store->update( $request->id, [
			'status' => $created ? FandomImportStatus::CREATING : FandomImportStatus::APPROVED,
			'stage' => $created ? FandomImportStatus::STAGE_HANDOFF : FandomImportStatus::STAGE_CREATE,
			'error' => '',
			'progress' => $progress,
		], [ FandomImportStatus::FAILED ] ) ) {
			return StatusValue::newFatal( 'wikioasismagic-fandomimport-error-changed' );
		}

		$this->pushJob( $created ?
			FandomImportQueueJob::newSpec( $request->id, FandomImportQueueJob::ACTION_RUN ) :
			FandomImportCreateWikiJob::newSpec( $request->id )
		);
		$this->log( 'retry', $request, $reviewer );

		return StatusValue::newGood();
	}

	/**
	 * Have the worker delete the files it kept so a failed import could resume.
	 */
	public function discardFiles( FandomImportRequest $request, User $reviewer ): StatusValue {
		if ( !$this->canReview( $reviewer ) ) {
			return StatusValue::newFatal( 'wikioasismagic-fandomimport-error-noreview' );
		}

		if ( $request->filesExpire === null ) {
			return StatusValue::newFatal( 'wikioasismagic-fandomimport-error-nofiles' );
		}

		$this->pushJob( FandomImportQueueJob::newSpec( $request->id, FandomImportQueueJob::ACTION_DISCARD ) );

		return StatusValue::newGood();
	}

	/**
	 * Create the wiki for an approved import. Runs in FandomImportCreateWikiJob.
	 *
	 * On success CreateWiki finishes setting the wiki up after the job (local
	 * account, admin rights) and then calls onWikiCreated().
	 */
	public function createWiki( int $id ): void {
		$request = $this->store->get( $id, true );
		if ( !$request || !$this->store->update( $id, [
			'status' => FandomImportStatus::CREATING,
			'stage' => FandomImportStatus::STAGE_CREATE,
		], [ FandomImportStatus::APPROVED ] ) ) {
			$this->logger->info( 'Fandom import {id} is no longer waiting for its wiki; not creating it.', [
				'id' => $id,
			] );
			return;
		}

		$requester = $this->userFactory->newFromId( $request->requesterId );
		$reviewer = $this->userFactory->newFromId( $request->reviewerId );

		try {
			$error = $this->wikiManagerFactory->newInstance( $request->dbname )->create(
				sitename: $request->sitename,
				language: $request->language,
				private: false,
				category: $request->category ?: 'uncategorised',
				requester: $requester->getName(),
				actor: $reviewer->getName(),
				reason: "[[Special:FandomImport/{$request->source->getSubpage()}|" .
					"Imported from {$request->source->getHost()}]]",
				extra: [ self::EXTRA_KEY => $id ]
			);
		} catch ( Throwable $e ) {
			$this->logger->error( 'Creating the wiki for Fandom import {id} failed: {message}', [
				'id' => $id,
				'message' => $e->getMessage(),
				'exception' => $e,
			] );
			$error = $e->getMessage();
		}

		if ( $error ) {
			$this->markFailed( $request, FandomImportStatus::STAGE_CREATE, strip_tags( $error ) );
		}
	}

	/**
	 * CreateWiki has finished setting up the wiki of an import: hand it to the worker.
	 */
	public function onWikiCreated( int $id, string $dbname ): void {
		$request = $this->store->get( $id, true );
		if ( !$request || $request->dbname !== $dbname ) {
			$this->logger->warning( 'CreateWiki reported {dbname} for unknown Fandom import {id}.', [
				'id' => $id,
				'dbname' => $dbname,
			] );
			return;
		}

		if ( $this->store->update( $id, [
			'stage' => FandomImportStatus::STAGE_HANDOFF,
		], [ FandomImportStatus::CREATING ] ) ) {
			$this->pushJob( FandomImportQueueJob::newSpec( $id, FandomImportQueueJob::ACTION_RUN ) );
		}
	}

	/**
	 * Write the import's manifest to the worker's queue. Runs in FandomImportQueueJob.
	 */
	public function handOff( int $id ): void {
		$request = $this->store->get( $id, true );
		if ( !$request || $request->status !== FandomImportStatus::CREATING ) {
			return;
		}

		try {
			$this->spool->writeManifest( $this->buildManifest( $request ) );
		} catch ( Throwable $e ) {
			$this->logger->error( 'Could not hand Fandom import {id} to the worker: {message}', [
				'id' => $id,
				'message' => $e->getMessage(),
				'exception' => $e,
			] );
			$this->markFailed( $request, FandomImportStatus::STAGE_HANDOFF, $e->getMessage() );
			return;
		}

		$this->store->update( $id, [
			'status' => FandomImportStatus::QUEUED,
			'stage' => FandomImportStatus::STAGE_HANDOFF,
		], [ FandomImportStatus::CREATING ] );
	}

	/**
	 * Ask the worker to delete an import's files. Runs in FandomImportQueueJob.
	 */
	public function handOffDiscard( int $id ): void {
		$this->spool->writeDiscard( $id, $this->farm->getCentralWiki() );
	}

	/**
	 * What the worker needs to know, and all it may trust: it rebuilds every
	 * URL from the subdomain and language and checks the rest.
	 */
	public function buildManifest( FandomImportRequest $request ): array {
		$source = $request->source;
		return [
			'version' => FandomImportSpool::MANIFEST_VERSION,
			'id' => $request->id,
			'central_wiki' => $this->farm->getCentralWiki(),
			'target_wiki' => $request->dbname,
			'source' => [
				'key' => $source->getKey(),
				'subdomain' => $source->subdomain,
				'lang' => $source->lang,
				'base_url' => $source->getBaseUrl(),
				'api_url' => $source->getApiUrl(),
			],
			'username_prefix' => (string)$this->options->get( ConfigNames::USERNAME_PREFIX ),
			'upload_user' => (string)$this->options->get( ConfigNames::UPLOAD_USER ),
			'summary' => 'Imported from ' . $source->getBaseUrl(),
			'prefer_full_history' => (bool)$this->options->get( ConfigNames::PREFER_FULL_HISTORY ),
			'queued_at' => ConvertibleTimestamp::convert( TS_ISO_8601, ConvertibleTimestamp::now() ),
			'attempt' => (int)$request->getProgress( 'attempt', 1 ),
		];
	}

	/**
	 * Take a report from the worker (maintenance/FandomImportCallback.php).
	 *
	 * A report that comes too late to matter, such as one for an import a
	 * reviewer has since retried, is accepted and ignored with a warning, so
	 * the worker does not keep retrying it.
	 *
	 * @param int $id
	 * @param string $event started, stage, progress, waiting-dump, done, failed or files-deleted
	 * @param string|null $stage
	 * @param array $data Progress to merge
	 * @param string $message A note to show, or the error for "failed"
	 * @return StatusValue
	 */
	public function handleWorkerEvent( int $id, string $event, ?string $stage, array $data, string $message ): StatusValue {
		if ( $stage !== null && !FandomImportStatus::isStage( $stage ) ) {
			return StatusValue::newFatal( 'wikioasismagic-fandomimport-error-badstage', $stage );
		}

		$request = $this->store->get( $id, true );
		if ( !$request ) {
			return StatusValue::newGood()->warning( 'wikioasismagic-fandomimport-error-unknown', $id );
		}

		$progress = $this->mergeProgress( $request->progress, $data );
		$filesExpire = is_string( $data['files_expire'] ?? null ) ?
			( ConvertibleTimestamp::convert( TS_MW, $data['files_expire'] ) ?: null ) :
			null;

		$fields = match ( $event ) {
			'started', 'stage' => [
				'status' => FandomImportStatus::RUNNING,
				'stage' => $stage ?? $request->stage,
				'progress' => $this->withNote( $progress, '' ),
			],
			'progress' => [
				'progress' => $this->withNote( $progress, $message ),
			],
			'waiting-dump' => [
				'status' => FandomImportStatus::WAITING_DUMP,
				'stage' => FandomImportStatus::STAGE_DUMP,
				'progress' => $this->withNote( $progress, '' ),
			],
			'done' => [
				'status' => FandomImportStatus::DONE,
				'stage' => FandomImportStatus::STAGE_FINALIZE,
				'progress' => [ 'files' => 'deleted' ] + $this->withNote( $progress, '' ),
				'error' => '',
				'files_expire' => null,
			],
			'failed' => [
				'status' => FandomImportStatus::FAILED,
				'stage' => $stage ?? $request->stage,
				'progress' => [ 'files' => $filesExpire ? 'kept' : 'deleted' ] + $this->withNote( $progress, '' ),
				'error' => mb_substr( $message, 0, self::MAX_ERROR ),
				'files_expire' => $filesExpire,
			],
			'files-deleted' => [
				'progress' => [ 'files' => 'deleted' ] + $request->progress,
				'files_expire' => null,
			],
			default => null,
		};

		if ( $fields === null ) {
			return StatusValue::newFatal( 'wikioasismagic-fandomimport-error-badevent', $event );
		}

		$from = $event === 'files-deleted' ? null : FandomImportStatus::WORKER;
		if ( !$this->store->update( $id, $fields, $from ) ) {
			return StatusValue::newGood()->warning( 'wikioasismagic-fandomimport-error-stale', $id, $request->status );
		}

		if ( $event === 'waiting-dump' && $request->status !== FandomImportStatus::WAITING_DUMP ) {
			$this->notifier->notify( $request, FandomImportNotifier::NEEDS_DUMP );
		} elseif ( $event === 'done' ) {
			$this->notifier->notify( $request, FandomImportNotifier::DONE, '', $this->getWikiUrl( $request ) );
			$this->log( 'complete', $request, null );
		} elseif ( $event === 'failed' ) {
			$this->notifier->notify( $request, FandomImportNotifier::FAILED, $message );
			$this->log( 'fail', $request, null, $message );
		}

		return StatusValue::newGood();
	}

	private function markFailed( FandomImportRequest $request, string $stage, string $error ): void {
		$error = mb_substr( trim( $error ), 0, self::MAX_ERROR );
		if ( !$this->store->update( $request->id, [
			'status' => FandomImportStatus::FAILED,
			'stage' => $stage,
			'error' => $error,
		], [
			FandomImportStatus::APPROVED,
			FandomImportStatus::CREATING,
			FandomImportStatus::QUEUED,
		] ) ) {
			return;
		}

		$this->notifier->notify( $request, FandomImportNotifier::FAILED, $error );
		$this->log( 'fail', $request, null, $error );
	}

	/**
	 * Merge the worker's progress, keeping only plain, small values: it is
	 * shown on a public page and stored in a blob.
	 */
	private function mergeProgress( array $progress, array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( !is_string( $key ) || !preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $key ) || $key === 'files_expire' ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$value = array_slice( array_filter(
					$value,
					static fn ( $item ): bool => is_string( $item ) || is_int( $item ) || is_float( $item ) ||
						( is_array( $item ) && json_encode( $item ) !== false && strlen( json_encode( $item ) ) < 500 )
				), 0, self::MAX_FAILED_FILES, true );
			} elseif ( is_string( $value ) ) {
				$value = mb_substr( $value, 0, 500 );
			} elseif ( !is_int( $value ) && !is_float( $value ) && !is_bool( $value ) && $value !== null ) {
				continue;
			}

			$progress[$key] = $value;
		}

		if ( strlen( (string)json_encode( $progress ) ) > self::MAX_PROGRESS_BYTES ) {
			unset( $progress['failed_files'] );
		}

		return $progress;
	}

	private function withNote( array $progress, string $note ): array {
		$note = mb_substr( trim( $note ), 0, 500 );
		if ( $note === '' ) {
			unset( $progress['note'] );
		} else {
			$progress['note'] = $note;
		}
		return $progress;
	}

	private function pushJob( JobSpecification $job ): void {
		$this->jobQueueGroupFactory->makeJobQueueGroup()->push( $job );
	}

	/**
	 * @param string $action
	 * @param FandomImportRequest $request
	 * @param UserIdentity|null $performer Null for what the system did by itself
	 * @param string $comment
	 */
	private function log(
		string $action,
		FandomImportRequest $request,
		?UserIdentity $performer,
		string $comment = ''
	): void {
		try {
			$entry = new ManualLogEntry( 'fandomimport', $action );
			$entry->setPerformer( $performer ?? User::newSystemUser( User::MAINTENANCE_SCRIPT_USER, [ 'steal' => true ] ) );
			$entry->setTarget( SpecialPage::getTitleFor( 'FandomImport', $request->source->getSubpage() ) );
			$entry->setComment( mb_substr( $comment, 0, 500 ) );
			$entry->setParameters( [
				'4::source' => $request->source->getHost(),
				'5::wiki' => $request->dbname,
			] );
			$entry->publish( $entry->insert() );
		} catch ( Throwable $e ) {
			$this->logger->error( 'Could not log Fandom import {id}: {message}', [
				'id' => $request->id,
				'message' => $e->getMessage(),
				'exception' => $e,
			] );
		}
	}
}
