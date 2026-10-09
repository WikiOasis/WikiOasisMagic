<?php

namespace WikiOasis\WikiOasisMagic\FandomImport;

use function in_array;

/**
 * Where an import is, and what may happen to it next.
 *
 * pending ─approve→ approved ─create job→ creating ─hand-off job→ queued ─worker→ running ─→ done
 *    └─decline→ declined                     │                                 ├─→ waiting-dump ─→ running
 *                                            └──────────── failed ←────────────┘
 * A failed import goes back to approved (wiki not created yet) or creating
 * (wiki exists, hand it to the worker again) when a reviewer retries it.
 */
class FandomImportStatus {

	public const PENDING = 'pending';
	public const DECLINED = 'declined';
	public const APPROVED = 'approved';
	public const CREATING = 'creating';
	public const QUEUED = 'queued';
	public const RUNNING = 'running';
	public const WAITING_DUMP = 'waiting-dump';
	public const DONE = 'done';
	public const FAILED = 'failed';

	public const ALL = [
		self::PENDING,
		self::DECLINED,
		self::APPROVED,
		self::CREATING,
		self::QUEUED,
		self::RUNNING,
		self::WAITING_DUMP,
		self::DONE,
		self::FAILED,
	];

	/** Statuses that stop anyone filing another import of the same Fandom wiki or onto the same name. */
	public const ACTIVE = [
		self::PENDING,
		self::APPROVED,
		self::CREATING,
		self::QUEUED,
		self::RUNNING,
		self::WAITING_DUMP,
		self::FAILED,
	];

	/** Statuses in which the worker may report on an import. */
	public const WORKER = [
		self::QUEUED,
		self::RUNNING,
		self::WAITING_DUMP,
	];

	/** Statuses shown together as "in progress" on the queue. */
	public const IN_PROGRESS = [
		self::APPROVED,
		self::CREATING,
		self::QUEUED,
		self::RUNNING,
		self::WAITING_DUMP,
	];

	public const STAGE_CREATE = 'create';
	public const STAGE_HANDOFF = 'handoff';
	public const STAGE_RESOLVE = 'resolve';
	public const STAGE_PREPARE = 'prepare';
	public const STAGE_DUMP = 'dump';
	public const STAGE_PAGES = 'pages';
	public const STAGE_IMAGES = 'images';
	public const STAGE_UPLOAD = 'upload';
	public const STAGE_FINALIZE = 'finalize';

	/** Stages in order: "create" and "handoff" happen in MediaWiki, the rest in the worker. */
	public const STAGES = [
		self::STAGE_CREATE,
		self::STAGE_HANDOFF,
		self::STAGE_RESOLVE,
		self::STAGE_PREPARE,
		self::STAGE_DUMP,
		self::STAGE_PAGES,
		self::STAGE_IMAGES,
		self::STAGE_UPLOAD,
		self::STAGE_FINALIZE,
	];

	public static function isValid( string $status ): bool {
		return in_array( $status, self::ALL, true );
	}

	public static function isStage( string $stage ): bool {
		return in_array( $stage, self::STAGES, true );
	}
}
