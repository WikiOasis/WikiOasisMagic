<?php

namespace WikiOasis\WikiOasisMagic\Specials;

use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Linker\Linker;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\User\UserFactory;
use StatusValue;
use WikiOasis\WikiOasisMagic\FandomImport\FandomClient;
use WikiOasis\WikiOasisMagic\FandomImport\FandomImportManager;
use WikiOasis\WikiOasisMagic\FandomImport\FandomImportPresenter;
use WikiOasis\WikiOasisMagic\FandomImport\FandomImportRequest;
use WikiOasis\WikiOasisMagic\FandomImport\FandomImportStatus;
use WikiOasis\WikiOasisMagic\FandomImport\FandomImportStore;
use WikiOasis\WikiOasisMagic\FandomImport\FandomSource;
use WikiOasis\WikiOasisMagic\FandomImport\FandomWikiInfo;
use function array_intersect;
use function count;
use function htmlspecialchars;
use function implode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function trim;

/**
 * Special:FandomImport: move a wiki from Fandom to its own new wiki here.
 *
 * Special:FandomImport/metawiki is meta.fandom.com, harrypotterwiki/de is
 * harrypotter.fandom.com/de. On a Fandom wiki nobody has asked for yet the
 * page is the request form; afterwards it follows that import, and is where
 * reviewers approve, decline and retry it.
 */
class SpecialFandomImport extends SpecialPage {

	private const LIST_LIMIT = 50;

	public function __construct(
		private readonly FandomImportManager $manager,
		private readonly FandomImportStore $store,
		private readonly FandomClient $client,
		private readonly FandomImportPresenter $presenter,
		private readonly UserFactory $userFactory,
	) {
		parent::__construct( 'FandomImport' );
	}

	/** @inheritDoc */
	public function doesWrites() {
		return true;
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'wiki';
	}

	/** @inheritDoc */
	public function execute( $subPage ) {
		$this->setHeaders();
		$out = $this->getOutput();
		$out->addModuleStyles( [ 'ext.wikioasismagic.codex.styles', 'ext.wikioasismagic.fandomimport.styles' ] );

		$unavailable = $this->manager->getUnavailableReason();
		if ( $unavailable !== null ) {
			$out->addHTML( Html::warningBox( $this->msg( $unavailable )->parse() ) );
			return;
		}

		$out->addHTML( Html::openElement( 'div', [ 'id' => 'wo-fi-app', 'class' => 'wo-fi-root' ] ) );
		$config = $this->render( trim( (string)$subPage ) );
		$out->addHTML( Html::closeElement( 'div' ) );

		if ( $config !== null && $out->getRedirect() === '' ) {
			$out->addJsConfigVars( 'wgWikiOasisFandomImport', $config );
			$out->addModules( 'ext.wikioasismagic.fandomimport' );
		}
	}

	/**
	 * Render the page without JavaScript, and return what the Vue app needs
	 * to replace it, or null when redirecting.
	 */
	private function render( string $subPage ): ?array {
		$out = $this->getOutput();
		$error = null;

		$lookup = trim( $this->getRequest()->getText( 'fandom' ) );
		if ( $lookup !== '' ) {
			$source = FandomSource::newFromInput( $lookup );
			if ( $source ) {
				$out->redirect( $this->getPageTitle( $source->getSubpage() )->getLocalURL() );
				return null;
			}

			$error = $this->msg( 'wikioasismagic-fandomimport-error-badinput' )->parse();
			$out->addHTML( Html::errorBox( $error ) );
		}

		if ( $subPage === '' ) {
			$this->showIndex();
			return $this->indexConfig( $error );
		}

		$source = FandomSource::newFromSubpage( $subPage ) ?? FandomSource::newFromInput( $subPage );
		if ( !$source ) {
			$error = $this->msg( 'wikioasismagic-fandomimport-error-badinput' )->parse();
			$out->addHTML( Html::errorBox( $error ) );
			$this->showIndex();
			return $this->indexConfig( $error );
		}

		if ( $source->getSubpage() !== $subPage ) {
			$out->redirect( $this->getPageTitle( $source->getSubpage() )->getLocalURL() );
			return null;
		}

		$out->setPageTitleMsg( $this->msg( 'wikioasismagic-fandomimport-title', $source->getHost() ) );
		$out->addBacklinkSubtitle( $this->getPageTitle() );

		$request = $this->store->getLatestForSource( $source );
		$wantsNew = $this->getRequest()->getBool( 'new' );
		if ( $request && !( $request->status === FandomImportStatus::DECLINED && $wantsNew ) ) {
			$this->showRequest( $request );
			return [
				'view' => 'status',
				'detail' => $this->presenter->detail( $request, $this->getContext() ),
			];
		}

		$this->showForm( $source, $wantsNew );
		return [
			'view' => 'request',
			'preview' => $this->presenter->preview( $source, $this->getContext() ),
			'form' => $this->presenter->form( $this->getContext() ),
			'indexUrl' => $this->getPageTitle()->getLocalURL(),
		];
	}

