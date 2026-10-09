<?php

namespace WikiOasis\WikiOasisMagic\Experiments;

use MediaWiki\Request\WebRequest;
use MediaWiki\User\UserIdentity;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use function bin2hex;
use function is_string;
use function preg_match;
use function random_bytes;

class UnitFactory {

	public const BROWSER_COOKIE = 'WOExperimentUnit';

	private const BROWSER_COOKIE_TTL = 90 * 86400;

	private ?string $mintedBrowser = null;

	public function __construct(
		private readonly WikiFarm $farm,
		private readonly string $secret = '',
	) {
	}

	public function forUser( UserIdentity $user ): ExperimentUnit {
		if ( !$user->isRegistered() ) {
			return new ExperimentUnit( ExperimentUnit::USER, '' );
		}

		return new ExperimentUnit(
			ExperimentUnit::USER,
			$user->getName(),
			fn () => $this->farm->getRegistration( $user ),
			$this->secret
		);
	}

	/**
	 * @param string|null $dbname
	 * @param string|null|false $created
	 */
	public function forWiki( ?string $dbname = null, string|null|false $created = false ): ExperimentUnit {
		$dbname ??= $this->farm->getCurrentWiki();
		return new ExperimentUnit(
			ExperimentUnit::WIKI,
			$dbname,
			$created !== false ? $created : fn () => $this->farm->getWikiCreation( $dbname ),
			$this->secret
		);
	}

	public function forBrowser( WebRequest $request, bool $create ): ExperimentUnit {
		return new ExperimentUnit(
			ExperimentUnit::BROWSER,
			$this->getBrowserToken( $request, $create ) ?? '',
			null,
			$this->secret
		);
	}

	public function getBrowserToken( WebRequest $request, bool $create ): ?string {
		$token = $request->getCookie( self::BROWSER_COOKIE, '' );
		if ( is_string( $token ) && preg_match( '/^[0-9a-f]{32}$/', $token ) ) {
			return $token;
		}

		if ( $this->mintedBrowser !== null || !$create ) {
			return $this->mintedBrowser;
		}

		$token = bin2hex( random_bytes( 16 ) );
		$request->response()->setCookie(
			self::BROWSER_COOKIE,
			$token,
			(int)ConvertibleTimestamp::now( TS_UNIX ) + self::BROWSER_COOKIE_TTL,
			[ 'prefix' => '', 'httpOnly' => true ]
		);
		$this->mintedBrowser = $token;

		return $token;
	}
}
