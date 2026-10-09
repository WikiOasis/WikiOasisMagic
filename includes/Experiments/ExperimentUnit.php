<?php

namespace WikiOasis\WikiOasisMagic\Experiments;

use Closure;
use function hash_hmac;
use function substr;

final class ExperimentUnit {

	public const WIKI = 'wiki';
	public const USER = 'user';
	public const BROWSER = 'browser';

	public const TYPES = [ self::WIKI, self::USER, self::BROWSER ];

	private ?Closure $createdLookup;
	private ?string $created = null;

	/**
	 * @param string $type
	 * @param string $id
	 * @param string|Closure|null $created
	 * @param string $secret
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $id,
		string|Closure|null $created = null,
		private readonly string $secret = '',
	) {
		if ( $created instanceof Closure ) {
			$this->createdLookup = $created;
		} else {
			$this->createdLookup = null;
			$this->created = $created;
		}
	}

	public function exists(): bool {
		return $this->id !== '';
	}

	public function getCreated(): ?string {
		if ( $this->createdLookup ) {
			$this->created = ( $this->createdLookup )();
			$this->createdLookup = null;
		}

		return $this->created;
	}

	public function getStorageKey(): string {
		return $this->type === self::WIKI ?
			$this->id :
			substr( hash_hmac( 'sha256', $this->type . ':' . $this->id, $this->secret ), 0, 32 );
	}
}