	private function indexConfig( ?string $error ): array {
		$blocker = $this->manager->getBlocker( $this->getUser() );
		return [
			'view' => 'index',
			'intro' => $this->msg( 'wikioasismagic-fandomimport-intro' )->parseAsBlock(),
			'error' => $error,
			'canRequest' => $blocker === null,
			'blocker' => $blocker ? $this->msg( $blocker )->parse() : null,
			'pageUrl' => $this->getPageTitle()->getLocalURL(),
		] + $this->presenter->index( $this->getContext() );
	}

	private function showIndex(): void {
		$out = $this->getOutput();
		$out->addWikiMsg( 'wikioasismagic-fandomimport-intro' );

		HTMLForm::factory( 'codex', [
			'fandom' => [
				'type' => 'text',
				'name' => 'fandom',
				'label-message' => 'wikioasismagic-fandomimport-lookup-label',
				'placeholder-message' => 'wikioasismagic-fandomimport-lookup-placeholder',
				'help-message' => 'wikioasismagic-fandomimport-lookup-help',
				'required' => true,
			],
		], $this->getContext() )
			->setMethod( 'get' )
			->setTitle( $this->getPageTitle() )
			->setSubmitTextMsg( 'wikioasismagic-fandomimport-lookup-submit' )
			->prepareForm()
			->displayForm( false );

		$user = $this->getUser();
		if ( $user->isRegistered() ) {
			$this->showList( 'wikioasismagic-fandomimport-list-mine',
				$this->store->list( [], self::LIST_LIMIT, $user->getId() ) );
		}

		if ( !$this->manager->canReview( $this->getAuthority() ) ) {
			return;
		}

		foreach ( [
			'wikioasismagic-fandomimport-list-pending' => [ FandomImportStatus::PENDING ],
			'wikioasismagic-fandomimport-list-inprogress' => FandomImportStatus::IN_PROGRESS,
			'wikioasismagic-fandomimport-list-failed' => [ FandomImportStatus::FAILED ],
			'wikioasismagic-fandomimport-list-done' => [ FandomImportStatus::DONE ],
			'wikioasismagic-fandomimport-list-declined' => [ FandomImportStatus::DECLINED ],
		] as $heading => $statuses ) {
			$this->showList( $heading, $this->store->list( $statuses, self::LIST_LIMIT ), true );
		}
	}

