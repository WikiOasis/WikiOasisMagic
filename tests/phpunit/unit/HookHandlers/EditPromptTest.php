<?php

namespace WikiOasis\WikiOasisMagic\Tests\HookHandlers;

use MediaWiki\Output\OutputPage;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Request\WebRequest;
use MediaWiki\Skin\Skin;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserEditTracker;
use MediaWikiUnitTestCase;
use WikiOasis\WikiOasisMagic\Experiments\Assignment;
use WikiOasis\WikiOasisMagic\Experiments\Experiment;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentManager;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentTracker;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentUnit;
use WikiOasis\WikiOasisMagic\HookHandlers\EditPrompt;

/**
 * @covers \WikiOasis\WikiOasisMagic\HookHandlers\EditPrompt
 */
class EditPromptTest extends MediaWikiUnitTestCase {

	private const DEFAULTS = [
		'action' => 'view',
		'article' => true,
		'printable' => false,
		'current' => true,
		'query' => [],
		'content' => true,
		'exists' => true,
		'redirect' => false,
		'model' => CONTENT_MODEL_WIKITEXT,
		'registered' => false,
		'bot' => false,
		'edits' => null,
		'canEdit' => true,
		'blocked' => false,
		'registeredExperiment' => true,
		'running' => true,
		'variant' => 'treatment',
		'forced' => false,
		'params' => [],
	];

	/**
	 * @param array $options
	 * @return array{modules:string[],vars:array,exposed:Assignment[],create:bool|null}
	 */
	private function runHook( array $options = [] ): array {
		$o = $options + self::DEFAULTS;

		$request = $this->createMock( WebRequest::class );
		$request->method( 'getRawVal' )->willReturnCallback( static fn ( $name ) => $o['query'][$name] ?? null );

		$title = $this->createMock( Title::class );
		$title->method( 'getNamespace' )->willReturn( NS_MAIN );
		$title->method( 'exists' )->willReturn( $o['exists'] );
		$title->method( 'isRedirect' )->willReturn( $o['redirect'] );
		$title->method( 'getContentModel' )->willReturn( $o['model'] );

		$user = $this->createMock( User::class );
		$user->method( 'isRegistered' )->willReturn( $o['registered'] );
		$user->method( 'isBot' )->willReturn( $o['bot'] );
		$user->method( 'probablyCan' )->willReturn( $o['canEdit'] );

		$result = [ 'modules' => [], 'vars' => [], 'exposed' => [], 'create' => null ];

		$out = $this->createMock( OutputPage::class );
		$out->method( 'getTitle' )->willReturn( $title );
		$out->method( 'getRequest' )->willReturn( $request );
		$out->method( 'getUser' )->willReturn( $user );
		$out->method( 'getActionName' )->willReturn( $o['action'] );
		$out->method( 'isArticle' )->willReturn( $o['article'] );
		$out->method( 'isPrintable' )->willReturn( $o['printable'] );
		$out->method( 'isRevisionCurrent' )->willReturn( $o['current'] );
		$out->method( 'addModules' )->willReturnCallback( static function ( $modules ) use ( &$result ) {
			$result['modules'] = array_merge( $result['modules'], (array)$modules );
		} );
		$out->method( 'addJsConfigVars' )->willReturnCallback( static function ( $name, $value ) use ( &$result ) {
			$result['vars'][$name] = $value;
		} );

		$namespaceInfo = $this->createMock( NamespaceInfo::class );
		$namespaceInfo->method( 'isContent' )->willReturn( $o['content'] );

		$permissionManager = $this->createMock( PermissionManager::class );
		$permissionManager->method( 'isBlockedFrom' )->willReturn( $o['blocked'] );

		$editTracker = $this->createMock( UserEditTracker::class );
		$editTracker->method( 'getUserEditCount' )->willReturn( $o['edits'] );

		$experiment = Experiment::newFromArray( EditPrompt::EXPERIMENT, [
			'unit' => 'browser',
			'active' => true,
			'default' => 'control',
			'variants' => [ 'control' => 50, 'treatment' => 50 ],
		] );
		$assignment = new Assignment(
			EditPrompt::EXPERIMENT,
			$o['variant'],
			true,
			$o['forced'],
			$o['params'],
			new ExperimentUnit( ExperimentUnit::BROWSER, 'abc' )
		);

		$manager = $this->createMock( ExperimentManager::class );
		$manager->method( 'getExperiment' )->willReturn( $o['registeredExperiment'] ? $experiment : null );
		$manager->method( 'isRunning' )->willReturn( $o['running'] );
		$manager->method( 'assignBrowser' )->willReturnCallback(
			static function ( $name, $request, $create ) use ( &$result, $assignment ) {
				$result['create'] = $create;
				return $assignment;
			}
		);

		$tracker = $this->createMock( ExperimentTracker::class );
		$tracker->method( 'expose' )->willReturnCallback( static function ( $assignment ) use ( &$result ) {
			$result['exposed'][] = $assignment;
		} );

		$handler = new EditPrompt( $manager, $tracker, $namespaceInfo, $permissionManager, $editTracker );
		$handler->onBeforePageDisplay( $out, $this->createMock( Skin::class ) );

		return $result;
	}

