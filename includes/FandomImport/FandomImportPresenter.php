<?php

namespace WikiOasis\WikiOasisMagic\FandomImport;

use MediaWiki\Context\IContextSource;
use MediaWiki\Language\LanguageNameUtils;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserFactory;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use function array_search;
use function in_array;
use function is_string;
use function strtolower;

/**
 * Turns Fandom imports into the plain arrays Special:FandomImport's Vue app
 * and its API modules work with. Dates are formatted for the viewer here, so
 * the app never has to.
 */
class FandomImportPresenter {

	/** Statuses that change by themselves, so the status page keeps refreshing. */
	private const LIVE = [
		FandomImportStatus::APPROVED,
		FandomImportStatus::CREATING,
		FandomImportStatus::QUEUED,
		FandomImportStatus::RUNNING,
		FandomImportStatus::WAITING_DUMP,
	];

	private const LIST_LIMIT = 50;

	public function __construct(
		private readonly FandomImportManager $manager,
		private readonly FandomImportStore $store,
		private readonly FandomClient $client,
		private readonly UserFactory $userFactory,
		private readonly LanguageNameUtils $languageNameUtils,
	) {
	}

	public function source( FandomSource $source ): array {
		return [
			'key' => $source->getKey(),
			'subpage' => $source->getSubpage(),
			'host' => $source->getHost(),
			'baseUrl' => $source->getBaseUrl(),
			'statisticsUrl' => $source->getPageUrl( 'Special:Statistics' ),
			'pageUrl' => SpecialPage::getTitleFor( 'FandomImport', $source->getSubpage() )->getLocalURL(),
		];
	}

	public function summary( FandomImportRequest $request, IContextSource $context ): array {
		return [
			'id' => $request->id,
			'source' => $this->source( $request->source ),
			'dbname' => $request->dbname,
			'sitename' => $request->sitename,
			'status' => $request->status,
			'stage' => $request->stage,
			'requester' => $this->user( $request->requesterId ),
			'updated' => $this->iso( $request->updated ),
			'updatedText' => $this->date( $request->updated, $context ),
		];
	}

	public function detail( FandomImportRequest $request, IContextSource $context ): array {
		$canReview = $this->manager->canReview( $context->getAuthority() );
		$status = $request->status;
		$info = $request->getWikiInfo();
		$dumpVariant = $request->getProgress( 'dump_variant' );
		$dumpDate = $request->getProgress( 'dump_date' );

		return $this->summary( $request, $context ) + [
			'wikiUrl' => $this->manager->getWikiUrl( $request ),
			'wikiExists' => $this->manager->wikiExists( $request ),
			'language' => $request->language,
			'languageName' => $this->languageNameUtils->getLanguageName( $request->language ) ?: $request->language,
			'category' => $request->category,
			'mode' => $request->mode,
			'reason' => $request->reason,
			'fandomUser' => $request->fandomUser,
			'fandomUserUrl' => $request->fandomUser !== '' ?
				$request->source->getPageUrl( 'User:' . $request->fandomUser ) :
				null,
			'reviewer' => $request->reviewerId ? $this->user( $request->reviewerId ) : null,
			'created' => $this->iso( $request->created ),
			'createdText' => $this->date( $request->created, $context ),
			'error' => $request->error,
			'filesExpire' => $request->filesExpire !== null ? $this->iso( $request->filesExpire ) : null,
			'filesExpireText' => $request->filesExpire !== null ? $this->date( $request->filesExpire, $context ) : null,
			'stages' => $this->stages( $request ),
			'progress' => $request->progress,
			'dump' => is_string( $dumpVariant ) ? [
				'variant' => $dumpVariant,
				'dateText' => is_string( $dumpDate ) ? $this->date( $dumpDate, $context, false ) : null,
			] : null,
			'info' => $info?->toArray(),
			'large' => $this->manager->isLarge( $info ),
			'live' => in_array( $status, self::LIVE, true ),
			'permissions' => [
				'review' => $canReview,
				'approve' => $canReview && $status === FandomImportStatus::PENDING,
				'decline' => $canReview && $status === FandomImportStatus::PENDING,
				'retry' => $canReview && $status === FandomImportStatus::FAILED,
				'discard' => $canReview && $status === FandomImportStatus::FAILED && $request->filesExpire !== null,
				'requestAgain' => $status === FandomImportStatus::DECLINED &&
					!$this->manager->getBlocker( $this->userFactory->newFromAuthority( $context->getAuthority() ) ),
			],
		];
	}

	/**
	 * What a Fandom wiki looks like before anyone asks to import it.
	 */
	public function preview( FandomSource $source, IContextSource $context ): array {
		$lookup = $this->client->getWikiInfo( $source );
		$info = $lookup->isGood() ? $lookup->getValue() : null;

		$dump = null;
		if ( $info ) {
			foreach ( $this->client->getDumps( $info->wikiId ) as $candidate ) {
				if ( $candidate['available'] ) {
					$dump = [
						'variant' => $candidate['variant'],
						'size' => $candidate['size'],
						'dateText' => $candidate['lastModified'] ?
							$this->date( $candidate['lastModified'], $context, false ) :
							null,
					];
					break;
				}
			}
		}

		return [
			'source' => $this->source( $source ),
			'info' => $info?->toArray(),
			'lookupError' => $info ? null : ( $lookup->hasMessage( 'wikioasismagic-fandomimport-lookup-missing' ) ?
				'missing' :
				'unreachable' ),
			'dump' => $dump,
			'large' => $this->manager->isLarge( $info ),
			'suggestedSubdomain' => $source->getSuggestedSubdomain(),
		];
	}

