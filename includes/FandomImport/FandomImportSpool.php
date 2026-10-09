<?php

namespace WikiOasis\WikiOasisMagic\FandomImport;

use MediaWiki\Config\ServiceOptions;
use RuntimeException;
use function bin2hex;
use function chmod;
use function file_put_contents;
use function is_dir;
use function is_writable;
use function json_encode;
use function random_bytes;
use function rename;
use function rtrim;
use function unlink;

/**
 * Hands work to the Fandom import worker, a cron job on the job runner host
 * that runs outside MediaWiki (salt: fandom_import). The two only meet in
 * the spool directory: MediaWiki drops files into queue/, the worker takes
 * them and reports back through maintenance/FandomImportCallback.php.
 *
 * Only a job can write here, because only the job runner host has the spool.
 */
class FandomImportSpool {

	public const CONSTRUCTOR_OPTIONS = [
		ConfigNames::SPOOL_DIR,
	];

	public const MANIFEST_VERSION = 1;

	public function __construct(
		private readonly ServiceOptions $options,
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	/**
	 * Ask the worker to run, or resume, an import.
	 */
	public function writeManifest( array $manifest ): void {
		$this->write( "{$manifest['id']}.json", $manifest );
	}

	/**
	 * Ask the worker to delete everything it kept for an import.
	 */
	public function writeDiscard( int $id, string $centralWiki ): void {
		$this->write( "$id.discard", [
			'version' => self::MANIFEST_VERSION,
			'id' => $id,
			'central_wiki' => $centralWiki,
		] );
	}

	/**
	 * Write a file the worker will see whole or not at all: it skips dot files,
	 * and rename() is atomic within the directory.
	 */
	private function write( string $name, array $data ): void {
		$queue = $this->getQueueDir();
		if ( !is_dir( $queue ) || !is_writable( $queue ) ) {
			throw new RuntimeException( "The Fandom import spool $queue is missing or not writable." );
		}

		$json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$temp = "$queue/.tmp-$name-" . bin2hex( random_bytes( 6 ) );

		if ( file_put_contents( $temp, $json . "\n" ) === false ) {
			throw new RuntimeException( "Could not write $temp." );
		}

		chmod( $temp, 0640 );
		if ( !rename( $temp, "$queue/$name" ) ) {
			unlink( $temp );
			throw new RuntimeException( "Could not move $temp to $queue/$name." );
		}
	}

	private function getQueueDir(): string {
		return rtrim( (string)$this->options->get( ConfigNames::SPOOL_DIR ), '/' ) . '/queue';
	}
}
