<?php

namespace WikiOasis\WikiOasisMagic\Experiments;

use WikiOasis\WikiOasisMagic\Onboarding\SurveyText;
use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function str_contains;
use function strtolower;

final class Metric {

	public const EDIT = 'edit';
	public const TAG = 'tag';
	public const LOG = 'log';
	public const SPECIAL_PAGE = 'specialpage';
	public const API = 'api';
	public const EVENT = 'event';

	public const TYPES = [ self::EDIT, self::TAG, self::LOG, self::SPECIAL_PAGE, self::API, self::EVENT ];

	/**
	 * @param string $name
	 * @param string $type
	 * @param array $filter
	 * @param mixed $label
	 * @param mixed $description
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $type,
		public readonly array $filter = [],
		public readonly mixed $label = null,
		public readonly mixed $description = null,
	) {
	}

	/**
	 * @param string $name
	 * @param mixed $spec
	 * @param string[] &$problems
	 */
	public static function newFromArray( string $name, mixed $spec, array &$problems ): ?self {
		if ( !preg_match( Experiment::NAME_PATTERN, $name ) ) {
			$problems[] = "metric name \"$name\" is invalid and was ignored";
			return null;
		}

		if ( is_string( $spec ) ) {
			$spec = [ 'type' => $spec ];
		}

		$type = is_array( $spec ) ? ( $spec['type'] ?? null ) : null;
		if ( !in_array( $type, self::TYPES, true ) ) {
			$problems[] = "metric \"$name\" needs a type, one of " . implode( ', ', self::TYPES );
			return null;
		}

		$filter = [];
		switch ( $type ) {
			case self::EDIT:
				$filter['namespaces'] = array_values( array_filter( (array)( $spec['namespaces'] ?? [] ), 'is_int' ) );
				break;

			case self::TAG:
				$filter['tags'] = self::stringList( $spec['tags'] ?? $spec['tag'] ?? [] );
				if ( !$filter['tags'] ) {
					$problems[] = "metric \"$name\" needs \"tags\"";
					return null;
				}
				break;

			case self::LOG:
				$filter['log'] = [];
				foreach ( self::stringList( $spec['log'] ?? [] ) as $log ) {
					[ $logType, $action ] = str_contains( $log, '/' ) ? explode( '/', $log, 2 ) : [ $log, '*' ];
					$filter['log'][] = [ $logType, $action ];
				}
				if ( !$filter['log'] ) {
					$problems[] = "metric \"$name\" needs \"log\", as \"type\" or \"type/action\"";
					return null;
				}
				break;

			case self::SPECIAL_PAGE:
				$filter['pages'] = array_map( 'strtolower', self::stringList( $spec['page'] ?? $spec['pages'] ?? [] ) );
				if ( !$filter['pages'] ) {
					$problems[] = "metric \"$name\" needs \"page\", a special page name";
					return null;
				}
				break;

			case self::API:
				$filter['modules'] = self::stringList( $spec['module'] ?? $spec['modules'] ?? [] );
				if ( !$filter['modules'] ) {
					$problems[] = "metric \"$name\" needs \"module\", an API module path";
					return null;
				}
				break;

			case self::EVENT:
				if ( ( $spec['client'] ?? false ) === true ) {
					$filter['client'] = true;
				}
				break;
		}

		$text = [];
		foreach ( [ 'label', 'description' ] as $field ) {
			$value = $spec[$field] ?? null;
			if ( $value === null ) {
				$text[$field] = null;
				continue;
			}
			$error = SurveyText::validate( $value );
			if ( $error !== null ) {
				$problems[] = "metric \"$name\" has an invalid \"$field\", which was ignored: $error";
				$value = null;
			}
			$text[$field] = $value;
		}

		return new self(
			name: $name,
			type: $type,
			filter: $filter,
			label: $text['label'],
			description: $text['description'],
		);
	}

	public function matchesEdit( int $namespace ): bool {
		return $this->type === self::EDIT &&
			( !$this->filter['namespaces'] || in_array( $namespace, $this->filter['namespaces'], true ) );
	}

	/** @param string[] $tags */
	public function matchesTags( array $tags ): bool {
		if ( $this->type !== self::TAG ) {
			return false;
		}

		foreach ( $tags as $tag ) {
			if ( in_array( $tag, $this->filter['tags'], true ) ) {
				return true;
			}
		}
		return false;
	}

	public function matchesLog( string $type, string $action ): bool {
		if ( $this->type !== self::LOG ) {
			return false;
		}

		foreach ( $this->filter['log'] as [ $wantedType, $wantedAction ] ) {
			if ( $wantedType === $type && ( $wantedAction === '*' || $wantedAction === $action ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param string $name */
	public function matchesSpecialPage( string $name ): bool {
		return $this->type === self::SPECIAL_PAGE && in_array( strtolower( $name ), $this->filter['pages'], true );
	}

	/** @param string $path */
	public function matchesApiModule( string $path ): bool {
		return $this->type === self::API && in_array( $path, $this->filter['modules'], true );
	}

	public function isClientEvent(): bool {
		return $this->type === self::EVENT && ( $this->filter['client'] ?? false ) === true;
	}

	public function toArray(): array {
		return [ 'type' => $this->type ] + $this->filter;
	}

	/** @return string[] */
	private static function stringList( mixed $value ): array {
		return array_values( array_filter(
			is_array( $value ) ? $value : [ $value ],
			static fn ( $item ) => is_string( $item ) && $item !== ''
		) );
	}
}