	/**
	 * Everything the request form needs besides the preview.
	 */
	public function form( IContextSource $context ): array {
		$languages = [];
		foreach ( $this->languageNameUtils->getLanguageNames() as $code => $name ) {
			$languages[] = [ 'value' => $code, 'label' => "$code – $name" ];
		}

		$categories = [];
		foreach ( $this->manager->getCategories() as $label => $value ) {
			$categories[] = [ 'value' => $value, 'label' => (string)$label ];
		}

		$blocker = $this->manager->getBlocker(
			$this->userFactory->newFromAuthority( $context->getAuthority() )
		);

		return [
			'domain' => $this->manager->getDomain(),
			'languages' => $languages,
			'categories' => $categories,
			'blocker' => $blocker ? $context->msg( $blocker )->parse() : null,
		];
	}

	public function index( IContextSource $context ): array {
		$user = $context->getUser();
		$mine = [];
		if ( $user->isRegistered() ) {
			foreach ( $this->store->list( [], self::LIST_LIMIT, $user->getId() ) as $request ) {
				$mine[] = $this->summary( $request, $context );
			}
		}

		$queues = null;
		if ( $this->manager->canReview( $context->getAuthority() ) ) {
			$queues = [];
			foreach ( [
				'pending' => [ FandomImportStatus::PENDING ],
				'inprogress' => FandomImportStatus::IN_PROGRESS,
				'failed' => [ FandomImportStatus::FAILED ],
				'done' => [ FandomImportStatus::DONE ],
				'declined' => [ FandomImportStatus::DECLINED ],
			] as $name => $statuses ) {
				$queues[$name] = [];
				foreach ( $this->store->list( $statuses, self::LIST_LIMIT ) as $request ) {
					$queues[$name][] = $this->summary( $request, $context );
				}
			}
		}

		return [ 'mine' => $mine, 'queues' => $queues ];
	}

	/**
	 * Whether the person who filed an import is an admin of the Fandom wiki.
	 *
	 * @return array{checked:bool,exists:bool,admin:bool,groups:string[]}
	 */
	public function account( FandomImportRequest $request ): array {
		$groups = $request->fandomUser !== '' ?
			$this->client->getUserGroups( $request->source, $request->fandomUser ) :
			null;

		if ( $groups === null ) {
			return [ 'checked' => false, 'exists' => false, 'admin' => false, 'groups' => [] ];
		}

		$admin = [];
		foreach ( $groups['groups'] as $group ) {
			if ( in_array( strtolower( $group ), [ 'sysop', 'bureaucrat' ], true ) ) {
				$admin[] = $group;
			}
		}

		return [
			'checked' => true,
			'exists' => $groups['exists'],
			'admin' => (bool)$admin,
			'groups' => $admin,
		];
	}

	/**
	 * @return array<int,array{id:string,state:string}>
	 */
	public function stages( FandomImportRequest $request ): array {
		$current = array_search( $request->stage, FandomImportStatus::STAGES, true );
		$stages = [];
		foreach ( FandomImportStatus::STAGES as $index => $stage ) {
			if ( $request->status === FandomImportStatus::DONE || ( $current !== false && $index < $current ) ) {
				$state = 'done';
			} elseif ( $index === $current && in_array( $request->status, [
				FandomImportStatus::PENDING,
				FandomImportStatus::DECLINED,
			], true ) ) {
				$state = 'todo';
			} elseif ( $index === $current ) {
				$state = match ( $request->status ) {
					FandomImportStatus::FAILED => 'failed',
					FandomImportStatus::WAITING_DUMP => 'waiting',
					default => 'current',
				};
			} else {
				$state = 'todo';
			}
			$stages[] = [ 'id' => $stage, 'state' => $state ];
		}

		return $stages;
	}

	/**
	 * @return array{name:string,url:string}|null
	 */
	private function user( int $id ): ?array {
		$user = $this->userFactory->newFromId( $id );
		if ( !$user->isRegistered() ) {
			return null;
		}

		return [
			'name' => $user->getName(),
			'url' => $user->getUserPage()->getLocalURL(),
		];
	}

	private function iso( string $timestamp ): ?string {
		return ConvertibleTimestamp::convert( TS_ISO_8601, $timestamp ) ?: null;
	}

	private function date( string $timestamp, IContextSource $context, bool $withTime = true ): ?string {
		$timestamp = ConvertibleTimestamp::convert( TS_MW, $timestamp );
		if ( !$timestamp ) {
			return null;
		}

		$language = $context->getLanguage();
		return $withTime ?
			$language->userTimeAndDate( $timestamp, $context->getUser() ) :
			$language->userDate( $timestamp, $context->getUser() );
	}
}
