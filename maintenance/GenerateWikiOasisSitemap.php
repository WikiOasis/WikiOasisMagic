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

use GenerateSitemap;
use MediaWiki\Context\RequestContext;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;

class GenerateWikiOasisSitemap extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Generates sitemap for all WikiOasis wikis (apart from private ones).' );
	}

	public function execute() {
        $dbname = $this->getConfig()->get( MainConfigNames::DBname );
        $remoteWikiFactory = $this->getServiceContainer()->get( 'RemoteWikiFactory' );
        $remoteWiki = $remoteWikiFactory->newInstance( $dbname );
        $isPrivate = $remoteWiki->isPrivate();
    
        $sitemapDir = "/var/www/mediawiki/sitemaps/{$dbname}";
        $tempDir = wfTempDir() . '/sitemaps';
    
        if ( $isPrivate ) {
            $this->output( "Deleting sitemap for wiki {$dbname}\n" );
            if ( is_dir( $sitemapDir ) ) {
                $this->deleteDirectory( $sitemapDir );
                $this->output( "Sitemap directory {$sitemapDir} has been deleted.\n" );
            } else {
                $this->output( "Sitemap directory {$sitemapDir} does not exist.\n" );
            }
        } else {
            $this->output( "Generating sitemap for wiki {$dbname}\n" );
    
            // 既存の sitemap ディレクトリがあれば削除
            if ( is_dir( $sitemapDir ) ) {
                $this->deleteDirectory( $sitemapDir );
            }
            // 新しい sitemap 用ディレクトリを作成
            if ( !mkdir( $sitemapDir, 0755, true ) && !is_dir( $sitemapDir ) ) {
                $this->output( "Failed to create sitemap directory {$sitemapDir}\n" );
                return;
            }
    
            // 一時ディレクトリをクリーンアップ
            if ( is_dir( $tempDir ) ) {
                $files = glob( $tempDir . '/*' );
                if ( $files !== false ) {
                    foreach ( $files as $file ) {
                        if ( is_file( $file ) ) {
                            unlink( $file );
                        }
                    }
                }
            } else {
                mkdir( $tempDir, 0755, true );
            }
    
            $generateSitemap = $this->createChild( GenerateSitemap::class );
            $generateSitemap->setOption( 'fspath', $tempDir );
            $generateSitemap->setOption( 'urlpath', "/sitemaps/{$dbname}/" );
            $generateSitemap->setOption( 'server', $this->getConfig()->get( MainConfigNames::Server ) );
            $generateSitemap->setOption( 'compress', 'yes' );
            $generateSitemap->execute();
    
            foreach ( glob( $tempDir . "/sitemap-*{$dbname}*" ) as $sitemapFile ) {
                if ( basename( $sitemapFile ) === "sitemap-index-{$dbname}.xml" ) {
                    continue;
                }
                $destination = $sitemapDir . "/" . basename( $sitemapFile );
                if ( rename( $sitemapFile, $destination ) ) {
                    $this->output( "Moved file " . basename( $sitemapFile ) . " to sitemap directory.\n" );
                } else {
                    $this->output( "Failed to move file " . basename( $sitemapFile ) . ".\n" );
                }
            }
        }
    }

    private function deleteDirectory( string $dir ): void {
        if ( !file_exists( $dir ) ) {
            return;
        }
        if ( !is_dir( $dir ) || is_link( $dir ) ) {
            unlink( $dir );
            return;
        }
        foreach ( scandir( $dir ) as $item ) {
            if ( $item === '.' || $item === '..' ) {
                continue;
            }
            $this->deleteDirectory( $dir . DIRECTORY_SEPARATOR . $item );
        }
        rmdir( $dir );
    }
    
}

// @codeCoverageIgnoreStart
return GenerateWikiOasisSitemap::class;
// @codeCoverageIgnoreEnd