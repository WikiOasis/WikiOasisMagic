<?php

namespace WikiOasis\WikiOasisMagic\Maintenance;

/**
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
 * @version 2.0
 */

use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use Wikimedia\AtEase\AtEase;
use WikiOasisFunctions;

class ChangeMediaWikiVersion extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Change the MediaWiki version for a specific wiki or a list of wikis from a text file.' );

		$this->addOption( 'mwversion', 'Sets the wikis requested to a different MediaWiki version. Accepts either a version number (e.g. 1.45) or an alias from WikiOasisFunctions::MEDIAWIKI_VERSIONS (e.g. stable).', true, true );
		$this->addOption( 'file', 'Path to file where the wikinames are stored. Must be one wikidb name per line. (Optional, falls back to current dbname)', false, true );
		$this->addOption( 'regex', 'Select wikis whose database name matches this PCRE pattern, e.g. \'/^(testwiki|test2wiki)$/\'. Overrides the --file option.', false, true );
		$this->addOption( 'dry-run', 'Performs a dry run without making any changes to the wikis.' );

		// All wikis
		$this->addOption( 'all-wikis', 'Change MediaWiki version on all wikis.' );

		// State options
		$this->addOption( 'active', 'Only change MediaWiki version on active wikis.' );
		$this->addOption( 'closed', 'Only change MediaWiki version on closed wikis.' );
		$this->addOption( 'deleted', 'Only change MediaWiki version on deleted wikis.' );
		$this->addOption( 'inactive', 'Only change MediaWiki version on inactive wikis.' );

		$this->requireExtension( 'CreateWiki' );
	}

	/**
	 * @suppress PhanUndeclaredClassMethod,PhanUndeclaredClassConstant WikiOasisFunctions is provided by the
	 *   WikiOasis/mw-config site configuration required in by LocalSettings.php,
	 *   not a MediaWiki extension CI can clone
	 */
	public function execute() {
		$dbnames = [];
		if ( $this->hasOption( 'regex' ) ) {
			$pattern = $this->getOption( 'regex' );
			$dbnames = $this->getWikiDbNamesByRegex( $pattern );
		} elseif ( $this->hasOption( 'file' ) ) {
			$dbnames = file( $this->getOption( 'file' ), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
			if ( $dbnames === false ) {
				$this->fatalError( 'Unable to read file, exiting' );
			}

			$dbnames = array_values( array_filter( array_map( 'trim', $dbnames ) ) );
		} elseif ( $this->hasOption( 'all-wikis' ) || $this->hasOption( 'active' ) || $this->hasOption( 'closed' ) || $this->hasOption( 'deleted' ) || $this->hasOption( 'inactive' ) ) {
			$dbnames = $this->getConfig()->get( MainConfigNames::LocalDatabases );
		} else {
			$dbnames[] = $this->getConfig()->get( MainConfigNames::DBname );
		}

		if ( $dbnames === [] ) {
			$this->fatalError( 'No wikis matched, nothing to change.' );
		}

		$newVersion = $this->resolveVersion( $this->getOption( 'mwversion' ) );
		$versionPath = WikiOasisFunctions::MEDIAWIKI_DIRECTORY . '/' . $newVersion;
		if ( !is_dir( $versionPath ) ) {
			$this->fatalError( "No such MediaWiki version installed: $versionPath" );
		}

		$remoteWikiFactory = $this->getServiceContainer()->get( 'RemoteWikiFactory' );

		$defaultVersion = WikiOasisFunctions::MEDIAWIKI_VERSIONS[WikiOasisFunctions::getDefaultMediaWikiVersion()];
		$changed = 0;
		$alreadyOn = 0;
		$filteredOut = 0;

		foreach ( $dbnames as $dbname ) {
			$remoteWiki = $remoteWikiFactory->newInstance( $dbname );
			$remoteWiki->disableResetDatabaseLists();

			$oldVersion = $this->resolveVersion(
				(string)( $remoteWiki->getExtraFieldData( 'mediawiki-version', $defaultVersion ) ?: $defaultVersion )
			);

			if ( $this->hasOption( 'active' ) && ( $remoteWiki->isClosed() || $remoteWiki->isDeleted() || $remoteWiki->isInactive() ) ) {
				$filteredOut++;
				continue;
			}

			if ( $this->hasOption( 'closed' ) && !$remoteWiki->isClosed() ) {
				$filteredOut++;
				continue;
			}

			if ( $this->hasOption( 'deleted' ) && !$remoteWiki->isDeleted() ) {
				$filteredOut++;
				continue;
			}

			if ( $this->hasOption( 'inactive' ) && !$remoteWiki->isInactive() ) {
				$filteredOut++;
				continue;
			}

			if ( $oldVersion === $newVersion ) {
				$this->output( "$dbname is already on $newVersion\n" );
				$alreadyOn++;
				continue;
			}

			if ( $this->hasOption( 'dry-run' ) ) {
				$this->output( "Dry run: Would upgrade $dbname from $oldVersion to $newVersion\n" );
				$changed++;
				continue;
			}

			$remoteWiki->setExtraFieldData(
				'mediawiki-version', $newVersion, default: $oldVersion
			);

			$remoteWiki->commit();
			$changed++;
			$this->output( "Upgraded $dbname from $oldVersion to $newVersion\n" );
		}

		$verb = $this->hasOption( 'dry-run' ) ? 'would change' : 'changed';
		$this->output( count( $dbnames ) . " wiki(s) selected: $changed $verb, $alreadyOn already on $newVersion, " .
			"$filteredOut skipped by state filter\n" );

		if ( $this->hasOption( 'dry-run' ) || $changed === 0 ) {
			return;
		}

		$this->output( "Regenerating database lists for $changed changed wiki(s)\n" );

		$dataStore = $this->getServiceContainer()->get( 'CreateWikiDataStore' );
		$dataStore->resetDatabaseLists( isNewChanges: true );
	}

	/**
	 * Turns an alias such as 'stable' into the version number it points at,
	 * matching what WikiOasisFunctions::getMediaWikiVersion() returns. A value
	 * that is not an alias is returned unchanged.
	 *
	 * @suppress PhanUndeclaredClassConstant WikiOasisFunctions is provided by the
	 *   WikiOasis/mw-config site configuration required in by LocalSettings.php,
	 *   not a MediaWiki extension CI can clone
	 */
	private function resolveVersion( string $version ): string {
		return WikiOasisFunctions::MEDIAWIKI_VERSIONS[$version] ?? $version;
	}

	private function getWikiDbNamesByRegex( string $pattern ): array {
		if ( AtEase::quietCall( 'preg_match', $pattern, '' ) === false ) {
			$this->fatalError( "Invalid --regex pattern: $pattern" );
		}

		$allDbNames = $this->getConfig()->get( MainConfigNames::LocalDatabases );

		$matchingDbNames = [];
		foreach ( $allDbNames as $dbName ) {
			if ( preg_match( $pattern, $dbName ) ) {
				$matchingDbNames[] = $dbName;
			}
		}

		return $matchingDbNames;
	}
}

// @codeCoverageIgnoreStart
return ChangeMediaWikiVersion::class;
// @codeCoverageIgnoreEnd