	/**
	 * @param string $heading
	 * @param FandomImportRequest[] $requests
	 * @param bool $showEmpty
	 */
	private function showList( string $heading, array $requests, bool $showEmpty = false ): void {
		if ( !$requests && !$showEmpty ) {
			return;
		}

		$out = $this->getOutput();
		$out->addHTML( Html::element( 'h2', [], $this->msg( $heading )->text() ) );

		if ( !$requests ) {
			$out->addHTML( Html::element( 'p', [ 'class' => 'wo-fi-empty' ],
				$this->msg( 'wikioasismagic-fandomimport-list-empty' )->text() ) );
			return;
		}

		$rows = '';
		foreach ( $requests as $request ) {
			$rows .= Html::rawElement( 'tr', [],
				Html::rawElement( 'td', [], $this->getLinkRenderer()->makeKnownLink(
					$this->getPageTitle( $request->source->getSubpage() ),
					$request->source->getHost()
				) ) .
				Html::element( 'td', [], $request->dbname ) .
				Html::rawElement( 'td', [], $this->userLink( $request->requesterId ) ) .
				Html::rawElement( 'td', [], $this->statusChip( $request->status ) ) .
				Html::element( 'td', [], $this->getLanguage()->userTimeAndDate( $request->updated, $this->getUser() ) )
			);
		}

		$header = '';
		foreach ( [ 'source', 'wiki', 'requester', 'status', 'updated' ] as $column ) {
			$header .= Html::element( 'th', [], $this->msg( "wikioasismagic-fandomimport-column-$column" )->text() );
		}

		$out->addHTML( Html::rawElement( 'table', [ 'class' => 'wikitable wo-fi-table' ],
			Html::rawElement( 'thead', [], Html::rawElement( 'tr', [], $header ) ) .
			Html::rawElement( 'tbody', [], $rows )
		) );
	}

	private function showForm( FandomSource $source, bool $wantsNew ): void {
		$out = $this->getOutput();
		$lookup = $this->client->getWikiInfo( $source );
		$info = $lookup->isGood() ? $lookup->getValue() : null;

		if ( $lookup->hasMessage( 'wikioasismagic-fandomimport-lookup-missing' ) ) {
			$out->addHTML( Html::errorBox( $this->msg( 'wikioasismagic-fandomimport-lookup-missing',
				$source->getBaseUrl(), $source->getHost() )->parse() ) );
			return;
		}

		$out->addHTML( $this->sourceCard( $source, $info ) );
		if ( !$info ) {
			$out->addHTML( Html::noticeBox(
				$this->msg( 'wikioasismagic-fandomimport-lookup-unreachable', $source->getHost() )->parse(), ''
			) );
		}

		$blocker = $this->manager->getBlocker( $this->getUser() );
		if ( $blocker ) {
			$out->addHTML( Html::errorBox( $this->msg( $blocker )->parse() ) );
			return;
		}

		$out->addWikiMsg( 'wikioasismagic-fandomimport-form-intro', $source->getHost() );
		if ( $this->manager->isLarge( $info ) && $info ) {
			$out->addHTML( Html::warningBox( $this->msg( 'wikioasismagic-fandomimport-form-large' )
				->numParams( $info->files )->parse() ) );
		}

		$fields = [
			'subdomain' => [
				'type' => 'text',
				'label-message' => 'wikioasismagic-fandomimport-field-subdomain',
				'help-message' => 'wikioasismagic-fandomimport-field-subdomain-help',
				'default' => $source->getSuggestedSubdomain(),
				'required' => true,
				'validation-callback' => function ( $value ) {
					$error = $this->manager->checkSubdomain( (string)$value );
					return $error ? $this->msg( $error ) : true;
				},
			],
			'sitename' => [
				'type' => 'text',
				'label-message' => 'wikioasismagic-fandomimport-field-sitename',
				'default' => $info?->sitename ?? '',
				'maxlength' => 128,
				'required' => true,
			],
			'language' => [
				'type' => 'language',
				'label-message' => 'wikioasismagic-fandomimport-field-language',
				'default' => $info && $info->language !== '' ? $info->language : 'en',
			],
		];

		$categories = $this->manager->getCategories();
		if ( $categories ) {
			$fields['category'] = [
				'type' => 'select',
				'label-message' => 'wikioasismagic-fandomimport-field-category',
				'options' => $categories,
				'default' => in_array( 'uncategorised', $categories, true ) ? 'uncategorised' : null,
			];
		}

		$fields += [
			'mode' => [
				'type' => 'radio',
				'label-message' => 'wikioasismagic-fandomimport-field-mode',
				'options-messages' => [
					'wikioasismagic-fandomimport-mode-move' => 'move',
					'wikioasismagic-fandomimport-mode-fork' => 'fork',
				],
				'default' => 'move',
			],
			'fandomuser' => [
				'type' => 'text',
				'label-message' => 'wikioasismagic-fandomimport-field-fandomuser',
				'help-message' => 'wikioasismagic-fandomimport-field-fandomuser-help',
				'maxlength' => 85,
			],
			'reason' => [
				'type' => 'textarea',
				'label-message' => 'wikioasismagic-fandomimport-field-reason',
				'help-message' => 'wikioasismagic-fandomimport-field-reason-help',
				'rows' => 5,
				'required' => true,
			],
			'agreement' => [
				'type' => 'check',
				'label-message' => 'wikioasismagic-fandomimport-field-agreement',
				'validation-callback' => fn ( $value ) => $value ? true : $this->msg( 'htmlform-required' ),
			],
		];

		$form = HTMLForm::factory( 'codex', $fields, $this->getContext() )
			->setTitle( $this->getPageTitle( $source->getSubpage() ) )
			->setFormIdentifier( 'fandomimport-request' )
			->setSubmitTextMsg( 'wikioasismagic-fandomimport-submit' )
			->setSubmitCallback( function ( array $data ) use ( $source, $info ) {
				$status = $this->manager->submit( $this->getUser(), $source, $info, $data );
				return $this->finish( $status, $source );
			} );

		if ( $wantsNew ) {
			$form->addHiddenField( 'new', '1' );
		}

		$form->show();
	}

