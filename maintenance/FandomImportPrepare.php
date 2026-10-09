<?php

namespace WikiOasis\WikiOasisMagic\Maintenance;

use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Shell\Shell;
use MediaWiki\User\User;
use Miraheze\ManageWiki\Helpers\Factories\ModuleFactory;
use Throwable;
use WikiOasis\WikiOasisMagic\FandomImport\ConfigNames;
use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function end;
use function explode;
use function file_get_contents;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function sort;
use function str_replace;
use function trim;

/**
 * Gets a new wiki ready for the pages of a Fandom wiki. The Fandom import
 * worker runs it on the new wiki before importing anything:
 *
 *   run.php WikiOasisMagic:FandomImportPrepare --wiki=muppetwiki \
 *     --siteinfo=/srv/fandom-import/work/42/siteinfo.json --upload-user="Fandom import"
 *
 * It turns on the extensions the Fandom wiki's pages rely on, adds its own
 * content namespaces and makes the account files are uploaded under. The
 * last line it prints is JSON for the worker, including the namespaces the
 * wiki now has: pages in any other namespace are left out of the import.
 */
class FandomImportPrepare extends Maintenance {

	/**
	 * Fandom's own namespaces: forums, user profiles, blogs, message walls,
	 * discussions, maps and the like. Nothing here can show them, so they are not recreated.
	 * (Module, 828 and 829, exists everywhere already.)
	 */
	private const FANDOM_NAMESPACES = [
		110, 111, 202, 203, 400, 401, 420, 421, 500, 501, 502, 503, 700, 701,
		828, 829, 1200, 1201, 1202, 1203, 2000, 2001, 2002, 2900, 2901,
	];

	private array $warnings = [];

