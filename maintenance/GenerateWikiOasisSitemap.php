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
        global $wgAWSBucketDomain, $wgAWSCredentials, $wgAWSRegion, $wgFileBackends;

        $dbname = $this->getConfig()->get( MainConfigNames::DBname );
        $remoteWikiFactory = $this->getServiceContainer()->get( 'RemoteWikiFactory' );
        $remoteWiki = $remoteWikiFactory->newInstance( $dbname );
        $isPrivate = $remoteWiki->isPrivate();

        $bucket = strtolower( $dbname );
        $prefix = 'sitemaps/';

        $s3 = $this->getS3Client();

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

        $wikiServer = $this->getConfig()->get( MainConfigNames::Server );

        $generateSitemap = $this->createChild( GenerateSitemap::class );
        $generateSitemap->setOption( 'fspath', $tempDir );
        $generateSitemap->setOption( 'urlpath', "/{$prefix}" );
        $generateSitemap->setOption( 'server', $wikiServer );
        $generateSitemap->setOption( 'compress', 'no' );
        $generateSitemap->execute();

        $indexFile = $tempDir . "/sitemap-index-{$dbname}.xml";

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

        $this->output( "Sitemap index: {$urlBase}sitemap-index-{$dbname}.xml\n" );
    }

    private function resolveBucketDomain( string $wgAWSBucketDomain, string $bucket ): string {
        if ( $wgAWSBucketDomain !== '' ) {
            $domain = str_replace( '$1', $bucket, $wgAWSBucketDomain );
            // Strip any scheme so the caller can prepend https:// exactly once
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
            'region' => $wgAWSRegion ?: 'garage',
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