	private function showRequest( FandomImportRequest $request ): void {
		$out = $this->getOutput();
		$source = $request->source;
		$status = $request->status;

		$out->addHTML( Html::rawElement( 'div', [ 'class' => 'wo-fi-banner' ],
			$this->statusChip( $status ) .
			Html::rawElement( 'p', [],
				$this->msg( "wikioasismagic-fandomimport-desc-$status", $source->getHost(), $request->dbname )->parse() )
		) );

		if ( $status === FandomImportStatus::FAILED ) {
			$out->addHTML( Html::errorBox(
				$this->msg( 'wikioasismagic-fandomimport-failed-at',
					$this->msg( "wikioasismagic-fandomimport-stage-{$request->stage}" )->text() )->parse() .
				Html::element( 'p', [ 'class' => 'wo-fi-error' ], $request->error ) .
				$this->filesNote( $request )
			) );
		} elseif ( $status === FandomImportStatus::DECLINED ) {
			$again = $this->manager->getBlocker( $this->getUser() ) ? '' : Html::rawElement( 'p', [],
				$this->getLinkRenderer()->makeKnownLink(
					$this->getPageTitle( $source->getSubpage() ),
					$this->msg( 'wikioasismagic-fandomimport-request-again' )->text(),
					[],
					[ 'new' => 1 ]
				) );
			$out->addHTML( Html::warningBox(
				$this->msg( 'wikioasismagic-fandomimport-declined-reason' )->parse() .
				Html::element( 'p', [ 'class' => 'wo-fi-error' ], $request->error ) . $again
			) );
		} elseif ( $status === FandomImportStatus::WAITING_DUMP ) {
			$out->addHTML( Html::warningBox( $this->msg( 'wikioasismagic-fandomimport-waiting-dump-help',
				$source->getPageUrl( 'Special:Statistics' ), $source->getHost() )->parse() ) );
		}

		$note = $request->getProgress( 'note' );
		if ( is_string( $note ) && $note !== '' &&
			in_array( $status, [ FandomImportStatus::RUNNING, FandomImportStatus::WAITING_DUMP ], true )
		) {
			$out->addHTML( Html::noticeBox( Html::element( 'span', [], $note ), '' ) );
		}

		$out->addHTML( $this->details( $request ) );

		if ( !in_array( $status, [ FandomImportStatus::PENDING, FandomImportStatus::DECLINED ], true ) ) {
			$out->addHTML( $this->stages( $request ) );
			$out->addHTML( $this->progress( $request ) );
		}

		$out->addHTML(
			Html::element( 'h2', [], $this->msg( 'wikioasismagic-fandomimport-reason-heading' )->text() ) .
			Html::element( 'div', [ 'class' => 'wo-fi-reason' ], $request->reason )
		);

		if ( $this->manager->canReview( $this->getAuthority() ) ) {
			$this->showReview( $request );
		}
	}

