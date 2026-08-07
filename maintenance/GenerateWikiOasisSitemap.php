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
 * @author Paladox
 * @author Universal Omega
 * @version 2.0
 */

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use GenerateSitemap;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;

class GenerateWikiOasisSitemap extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Generates sitemap for all WikiOasis wikis (apart from private ones).' );

		$this->requireExtension( 'AWS' );
		$this->requireExtension( 'CreateWiki' );
	}

	public function execute() {
		global $wgAWSBucketDomain, $wgAWSBucketName;

		$dbname = $this->getConfig()->get( MainConfigNames::DBname );
		$remoteWikiFactory = $this->getServiceContainer()->get( 'RemoteWikiFactory' );
		$remoteWiki = $remoteWikiFactory->newInstance( $dbname );
		$isPrivate = $remoteWiki->isPrivate();

		$bucket = $wgAWSBucketName ?? '';
		$prefix = 'sitemaps/' . strtolower( $dbname ) . '/';
		// Layout used before sitemaps were moved under a shared sitemaps/ prefix.
		$legacyPrefix = strtolower( $dbname ) . '/sitemaps/';

		$s3 = $this->getS3Client();

		// Always clear the old location so it does not serve stale sitemaps.
		$this->deleteS3Prefix( $s3, $bucket, $legacyPrefix );

		if ( $isPrivate ) {
			$this->output( "Deleting sitemaps for private wiki {$dbname}\n" );
			$this->deleteS3Prefix( $s3, $bucket, $prefix );
			return;
		}

		$bucketDomain = $this->resolveBucketDomain( $wgAWSBucketDomain ?? '', $bucket );
		$urlBase = "https://{$bucketDomain}/{$prefix}";

		$this->output( "Generating sitemap for wiki {$dbname}\n" );

		$tempDir = wfTempDir() . "/sitemaps-{$dbname}";
		if ( is_dir( $tempDir ) ) {
			$this->cleanDir( $tempDir );
		} else {
			mkdir( $tempDir, 0755, true );
		}

		$generateSitemap = $this->createChild( GenerateSitemap::class );
		$generateSitemap->setOption( 'fspath', $tempDir );
		// Pin the identifier so the generated filenames are predictable; core would
		// otherwise use the wiki's DB domain, which can carry a table prefix.
		$generateSitemap->setOption( 'identifier', $dbname );
		$generateSitemap->setOption( 'compress', 'no' );
		$generateSitemap->execute();

		// Core builds the index <loc> entries from $wgCanonicalServer (the wiki's own
		// domain, custom or not), but the files only ever exist in the bucket. Point
		// them at where they are actually uploaded.
		$this->rewriteIndexUrls(
			$tempDir . '/sitemap-index-' . rawurlencode( $dbname ) . '.xml',
			$urlBase
		);

		foreach ( glob( $tempDir . "/sitemap-*{$dbname}*" ) ?: [] as $file ) {
			if ( !is_file( $file ) ) {
				continue;
			}
			$key = $prefix . basename( $file );
			try {
				$s3->putObject( [
					'Bucket' => $bucket,
					'Key' => $key,
					'Body' => fopen( $file, 'r' ),
					'ContentType' => 'application/xml',
				] );
				$this->output( "Uploaded {$key} to bucket '{$bucket}'.\n" );
			} catch ( AwsException $e ) {
				$this->output( "Failed to upload {$key}: {$e->getMessage()}\n" );
			}
			unlink( $file );
		}

		$indexName = 'sitemap-index-' . rawurlencode( $dbname ) . '.xml';
		$this->output( "Sitemap index: {$urlBase}{$indexName}\n" );
	}

	/**
	 * Rewrite every <loc> in the sitemap index to the bucket URL the sitemap
	 * files are uploaded to, preserving each entry's filename.
	 */
	private function rewriteIndexUrls( string $indexFile, string $urlBase ): void {
		if ( !is_file( $indexFile ) ) {
			$this->output( "Sitemap index {$indexFile} not found; skipping URL rewrite.\n" );
			return;
		}

		$xml = file_get_contents( $indexFile );
		if ( $xml === false ) {
			$this->output( "Failed to read sitemap index {$indexFile}.\n" );
			return;
		}

		$rewritten = preg_replace_callback(
			'#<loc>(.*?)</loc>#s',
			static function ( array $matches ) use ( $urlBase ): string {
				$filename = basename( htmlspecialchars_decode( $matches[1], ENT_QUOTES ) );
				return '<loc>' . htmlspecialchars( $urlBase . $filename, ENT_QUOTES ) . '</loc>';
			},
			$xml
		);

		if ( $rewritten === null || file_put_contents( $indexFile, $rewritten ) === false ) {
			$this->output( "Failed to rewrite sitemap index {$indexFile}.\n" );
		}
	}

	private function resolveBucketDomain( string $awsBucketDomain, string $bucket ): string {
		if ( $awsBucketDomain !== '' ) {
			$domain = str_replace( '$1', $bucket, $awsBucketDomain );
			$domain = preg_replace( '#^https?://#', '', $domain );
			return rtrim( $domain, '/' );
		}

		// Fallback: derive from the S3 endpoint using path-style addressing
		global $wgFileBackends;
		$endpoint = (string)( $wgFileBackends['s3']['endpoint'] ?? '' );
		if ( $endpoint !== '' ) {
			$parts = parse_url( $endpoint );
			if ( isset( $parts['host'] ) ) {
				$port = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
				return $parts['host'] . $port . '/' . $bucket;
			}
		}

		return $bucket;
	}

	private function deleteS3Prefix( S3Client $s3, string $bucket, string $prefix ): void {
		try {
			$result = $s3->listObjectsV2( [
				'Bucket' => $bucket,
				'Prefix' => $prefix,
			] );
			foreach ( (array)( $result->get( 'Contents' ) ?? [] ) as $object ) {
				$key = (string)( $object['Key'] ?? '' );
				if ( $key === '' ) {
					continue;
				}
				$s3->deleteObject( [ 'Bucket' => $bucket, 'Key' => $key ] );
				$this->output( "Deleted {$key} from bucket '{$bucket}'.\n" );
			}
		} catch ( AwsException $e ) {
			$this->output( "Failed to clean sitemaps from bucket '{$bucket}': {$e->getMessage()}\n" );
		}
	}

	private function cleanDir( string $dir ): void {
		foreach ( glob( $dir . '/*' ) ?: [] as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
	}

	private function getS3Client(): S3Client {
		global $wgAWSCredentials, $wgAWSRegion, $wgFileBackends;

		$s3Config = $wgFileBackends['s3'] ?? [];
		$clientConfig = [
			'version' => $s3Config['version'] ?? 'latest',
			'region' => $wgAWSRegion ?: 'auto',
		];

		if ( !empty( $wgAWSCredentials['key'] ) && !empty( $wgAWSCredentials['secret'] ) ) {
			$clientConfig['credentials'] = $wgAWSCredentials;
		}

		if ( isset( $s3Config['endpoint'] ) ) {
			$clientConfig['endpoint'] = $s3Config['endpoint'];
		}

		if ( isset( $s3Config['use_path_style_endpoint'] ) ) {
			$clientConfig['use_path_style_endpoint'] = (bool)$s3Config['use_path_style_endpoint'];
		}

		if ( isset( $s3Config['http'] ) && is_array( $s3Config['http'] ) ) {
			$clientConfig['http'] = $s3Config['http'];
		}

		return new S3Client( $clientConfig );
	}
}

// @codeCoverageIgnoreStart
return GenerateWikiOasisSitemap::class;
// @codeCoverageIgnoreEnd
