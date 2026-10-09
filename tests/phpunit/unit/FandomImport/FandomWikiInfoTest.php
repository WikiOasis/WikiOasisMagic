<?php

namespace WikiOasis\WikiOasisMagic\Tests\FandomImport;

use MediaWikiUnitTestCase;
use WikiOasis\WikiOasisMagic\FandomImport\FandomClient;
use WikiOasis\WikiOasisMagic\FandomImport\FandomWikiInfo;

/**
 * @covers \WikiOasis\WikiOasisMagic\FandomImport\FandomWikiInfo
 * @covers \WikiOasis\WikiOasisMagic\FandomImport\FandomClient::getDumpUrl
 */
class FandomWikiInfoTest extends MediaWikiUnitTestCase {

	private const SITEINFO = [
		'batchcomplete' => true,
		'query' => [
			'general' => [
				'sitename' => 'Muppet Wiki',
				'wikiid' => 'muppet',
				'lang' => 'en',
			],
			'statistics' => [
				'pages' => 409081,
				'articles' => 53106,
				'edits' => 1875052,
				'images' => 267057,
			],
			'rightsinfo' => [
				'url' => 'https://www.fandom.com/licensing',
				'text' => 'CC-BY-SA',
			],
			'extensions' => [
				[ 'type' => 'parserhook', 'name' => 'PortableInfobox' ],
				[ 'type' => 'parserhook', 'name' => 'Tabber' ],
				[ 'type' => 'other' ],
			],
		],
	];

	public function testNewFromSiteInfo(): void {
		$info = FandomWikiInfo::newFromSiteInfo( self::SITEINFO );

		$this->assertSame( 'Muppet Wiki', $info->sitename );
		$this->assertSame( 'muppet', $info->wikiId );
		$this->assertSame( 'en', $info->language );
		$this->assertSame( 53106, $info->articles );
		$this->assertSame( 409081, $info->pages );
		$this->assertSame( 267057, $info->files );
		$this->assertSame( 'CC-BY-SA', $info->license );
		$this->assertSame( [ 'PortableInfobox', 'Tabber' ], $info->extensions );
	}

	public function testRoundTrip(): void {
		$info = FandomWikiInfo::newFromSiteInfo( self::SITEINFO );

		$this->assertEquals( $info, FandomWikiInfo::newFromArray( $info->toArray() ) );
	}

	public function testRejectsResponsesWithoutWikiId(): void {
		$this->assertNull( FandomWikiInfo::newFromSiteInfo( [ 'query' => [ 'general' => [ 'sitename' => 'x' ] ] ] ) );
		$this->assertNull( FandomWikiInfo::newFromSiteInfo( [ 'error' => [ 'code' => 'x' ] ] ) );
		$this->assertNull( FandomWikiInfo::newFromArray( [] ) );
	}

	public function testDumpUrl(): void {
		$this->assertSame(
			'https://s3.amazonaws.com/wikia_xml_dumps/m/mu/muppet_pages_full.xml.7z',
			FandomClient::getDumpUrl( 'muppet', FandomClient::VARIANT_FULL )
		);
		$this->assertSame(
			'https://s3.amazonaws.com/wikia_xml_dumps/d/de/deharrypotter_pages_current.xml.7z',
			FandomClient::getDumpUrl( 'deharrypotter', FandomClient::VARIANT_CURRENT )
		);
	}
}
