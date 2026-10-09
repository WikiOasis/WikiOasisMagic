<?php

namespace WikiOasis\WikiOasisMagic\Onboarding;

use MediaWiki\Config\Config;
use MediaWiki\Html\Html;
use MediaWiki\ResourceLoader\CodexModule;
use Throwable;
use function is_array;
use function is_string;

class OnboardingIcons {

	/** @var array<string,mixed>|null */
	private ?array $icons = null;

	public function __construct(
		private readonly Config $config,
	) {
	}

	/**
	 * @param string|null $name
	 * @param string $dir
	 * @param string $class
	 */
	public function render( ?string $name, string $dir = 'ltr', string $class = '' ): string {
		$paths = $name ? $this->getPaths( $name, $dir ) : null;
		$attribs = [ 'class' => 'cdx-icon' . ( $class !== '' ? " $class" : '' ), 'aria-hidden' => 'true' ];

		if ( $paths === null ) {
			return Html::element( 'span', $attribs );
		}

		return Html::rawElement( 'span', $attribs,
			'<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" aria-hidden="true">' .
			$paths . '</svg>'
		);
	}

	private function getPaths( string $name, string $dir ): ?string {
		if ( $this->icons === null ) {
			try {
				$this->icons = CodexModule::getIcons( null, $this->config );
			} catch ( Throwable ) {
				$this->icons = [];
			}
		}

		$icon = $this->icons[$name] ?? null;
		if ( is_string( $icon ) ) {
			return $icon;
		}

		if ( !is_array( $icon ) ) {
			return null;
		}

		if ( isset( $icon['rtl'] ) && $dir === 'rtl' && is_string( $icon['rtl'] ) ) {
			return $icon['rtl'];
		}

		if ( isset( $icon['ltr'] ) && is_string( $icon['ltr'] ) ) {
			$paths = $icon['ltr'];
			return $dir === 'rtl' && !empty( $icon['shouldFlip'] ) ?
				'<g transform="translate(20 0) scale(-1 1)">' . $paths . '</g>' :
				$paths;
		}

		return is_string( $icon['default'] ?? null ) ? $icon['default'] : null;
	}
}
