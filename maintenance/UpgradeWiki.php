<?php

namespace WikiOasis\WikiOasisMagic\Maintenance;

/**
 * Runs a wiki upgrade described by a JSON file (SQL patches + maintenance scripts).
 *
 * The wikis being upgraded are given as arguments, via --group or via --file. The
 * --wiki option only decides which wiki this script itself runs under, and does not
 * have to be one of the wikis being upgraded.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Maintenance
 * @author Universal Omega
 * @version 3.0
 */

use MediaWiki\Exception\MWExceptionHandler;
use MediaWiki\Http\Telemetry;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Shell\Shell;
use MwSql;
use RuntimeException;
use Throwable;
use Wikimedia\Rdbms\IDatabase;
use WikiOasis\WikiOasisMagic\UpgradeGroups;

class UpgradeWiki extends Maintenance {

	private const LOG_FILE = '/var/log/mediawiki/debuglogs/UpgradeWiki-exceptions.log';

	private ?string $currentStep = null;
	private ?string $currentWiki = null;
	private bool $completed = false;

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Run a wiki upgrade defined in a JSON file (patches + maintenance steps) ' .
			'against one wiki, several wikis, or an upgrade group from cw_cache.'
		);

		$this->addArg(
			'wiki-to-upgrade',
			'Database name of the wiki to upgrade. May be repeated to upgrade several wikis. ' .
				'This is independent of --wiki, which only selects the wiki this script runs under.',
			false,
			true
		);

		$this->addOption( 'json', 'Path to JSON file describing the upgrade.', true, true );
		$this->addOption(
			'group',
			'Name of an upgrade group database list in cw_cache (e.g. group1) whose wikis ' .
				'should be upgraded. Accepts a comma separated list of groups.',
			false,
			true
		);

		$this->addOption(
			'file',
			'Path to a file of database names to upgrade, one per line.',
			false,
			true
		);

		$this->addOption(
			'cache-directory',
			'Override the directory upgrade group lists are read from (defaults to cw_cache).',
			false,
			true
		);

		$this->addOption(
			'group-prefix',
			'Prefix used by upgrade group lists. Defaults to "' . UpgradeGroups::DEFAULT_PREFIX . '".',
			false,
			true
		);

		$this->addOption(
			'change-version',
			'Run ChangeMediaWikiVersion for each wiki first, setting mwversion to the JSON\'s ' .
				'mwversion key, before any patches or maintenance scripts run for that wiki.'
		);

		$this->addOption( 'force', 'Run the upgrade again even on wikis already marked as upgraded.' );
		$this->addOption( 'dry-run', 'List the wikis that would be upgraded, then exit.' );
		$this->addOption(
			'continue-on-error',
			'Keep upgrading the remaining wikis after one of them fails.'
		);

		$this->requireExtension( 'WikiOasisMagic' );
	}

	public function execute(): void {
		$jsonPath = $this->getOption( 'json' );

		$this->currentStep = "loading JSON file '$jsonPath'";
		$this->registerFailureShutdownHandler();

		$json = $this->loadJson( $jsonPath );
		$this->assertRunningVersion( $json );

		$mwversion = $json['mwversion'];
		$updateKey = "upgrade-wiki-$mwversion";

		$targets = $this->resolveTargets();
		if ( $targets === [] ) {
			$this->completed = true;
			$this->fatalError(
				'No wikis to upgrade. Pass one or more database names as arguments, ' .
				'or use --group or --file.'
			);
		}

		$count = count( $targets );
		$this->output( "=== Upgrading $count wiki(s) to $mwversion based on JSON '$jsonPath' ===\n" );

		if ( $this->hasOption( 'dry-run' ) ) {
			foreach ( $targets as $wiki ) {
				$state = $this->hasAlreadyUpgraded( $wiki, $updateKey ) ? ' (already upgraded)' : '';
				$this->output( "Would upgrade: $wiki$state\n" );
			}

			$this->completed = true;
			return;
		}

		$upgraded = [];
		$skipped = [];
		$failed = [];

		foreach ( $targets as $wiki ) {
			$this->currentWiki = $wiki;

			if ( !$this->hasOption( 'force' ) && $this->hasAlreadyUpgraded( $wiki, $updateKey ) ) {
				$this->output( "$wiki has already been upgraded to $mwversion, skipping.\n" );
				$skipped[] = $wiki;
				continue;
			}

			if ( $this->upgradeWiki( $wiki, $json, $updateKey ) ) {
				$upgraded[] = $wiki;
				continue;
			}

			$failed[] = $wiki;
			if ( !$this->hasOption( 'continue-on-error' ) ) {
				$this->error( 'Stopping, pass --continue-on-error to upgrade the remaining wikis anyway.' );
				break;
			}
		}

		$this->currentWiki = null;
		$this->completed = true;

		$this->output( "=== Done: " . count( $upgraded ) . ' upgraded, ' . count( $skipped ) .
			' skipped, ' . count( $failed ) . " failed ===\n" );

		if ( $failed !== [] ) {
			$this->fatalError( 'Upgrade failed on: ' . implode( ', ', $failed ) );
		}
	}

	/**
	 * Wikis this run should upgrade, in order and without duplicates.
	 *
	 * @return string[]
	 */
	private function resolveTargets(): array {
		$targets = $this->getArgs();

		if ( $this->hasOption( 'group' ) ) {
			$groups = new UpgradeGroups(
				$this->getOption( 'cache-directory' ) ?: null,
				$this->getOption( 'group-prefix', UpgradeGroups::DEFAULT_PREFIX )
			);

			foreach ( explode( ',', $this->getOption( 'group' ) ) as $group ) {
				$group = trim( $group );
				if ( $group === '' ) {
					continue;
				}

				if ( !$groups->groupExists( $group ) ) {
					$this->currentStep = "reading upgrade group '$group'";
					$this->fatalError( "No such upgrade group: $group" );
				}

				$wikis = $groups->getWikis( $group );
				$this->output( '==> Group ' . $group . ': ' . count( $wikis ) . " wiki(s)\n" );
				$targets = array_merge( $targets, $wikis );
			}
		}

		if ( $this->hasOption( 'file' ) ) {
			$fromFile = file( $this->getOption( 'file' ), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
			if ( $fromFile === false ) {
				$this->currentStep = 'reading --file';
				$this->fatalError( 'Unable to read file, exiting.' );
			}

			$targets = array_merge( $targets, array_map( 'trim', $fromFile ) );
		}

		$targets = array_values( array_unique( array_filter( $targets, static fn ( $wiki ) => $wiki !== '' ) ) );

		$localDatabases = $this->getConfig()->get( MainConfigNames::LocalDatabases );
		foreach ( $targets as $wiki ) {
			if ( !in_array( $wiki, $localDatabases, true ) ) {
				$this->error( "Warning: '$wiki' is not in \$wgLocalDatabases." );
			}
		}

		return $targets;
	}

	private function upgradeWiki( string $wiki, array $json, string $updateKey ): bool {
		$this->output( "=== Upgrading '$wiki' ===\n" );

		try {
			if ( $this->hasOption( 'change-version' ) ) {
				$this->runVersionChange( $wiki, $json );
			}

			$this->runPatchesSection( $wiki, $json, 'pre_patches', "=== Running pre-maintenance SQL patches ===\n" );
			$this->runMaintenanceSection( $wiki, $json );
			$this->runPatchesSection( $wiki, $json, 'post_patches', "=== Running post-maintenance SQL patches ===\n" );

			$this->currentStep = "recording the upgrade in $wiki's updatelog";
			$this->markUpgraded( $wiki, $updateKey );

			$this->output( "All steps completed for '$wiki'.\n" );
			return true;
		} catch ( Throwable $t ) {
			MWExceptionHandler::rollbackPrimaryChangesAndLog( $t );
			$this->logToFile( $t, $wiki );
			$logger = LoggerFactory::getInstance( 'UpgradeWiki' );
			$logger->critical( 'UpgradeWiki failed on {wiki} during {step}: {message}', [
				'exception' => $t,
				'message' => $t->getMessage(),
				'step' => $this->currentStep,
				'wiki' => $wiki,
			] );

			$this->error( "Upgrade of '$wiki' failed during {$this->currentStep}: {$t->getMessage()}" );
			return false;
		}
	}

	private function getTargetDatabase( string $wiki ): IDatabase {
		return $this->getServiceContainer()
			->getDBLoadBalancerFactory()
			->getMainLB( $wiki )
			->getConnection( DB_PRIMARY, [], $wiki );
	}

	private function hasAlreadyUpgraded( string $wiki, string $updateKey ): bool {
		$dbw = $this->getTargetDatabase( $wiki );
		return (bool)$dbw->newSelectQueryBuilder()
			->select( 'ul_key' )
			->from( 'updatelog' )
			->where( [ 'ul_key' => $updateKey ] )
			->caller( __METHOD__ )
			->fetchField();
	}

	private function markUpgraded( string $wiki, string $updateKey ): void {
		$dbw = $this->getTargetDatabase( $wiki );
		$dbw->newInsertQueryBuilder()
			->insertInto( 'updatelog' )
			->ignore()
			->row( [ 'ul_key' => $updateKey ] )
			->caller( __METHOD__ )
			->execute();
	}

	private function registerFailureShutdownHandler(): void {
		register_shutdown_function( function (): void {
			if ( $this->completed ) {
				return;
			}

			$wiki = $this->currentWiki ?? 'an unknown wiki';
			$step = $this->currentStep ?? 'an unknown step';
			$requestId = Telemetry::getInstance()->getRequestId();
			$time = date( 'Y-m-d H:i:s' );
			$message = "$wiki [$requestId $time] UpgradeWiki exited unexpectedly while $step. "
				. "This is almost always a fatalError() call, check this run's stderr output "
				. "for the actual message.\n\n";
			file_put_contents( self::LOG_FILE, $message, FILE_APPEND );

			$logger = LoggerFactory::getInstance( 'UpgradeWiki' );
			$logger->critical( 'UpgradeWiki on {wiki} exited unexpectedly while {step}', [
				'step' => $step,
				'wiki' => $wiki,
			] );
		} );
	}

	private function logToFile( Throwable $t, string $wiki ): void {
		$requestId = Telemetry::getInstance()->getRequestId();
		$time = date( 'Y-m-d H:i:s' );
		$message = "$wiki [$requestId $time] UpgradeWiki exception\n$t\n\n";
		file_put_contents( self::LOG_FILE, $message, FILE_APPEND );
	}

	private function assertRunningVersion( array $json ): void {
		$mwversion = $json['mwversion'] ?? null;
		if ( !is_string( $mwversion ) || $mwversion === '' ) {
			$this->currentStep = "validating JSON key 'mwversion'";
			$this->completed = true;
			$this->fatalError( "JSON key 'mwversion' must be a non-empty string." );
		}

		$runningVersion = MW_VERSION;
		if ( !str_starts_with( $runningVersion, $mwversion ) ) {
			$this->currentStep = 'validating running MediaWiki version';
			$this->completed = true;
			$this->fatalError(
				"This script is running under MediaWiki $runningVersion, but the JSON targets $mwversion. "
				. 'Make sure to run this script on the target version.'
			);
		}
	}

	private function runVersionChange( string $wiki, array $json ): void {
		$mwversion = $json['mwversion'];

		$this->output( "=== Running ChangeMediaWikiVersion to set mwversion to '$mwversion' for '$wiki' ===\n" );
		$this->currentStep = "running ChangeMediaWikiVersion to set mwversion to '$mwversion' for '$wiki'";

		// --regex pins the change to this wiki alone, whichever wiki the script runs under.
		$this->runMaintenanceClass( $wiki, ChangeMediaWikiVersion::class, [
			'mwversion' => $mwversion,
			'regex' => '/^' . preg_quote( $wiki, '/' ) . '$/',
		], [] );
	}

	private function runPatchesSection( string $wiki, array $json, string $key, string $header ): void {
		$items = $json[$key] ?? [];
		if ( $items === [] ) {
			return;
		}

		if ( !is_array( $items ) ) {
			$this->currentStep = "validating JSON key '$key'";
			$this->fatalError( "JSON key '$key' must be an array." );
		}

		$this->output( $header );
		foreach ( $items as $item ) {
			$requiredExtension = $item['if_extension_enabled'] ?? null;
			if ( $requiredExtension !== null && !$this->hasExtension( $requiredExtension ) ) {
				$this->output( "==> Skipping SQL: required extension '$requiredExtension' not enabled\n" );
				continue;
			}

			$filename = $this->normalizePatchItemToFilename( $item, $key );
			$this->currentStep = "running SQL patch '$filename' from '$key' on '$wiki'";
			$this->runSqlFile( $wiki, $filename );
		}
	}

	private function runMaintenanceSection( string $wiki, array $json ): void {
		$items = $json['maintenance'] ?? [];
		if ( $items === [] ) {
			return;
		}

		if ( !is_array( $items ) ) {
			$this->currentStep = "validating JSON key 'maintenance'";
			$this->fatalError( "JSON key 'maintenance' must be an array." );
		}

		$this->output( "=== Running maintenance scripts ===\n" );
		foreach ( $items as $idx => $item ) {
			if ( !is_array( $item ) ) {
				$this->currentStep = "validating maintenance[$idx]";
				$this->fatalError( "maintenance[$idx] must be an object." );
			}

			$requiredExtension = $item['if_extension_enabled'] ?? null;
			if ( $requiredExtension !== null && !$this->hasExtension( $requiredExtension ) ) {
				$this->output( "==> Skipping maintenance: required extension '$requiredExtension' not enabled\n" );
				continue;
			}

			$class = $item['class'] ?? null;
			if ( !is_string( $class ) || $class === '' ) {
				$this->currentStep = "validating maintenance[$idx].class";
				$this->fatalError( "maintenance[$idx].class must be a non-empty string." );
			}

			$options = $item['options'] ?? [];
			$args = $item['args'] ?? [];
			if ( $options !== [] && !is_array( $options ) ) {
				$this->currentStep = "validating maintenance[$idx].options for $class";
				$this->fatalError( "maintenance[$idx].options must be an object (key/value) if present." );
			}

			if ( $args !== [] && !is_array( $args ) ) {
				$this->currentStep = "validating maintenance[$idx].args for $class";
				$this->fatalError( "maintenance[$idx].args must be an array if present." );
			}

			$this->output( "==> Maintenance: $class\n" );
			$this->currentStep = "running maintenance class '$class' on '$wiki'";
			$this->runMaintenanceClass( $wiki, $class, $options, $args );
		}
	}

	private function runSqlFile( string $wiki, string $filename ): void {
		$this->output( '==> SQL: ' . basename( $filename ) . "\n" );
		$maint = new MwSql();
		$maint->setOption( 'wikidb', $wiki );
		$maint->setArg( 0, $filename );
		$maint->execute();
	}

	private function runMaintenanceClass( string $wiki, string $class, array $options, array $args ): void {
		if ( !class_exists( $class ) ) {
			$this->fatalError( "Maintenance class not found: $class" );
		}

		$options = $this->validateOptions( $class, $options );
		$args = $this->validateArgs( $class, $args );

		// A maintenance class instantiated in this process talks to the database of the
		// wiki this process was booted for, so it can only be run in-process when that
		// is the wiki being upgraded. Anything else has to be a separate process.
		if ( $wiki === $this->getConfig()->get( MainConfigNames::DBname ) ) {
			$this->runMaintenanceClassInProcess( $wiki, $class, $options, $args );
			return;
		}

		$this->runMaintenanceClassInSubprocess( $wiki, $class, $options, $args );
	}

	private function runMaintenanceClassInProcess( string $wiki, string $class, array $options, array $args ): void {
		/** @var Maintenance $maint */
		$maint = new $class();
		'@phan-var Maintenance $maint';

		if ( !isset( $options['wiki'] ) ) {
			$maint->setOption( 'wiki', $wiki );
		}

		foreach ( $options as $key => $value ) {
			$maint->setOption( $key, $value );
		}

		$argIndex = 0;
		foreach ( $args as $arg ) {
			$maint->setArg( $argIndex, $arg );
			$argIndex++;
		}

		$maint->execute();
	}

	/**
	 * @suppress SecurityCheck-ShellInjection Every argument goes through Shell::escape()
	 */
	private function runMaintenanceClassInSubprocess(
		string $wiki, string $class, array $options, array $args
	): void {
		$php = $this->getConfig()->get( MainConfigNames::PhpCli ) ?: PHP_BINARY;
		$command = [
			$php,
			MW_INSTALL_PATH . '/maintenance/run.php',
			$class,
			'--wiki',
			$wiki,
		];

		foreach ( $options as $key => $value ) {
			if ( $value === null || $value === false ) {
				continue;
			}

			$command[] = "--$key";
			if ( $value !== true ) {
				$command[] = (string)$value;
			}
		}

		foreach ( $args as $arg ) {
			$command[] = $arg;
		}

		$this->output( '===> ' . implode( ' ', $command ) . "\n" );

		$exitCode = 0;
		// passthru() rather than Shell::command() so that the output of a step that can
		// run for hours streams out live instead of being buffered until it finishes.
		// Every argument is escaped by Shell::escape() above.
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.passthru
		passthru( Shell::escape( ...$command ), $exitCode );

		if ( $exitCode !== 0 ) {
			throw new RuntimeException( "$class exited with status $exitCode on $wiki." );
		}
	}

	private function validateOptions( string $class, array $options ): array {
		$validated = [];
		foreach ( $options as $key => $value ) {
			if ( !is_string( $key ) || $key === '' ) {
				$this->fatalError( "Invalid option key for $class (must be non-empty string)." );
			}

			if (
				$value !== null &&
				!is_string( $value ) &&
				!is_int( $value ) &&
				!is_bool( $value )
			) {
				$this->fatalError(
					"Option '$key' for $class must be string/int/bool/null (got non-scalar)."
				);
			}

			$validated[$key] = $value;
		}

		return $validated;
	}

	private function validateArgs( string $class, array $args ): array {
		$validated = [];
		foreach ( $args as $arg ) {
			if ( !is_string( $arg ) && !is_int( $arg ) ) {
				$this->fatalError( "Args for $class must be strings/ints." );
			}

			$validated[] = (string)$arg;
		}

		return $validated;
	}

	private function hasExtension( string $name ): bool {
		return ExtensionRegistry::getInstance()->isLoaded( $name );
	}

	private function normalizePatchItemToFilename( mixed $item, string $sectionKey ): string {
		if ( is_string( $item ) && $item !== '' ) {
			return $item;
		}

		if ( is_array( $item ) ) {
			$file = $item['file'] ?? null;
			if ( is_string( $file ) && $file !== '' ) {
				return $file;
			}
		}

		$this->fatalError(
			"Each entry in '$sectionKey' must be either a string filename or {\"file\": \"...\"}."
		);
	}

	private function loadJson( string $filename ): array {
		$json = file_get_contents( $filename );
		if ( $json === false ) {
			$this->completed = true;
			$this->fatalError( "Failed to read JSON file: $filename" );
		}

		$data = json_decode( $json, true );
		if ( !is_array( $data ) ) {
			$this->completed = true;
			$this->fatalError( "JSON file did not decode to an object: $filename" );
		}

		return $data;
	}
}

// @codeCoverageIgnoreStart
return UpgradeWiki::class;
// @codeCoverageIgnoreEnd