	private ?ModuleFactory $moduleFactory = null;
	private bool $moduleFactoryLoaded = false;

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Prepare a new wiki for a Fandom import.' );
		$this->addOption( 'siteinfo', 'The Fandom wiki\'s api.php siteinfo response, as a JSON file', false, true );
		$this->addOption( 'upload-user', 'The account to upload files as', false, true );
		$this->addOption( 'list-namespaces', 'Only print the namespaces this wiki has, as JSON' );
		$this->requireExtension( 'WikiOasisMagic' );
	}

	public function execute(): void {
		if ( $this->hasOption( 'list-namespaces' ) ) {
			$this->output( json_encode( [ 'namespaces' => $this->getNamespaces() ] ) . "\n" );
			return;
		}

		if ( !$this->hasOption( 'siteinfo' ) || !$this->hasOption( 'upload-user' ) ) {
			$this->fatalError( '--siteinfo and --upload-user are required.' );
		}

		$json = file_get_contents( (string)$this->getOption( 'siteinfo' ) );
		$siteinfo = $json !== false ? json_decode( $json, true ) : null;
		$query = is_array( $siteinfo ) ? ( $siteinfo['query'] ?? null ) : null;
		if ( !is_array( $query ) ) {
			$this->fatalError( 'The siteinfo file is missing or is not an api.php siteinfo response.' );
		}

		$extensions = $this->enableExtensions( is_array( $query['extensions'] ?? null ) ? $query['extensions'] : [] );
		$created = $this->createNamespaces( is_array( $query['namespaces'] ?? null ) ? $query['namespaces'] : [] );
		$uploadUser = $this->getUploadUser( (string)$this->getOption( 'upload-user' ) );

		$namespaces = $extensions || $created ? $this->getNamespacesFromNewProcess() : $this->getNamespaces();
		foreach ( $created as $namespace ) {
			$namespaces[] = $namespace['id'];
			$namespaces[] = $namespace['id'] + 1;
		}
		$namespaces = array_values( array_unique( $namespaces ) );
		sort( $namespaces );

		foreach ( $this->warnings as $warning ) {
			$this->error( "Warning: $warning" );
		}

		$this->output( json_encode( [
			'namespaces' => $namespaces,
			'upload_user' => $uploadUser,
			'enabled_extensions' => $extensions,
			'created_namespaces' => $created,
			'warnings' => $this->warnings,
		] ) . "\n" );
	}

	/**
	 * @param array $fandomExtensions siteinfo's extensions
	 * @return string[] The ManageWiki extensions turned on
	 */
	private function enableExtensions( array $fandomExtensions ): array {
		$moduleFactory = $this->getModuleFactory();
		if ( !$moduleFactory || !$moduleFactory->isEnabled( 'extensions' ) ) {
			return [];
		}

		$map = (array)$this->getConfig()->get( ConfigNames::EXTENSION_MAP );
		$available = (array)$this->getConfig()->get( 'ManageWikiExtensions' );
		$enabled = $moduleFactory->extensionsLocal()->list();

		$wanted = [];
		foreach ( $fandomExtensions as $extension ) {
			$key = is_string( $extension['name'] ?? null ) ? ( $map[$extension['name']] ?? null ) : null;
			if ( is_string( $key ) && isset( $available[$key] ) && !in_array( $key, $enabled, true ) ) {
				$wanted[] = $key;
			}
		}

		$added = [];
		foreach ( array_unique( $wanted ) as $key ) {
			try {
				$module = $moduleFactory->extensionsLocal();
				$module->add( [ $key ] );
				$module->commit();
				if ( $module->getErrors() ) {
					$this->warnings[] = "Could not enable $key: " . json_encode( $module->getErrors() );
					continue;
				}
				$added[] = $key;
			} catch ( Throwable $e ) {
				$this->warnings[] = "Could not enable $key: {$e->getMessage()}";
			}
		}

		return $added;
	}

	/**
	 * Recreate the namespaces the Fandom wiki's admins added, with the same
	 * numbers and names, so their pages import into them.
	 *
	 * @param array $fandomNamespaces siteinfo's namespaces
	 * @return array<int,array{id:int,name:string}>
	 */
	private function createNamespaces( array $fandomNamespaces ): array {
		$moduleFactory = $this->getModuleFactory();
		if ( !$moduleFactory || !$moduleFactory->isEnabled( 'namespaces' ) ) {
			return [];
		}

		$byId = [];
		foreach ( $fandomNamespaces as $namespace ) {
			if ( is_array( $namespace ) && is_int( $namespace['id'] ?? null ) && is_string( $namespace['name'] ?? null ) ) {
				$byId[$namespace['id']] = $namespace;
			}
		}

		$namespaceInfo = $this->getServiceContainer()->getNamespaceInfo();
		$subjects = array_filter(
			$byId,
			static fn ( array $namespace, int $id ): bool => $id >= 100 && $id % 2 === 0 &&
				!in_array( $id, self::FANDOM_NAMESPACES, true ) && !$namespaceInfo->exists( $id ),
			ARRAY_FILTER_USE_BOTH
		);

		$created = [];
		foreach ( $subjects as $id => $subject ) {
			$name = str_replace( ' ', '_', $subject['name'] );
			$talkName = str_replace( ' ', '_', $byId[$id + 1]['name'] ?? "{$subject['name']} talk" );

			try {
				$module = $moduleFactory->namespacesLocal();
				if ( $module->exists( $id ) || $module->exists( $id + 1 ) ||
					$module->nameExists( $name, checkMetaNS: true ) || $module->nameExists( $talkName, checkMetaNS: true )
				) {
					$this->warnings[] = "Skipped namespace $id ($name): its number or name is taken.";
					continue;
				}

				$module->modify( $id, $this->namespaceData( $name, (bool)( $subject['content'] ?? false ),
					(bool)( $subject['subpages'] ?? false ) ), maintainPrefix: false );
				$module->modify( $id + 1, $this->namespaceData( $talkName, false, true ), maintainPrefix: false );
				$module->commit();

				if ( $module->getErrors() ) {
					$this->warnings[] = "Could not add namespace $id ($name): " . json_encode( $module->getErrors() );
					continue;
				}

				$created[] = [ 'id' => $id, 'name' => $name ];
			} catch ( Throwable $e ) {
				$this->warnings[] = "Could not add namespace $id ($name): {$e->getMessage()}";
			}
		}

		return $created;
	}

	/**
	 * @return int[]
	 */
	private function getNamespaces(): array {
		return $this->getServiceContainer()->getNamespaceInfo()->getValidNamespaces();
	}

	/**
	 * @return int[]
	 */
	private function getNamespacesFromNewProcess(): array {
		$result = Shell::makeScriptCommand( self::class, [
			'--wiki', $this->getConfig()->get( MainConfigNames::DBname ),
			'--list-namespaces',
		] )->limits( [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ] )->execute();

		$lines = explode( "\n", trim( $result->getStdout() ) );
		$data = json_decode( (string)end( $lines ), true );
		if ( $result->getExitCode() !== 0 || !is_array( $data['namespaces'] ?? null ) ) {
			$this->warnings[] = 'Could not list the namespaces in a new process; extensions just enabled ' .
				'may have namespaces whose pages are left out. ' . trim( $result->getStderr() );
			return $this->getNamespaces();
		}

		return array_map( 'intval', $data['namespaces'] );
	}

	private function namespaceData( string $name, bool $content, bool $subpages ): array {
		return [
			'name' => $name,
			'searchable' => (int)$content,
			'subpages' => (int)$subpages,
			'content' => (int)$content,
			'contentmodel' => CONTENT_MODEL_WIKITEXT,
			'protection' => '',
			'aliases' => [],
			'core' => 0,
			'additional' => [],
		];
	}

	/**
	 * A system account, so nobody can log in as it. If someone already
	 * registered the name, files go up as "Maintenance script" instead.
	 */
	private function getUploadUser( string $name ): string {
		$user = User::newSystemUser( $name, [ 'steal' => false ] );
		if ( $user ) {
			return $user->getName();
		}

		$this->warnings[] = "\"$name\" is taken or invalid; uploading as " . User::MAINTENANCE_SCRIPT_USER . '.';
		return User::newSystemUser( User::MAINTENANCE_SCRIPT_USER, [ 'steal' => true ] )->getName();
	}

	private function getModuleFactory(): ?ModuleFactory {
		if ( !$this->moduleFactoryLoaded ) {
			$this->moduleFactoryLoaded = true;
			if ( $this->getServiceContainer()->hasService( 'ManageWikiModuleFactory' ) ) {
				$this->moduleFactory = $this->getServiceContainer()->get( 'ManageWikiModuleFactory' );
			} else {
				$this->warnings[] = 'ManageWiki is not installed; no extensions or namespaces were added.';
			}
		}

		return $this->moduleFactory;
	}
}

return FandomImportPrepare::class;