	public function testTreatmentShowsThePrompt(): void {
		$result = $this->runHook();

		$this->assertSame( [ EditPrompt::MODULE ], $result['modules'] );
		$this->assertSame( [
			'experiment' => EditPrompt::EXPERIMENT,
			'prompt' => true,
			'track' => true,
			'maxShows' => EditPrompt::MAX_SHOWS,
		], $result['vars'][EditPrompt::CONFIG_VAR] );
		$this->assertCount( 1, $result['exposed'] );
		$this->assertTrue( $result['create'] );
	}

	public function testControlOnlyTracks(): void {
		$result = $this->runHook( [ 'variant' => 'control' ] );

		$this->assertSame( [ EditPrompt::MODULE ], $result['modules'] );
		$this->assertFalse( $result['vars'][EditPrompt::CONFIG_VAR]['prompt'] );
		$this->assertTrue( $result['vars'][EditPrompt::CONFIG_VAR]['track'] );
		$this->assertCount( 1, $result['exposed'] );
	}

	public function testStoppedControlLoadsNothing(): void {
		$result = $this->runHook( [ 'variant' => 'control', 'running' => false ] );

		$this->assertSame( [], $result['modules'] );
		$this->assertSame( [], $result['vars'] );
		$this->assertFalse( $result['create'] );
	}

	public function testStoppedTreatmentStillShowsWithoutTracking(): void {
		$result = $this->runHook( [ 'running' => false ] );

		$this->assertSame( [ EditPrompt::MODULE ], $result['modules'] );
		$this->assertTrue( $result['vars'][EditPrompt::CONFIG_VAR]['prompt'] );
		$this->assertFalse( $result['vars'][EditPrompt::CONFIG_VAR]['track'] );
		$this->assertFalse( $result['create'] );
	}

	public function testMaxShowsComesFromTheVariant(): void {
		$result = $this->runHook( [ 'params' => [ 'maxShows' => 5 ] ] );
		$this->assertSame( 5, $result['vars'][EditPrompt::CONFIG_VAR]['maxShows'] );

		$result = $this->runHook( [ 'params' => [ 'maxShows' => -2 ] ] );
		$this->assertSame( 0, $result['vars'][EditPrompt::CONFIG_VAR]['maxShows'] );
	}

	public function testNewcomerWithoutEditsIsEligible(): void {
		$result = $this->runHook( [ 'registered' => true, 'edits' => 0 ] );

		$this->assertSame( [ EditPrompt::MODULE ], $result['modules'] );
		$this->assertCount( 1, $result['exposed'] );
	}

	public static function provideIneligible(): array {
		return [
			'unregistered experiment' => [ [ 'registeredExperiment' => false ] ],
			'edit action' => [ [ 'action' => 'edit' ] ],
			'history action' => [ [ 'action' => 'history' ] ],
			'not an article view' => [ [ 'article' => false ] ],
			'printable' => [ [ 'printable' => true ] ],
			'old revision' => [ [ 'current' => false ] ],
			'diff' => [ [ 'query' => [ 'diff' => 'prev' ] ] ],
			'oldid' => [ [ 'query' => [ 'oldid' => '12' ] ] ],
			'not a content namespace' => [ [ 'content' => false ] ],
			'missing page' => [ [ 'exists' => false ] ],
			'redirect' => [ [ 'redirect' => true ] ],
			'not wikitext' => [ [ 'model' => CONTENT_MODEL_JSON ] ],
			'cannot edit' => [ [ 'canEdit' => false ] ],
			'blocked' => [ [ 'blocked' => true ] ],
			'user with edits' => [ [ 'registered' => true, 'edits' => 3 ] ],
			'bot' => [ [ 'registered' => true, 'edits' => 0, 'bot' => true ] ],
		];
	}

	/**
	 * @dataProvider provideIneligible
	 */
	public function testIneligibleViewsAreLeftAlone( array $options ): void {
		$result = $this->runHook( $options );

		$this->assertSame( [], $result['modules'] );
		$this->assertSame( [], $result['vars'] );
		$this->assertSame( [], $result['exposed'] );
		$this->assertNull( $result['create'] );
	}
}
