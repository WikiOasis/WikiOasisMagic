<?php

namespace WikiOasis\WikiOasisMagic\Onboarding;

use StatusValue;
use function array_is_list;
use function count;
use function in_array;
use function is_array;
use function is_bool;
use function is_scalar;
use function is_string;
use function parse_url;
use function preg_match;
use function str_starts_with;

class SurveyValidator {

	public const MAX_QUESTIONS = 6;
	public const MAX_OPTIONS = 12;
	public const MAX_CARDS = 12;

	public const ACTION_CREATE_WIKI = 'createwiki';
	public const ACTION_IMPORT_WIKI = 'importwiki';

	private const ACTIONS = [ self::ACTION_CREATE_WIKI, self::ACTION_IMPORT_WIKI ];
	private const ID_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';
	private const ICON_PATTERN = '/^cdxIcon[A-Za-z0-9]{1,60}$/';

	/**
	 * @param mixed $data
	 * @param bool $canRequestWikis
	 * @return StatusValue
	 */
	public function validate( mixed $data, bool $canRequestWikis ): StatusValue {
		$status = StatusValue::newGood();

		if ( !is_array( $data ) || array_is_list( $data ) ) {
			$status->fatal( 'wikioasismagic-onboarding-survey-invalid', '(root)', 'must be a JSON object' );
			return $status;
		}

		$questions = $this->validateQuestions( $data['questions'] ?? null, $canRequestWikis, $status );
		$cards = $this->validateCards( $data['cards'] ?? [], $questions, $status );

		$finish = [ 'title' => null, 'lead' => null ];
		if ( isset( $data['finish'] ) ) {
			if ( !is_array( $data['finish'] ) ) {
				$this->error( $status, 'finish', 'must be an object' );
			} else {
				foreach ( [ 'title', 'lead' ] as $field ) {
					if ( isset( $data['finish'][$field] ) ) {
						$finish[$field] = $this->text( $data['finish'][$field], "finish.$field", $status );
					}
				}
			}
		}

		if ( $status->isOK() ) {
			$status->setResult( true, [
				'questions' => $questions,
				'cards' => $cards,
				'finish' => $finish,
			] );
		}

		return $status;
	}

	private function validateQuestions( mixed $questions, bool $canRequestWikis, StatusValue $status ): array {
		if ( !is_array( $questions ) || !array_is_list( $questions ) || !$questions ) {
			$this->error( $status, 'questions', 'must be a non-empty list' );
			return [];
		}

		if ( count( $questions ) > self::MAX_QUESTIONS ) {
			$this->error( $status, 'questions', 'must have at most ' . self::MAX_QUESTIONS . ' entries' );
		}

		$normalised = [];
		foreach ( $questions as $i => $question ) {
			$path = "questions[$i]";
			if ( !is_array( $question ) ) {
				$this->error( $status, $path, 'must be an object' );
				continue;
			}

			$id = $this->id( $question['id'] ?? null, "$path.id", $status );
			if ( $id !== null && isset( $normalised[$id] ) ) {
				$this->error( $status, "$path.id", "\"$id\" is used by another question" );
			}

			$type = $question['type'] ?? 'multi';
			if ( !in_array( $type, [ 'single', 'multi' ], true ) ) {
				$this->error( $status, "$path.type", 'must be "single" or "multi"' );
				$type = 'multi';
			}

			$style = $question['style'] ?? ( $type === 'multi' ? 'cards' : 'list' );
			if ( !in_array( $style, [ 'cards', 'list' ], true ) ) {
				$this->error( $status, "$path.style", 'must be "cards" or "list"' );
				$style = 'cards';
			}

			$options = $this->validateOptions( $question['options'] ?? null, $path, $canRequestWikis, $status );

			if ( $id === null ) {
				continue;
			}

			if ( !$options ) {
				$status->warning( 'wikioasismagic-onboarding-survey-dropped', $path );
				continue;
			}

			$normalised[$id] = [
				'id' => $id,
				'type' => $type,
				'style' => $style,
				'label' => $this->text( $question['label'] ?? null, "$path.label", $status ),
				'help' => isset( $question['help'] ) ? $this->text( $question['help'], "$path.help", $status ) : null,
				'options' => $options,
			];
		}

		return $normalised;
	}

