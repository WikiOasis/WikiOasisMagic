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
use WikiOasis\WikiOasisMagic\EditPrompt\EditPromptGate;
use WikiOasis\WikiOasisMagic\Experiments\Assignment;
use WikiOasis\WikiOasisMagic\Experiments\Experiment;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentManager;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentTracker;
use WikiOasis\WikiOasisMagic\Experiments\ExperimentUnit;
use WikiOasis\WikiOasisMagic\HookHandlers\EditPrompt;

/**
 * @covers \WikiOasis\WikiOasisMagic\HookHandlers\EditPrompt
 * @covers \WikiOasis\WikiOasisMagic\EditPrompt\EditPromptGate
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
	 * @param array &$result
	 * @return array{gate:EditPromptGate,user:User,title:Title,request:WebRequest}
	 */
	private function newGate( array $options, array &$result ): array {
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

		$namespaceInfo = $this->createMock( NamespaceInfo::class );
		$namespaceInfo->method( 'isContent' )->willReturn( $o['content'] );

		$permissionManager = $this->createMock( PermissionManager::class );
		$permissionManager->method( 'isBlockedFrom' )->willReturn( $o['blocked'] );

		$editTracker = $this->createMock( UserEditTracker::class );
		$editTracker->method( 'getUserEditCount' )->willReturn( $o['edits'] );

		$experiment = Experiment::newFromArray( EditPromptGate::EXPERIMENT, [
			'unit' => 'browser',
			'active' => true,
			'default' => 'control',
			'variants' => [ 'control' => 50, 'treatment' => 50 ],
		] );
		$assignment = new Assignment(
			EditPromptGate::EXPERIMENT,
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
				$result['create'][] = $create;
				return $assignment;
			}
		);

		$tracker = $this->createMock( ExperimentTracker::class );
		$tracker->method( 'expose' )->willReturnCallback( static function ( $assignment ) use ( &$result ) {
			$result['exposed'][] = $assignment;
		} );

		return [
			'gate' => new EditPromptGate( $manager, $tracker, $namespaceInfo, $permissionManager, $editTracker ),
			'user' => $user,
			'title' => $title,
			'request' => $request,
		];
	}

	/**
	 * @param array $options
	 * @return array{modules:string[],vars:array,exposed:Assignment[],create:bool[]}
	 */
	private function runHook( array $options = [] ): array {
		$o = $options + self::DEFAULTS;
		$result = [ 'modules' => [], 'vars' => [], 'exposed' => [], 'create' => [] ];
		$parts = $this->newGate( $options, $result );

		$out = $this->createMock( OutputPage::class );
		$out->method( 'getTitle' )->willReturn( $parts['title'] );
		$out->method( 'getRequest' )->willReturn( $parts['request'] );
		$out->method( 'getUser' )->willReturn( $parts['user'] );
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

		( new EditPrompt( $parts['gate'] ) )->onBeforePageDisplay( $out, $this->createMock( Skin::class ) );

		return $result;
	}

	/**
	 * @param array $options
	 * @return array{assignment:array,exposed:Assignment[],create:bool[]}
	 */
	private function runAssign( array $options = [] ): array {
		$result = [ 'exposed' => [], 'create' => [] ];
		$parts = $this->newGate( $options, $result );
		$result['assignment'] = $parts['gate']->assign( $parts['user'], $parts['title'], $parts['request'] );
		return $result;
	}

	public function testPageCarriesNoPerBrowserState(): void {
		$result = $this->runHook();

		$this->assertSame( [ EditPrompt::MODULE ], $result['modules'] );
		$this->assertSame(
			[ 'experiment' => EditPromptGate::EXPERIMENT ],
			$result['vars'][EditPrompt::CONFIG_VAR]
		);
		$this->assertSame( [], $result['exposed'] );
		$this->assertSame( [], $result['create'] );
	}

	public function testRunningControlStillLoadsForTracking(): void {
		$result = $this->runHook( [ 'variant' => 'control' ] );

		$this->assertSame( [ EditPrompt::MODULE ], $result['modules'] );
		$this->assertSame( [], $result['exposed'] );
	}

	public function testStoppedControlLoadsNothing(): void {
		$result = $this->runHook( [ 'variant' => 'control', 'running' => false ] );

		$this->assertSame( [], $result['modules'] );
		$this->assertSame( [ false ], $result['create'] );
	}

	public function testStoppedTreatmentStillLoads(): void {
		$result = $this->runHook( [ 'running' => false ] );

		$this->assertSame( [ EditPrompt::MODULE ], $result['modules'] );
		$this->assertSame( [ false ], $result['create'] );
	}

	public function testAnonymousBlocksAreLeftToTheApi(): void {
		$this->assertSame( [ EditPrompt::MODULE ], $this->runHook( [ 'blocked' => true ] )['modules'] );
		$this->assertSame(
			[],
			$this->runHook( [ 'blocked' => true, 'registered' => true, 'edits' => 0 ] )['modules']
		);

		$result = $this->runAssign( [ 'blocked' => true ] );
		$this->assertFalse( $result['assignment']['prompt'] );
		$this->assertSame( [], $result['exposed'] );
	}

	public function testAssignTreatment(): void {
		$result = $this->runAssign();

		$this->assertSame(
			[ 'prompt' => true, 'track' => true, 'maxShows' => EditPromptGate::MAX_SHOWS ],
			$result['assignment']
		);
		$this->assertCount( 1, $result['exposed'] );
		$this->assertSame( [ true ], $result['create'] );
	}

	public function testAssignControl(): void {
		$result = $this->runAssign( [ 'variant' => 'control' ] );

		$this->assertFalse( $result['assignment']['prompt'] );
		$this->assertTrue( $result['assignment']['track'] );
		$this->assertCount( 1, $result['exposed'] );
	}

	public function testAssignWhileStoppedDoesNotTrack(): void {
		$result = $this->runAssign( [ 'running' => false ] );

		$this->assertTrue( $result['assignment']['prompt'] );
		$this->assertFalse( $result['assignment']['track'] );
		$this->assertSame( [ false ], $result['create'] );
	}

	public function testMaxShowsComesFromTheVariant(): void {
		$this->assertSame( 5, $this->runAssign( [ 'params' => [ 'maxShows' => 5 ] ] )['assignment']['maxShows'] );
		$this->assertSame( 0, $this->runAssign( [ 'params' => [ 'maxShows' => -2 ] ] )['assignment']['maxShows'] );
	}

	public function testNewcomerWithoutEditsIsEligible(): void {
		$this->assertSame(
			[ EditPrompt::MODULE ],
			$this->runHook( [ 'registered' => true, 'edits' => 0 ] )['modules']
		);
		$this->assertCount( 1, $this->runAssign( [ 'registered' => true, 'edits' => 0 ] )['exposed'] );
	}

	public static function provideIneligibleViews(): array {
		return [
			'edit action' => [ [ 'action' => 'edit' ] ],
			'history action' => [ [ 'action' => 'history' ] ],
			'not an article view' => [ [ 'article' => false ] ],
			'printable' => [ [ 'printable' => true ] ],
			'old revision' => [ [ 'current' => false ] ],
			'diff' => [ [ 'query' => [ 'diff' => 'prev' ] ] ],
			'oldid' => [ [ 'query' => [ 'oldid' => '12' ] ] ],
		];
	}

	/**
	 * @dataProvider provideIneligibleViews
	 */
	public function testIneligibleViewsAreLeftAlone( array $options ): void {
		$result = $this->runHook( $options );

		$this->assertSame( [], $result['modules'] );
		$this->assertSame( [], $result['vars'] );
	}

	public static function provideIneligibleReaders(): array {
		return [
			'unregistered experiment' => [ [ 'registeredExperiment' => false ] ],
			'not a content namespace' => [ [ 'content' => false ] ],
			'missing page' => [ [ 'exists' => false ] ],
			'redirect' => [ [ 'redirect' => true ] ],
			'not wikitext' => [ [ 'model' => CONTENT_MODEL_JSON ] ],
			'cannot edit' => [ [ 'canEdit' => false ] ],
			'user with edits' => [ [ 'registered' => true, 'edits' => 3 ] ],
			'bot' => [ [ 'registered' => true, 'edits' => 0, 'bot' => true ] ],
		];
	}

	/**
	 * @dataProvider provideIneligibleReaders
	 */
	public function testIneligibleReadersAreLeftAlone( array $options ): void {
		$this->assertSame( [], $this->runHook( $options )['modules'] );

		$result = $this->runAssign( $options );
		$this->assertSame( [ 'prompt' => false, 'track' => false, 'maxShows' => 0 ], $result['assignment'] );
		$this->assertSame( [], $result['exposed'] );
		$this->assertSame( [], $result['create'] );
	}
}
