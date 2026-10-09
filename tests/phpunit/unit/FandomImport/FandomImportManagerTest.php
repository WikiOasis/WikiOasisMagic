<?php

namespace WikiOasis\WikiOasisMagic\Tests\FandomImport;

use MediaWiki\Config\HashConfig;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\Language\LanguageNameUtils;
use MediaWiki\User\UserFactory;
use MediaWikiUnitTestCase;
use Miraheze\CreateWiki\Services\CreateWikiValidator;
use Miraheze\CreateWiki\Services\WikiManagerFactory;
use Psr\Log\NullLogger;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use WikiOasis\WikiOasisMagic\Experiments\WikiFarm;
use WikiOasis\WikiOasisMagic\FandomImport\ConfigNames;
use WikiOasis\WikiOasisMagic\FandomImport\FandomImportManager;
use WikiOasis\WikiOasisMagic\FandomImport\FandomImportNotifier;
use WikiOasis\WikiOasisMagic\FandomImport\FandomImportRequest;
use WikiOasis\WikiOasisMagic\FandomImport\FandomImportSpool;
use WikiOasis\WikiOasisMagic\FandomImport\FandomImportStatus;
use WikiOasis\WikiOasisMagic\FandomImport\FandomImportStore;
use WikiOasis\WikiOasisMagic\FandomImport\FandomSource;
use WikiOasis\WikiOasisMagic\Onboarding\WikiRequestSubmitter;

/**
 * @covers \WikiOasis\WikiOasisMagic\FandomImport\FandomImportManager
 */
class FandomImportManagerTest extends MediaWikiUnitTestCase {

	private FandomImportStore $store;
	private FandomImportNotifier $notifier;
	private ?array $updated = null;
	private ?array $updatedFrom = null;

	protected function setUp(): void {
		parent::setUp();
		ConvertibleTimestamp::setFakeTime( '20261009120000' );
	}

	private function newRequest( string $status, array $progress = [] ): FandomImportRequest {
		return new FandomImportRequest(
			id: 42,
			source: FandomSource::newFromSubpage( 'harrypotterwiki/de' ),
			dbname: 'deharrypotterwiki',
			sitename: 'Harry-Potter-Lexikon',
			language: 'de',
			category: 'test',
			mode: 'move',
			reason: 'Moving.',
			fandomUser: 'Someone',
			requesterId: 5,
			reviewerId: 6,
			status: $status,
			stage: FandomImportStatus::STAGE_IMAGES,
			progress: $progress,
			info: [],
			error: '',
			filesExpire: null,
			created: '20261009110000',
			updated: '20261009110000',
		);
	}

	private function newManager( ?FandomImportRequest $request, bool $updates = true ): FandomImportManager {
		$this->store = $this->createMock( FandomImportStore::class );
		$this->store->method( 'get' )->willReturn( $request );
		$this->store->method( 'update' )->willReturnCallback(
			function ( int $id, array $fields, ?array $from ) use ( $updates ): bool {
				$this->updated = $fields;
				$this->updatedFrom = $from;
				return $updates;
			}
		);

		$this->notifier = $this->createMock( FandomImportNotifier::class );

		$farm = $this->createMock( WikiFarm::class );
		$farm->method( 'getCentralWiki' )->willReturn( 'metawiki' );

		$validator = $this->createMock( CreateWikiValidator::class );
		$validator->method( 'getValidUrl' )->willReturn( 'https://deharrypotter.example.org' );

		return new FandomImportManager(
			new ServiceOptions( FandomImportManager::CONSTRUCTOR_OPTIONS, new HashConfig( [
				ConfigNames::ENABLED => true,
				ConfigNames::LARGE_FILE_COUNT => 20000,
				ConfigNames::UPLOAD_USER => 'Fandom import',
				ConfigNames::USERNAME_PREFIX => 'wikia',
				ConfigNames::PREFER_FULL_HISTORY => true,
			] ) ),
			new HashConfig( [] ),
			$this->store,
			$this->createMock( FandomImportSpool::class ),
			$this->notifier,
			$farm,
			$this->createMock( WikiRequestSubmitter::class ),
			$validator,
			$this->createMock( WikiManagerFactory::class ),
			$this->createMock( JobQueueGroupFactory::class ),
			$this->createMock( LanguageNameUtils::class ),
			$this->createMock( UserFactory::class ),
			new NullLogger()
		);
	}

	public function testBuildManifest(): void {
		$manager = $this->newManager( null );
		$manifest = $manager->buildManifest( $this->newRequest( FandomImportStatus::CREATING, [ 'attempt' => 2 ] ) );

		$this->assertSame( [
			'version' => 1,
			'id' => 42,
			'central_wiki' => 'metawiki',
			'target_wiki' => 'deharrypotterwiki',
			'source' => [
				'key' => 'harrypotter/de',
				'subdomain' => 'harrypotter',
				'lang' => 'de',
				'base_url' => 'https://harrypotter.fandom.com/de',
				'api_url' => 'https://harrypotter.fandom.com/de/api.php',
			],
			'username_prefix' => 'wikia',
			'upload_user' => 'Fandom import',
			'summary' => 'Imported from https://harrypotter.fandom.com/de',
			'prefer_full_history' => true,
			'queued_at' => '2026-10-09T12:00:00Z',
			'attempt' => 2,
		], $manifest );
	}

