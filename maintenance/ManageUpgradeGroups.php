<?php

namespace WikiOasis\WikiOasisMagic\Maintenance;

/**
 * Manages the upgrade group database lists (group1, group2, ...) in cw_cache that
 * UpgradeWiki --group deploys to.
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
 * @author Zippybonzo
 * @version 1.0
 */

use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use WikiOasis\WikiOasisMagic\UpgradeGroups;

class ManageUpgradeGroups extends Maintenance {

	private UpgradeGroups $groups;

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Manage the upgrade group database lists (group1, group2, ...) in cw_cache. ' .
			'Groups never share a wiki, and every run checks all groups for duplicates.'
		);

		$this->addOption( 'list', 'List every group, or just --group, and the wikis in it.' );
		$this->addOption( 'add', 'Comma separated database names to add to --group.', false, true );
		$this->addOption(
			'remove',
			'Comma separated database names to remove from --group, or from every group ' .
				'if --group is not given.',
			false,
			true
		);

		$this->addOption(
			'percent',
			'Create or top up a group so that the groups together cover this percentage of ' .
				'all wikis. Wikis already in a group are never listed again.',
			false,
			true
		);

		$this->addOption( 'check', 'Report wikis that appear in more than one group, and wikis in no group.' );
		$this->addOption( 'fix', 'With --check, drop duplicates, keeping the first group a wiki appears in.' );
		$this->addOption( 'delete-group', 'Delete the group given by --group.' );

		$this->addOption(
			'group',
			'Group to act on, e.g. group1. Defaults to a new group for --percent.',
			false,
			true
		);

		$this->addOption(
			'file',
			'Path to a file of database names, one per line, added to --add or --remove.',
			false,
			true
		);

		$this->addOption(
			'move',
			'With --add, move wikis that are already in another group instead of refusing them.'
		);

		$this->addOption( 'random', 'With --percent, pick wikis at random rather than in name order.' );
		$this->addOption( 'seed', 'Seed for --random, so a selection can be reproduced.', false, true );
		$this->addOption(
			'skip-validation',
			'Allow database names that are not in $wgLocalDatabases.'
		);

		$this->addOption(
			'cache-directory',
			'Override the directory group lists are read from and written to (defaults to cw_cache).',
			false,
			true
		);

		$this->addOption(
			'group-prefix',
			'Prefix used by group lists. Defaults to "' . UpgradeGroups::DEFAULT_PREFIX . '".',
			false,
			true
		);

		$this->addOption( 'dry-run', 'Report what would change without writing anything.' );

		$this->requireExtension( 'WikiOasisMagic' );
	}

	public function execute(): void {
		$this->groups = new UpgradeGroups(
			$this->getOption( 'cache-directory' ) ?: null,
			$this->getOption( 'group-prefix', UpgradeGroups::DEFAULT_PREFIX )
		);

		// Duplicates make a staged rollout meaningless, so shout about them on every run.
		$this->reportDuplicates();

		if ( $this->hasOption( 'delete-group' ) ) {
			$this->doDeleteGroup();
			return;
		}

		// --file on its own, with a --group, means "add the wikis in this file".
		$fileOnlyAdd = $this->hasOption( 'file' ) &&
			!$this->hasOption( 'add' ) &&
			!$this->hasOption( 'remove' ) &&
			!$this->hasOption( 'percent' ) &&
			!$this->hasOption( 'check' ) &&
			!$this->hasOption( 'list' );

		if ( $this->hasOption( 'add' ) || $fileOnlyAdd ) {
			$this->doAdd();
			return;
		}

		if ( $this->hasOption( 'remove' ) ) {
			$this->doRemove();
			return;
		}

		if ( $this->hasOption( 'percent' ) ) {
			$this->doPercent();
			return;
		}

		if ( $this->hasOption( 'check' ) ) {
			$this->doCheck();
			return;
		}

		$this->doList();
	}

	private function doList(): void {
		$group = $this->getOption( 'group' );
		if ( $group !== null ) {
			$this->requireGroupExists( $group );
			$wikis = $this->groups->getWikis( $group );
			$this->output( "$group (" . count( $wikis ) . " wikis):\n" );
			foreach ( $wikis as $wiki ) {
				$this->output( "  $wiki\n" );
			}

			return;
		}

		$all = $this->groups->getAllGroups();
		if ( $all === [] ) {
			$this->output( "No groups exist in {$this->groups->getCacheDirectory()}.\n" );
			return;
		}

		$total = count( $this->getAllWikis() );
		$covered = 0;
		foreach ( $all as $name => $wikis ) {
			$covered += count( $wikis );
			$this->output( "$name (" . count( $wikis ) . " wikis):\n" );
			foreach ( $wikis as $wiki ) {
				$this->output( "  $wiki\n" );
			}
		}

		$this->output( "\n$covered of $total wikis (" . $this->formatPercent( $covered, $total ) .
			") are in a group.\n" );
	}

	private function doCheck(): void {
		$duplicates = $this->groups->getDuplicates();
		if ( $duplicates !== [] && $this->hasOption( 'fix' ) ) {
			$this->removeDuplicates( $duplicates );
		}

		$assigned = $this->groups->getAssignedWikis();
		$all = $this->getAllWikis();
		$ungrouped = array_values( array_diff( $all, $assigned ) );
		$unknown = array_values( array_diff( $assigned, $all ) );

		$this->output( count( $assigned ) . ' of ' . count( $all ) . ' wikis (' .
			$this->formatPercent( count( $assigned ), count( $all ) ) . ") are in a group.\n" );

		if ( $unknown !== [] ) {
			$this->output( "Wikis in a group but not in \$wgLocalDatabases:\n" );
			foreach ( $unknown as $wiki ) {
				$this->output( "  $wiki\n" );
			}
		}

		$this->output( count( $ungrouped ) . " wikis are in no group.\n" );
		if ( $duplicates === [] ) {
			$this->output( "No duplicates found.\n" );
		}
	}

	private function removeDuplicates( array $duplicates ): void {
		$toRemove = [];
		foreach ( $duplicates as $wiki => $groups ) {
			// Keep the first group the wiki landed in, it is the earliest rollout stage.
			foreach ( array_slice( $groups, 1 ) as $group ) {
				$toRemove[$group][] = (string)$wiki;
			}
		}

		foreach ( $toRemove as $group => $wikis ) {
			$remaining = array_values( array_diff( $this->groups->getWikis( $group ), $wikis ) );
			$this->output( 'Removing ' . implode( ', ', $wikis ) . " from $group as duplicates.\n" );
			$this->writeGroup( $group, $remaining );
		}
	}

	private function doDeleteGroup(): void {
		$group = $this->getOption( 'group' );
		if ( $group === null ) {
			$this->fatalError( '--delete-group requires --group.' );
		}

		$this->requireGroupExists( $group );
		$count = count( $this->groups->getWikis( $group ) );

		if ( $this->hasOption( 'dry-run' ) ) {
			$this->output( "Dry run: would delete $group ($count wikis).\n" );
			return;
		}

		if ( !$this->groups->deleteGroup( $group ) ) {
			$this->fatalError( "Failed to delete $group." );
		}

		$this->output( "Deleted $group ($count wikis).\n" );
	}

	private function doAdd(): void {
		$group = $this->getOption( 'group' );
		if ( $group === null ) {
			$this->fatalError( '--add requires --group.' );
		}

		if ( !$this->groups->isValidGroupName( $group ) ) {
			$this->fatalError( "Invalid group name: $group" );
		}

		$requested = $this->getWikiListOption( 'add' );
		if ( $requested === [] ) {
			$this->fatalError( 'No database names given to add.' );
		}

		$this->assertKnownWikis( $requested );

		$current = $this->groups->getWikis( $group );
		$assignments = $this->groups->getAssignments();

		$toAdd = [];
		$elsewhere = [];
		foreach ( $requested as $wiki ) {
			if ( in_array( $wiki, $current, true ) ) {
				$this->output( "$wiki is already in $group, skipping.\n" );
				continue;
			}

			$otherGroups = array_diff( $assignments[$wiki] ?? [], [ $group ] );
			if ( $otherGroups !== [] ) {
				$elsewhere[$wiki] = array_values( $otherGroups );
				if ( !$this->hasOption( 'move' ) ) {
					continue;
				}
			}

			$toAdd[] = $wiki;
		}

		foreach ( $elsewhere as $wiki => $otherGroups ) {
			$where = implode( ', ', $otherGroups );
			if ( $this->hasOption( 'move' ) ) {
				$this->output( "Moving $wiki out of $where into $group.\n" );
			} else {
				$this->error( "$wiki is already in $where, skipping. Pass --move to move it." );
			}
		}

		if ( $toAdd === [] ) {
			$this->output( "Nothing to add to $group.\n" );
			return;
		}

		if ( $this->hasOption( 'move' ) ) {
			foreach ( $elsewhere as $wiki => $otherGroups ) {
				foreach ( $otherGroups as $otherGroup ) {
					$remaining = array_values(
						array_diff( $this->groups->getWikis( $otherGroup ), [ (string)$wiki ] )
					);
					$this->writeGroup( $otherGroup, $remaining );
				}
			}
		}

		$this->output( 'Adding ' . count( $toAdd ) . " wikis to $group: " . implode( ', ', $toAdd ) . "\n" );
		$this->writeGroup( $group, array_merge( $current, $toAdd ) );
	}

	private function doRemove(): void {
		$requested = $this->getWikiListOption( 'remove' );
		if ( $requested === [] ) {
			$this->fatalError( 'No database names given to remove.' );
		}

		$group = $this->getOption( 'group' );
		$targetGroups = $group !== null ? [ $group ] : $this->groups->getGroupNames();
		if ( $group !== null ) {
			$this->requireGroupExists( $group );
		}

		$removedAny = false;
		foreach ( $targetGroups as $targetGroup ) {
			$current = $this->groups->getWikis( $targetGroup );
			$remaining = array_values( array_diff( $current, $requested ) );
			$removed = array_values( array_diff( $current, $remaining ) );
			if ( $removed === [] ) {
				continue;
			}

			$removedAny = true;
			$this->output( 'Removing ' . implode( ', ', $removed ) . " from $targetGroup.\n" );
			$this->writeGroup( $targetGroup, $remaining );
		}

		if ( !$removedAny ) {
			$this->output( "None of those wikis were in a group.\n" );
		}
	}

	private function doPercent(): void {
		$percent = (float)$this->getOption( 'percent' );
		if ( $percent <= 0 || $percent > 100 ) {
			$this->fatalError( '--percent must be greater than 0 and at most 100.' );
		}

		$all = $this->getAllWikis();
		$total = count( $all );
		if ( $total === 0 ) {
			$this->fatalError( 'No wikis found in $wgLocalDatabases.' );
		}

		$assigned = $this->groups->getAssignedWikis();
		$group = $this->getOption( 'group' ) ?? $this->groups->getNextGroupName();
		if ( !$this->groups->isValidGroupName( $group ) ) {
			$this->fatalError( "Invalid group name: $group" );
		}

		// Only wikis that still exist count towards coverage.
		$coveredNow = count( array_intersect( $all, $assigned ) );
		$target = (int)ceil( $total * $percent / 100 );
		$needed = $target - $coveredNow;

		if ( $needed <= 0 ) {
			$this->output( "Groups already cover $coveredNow of $total wikis (" .
				$this->formatPercent( $coveredNow, $total ) . "), at or above $percent%. Nothing to do.\n" );
			return;
		}

		$candidates = array_values( array_diff( $all, $assigned ) );
		if ( $candidates === [] ) {
			$this->output( "Every wiki is already in a group. Nothing to do.\n" );
			return;
		}

		if ( $this->hasOption( 'random' ) ) {
			$seed = $this->getOption( 'seed' );
			if ( $seed !== null ) {
				mt_srand( (int)$seed );
			}

			shuffle( $candidates );
		} else {
			sort( $candidates );
		}

		$selected = array_slice( $candidates, 0, $needed );
		$existing = $this->groups->getWikis( $group );
		$newContents = array_merge( $existing, array_values( array_diff( $selected, $existing ) ) );

		$this->output( "Target $percent% of $total wikis = $target wikis, $coveredNow already grouped.\n" );
		$this->output( 'Putting ' . count( $selected ) . " wikis into $group:\n" );
		foreach ( $selected as $wiki ) {
			$this->output( "  $wiki\n" );
		}

		$this->writeGroup( $group, $newContents );

		$coverage = $coveredNow + count( $selected );
		$this->output( "Groups now cover $coverage of $total wikis (" .
			$this->formatPercent( $coverage, $total ) . ").\n" );
	}

	/**
	 * Database names from an option plus --file, trimmed and deduplicated.
	 *
	 * @return string[]
	 */
	private function getWikiListOption( string $option ): array {
		$wikis = [];
		$value = $this->getOption( $option );
		if ( is_string( $value ) ) {
			$wikis = explode( ',', $value );
		}

		if ( $this->hasOption( 'file' ) ) {
			$fromFile = file( $this->getOption( 'file' ), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
			if ( $fromFile === false ) {
				$this->fatalError( 'Unable to read file, exiting.' );
			}

			$wikis = array_merge( $wikis, $fromFile );
		}

		$wikis = array_map( 'trim', $wikis );
		return array_values( array_unique( array_filter( $wikis, static fn ( $wiki ) => $wiki !== '' ) ) );
	}

	/**
	 * @param string[] $wikis
	 */
	private function assertKnownWikis( array $wikis ): void {
		if ( $this->hasOption( 'skip-validation' ) ) {
			return;
		}

		$unknown = array_values( array_diff( $wikis, $this->getAllWikis() ) );
		if ( $unknown !== [] ) {
			$this->fatalError(
				'Not in $wgLocalDatabases: ' . implode( ', ', $unknown ) .
				'. Pass --skip-validation to add them anyway.'
			);
		}
	}

	/**
	 * @return string[]
	 */
	private function getAllWikis(): array {
		return $this->getConfig()->get( MainConfigNames::LocalDatabases );
	}

	private function requireGroupExists( string $group ): void {
		if ( !$this->groups->isValidGroupName( $group ) ) {
			$this->fatalError( "Invalid group name: $group" );
		}

		if ( !$this->groups->groupExists( $group ) ) {
			$this->fatalError( "No such group: $group" );
		}
	}

	private function reportDuplicates(): void {
		$duplicates = $this->groups->getDuplicates();
		foreach ( $duplicates as $wiki => $groups ) {
			$this->error( "Duplicate: $wiki is in " . implode( ', ', $groups ) . '.' );
		}

		if ( $duplicates !== [] && !$this->hasOption( 'check' ) ) {
			$this->error( 'Run this script with --check --fix to drop those duplicates.' );
		}
	}

	/**
	 * @param string[] $wikis
	 */
	private function writeGroup( string $group, array $wikis ): void {
		$wikis = array_values( array_unique( $wikis ) );
		if ( $this->hasOption( 'dry-run' ) ) {
			$this->output( 'Dry run: would write ' . count( $wikis ) . " wikis to $group.\n" );
			return;
		}

		$this->groups->setWikis( $group, $wikis );
		$this->output( 'Wrote ' . count( $wikis ) . " wikis to " . $this->groups->getFilePath( $group ) . "\n" );
	}
}

// @codeCoverageIgnoreStart
return ManageUpgradeGroups::class;
// @codeCoverageIgnoreEnd