	private function showReview( FandomImportRequest $request ): void {
		$out = $this->getOutput();
		$isPending = $request->status === FandomImportStatus::PENDING;
		$isFailed = $request->status === FandomImportStatus::FAILED;
		if ( !$isPending && !$isFailed ) {
			return;
		}

		$out->addHTML( Html::element( 'h2', [], $this->msg( 'wikioasismagic-fandomimport-review-heading' )->text() ) );

		if ( $isPending ) {
			$out->addHTML( $this->fandomAccountCheck( $request ) );
			$info = $request->getWikiInfo();
			$large = $this->manager->isLarge( $info );

			$approveFields = [
				'comment' => [
					'type' => 'textarea',
					'rows' => 3,
					'label-message' => 'wikioasismagic-fandomimport-review-comment',
				],
			];
			if ( $large ) {
				$approveFields['acknowledge'] = [
					'type' => 'check',
					'label' => $info ?
						$this->msg( 'wikioasismagic-fandomimport-review-acknowledge' )->numParams( $info->files )->text() :
						$this->msg( 'wikioasismagic-fandomimport-review-acknowledge-unknown' )->text(),
				];
			}

			$this->reviewForm( $request, 'approve', $approveFields, false,
				fn ( array $data ) => $this->manager->approve(
					$request, $this->getUser(), (string)( $data['comment'] ?? '' ), (bool)( $data['acknowledge'] ?? false )
				)
			);

			$this->reviewForm( $request, 'decline', [
				'reason' => [
					'type' => 'textarea',
					'rows' => 3,
					'label-message' => 'wikioasismagic-fandomimport-review-decline-reason',
					'required' => true,
				],
			], true,
				fn ( array $data ) => $this->manager->decline( $request, $this->getUser(), (string)$data['reason'] )
			);
			return;
		}

		$this->reviewForm( $request, 'retry', [], false,
			fn () => $this->manager->retry( $request, $this->getUser() )
		);

		if ( $request->filesExpire !== null ) {
			$this->reviewForm( $request, 'discard', [], true,
				fn () => $this->manager->discardFiles( $request, $this->getUser() )
			);
		}
	}

	/**
	 * One small form per action, so each button posts only its own fields.
	 */
	private function reviewForm(
		FandomImportRequest $request,
		string $action,
		array $fields,
		bool $destructive,
		callable $callback
	): void {
		$form = HTMLForm::factory( 'codex', $fields, $this->getContext() )
			->setTitle( $this->getPageTitle( $request->source->getSubpage() ) )
			->setFormIdentifier( "fandomimport-$action" )
			->setId( "wo-fi-$action" )
			->setSubmitTextMsg( "wikioasismagic-fandomimport-action-$action" )
			->setSubmitCallback( fn ( array $data ) => $this->finish( $callback( $data ), $request->source ) );

		if ( $destructive ) {
			$form->setSubmitDestructive();
		}

		$form->show();
	}

	/**
	 * Back to the import's page after a change, so reloading does not repeat it.
	 *
	 * @return bool|Status
	 */
	private function finish( StatusValue $status, FandomSource $source ): bool|Status {
		if ( !$status->isOK() ) {
			return Status::wrap( $status );
		}

		$this->getOutput()->redirect( $this->getPageTitle( $source->getSubpage() )->getFullURL() );
		return true;
	}

