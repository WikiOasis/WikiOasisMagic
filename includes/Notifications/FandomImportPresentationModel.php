<?php

namespace WikiOasis\WikiOasisMagic\Notifications;

use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\Message\Message;
use WikiOasis\WikiOasisMagic\FandomImport\FandomImportNotifier;

/**
 * Echo notifications about a Fandom import: done, failed, waiting for a
 * fresh dump, or declined. The message keys follow the event type.
 */
class FandomImportPresentationModel extends EchoEventPresentationModel {

	/** @inheritDoc */
	public function getIconType(): string {
		return $this->type === FandomImportNotifier::DONE ? 'global' : 'placeholder';
	}

	/** @inheritDoc */
	public function getHeaderMessage(): Message {
		return $this->msg( "notification-header-{$this->type}" )
			->plaintextParams(
				(string)$this->event->getExtraParam( 'source', '' ),
				(string)$this->event->getExtraParam( 'sitename', '' )
			);
	}

	/** @inheritDoc */
	public function getBodyMessage() {
		$text = (string)$this->event->getExtraParam( 'text', '' );
		if ( $text !== '' ) {
			return $this->msg( 'notification-body-fandom-import-text' )->plaintextParams( $text );
		}

		if ( $this->type === FandomImportNotifier::NEEDS_DUMP ) {
			return $this->msg( 'notification-body-fandom-import-needs-dump' )
				->plaintextParams( (string)$this->event->getExtraParam( 'source', '' ) );
		}

		return false;
	}

	/** @inheritDoc */
	public function getPrimaryLink() {
		$title = $this->event->getTitle();
		if ( !$title ) {
			return false;
		}

		return [
			'url' => $title->getFullURL(),
			'label' => $this->msg( 'notification-link-fandom-import-status' )->text(),
		];
	}

	/** @inheritDoc */
	public function getSecondaryLinks(): array {
		$wikiUrl = $this->event->getExtraParam( 'wiki-url' );
		if ( $this->type !== FandomImportNotifier::DONE || !$wikiUrl ) {
			return [];
		}

		return [ [
			'url' => $wikiUrl,
			'label' => $this->msg( 'notification-link-fandom-import-visit' )->text(),
			'prioritized' => true,
		] ];
	}
}
