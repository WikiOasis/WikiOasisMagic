<?php

namespace WikiOasis\WikiOasisMagic\Jobs;

use MediaWiki\JobQueue\Job;
use MediaWiki\JobQueue\JobSpecification;
use Throwable;
use WikiOasis\WikiOasisMagic\FandomImport\FandomImportManager;

/**
 * Hands a Fandom import to the worker, or asks it to delete an import's
 * files, by writing to its spool. A job because only the job runner host
 * has the spool and runs the worker.
 */
class FandomImportQueueJob extends Job {

	public const COMMAND = 'wikiOasisFandomImportQueue';

	public const ACTION_RUN = 'run';
	public const ACTION_DISCARD = 'discard';

	public function __construct(
		array $params,
		private readonly FandomImportManager $manager,
	) {
		parent::__construct( self::COMMAND, $params );
	}

	public static function newSpec( int $id, string $action ): JobSpecification {
		return new JobSpecification( self::COMMAND, [ 'id' => $id, 'action' => $action ] );
	}

	public function run(): bool {
		$id = (int)$this->params['id'];

		if ( ( $this->params['action'] ?? self::ACTION_RUN ) === self::ACTION_DISCARD ) {
			try {
				$this->manager->handOffDiscard( $id );
			} catch ( Throwable $e ) {
				$this->setLastError( $e->getMessage() );
				return false;
			}
			return true;
		}

		$this->manager->handOff( $id );
		return true;
	}
}