	private function sourceCard( FandomSource $source, ?FandomWikiInfo $info ): string {
		$rows = [
			'wikioasismagic-fandomimport-info-address' => Html::element( 'a',
				[ 'href' => $source->getBaseUrl(), 'rel' => 'nofollow', 'class' => 'external' ], $source->getHost() ),
		];

		if ( $info ) {
			$lang = $this->getLanguage();
			$rows += [
				'wikioasismagic-fandomimport-info-sitename' => htmlspecialchars( $info->sitename ),
				'wikioasismagic-fandomimport-info-language' => htmlspecialchars( $info->language ),
				'wikioasismagic-fandomimport-info-pages' => $this->msg( 'wikioasismagic-fandomimport-info-pages-value' )
					->numParams( $info->articles, $info->pages )->escaped(),
				'wikioasismagic-fandomimport-info-files' => $lang->formatNum( $info->files ),
				'wikioasismagic-fandomimport-info-license' => $info->licenseUrl !== '' ?
					Html::element( 'a', [ 'href' => $info->licenseUrl, 'rel' => 'nofollow', 'class' => 'external' ],
						$info->license ?: $info->licenseUrl ) :
					htmlspecialchars( $info->license ),
				'wikioasismagic-fandomimport-info-dump' => $this->dumpSummary( $source, $info ),
			];
		}

		return Html::rawElement( 'div', [ 'class' => 'cdx-card wo-fi-card' ],
			Html::rawElement( 'span', [ 'class' => 'cdx-card__text' ], $this->definitionList( $rows ) )
		);
	}

	private function dumpSummary( FandomSource $source, FandomWikiInfo $info ): string {
		foreach ( $this->client->getDumps( $info->wikiId ) as $dump ) {
			if ( $dump['available'] ) {
				$date = $dump['lastModified'] ?
					$this->getLanguage()->userDate( $dump['lastModified'], $this->getUser() ) :
					$this->msg( 'wikioasismagic-fandomimport-unknown' )->text();
				return $this->msg( "wikioasismagic-fandomimport-dump-{$dump['variant']}", $date )->escaped();
			}
		}

		return $this->msg( 'wikioasismagic-fandomimport-dump-none',
			$source->getPageUrl( 'Special:Statistics' ) )->parse();
	}

	private function details( FandomImportRequest $request ): string {
		$lang = $this->getLanguage();
		$user = $this->getUser();
		$wikiUrl = $this->manager->getWikiUrl( $request );

		$rows = [
			'wikioasismagic-fandomimport-info-address' => Html::element( 'a',
				[ 'href' => $request->source->getBaseUrl(), 'rel' => 'nofollow', 'class' => 'external' ],
				$request->source->getHost() ),
			'wikioasismagic-fandomimport-info-newwiki' => $this->manager->wikiExists( $request ) ?
				Html::element( 'a', [ 'href' => $wikiUrl ], $wikiUrl ) :
				$this->msg( 'wikioasismagic-fandomimport-info-newwiki-pending', $wikiUrl )->escaped(),
			'wikioasismagic-fandomimport-info-sitename' => htmlspecialchars( $request->sitename ),
			'wikioasismagic-fandomimport-info-mode' =>
				$this->msg( "wikioasismagic-fandomimport-mode-{$request->mode}" )->escaped(),
			'wikioasismagic-fandomimport-info-requester' => $this->userLink( $request->requesterId ),
			'wikioasismagic-fandomimport-info-requested' => htmlspecialchars( $lang->userTimeAndDate( $request->created, $user ) ),
		];

		if ( $request->fandomUser !== '' ) {
			$rows['wikioasismagic-fandomimport-info-fandomuser'] = Html::element( 'a', [
				'href' => $request->source->getPageUrl( 'User:' . $request->fandomUser ),
				'rel' => 'nofollow',
				'class' => 'external',
			], $request->fandomUser );
		}

		if ( $request->reviewerId ) {
			$rows['wikioasismagic-fandomimport-info-reviewer'] = $this->userLink( $request->reviewerId );
		}

		$rows['wikioasismagic-fandomimport-info-updated'] = htmlspecialchars( $lang->userTimeAndDate( $request->updated, $user ) );

		return $this->definitionList( $rows, 'wo-fi-details' );
	}

