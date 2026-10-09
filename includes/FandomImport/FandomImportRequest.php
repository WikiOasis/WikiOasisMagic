<?php

namespace WikiOasis\WikiOasisMagic\FandomImport;

use stdClass;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use function in_array;
use function is_array;
use function json_decode;

/**
 * One row of wo_fandom_import.
 */
class FandomImportRequest {

	public function __construct(
		public readonly int $id,
		public readonly FandomSource $source,
		public readonly string $dbname,
		public readonly string $sitename,
		public readonly string $language,
		public readonly string $category,
		public readonly string $mode,
		public readonly string $reason,
		public readonly string $fandomUser,
		public readonly int $requesterId,
		public readonly int $reviewerId,
		public readonly string $status,
		public readonly string $stage,
		public readonly array $progress,
		public readonly array $info,
		public readonly string $error,
		public readonly ?string $filesExpire,
		public readonly string $created,
		public readonly string $updated,
	) {
	}

	public static function newFromRow( stdClass $row ): ?self {
		$source = FandomSource::newFromKey( (string)$row->wofi_source );
		if ( !$source ) {
			return null;
		}

		$progress = json_decode( (string)$row->wofi_progress, true );
		$info = json_decode( (string)$row->wofi_info, true );

		return new self(
			id: (int)$row->wofi_id,
			source: $source,
			dbname: (string)$row->wofi_dbname,
			sitename: (string)$row->wofi_sitename,
			language: (string)$row->wofi_language,
			category: (string)$row->wofi_category,
			mode: (string)$row->wofi_mode,
			reason: (string)$row->wofi_reason,
			fandomUser: (string)$row->wofi_fandom_user,
			requesterId: (int)$row->wofi_requester,
			reviewerId: (int)$row->wofi_reviewer,
			status: (string)$row->wofi_status,
			stage: (string)$row->wofi_stage,
			progress: is_array( $progress ) ? $progress : [],
			info: is_array( $info ) ? $info : [],
			error: (string)$row->wofi_error,
			filesExpire: $row->wofi_files_expire !== null ?
				( ConvertibleTimestamp::convert( TS_MW, $row->wofi_files_expire ) ?: null ) :
				null,
			created: ConvertibleTimestamp::convert( TS_MW, $row->wofi_created ) ?: '',
			updated: ConvertibleTimestamp::convert( TS_MW, $row->wofi_updated ) ?: '',
		);
	}

	public function getWikiInfo(): ?FandomWikiInfo {
		return $this->info ? FandomWikiInfo::newFromArray( $this->info ) : null;
	}

	public function isActive(): bool {
		return in_array( $this->status, FandomImportStatus::ACTIVE, true );
	}

	public function isMove(): bool {
		return $this->mode === 'move';
	}

	public function getProgress( string $key, mixed $default = null ): mixed {
		return $this->progress[$key] ?? $default;
	}
}
