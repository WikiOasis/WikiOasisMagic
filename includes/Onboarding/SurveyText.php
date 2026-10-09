<?php

namespace WikiOasis\WikiOasisMagic\Onboarding;

use MessageLocalizer;
use function array_key_first;
use function array_map;
use function is_array;
use function is_int;
use function is_string;
use function mb_strlen;
use function preg_match;
use function str_replace;
use function strtolower;
use function trim;

class SurveyText {

	private const MAX_LENGTH = 500;
	private const LANGUAGE_CODE = '/^[a-z]{2,3}(-[a-z0-9]{1,8})*$/';
	private const MESSAGE_KEY = '/^[a-zA-Z0-9_.-]{1,200}$/';

	/**
	 * @param MessageLocalizer $localizer
	 * @param string[] $languageChain
	 * @param string $sitename
	 */
	public function __construct(
		private readonly MessageLocalizer $localizer,
		private readonly array $languageChain,
		private readonly string $sitename,
	) {
	}

	public function get( mixed $text ): string {
		if ( $text === null ) {
			return '';
		}

		if ( is_string( $text ) ) {
			return str_replace( '{sitename}', $this->sitename, $text );
		}

		if ( isset( $text['msg'] ) ) {
			$params = array_map( 'strval', (array)( $text['params'] ?? [] ) );
			return $this->localizer->msg( $text['msg'], ...$params )->text();
		}

		foreach ( [ ...$this->languageChain, 'en' ] as $code ) {
			if ( isset( $text[$code] ) ) {
				return str_replace( '{sitename}', $this->sitename, $text[$code] );
			}
		}

		$first = array_key_first( $text );
		return $first === null ? '' : str_replace( '{sitename}', $this->sitename, $text[$first] );
	}

	public static function validate( mixed $text ): ?string {
		if ( is_string( $text ) ) {
			$length = mb_strlen( trim( $text ) );
			if ( $length === 0 ) {
				return 'must not be empty';
			}
			return $length > self::MAX_LENGTH ? 'must be at most ' . self::MAX_LENGTH . ' characters' : null;
		}

		if ( !is_array( $text ) || !$text ) {
			return 'must be a string, {"msg": "key"} or an object of language codes';
		}

		if ( isset( $text['msg'] ) ) {
			if ( !is_string( $text['msg'] ) || !preg_match( self::MESSAGE_KEY, $text['msg'] ) ) {
				return '"msg" must be a message key';
			}
			foreach ( (array)( $text['params'] ?? [] ) as $param ) {
				if ( !is_string( $param ) && !is_int( $param ) ) {
					return '"params" must be a list of strings';
				}
			}
			return null;
		}

		foreach ( $text as $code => $value ) {
			if ( !is_string( $code ) || !preg_match( self::LANGUAGE_CODE, strtolower( $code ) ) ) {
				return "\"$code\" is not a language code";
			}
			$problem = is_string( $value ) ? self::validate( $value ) : 'must be a string';
			if ( $problem !== null ) {
				return "\"$code\" $problem";
			}
		}

		return null;
	}
}