	private function validateOptions( mixed $options, string $questionPath, bool $canRequestWikis, StatusValue $status ): array {
		$path = "$questionPath.options";
		if ( !is_array( $options ) || !array_is_list( $options ) || !$options ) {
			$this->error( $status, $path, 'must be a non-empty list' );
			return [];
		}

		if ( count( $options ) > self::MAX_OPTIONS ) {
			$this->error( $status, $path, 'must have at most ' . self::MAX_OPTIONS . ' entries' );
		}

		$normalised = [];
		foreach ( $options as $i => $option ) {
			$optionPath = "{$path}[$i]";
			if ( !is_array( $option ) ) {
				$this->error( $status, $optionPath, 'must be an object' );
				continue;
			}

			$id = $this->id( $option['id'] ?? null, "$optionPath.id", $status );
			if ( $id !== null && isset( $normalised[$id] ) ) {
				$this->error( $status, "$optionPath.id", "\"$id\" is used by another option" );
			}

			$action = $option['action'] ?? null;
			if ( $action !== null && !in_array( $action, self::ACTIONS, true ) ) {
				$this->error( $status, "$optionPath.action", 'must be "createwiki" or "importwiki"' );
				$action = null;
			}

			if ( $action !== null && !$canRequestWikis ) {
				$status->warning( 'wikioasismagic-onboarding-survey-dropped', $optionPath );
				continue;
			}

			if ( $id === null ) {
				continue;
			}

			$normalised[$id] = [
				'id' => $id,
				'label' => $this->text( $option['label'] ?? null, "$optionPath.label", $status ),
				'description' => isset( $option['description'] ) ?
					$this->text( $option['description'], "$optionPath.description", $status ) :
					null,
				'icon' => $this->icon( $option['icon'] ?? null, "$optionPath.icon", $status ),
				'action' => $action,
				'freeText' => $this->flag( $option['freeText'] ?? false, "$optionPath.freeText", $status ),
				'tips' => $this->flag( $option['tips'] ?? false, "$optionPath.tips", $status ),
			];
		}

		return $normalised;
	}

	private function validateCards( mixed $cards, array $questions, StatusValue $status ): array {
		if ( !is_array( $cards ) || ( $cards && !array_is_list( $cards ) ) ) {
			$this->error( $status, 'cards', 'must be a list' );
			return [];
		}

		if ( count( $cards ) > self::MAX_CARDS ) {
			$this->error( $status, 'cards', 'must have at most ' . self::MAX_CARDS . ' entries' );
		}

		$normalised = [];
		foreach ( $cards as $i => $card ) {
			$path = "cards[$i]";
			if ( !is_array( $card ) ) {
				$this->error( $status, $path, 'must be an object' );
				continue;
			}

			$id = $this->id( $card['id'] ?? null, "$path.id", $status );
			if ( $id !== null && isset( $normalised[$id] ) ) {
				$this->error( $status, "$path.id", "\"$id\" is used by another card" );
			}

			$page = $card['page'] ?? null;
			$url = $card['url'] ?? null;
			if ( ( $page === null ) === ( $url === null ) ) {
				$this->error( $status, $path, 'needs exactly one of "page" and "url"' );
			}
			if ( $page !== null && ( !is_string( $page ) || $page === '' ) ) {
				$this->error( $status, "$path.page", 'must be a page title' );
			}
			if ( $url !== null && !$this->isUrl( $url ) ) {
				$this->error( $status, "$path.url", 'must be an http(s) URL or start with {directory}' );
			}

			$query = $card['query'] ?? [];
			if ( !is_array( $query ) || ( $query && array_is_list( $query ) ) ) {
				$this->error( $status, "$path.query", 'must be an object of strings' );
				$query = [];
			}
			foreach ( $query as $value ) {
				if ( !is_scalar( $value ) ) {
					$this->error( $status, "$path.query", 'must be an object of strings' );
					break;
				}
			}

			$when = $this->validateWhen( $card['when'] ?? [], "$path.when", $questions, $status );

			if ( $id === null ) {
				continue;
			}

			$normalised[$id] = [
				'id' => $id,
				'title' => $this->text( $card['title'] ?? null, "$path.title", $status ),
				'description' => isset( $card['description'] ) ?
					$this->text( $card['description'], "$path.description", $status ) :
					null,
				'icon' => $this->icon( $card['icon'] ?? null, "$path.icon", $status ),
				'page' => is_string( $page ) ? $page : null,
				'url' => is_string( $url ) ? $url : null,
				'query' => $query,
				'central' => $this->flag( $card['central'] ?? false, "$path.central", $status ),
				'requiresExists' => $this->flag( $card['requiresExists'] ?? false, "$path.requiresExists", $status ),
				'when' => $when,
			];
		}

		return $normalised;
	}

