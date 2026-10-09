<?php

namespace WikiOasis\WikiOasisMagic\Tests\Experiments;

use MediaWikiUnitTestCase;
use WikiOasis\WikiOasisMagic\Experiments\Metric;

/**
 * @covers \WikiOasis\WikiOasisMagic\Experiments\Metric
 */
class MetricTest extends MediaWikiUnitTestCase {

	private function newMetric( mixed $spec ): ?Metric {
		$problems = [];
		return Metric::newFromArray( 'm', $spec, $problems );
	}

	public function testEdit(): void {
		$any = $this->newMetric( 'edit' );
		$this->assertTrue( $any->matchesEdit( 4 ) );

		$articles = $this->newMetric( [ 'type' => 'edit', 'namespaces' => [ 0 ] ] );
		$this->assertTrue( $articles->matchesEdit( 0 ) );
		$this->assertFalse( $articles->matchesEdit( 1 ) );
		$this->assertFalse( $articles->matchesTags( [ 'x' ] ) );
	}

	public function testTag(): void {
		$metric = $this->newMetric( [ 'type' => 'tag', 'tags' => [ 'discussiontools-reply', 'visualeditor' ] ] );
		$this->assertTrue( $metric->matchesTags( [ 'mobile edit', 'visualeditor' ] ) );
		$this->assertFalse( $metric->matchesTags( [ 'mobile edit' ] ) );
		$this->assertNull( $this->newMetric( [ 'type' => 'tag' ] ) );
	}

	public function testLog(): void {
		$metric = $this->newMetric( [ 'type' => 'log', 'log' => [ 'farmer/requestwiki', 'upload' ] ] );
		$this->assertTrue( $metric->matchesLog( 'farmer', 'requestwiki' ) );
		$this->assertFalse( $metric->matchesLog( 'farmer', 'requestapprove' ) );
		$this->assertTrue( $metric->matchesLog( 'upload', 'overwrite' ) );
	}

	public function testSpecialPageAndApi(): void {
		$page = $this->newMetric( [ 'type' => 'specialpage', 'page' => 'TopicSubscriptions' ] );
		$this->assertTrue( $page->matchesSpecialPage( 'topicsubscriptions' ) );
		$this->assertFalse( $page->matchesSpecialPage( 'Watchlist' ) );

		$api = $this->newMetric( [ 'type' => 'api', 'module' => 'visualeditoredit' ] );
		$this->assertTrue( $api->matchesApiModule( 'visualeditoredit' ) );
		$this->assertFalse( $api->matchesApiModule( 'edit' ) );
	}

	public function testClientEvent(): void {
		$client = $this->newMetric( [ 'type' => 'event', 'client' => true ] );
		$this->assertTrue( $client->isClientEvent() );
		$this->assertSame( [ 'type' => 'event', 'client' => true ], $client->toArray() );

		$server = $this->newMetric( [ 'type' => 'event' ] );
		$this->assertFalse( $server->isClientEvent() );
		$this->assertSame( [ 'type' => 'event' ], $server->toArray() );

		$this->assertFalse( $this->newMetric( [ 'type' => 'edit', 'client' => true ] )->isClientEvent() );
		$this->assertFalse( $this->newMetric( [ 'type' => 'event', 'client' => 'yes' ] )->isClientEvent() );
	}

	public function testInvalid(): void {
		$problems = [];
		$this->assertNull( Metric::newFromArray( 'Bad Name', 'edit', $problems ) );
		$this->assertNull( Metric::newFromArray( 'm', [ 'type' => 'nonsense' ], $problems ) );
		$this->assertCount( 2, $problems );
	}
}
