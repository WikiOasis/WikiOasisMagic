<?php

namespace WikiOasis\WikiOasisMagic\Jobs;

use Job;
use MediaWiki\JobQueue\GenericParameterJob;
use MediaWiki\MediaWikiServices;
use WikiOasis\WikiOasisMagic\CloudflarePurger;

/**
 * Transitional no-longer-pushed job that purges the Cloudflare cache.
 *
 * Purging now happens in a deferred update in
 * {@see \WikiOasis\WikiOasisMagic\HookHandlers\CloudflarePurge}, so nothing
 * enqueues this any more. It is kept, and kept registered in $wgJobClasses, only
 * so that jobs still sitting in the queue at deploy time drain rather than
 * failing to instantiate. Safe to delete once the queue is empty.
 */
class CloudflarePurgeJob extends Job implements GenericParameterJob {

	public function __construct( array $params ) {
		parent::__construct( 'cloudflarePagePurge', $params );
	}

	public function run(): bool {
		$services = MediaWikiServices::getInstance();
		$purger = new CloudflarePurger(
			$services->getMainConfig(),
			$services->getHttpRequestFactory()
		);

		if ( !$purger->purge( $this->params, 'CloudflarePurgeJob' ) ) {
			$this->setLastError( 'Cloudflare purge failed' );
			return false;
		}

		return true;
	}
}