	private function stages( FandomImportRequest $request ): string {
		$items = '';
		foreach ( $this->presenter->stages( $request ) as $stage ) {
			$items .= Html::element( 'li', [ 'class' => "wo-fi-stage wo-fi-stage--{$stage['state']}" ],
				$this->msg( "wikioasismagic-fandomimport-stage-{$stage['id']}" )->text() );
		}

		return Html::element( 'h2', [], $this->msg( 'wikioasismagic-fandomimport-stages-heading' )->text() ) .
			Html::rawElement( 'ol', [ 'class' => 'wo-fi-stages' ], $items );
	}

	private function progress( FandomImportRequest $request ): string {
		$lang = $this->getLanguage();
		$num = static fn ( string $key ): ?string => is_numeric( $request->getProgress( $key ) ) ?
			$lang->formatNum( $request->getProgress( $key ) ) :
			null;

		$rows = [];
		$dumpVariant = $request->getProgress( 'dump_variant' );
		if ( is_string( $dumpVariant ) ) {
			$date = $request->getProgress( 'dump_date' );
			$rows['wikioasismagic-fandomimport-progress-dump'] = $this->msg(
				"wikioasismagic-fandomimport-dump-$dumpVariant",
				is_string( $date ) ? $lang->userDate( $date, $this->getUser() ) : $this->msg( 'wikioasismagic-fandomimport-unknown' )->text()
			)->escaped();
		}

		foreach ( [
			'pages_kept' => 'wikioasismagic-fandomimport-progress-pages',
			'pages_skipped' => 'wikioasismagic-fandomimport-progress-pages-skipped',
			'images_total' => 'wikioasismagic-fandomimport-progress-images-total',
			'images_fetched' => 'wikioasismagic-fandomimport-progress-images-fetched',
			'images_added' => 'wikioasismagic-fandomimport-progress-images-added',
			'images_skipped' => 'wikioasismagic-fandomimport-progress-images-skipped',
			'images_failed' => 'wikioasismagic-fandomimport-progress-images-failed',
		] as $key => $label ) {
			$value = $num( $key );
			if ( $value !== null ) {
				$rows[$label] = htmlspecialchars( $value );
			}
		}

		if ( is_numeric( $request->getProgress( 'images_bytes_total' ) ) ) {
			$rows['wikioasismagic-fandomimport-progress-images-bytes'] =
				htmlspecialchars( $lang->formatSize( (int)$request->getProgress( 'images_bytes_total' ) ) );
		}

		$batches = $request->getProgress( 'batches_total' );
		if ( is_numeric( $batches ) && $batches > 0 ) {
			$rows['wikioasismagic-fandomimport-progress-batches'] = $this->msg(
				'wikioasismagic-fandomimport-progress-batches-value'
			)->numParams( (int)$request->getProgress( 'batches_done', 0 ), (int)$batches )->escaped();
		}

		foreach ( [
			'enabled_extensions' => 'wikioasismagic-fandomimport-progress-extensions',
			'created_namespaces' => 'wikioasismagic-fandomimport-progress-namespaces',
		] as $key => $label ) {
			$list = $request->getProgress( $key );
			if ( is_array( $list ) && $list ) {
				$names = [];
				foreach ( $list as $item ) {
					$names[] = is_array( $item ) ? (string)( $item['name'] ?? '' ) : (string)$item;
				}
				$rows[$label] = htmlspecialchars( $lang->commaList( $names ) );
			}
		}

		$skipped = $request->getProgress( 'skipped_namespaces' );
		if ( is_array( $skipped ) && $skipped ) {
			$parts = [];
			foreach ( $skipped as $ns => $count ) {
				$parts[] = $this->msg( 'wikioasismagic-fandomimport-progress-skipped-ns', $ns )->numParams( $count )->text();
			}
			$rows['wikioasismagic-fandomimport-progress-skipped-namespaces'] = htmlspecialchars( $lang->commaList( $parts ) );
		}

		if ( $request->status !== FandomImportStatus::FAILED && $request->filesExpire === null &&
			$request->getProgress( 'files' ) === 'deleted'
		) {
			$rows['wikioasismagic-fandomimport-progress-files'] =
				$this->msg( 'wikioasismagic-fandomimport-files-deleted' )->escaped();
		}

		if ( !$rows ) {
			return '';
		}

		$html = Html::element( 'h2', [], $this->msg( 'wikioasismagic-fandomimport-progress-heading' )->text() ) .
			$this->definitionList( $rows, 'wo-fi-progress' );

		$failed = $request->getProgress( 'failed_files' );
		if ( is_array( $failed ) && $failed ) {
			$items = '';
			foreach ( $failed as $name ) {
				$items .= Html::element( 'li', [], (string)$name );
			}
			$html .= Html::rawElement( 'details', [ 'class' => 'wo-fi-failed-files' ],
				Html::element( 'summary', [],
					$this->msg( 'wikioasismagic-fandomimport-progress-failed-files' )->numParams( count( $failed ) )->text() ) .
				Html::rawElement( 'ul', [], $items )
			);
		}

		return $html;
	}

