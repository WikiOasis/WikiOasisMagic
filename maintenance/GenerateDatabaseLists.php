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
 * @author Zippybonzo
 * @version 1.0
 */

use MediaWiki\Maintenance\Maintenance;
use MirahezeFunctions;
use Wikimedia\StaticArrayWriter;

class GenerateDatabaseLists extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Generates database list cache files for all wikis' );
	}

	/**
	 * @suppress PhanUndeclaredClassMethod,PhanEmptyForeach MirahezeFunctions is provided
	 *   by the WikiOasis/mw-config site configuration required in by LocalSettings.php,
	 *   not a MediaWiki extension CI can clone, so phan can't see it populates
	 *   $databaseLists by reference
	 */
	public function execute() {
		$databaseLists = [];

		// Populate from MirahezeFunctions
		MirahezeFunctions::onGenerateDatabaseLists( $databaseLists );

		foreach ( $databaseLists as $listName => $listData ) {
			$this->writeCacheFile( $listName, $listData );
		}

		$this->output( "Successfully generated " . count( $databaseLists ) . " database lists.\n" );
	}

	/**
	 * @suppress PhanUndeclaredClassMethod MirahezeFunctions is provided by the
	 *   WikiOasis/mw-config site configuration required in by LocalSettings.php,
	 *   not a MediaWiki extension CI can clone
	 */
	private function writeCacheFile( string $listName, array $data ): void {
		$cacheDir = MirahezeFunctions::getCacheDirectory();
		$fileName = "$cacheDir/$listName.php";

		$cacheData = [
			'databases' => $data,
		];

		$contents = StaticArrayWriter::write(
			$cacheData,
			"Auto-generated database list: $listName"
		);

		if ( file_put_contents( $fileName, $contents ) ) {
			$this->output( "Written: $fileName\n" );
			opcache_invalidate( $fileName, true );
		} else {
			$this->error( "Failed to write: $fileName\n" );
		}
	}
}

$maintClass = GenerateDatabaseLists::class;
