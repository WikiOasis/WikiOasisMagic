<?php

namespace WikiOasis\WikiOasisMagic\FandomImport;

use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserFactory;
use Psr\Log\LoggerInterface;
use Throwable;
use function mb_substr;

/**
 * Tells the requester what happened to their import, through Echo (which
 * also emails them, by default). Sent on the central wiki, where they filed it.
 */
class FandomImportNotifier {

	public const DONE = 'fandom-import-done';
	public const FAILED = 'fandom-import-failed';
	public const NEEDS_DUMP = 'fandom-import-needs-dump';
	public const DECLINED = 'fandom-import-declined';

	public const TYPES = [ self::DONE, self::FAILED, self::NEEDS_DUMP, self::DECLINED ];

	public function __construct(
		private readonly ExtensionRegistry $extensionRegistry,
		private readonly UserFactory $userFactory,
		private readonly LoggerInterface $logger,
	) {
	}

	public function notify( FandomImportRequest $request, string $type, string $text = '', ?string $wikiUrl = null ): void {
		if ( !$this->extensionRegistry->isLoaded( 'Echo' ) ) {
			return;
		}

		$requester = $this->userFactory->newFromId( $request->requesterId );
		if ( !$requester->isRegistered() ) {
			return;
		}

		try {
			Event::create( [
				'type' => $type,
				'title' => SpecialPage::getTitleFor( 'FandomImport', $request->source->getSubpage() ),
				'agent' => $requester,
				'extra' => [
					'source' => $request->source->getHost(),
					'sitename' => $request->sitename,
					'wiki-url' => $wikiUrl,
					'text' => mb_substr( $text, 0, 500 ),
					'notifyAgent' => true,
				],
			] );
		} catch ( Throwable $e ) {
			$this->logger->error( 'Could not notify about Fandom import {id}: {message}', [
				'id' => $request->id,
				'message' => $e->getMessage(),
				'exception' => $e,
			] );
		}
	}
}
