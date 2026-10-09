<?php

namespace WikiOasis\WikiOasisMagic\Experiments;

use function is_bool;

class Assignment {

	public const CONTROL = 'control';

	/**
	 * @param string $experiment
	 * @param string $variant
	 * @param bool $enrolled
	 * @param bool $forced
	 * @param array $params
	 * @param ExperimentUnit|null $unit
	 */
	public function __construct(
		public readonly string $experiment,
		public readonly string $variant,
		public readonly bool $enrolled,
		public readonly bool $forced = false,
		public readonly array $params = [],
		public readonly ?ExperimentUnit $unit = null,
	) {
	}

	public function isLegacy(): bool {
		$legacy = $this->params['legacy'] ?? null;
		return is_bool( $legacy ) ? $legacy : $this->variant === self::CONTROL;
	}

	public function getParam( string $name, mixed $default = null ): mixed {
		return $this->params[$name] ?? $default;
	}

	public function isCounted(): bool {
		return !$this->forced && $this->unit !== null && $this->unit->exists();
	}

	public function getFunnelLabel(): string {
		return $this->enrolled ? $this->variant : 'none';
	}
}