	/**
	 * @return array<string,string[]>
	 */
	private function validateWhen( mixed $when, string $path, array $questions, StatusValue $status ): array {
		if ( $when === [] ) {
			return [];
		}

		if ( !is_array( $when ) || array_is_list( $when ) ) {
			$this->error( $status, $path, 'must be an object of question id => option ids' );
			return [];
		}

		$normalised = [];
		foreach ( $when as $questionId => $optionIds ) {
			if ( !isset( $questions[$questionId] ) ) {
				$status->warning( 'wikioasismagic-onboarding-survey-unknown-question', $path, (string)$questionId );
				continue;
			}

			if ( !is_array( $optionIds ) || !array_is_list( $optionIds ) ) {
				$this->error( $status, "$path.$questionId", 'must be a list of option ids' );
				continue;
			}

			foreach ( $optionIds as $optionId ) {
				if ( !is_string( $optionId ) || !isset( $questions[$questionId]['options'][$optionId] ) ) {
					$status->warning(
						'wikioasismagic-onboarding-survey-unknown-option',
						"$path.$questionId",
						is_scalar( $optionId ) ? (string)$optionId : '?'
					);
					continue;
				}
				$normalised[$questionId][] = $optionId;
			}
		}

		return $normalised ?: [ '_never' => [] ];
	}

	private function id( mixed $id, string $path, StatusValue $status ): ?string {
		if ( !is_string( $id ) || !preg_match( self::ID_PATTERN, $id ) ) {
			$this->error( $status, $path, 'must be lowercase letters, digits and underscores, starting with a letter' );
			return null;
		}
		return $id;
	}

	private function text( mixed $text, string $path, StatusValue $status ): mixed {
		$problem = SurveyText::validate( $text );
		if ( $problem !== null ) {
			$this->error( $status, $path, $problem );
		}
		return $text;
	}

	private function icon( mixed $icon, string $path, StatusValue $status ): ?string {
		if ( $icon === null ) {
			return null;
		}
		if ( !is_string( $icon ) || !preg_match( self::ICON_PATTERN, $icon ) ) {
			$this->error( $status, $path, 'must be a Codex icon name such as "cdxIconEdit"' );
			return null;
		}
		return $icon;
	}

	private function flag( mixed $value, string $path, StatusValue $status ): bool {
		if ( !is_bool( $value ) ) {
			$this->error( $status, $path, 'must be true or false' );
			return false;
		}
		return $value;
	}

	private function isUrl( mixed $url ): bool {
		if ( !is_string( $url ) ) {
			return false;
		}
		if ( str_starts_with( $url, '{directory}' ) ) {
			return true;
		}
		$scheme = parse_url( $url, PHP_URL_SCHEME );
		return in_array( $scheme, [ 'http', 'https' ], true ) && parse_url( $url, PHP_URL_HOST );
	}

	private function error( StatusValue $status, string $path, string $problem ): void {
		$status->fatal( 'wikioasismagic-onboarding-survey-invalid', $path, $problem );
	}
}
