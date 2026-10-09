<?php

namespace WikiOasis\WikiOasisMagic\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Status\Status;
use WikiOasis\WikiOasisMagic\FandomImport\FandomImportManager;
use function is_array;
use function json_decode;

/**
 * How the Fandom import worker (salt: fandom_import) reports back. Run on the
 * central wiki:
 *
 *   run.php WikiOasisMagic:FandomImportCallback --wiki=metawiki --id=42 --event=stage --stage=pages
 */
class FandomImportCallback extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Record a report from the Fandom import worker.' );
		$this->addOption( 'id', 'The import', true, true );
		$this->addOption( 'event',
			'started, stage, progress, waiting-dump, done, failed or files-deleted', true, true );
		$this->addOption( 'stage', 'The stage the worker is in', false, true );
		$this->addOption( 'data', 'Progress to record, as a JSON object', false, true );
		$this->addOption( 'message', 'A note to show, or the error when the event is "failed"', false, true );
		$this->requireExtension( 'WikiOasisMagic' );
	}

	public function execute(): void {
		/** @var FandomImportManager $manager */
		$manager = $this->getServiceContainer()->get( 'WikiOasisMagic.FandomImportManager' );

		$unavailable = $manager->getUnavailableReason();
		if ( $unavailable !== null ) {
			$this->fatalError( wfMessage( $unavailable )->inLanguage( 'en' )->text() );
		}

		$data = [];
		if ( $this->hasOption( 'data' ) ) {
			$data = json_decode( (string)$this->getOption( 'data' ), true );
			if ( !is_array( $data ) ) {
				$this->fatalError( '--data must be a JSON object.' );
			}
		}

		$status = $manager->handleWorkerEvent(
			(int)$this->getOption( 'id' ),
			(string)$this->getOption( 'event' ),
			$this->getOption( 'stage' ),
			$data,
			(string)$this->getOption( 'message', '' )
		);

		if ( $status->isGood() ) {
			$this->output( "ok\n" );
			return;
		}

		$text = Status::wrap( $status )->getWikiText( false, false, 'en' );
		if ( !$status->isOK() ) {
			$this->fatalError( $text );
		}

		$this->output( "ok, but: $text\n" );
	}
}

return FandomImportCallback::class;
