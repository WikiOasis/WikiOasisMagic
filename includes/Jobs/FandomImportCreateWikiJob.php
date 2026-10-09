<?php

namespace WikiOasis\WikiOasisMagic\Jobs;

use MediaWiki\JobQueue\Job;
use MediaWiki\JobQueue\JobSpecification;
use WikiOasis\WikiOasisMagic\FandomImport\FandomImportManager;

/**
 * Creates the wiki of an approved Fandom import, on the central wiki.
 */
class FandomImportCreateWikiJob extends Job {

	public const COMMAND = 'wikiOasisFandomImportCreateWiki';

	public function __construct(
		array $params,
		private readonly FandomImportManager $manager,
	) {
		parent::__construct( self::COMMAND, $params );
	}

	public static function newSpec( int $id ): JobSpecification {
		return new JobSpecification( self::COMMAND, [ 'id' => $id ] );
	}

	public function run(): bool {
		$this->manager->createWiki( (int)$this->params['id'] );
		return true;
	}

	/**
	 * A half-made wiki cannot be made again; a failure is reported on the
	 * import instead, where a reviewer can retry it.
	 */
	public function allowRetries(): bool {
		return false;
	}
}
