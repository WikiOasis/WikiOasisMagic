<?php

namespace WikiOasis\WikiOasisMagic\Tests\FandomImport;

use MediaWikiUnitTestCase;
use WikiOasis\WikiOasisMagic\FandomImport\FandomSource;

/**
 * @covers \WikiOasis\WikiOasisMagic\FandomImport\FandomSource
 */
class FandomSourceTest extends MediaWikiUnitTestCase {

	public static function provideSubpages(): array {
		return [
			'plain' => [ 'metawiki', 'meta', null ],
			'uppercase' => [ 'MetaWiki', 'meta', null ],
			'hyphen' => [ 'genshin-impactwiki', 'genshin-impact', null ],
			'language' => [ 'harrypotterwiki/de', 'harrypotter', 'de' ],
			'language with region' => [ 'starwarswiki/pt-br', 'starwars', 'pt-br' ],
			'subdomain ending in wiki' => [ 'minecraftwikiwiki', 'minecraftwiki', null ],
		];
	}

	/**
	 * @dataProvider provideSubpages
	 */
	public function testNewFromSubpage( string $subPage, string $subdomain, ?string $lang ): void {
		$source = FandomSource::newFromSubpage( $subPage );

		$this->assertNotNull( $source );
		$this->assertSame( $subdomain, $source->subdomain );
		$this->assertSame( $lang, $source->lang );
	}

	public static function provideBadSubpages(): array {
		return [
			'no suffix' => [ 'meta' ],
			'only the suffix' => [ 'wiki' ],
			'too many parts' => [ 'metawiki/de/x' ],
			'bad characters' => [ 'me.tawiki' ],
			'leading hyphen' => [ '-metawiki' ],
			'trailing hyphen' => [ 'meta-wiki' ],
			'bad language' => [ 'metawiki/../../etc' ],
			'reserved' => [ 'wwwwiki' ],
		];
	}

	/**
	 * @dataProvider provideBadSubpages
	 */
	public function testNewFromSubpageRejects( string $subPage ): void {
		$this->assertNull( FandomSource::newFromSubpage( $subPage ) );
	}

	public static function provideInputs(): array {
		return [
			'full url with page' => [ 'https://muppet.fandom.com/wiki/Kermit_the_Frog', 'muppetwiki' ],
			'bare host' => [ 'muppet.fandom.com', 'muppetwiki' ],
			'language wiki' => [ 'https://harrypotter.fandom.com/de/wiki/Hauptseite', 'harrypotterwiki/de' ],
			'language root' => [ 'harrypotter.fandom.com/de', 'harrypotterwiki/de' ],
			'old wikia host' => [ 'http://muppet.wikia.com/wiki/Main_Page', 'muppetwiki' ],
			'subpage form' => [ 'muppetwiki', 'muppetwiki' ],
			'bare subdomain' => [ 'muppet', 'muppetwiki' ],
			'api url' => [ 'https://muppet.fandom.com/api.php', 'muppetwiki' ],
		];
	}

	/**
	 * @dataProvider provideInputs
	 */
	public function testNewFromInput( string $input, string $subPage ): void {
		$this->assertSame( $subPage, FandomSource::newFromInput( $input )?->getSubpage() );
	}

	public static function provideBadInputs(): array {
		return [
			'empty' => [ '' ],
			'other host' => [ 'https://example.org/wiki/Main_Page' ],
			'lookalike host' => [ 'https://muppet.fandom.com.example.org' ],
			'nested subdomain' => [ 'https://a.b.fandom.com' ],
			'fandom itself' => [ 'https://www.fandom.com' ],
		];
	}

	/**
	 * @dataProvider provideBadInputs
	 */
	public function testNewFromInputRejects( string $input ): void {
		$this->assertNull( FandomSource::newFromInput( $input ) );
	}

	public function testUrlsAndNames(): void {
		$source = FandomSource::newFromSubpage( 'harrypotterwiki/de' );

		$this->assertSame( 'harrypotter/de', $source->getKey() );
		$this->assertSame( 'harrypotter.fandom.com/de', $source->getHost() );
		$this->assertSame( 'https://harrypotter.fandom.com/de', $source->getBaseUrl() );
		$this->assertSame( 'https://harrypotter.fandom.com/de/api.php', $source->getApiUrl() );
		$this->assertSame(
			'https://harrypotter.fandom.com/de/wiki/Special:Statistics',
			$source->getPageUrl( 'Special:Statistics' )
		);
		$this->assertSame( 'deharrypotter', $source->getSuggestedSubdomain() );
		$this->assertSame( 'harrypotterwiki/de', FandomSource::newFromKey( $source->getKey() )->getSubpage() );
	}

	public function testSuggestedSubdomainDropsHyphens(): void {
		$this->assertSame(
			'genshinimpact',
			FandomSource::newFromSubpage( 'genshin-impactwiki' )->getSuggestedSubdomain()
		);
	}
}