	private function fandomAccountCheck( FandomImportRequest $request ): string {
		if ( $request->fandomUser === '' ) {
			return Html::noticeBox( $this->msg( 'wikioasismagic-fandomimport-account-none' )->parse(), '' );
		}

		$groups = $this->client->getUserGroups( $request->source, $request->fandomUser );
		if ( $groups === null ) {
			return Html::noticeBox(
				$this->msg( 'wikioasismagic-fandomimport-account-unknown', $request->fandomUser )->parse(), ''
			);
		}

		if ( !$groups['exists'] ) {
			return Html::warningBox( $this->msg( 'wikioasismagic-fandomimport-account-missing', $request->fandomUser )->parse() );
		}

		$admin = array_intersect( $groups['groups'], [ 'sysop', 'bureaucrat' ] );
		return $admin ?
			Html::successBox( $this->msg( 'wikioasismagic-fandomimport-account-admin', $request->fandomUser,
				implode( ', ', $admin ) )->parse() ) :
			Html::warningBox( $this->msg( 'wikioasismagic-fandomimport-account-notadmin', $request->fandomUser )->parse() );
	}

	private function filesNote( FandomImportRequest $request ): string {
		if ( $request->filesExpire === null ) {
			return '';
		}

		return Html::rawElement( 'p', [], $this->msg( 'wikioasismagic-fandomimport-files-kept',
			$this->getLanguage()->userTimeAndDate( $request->filesExpire, $this->getUser() ) )->parse() );
	}

	private function statusChip( string $status ): string {
		return Html::element( 'span', [ 'class' => "wo-fi-status wo-fi-status--$status" ],
			$this->msg( "wikioasismagic-fandomimport-status-$status" )->text() );
	}

	private function userLink( int $userId ): string {
		$user = $this->userFactory->newFromId( $userId );
		return $user->isRegistered() ? Linker::userLink( $user->getId(), $user->getName() ) :
			$this->msg( 'wikioasismagic-fandomimport-unknown' )->escaped();
	}

	/**
	 * @param array<string,string> $rows Label message keys to HTML
	 * @param string $class
	 */
	private function definitionList( array $rows, string $class = '' ): string {
		$html = '';
		foreach ( $rows as $label => $value ) {
			$html .= Html::element( 'dt', [], $this->msg( $label )->text() ) . Html::rawElement( 'dd', [], $value );
		}

		return Html::rawElement( 'dl', [ 'class' => [ 'wo-fi-dl', $class ] ], $html );
	}

}
