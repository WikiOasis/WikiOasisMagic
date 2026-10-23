<?php

namespace WikiOasis\WikiOasisMagic;

/**
 * Reads and writes the upgrade group database lists (group1, group2, ...) stored
 * in the CreateWiki cache directory (cw_cache).
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
 * @author Zippybonzo
 * @version 1.0
 */

use InvalidArgumentException;
use RuntimeException;
use Wikimedia\StaticArrayWriter;
use WikiOasisFunctions;

class UpgradeGroups {

	public const DEFAULT_PREFIX = 'group';

	private string $cacheDirectory;
	private string $prefix;

	/**
	 * @param ?string $cacheDirectory Defaults to the CreateWiki cache directory (cw_cache).
	 * @param string $prefix Group name prefix, groups are named "<prefix><number>".
	 *
	 * @suppress PhanUndeclaredClassMethod WikiOasisFunctions is provided by the
	 *   WikiOasis/mw-config site configuration required in by LocalSettings.php,
	 *   not a MediaWiki extension CI can clone
	 */
	public function __construct( ?string $cacheDirectory = null, string $prefix = self::DEFAULT_PREFIX ) {
		$this->cacheDirectory = rtrim( (string)( $cacheDirectory ?? WikiOasisFunctions::getCacheDirectory() ), '/' );
		$this->prefix = $prefix;
	}

	public function getCacheDirectory(): string {
		return $this->cacheDirectory;
	}

	public function getPrefix(): string {
		return $this->prefix;
	}

	/**
	 * Names of every existing group, ordered by their trailing number.
	 *
	 * @return string[]
	 */
	public function getGroupNames(): array {
		$pattern = $this->cacheDirectory . '/' . $this->prefix . '[0-9]*.php';
		$files = glob( $pattern ) ?: [];

		$groups = [];
		foreach ( $files as $file ) {
			$name = basename( $file, '.php' );
			if ( preg_match( '/^' . preg_quote( $this->prefix, '/' ) . '([0-9]+)$/', $name, $matches ) ) {
				$groups[(int)$matches[1]] = $name;
			}
		}

		ksort( $groups );
		return array_values( $groups );
	}

	/**
	 * Name the next group would get, e.g. group3 when group1 and group2 exist.
	 */
	public function getNextGroupName(): string {
		$highest = 0;
		foreach ( $this->getGroupNames() as $group ) {
			$number = (int)substr( $group, strlen( $this->prefix ) );
			$highest = max( $highest, $number );
		}

		return $this->prefix . ( $highest + 1 );
	}

	public function getFilePath( string $group ): string {
		$this->assertValidGroupName( $group );
		return $this->cacheDirectory . '/' . $group . '.php';
	}

	public function groupExists( string $group ): bool {
		return file_exists( $this->getFilePath( $group ) );
	}

	/**
	 * Database names in a group, in the order they are stored, without duplicates.
	 *
	 * @return string[]
	 */
	public function getWikis( string $group ): array {
		return $this->readList( $group );
	}

	/**
	 * Database names in any cw_cache database list, such as a group or one of the
	 * lists CreateWiki generates (active, closed, inactive, deleted, public,
	 * private, databases).
	 *
	 * @return string[]
	 */
	public function readList( string $name ): array {
		$this->assertValidListName( $name );
		$file = $this->cacheDirectory . '/' . $name . '.php';
		if ( !file_exists( $file ) ) {
			return [];
		}

		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $file, true );
		}

		$data = require $file;
		if ( !is_array( $data ) || !isset( $data['databases'] ) || !is_array( $data['databases'] ) ) {
			throw new RuntimeException( "Database list '$file' does not contain a 'databases' array." );
		}

		$databases = $data['databases'];
		$wikis = array_is_list( $databases ) ? $databases : array_keys( $databases );

		return array_values( array_unique( array_map( 'strval', $wikis ) ) );
	}

	public function listExists( string $name ): bool {
		$this->assertValidListName( $name );
		return file_exists( $this->cacheDirectory . '/' . $name . '.php' );
	}

	/**
	 * Every group and its wikis.
	 *
	 * @return array<string,string[]> Group name => database names
	 */
	public function getAllGroups(): array {
		$groups = [];
		foreach ( $this->getGroupNames() as $group ) {
			$groups[$group] = $this->getWikis( $group );
		}

		return $groups;
	}

	/**
	 * Every wiki that is already in a group, mapped to the groups it appears in.
	 *
	 * @return array<string,string[]> Database name => group names
	 */
	public function getAssignments(): array {
		$assignments = [];
		foreach ( $this->getAllGroups() as $group => $wikis ) {
			foreach ( $wikis as $wiki ) {
				$assignments[$wiki][] = $group;
			}
		}

		return $assignments;
	}

	/**
	 * Wikis that appear in more than one group.
	 *
	 * @return array<string,string[]> Database name => group names
	 */
	public function getDuplicates(): array {
		return array_filter(
			$this->getAssignments(),
			static fn ( array $groups ): bool => count( $groups ) > 1
		);
	}

	/**
	 * @return string[] Every wiki that is in any group.
	 */
	public function getAssignedWikis(): array {
		return array_keys( $this->getAssignments() );
	}

	/**
	 * Write a group, dropping duplicates while keeping the given order.
	 *
	 * @param string $group
	 * @param string[] $wikis
	 */
	public function setWikis( string $group, array $wikis ): void {
		$file = $this->getFilePath( $group );

		$databases = [];
		foreach ( $wikis as $wiki ) {
			$databases[$wiki] = [];
		}

		$contents = StaticArrayWriter::write(
			[ 'databases' => $databases ],
			"Auto-generated database list: $group"
		);

		if ( !is_dir( $this->cacheDirectory ) ) {
			throw new RuntimeException( "Cache directory does not exist: {$this->cacheDirectory}" );
		}

		$temporary = $file . '.tmp' . getmypid();
		if ( file_put_contents( $temporary, $contents ) === false ) {
			throw new RuntimeException( "Failed to write database list: $temporary" );
		}

		if ( !rename( $temporary, $file ) ) {
			unlink( $temporary );
			throw new RuntimeException( "Failed to move database list into place: $file" );
		}

		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $file, true );
		}
	}

	public function deleteGroup( string $group ): bool {
		$file = $this->getFilePath( $group );
		if ( !file_exists( $file ) ) {
			return false;
		}

		$deleted = unlink( $file );
		if ( $deleted && function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $file, true );
		}

		return $deleted;
	}

	/**
	 * Groups are always "<prefix><number>". cw_cache also holds CreateWiki's own
	 * lists and a cache file per wiki named after its database, so anything looser
	 * could overwrite one of those.
	 */
	public function isValidGroupName( string $group ): bool {
		return (bool)preg_match( '/^' . preg_quote( $this->prefix, '/' ) . '[0-9]+$/', $group );
	}

	private function assertValidGroupName( string $group ): void {
		if ( !$this->isValidGroupName( $group ) ) {
			throw new InvalidArgumentException(
				"Invalid group name: $group (groups must be named {$this->prefix}<number>)"
			);
		}
	}

	private function assertValidListName( string $name ): void {
		if ( !preg_match( '/^[A-Za-z0-9_-]+$/', $name ) ) {
			throw new InvalidArgumentException( "Invalid database list name: $name" );
		}
	}
}