	public function testStageMovesToRunning(): void {
		$manager = $this->newManager( $this->newRequest( FandomImportStatus::QUEUED, [ 'note' => 'old' ] ) );
		$this->notifier->expects( $this->never() )->method( 'notify' );

		$this->assertTrue( $manager->handleWorkerEvent( 42, 'stage', 'pages', [], '' )->isGood() );
		$this->assertSame( FandomImportStatus::RUNNING, $this->updated['status'] );
		$this->assertSame( 'pages', $this->updated['stage'] );
		$this->assertArrayNotHasKey( 'note', $this->updated['progress'] );
		$this->assertSame( FandomImportStatus::WORKER, $this->updatedFrom );
	}

	public function testProgressKeepsOnlyPlainValues(): void {
		$manager = $this->newManager( $this->newRequest( FandomImportStatus::RUNNING, [ 'pages_kept' => 1 ] ) );

		$manager->handleWorkerEvent( 42, 'progress', null, [
			'pages_kept' => 10,
			'images_total' => 3,
			'Bad Key' => 1,
			'object' => (object)[],
			'files_expire' => '2026-10-12T00:00:00Z',
			'failed_files' => [ 'A.png', 'B.png' ],
		], 'Waiting for disk space' );

		$this->assertSame( [
			'pages_kept' => 10,
			'images_total' => 3,
			'failed_files' => [ 'A.png', 'B.png' ],
			'note' => 'Waiting for disk space',
		], $this->updated['progress'] );
	}

	public function testDoneNotifiesTheRequester(): void {
		$request = $this->newRequest( FandomImportStatus::RUNNING );
		$manager = $this->newManager( $request );
		$this->notifier->expects( $this->once() )->method( 'notify' )
			->with( $request, FandomImportNotifier::DONE, '', 'https://deharrypotter.example.org' );

		$this->assertTrue( $manager->handleWorkerEvent( 42, 'done', null, [ 'images_added' => 2 ], '' )->isGood() );
		$this->assertSame( FandomImportStatus::DONE, $this->updated['status'] );
		$this->assertNull( $this->updated['files_expire'] );
		$this->assertSame( 'deleted', $this->updated['progress']['files'] );
		$this->assertSame( 2, $this->updated['progress']['images_added'] );
	}

	public function testFailedKeepsFilesUntilTheyExpire(): void {
		$request = $this->newRequest( FandomImportStatus::RUNNING );
		$manager = $this->newManager( $request );
		$this->notifier->expects( $this->once() )->method( 'notify' )
			->with( $request, FandomImportNotifier::FAILED, 'wikiteam3 exited with status 1' );

		$manager->handleWorkerEvent( 42, 'failed', 'images', [ 'files_expire' => '2026-10-12T17:00:00Z' ],
			'wikiteam3 exited with status 1' );

		$this->assertSame( FandomImportStatus::FAILED, $this->updated['status'] );
		$this->assertSame( 'images', $this->updated['stage'] );
		$this->assertSame( 'wikiteam3 exited with status 1', $this->updated['error'] );
		$this->assertSame( '20261012170000', $this->updated['files_expire'] );
		$this->assertSame( 'kept', $this->updated['progress']['files'] );
	}

	public function testWaitingForADumpNotifiesOnce(): void {
		$manager = $this->newManager( $this->newRequest( FandomImportStatus::WAITING_DUMP ) );
		$this->notifier->expects( $this->never() )->method( 'notify' );

		$manager->handleWorkerEvent( 42, 'waiting-dump', null, [], '' );
		$this->assertSame( FandomImportStatus::WAITING_DUMP, $this->updated['status'] );
	}

	public function testFilesDeletedIsAcceptedInAnyStatus(): void {
		$manager = $this->newManager( $this->newRequest( FandomImportStatus::FAILED ) );

		$this->assertTrue( $manager->handleWorkerEvent( 42, 'files-deleted', null, [], '' )->isGood() );
		$this->assertNull( $this->updatedFrom );
		$this->assertNull( $this->updated['files_expire'] );
	}

	public function testLateReportsAreIgnored(): void {
		$manager = $this->newManager( $this->newRequest( FandomImportStatus::DONE ), false );
		$this->notifier->expects( $this->never() )->method( 'notify' );

		$status = $manager->handleWorkerEvent( 42, 'failed', 'upload', [], 'too late' );
		$this->assertTrue( $status->isOK() );
		$this->assertFalse( $status->isGood() );
	}

	public function testUnknownImportIsIgnored(): void {
		$status = $this->newManager( null )->handleWorkerEvent( 7, 'done', null, [], '' );

		$this->assertTrue( $status->isOK() );
		$this->assertFalse( $status->isGood() );
	}

	public function testBadEventsAndStagesAreErrors(): void {
		$manager = $this->newManager( $this->newRequest( FandomImportStatus::RUNNING ) );

		$this->assertFalse( $manager->handleWorkerEvent( 42, 'explode', null, [], '' )->isOK() );
		$this->assertFalse( $manager->handleWorkerEvent( 42, 'stage', 'nope', [], '' )->isOK() );
	}
}
