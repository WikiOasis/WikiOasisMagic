<?php

namespace WikiOasis\WikiOasisMagic\Jobs;

use MediaWiki\JobQueue\GenericParameterJob;
use MediaWiki\JobQueue\Job;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;
use function implode;

class ExperimentLogJob extends Job implements GenericParameterJob {

	public const COMMAND = 'wikiOasisExperimentLog';

	public const PERFORMER = 'Experiment management';

	public function __construct( array $params ) {
		parent::__construct( self::COMMAND, $params );
	}

	/**
	 * @param string $dbname
	 * @param string[] $changes
	 */
	public static function newSpec( string $dbname, array $changes ): JobSpecification {
		return new JobSpecification( self::COMMAND, [
			'wiki' => $dbname,
			'changes' => $changes,
		] );
	}

	public function run(): bool {
		$performer = User::newSystemUser( self::PERFORMER, [ 'steal' => true ] );
		if ( !$performer ) {
			$this->setLastError( 'Could not get the "' . self::PERFORMER . '" system user' );
			return false;
		}

		$entry = new ManualLogEntry( 'managewiki', 'settings' );
		$entry->setPerformer( $performer );
		$entry->setTarget( SpecialPage::getTitleFor( 'ManageWiki', 'extensions' ) );
		$entry->setParameters( [
			'4::wiki' => $this->params['wiki'],
			'5::changes' => implode( ', ', (array)$this->params['changes'] ),
		] );
		$entry->setForceBotFlag( true );
		$entry->publish( $entry->insert() );

		return true;
	}
}
