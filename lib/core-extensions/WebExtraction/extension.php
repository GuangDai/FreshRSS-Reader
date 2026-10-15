<?php
declare(strict_types=1);

use FreshRSS\Services\Extensions\ConfigurationService as ExtensionsConfigurationService;
use FreshRSS\Services\Extraction\ExtractionEngine;

final class WebExtractionExtension extends Minz_Extension {
	public const MAX_CONFIGURATION_BYTES = 1048576;
	public const MAX_RECIPES = 128;
	public string $configurationJson = '{}';
	public string $configurationVersion = '';
	public string $latestConfigurationJson = '';
	public string $previewSource = '';
	public string $previewFeed = '';
	public string $previewResult = '';
	/** @var list<string> */
	public array $configurationErrors = [];

	#[\Override]
	public function init(): void {
		parent::init();
		$this->registerHook(Minz_HookType::EntryBeforeAdd, [$this, 'processEntry']);
		$this->registerHook(Minz_HookType::EntryBeforeUpdate, [$this, 'processEntry']);
	}

	#[\Override]
	public function getConfigurationSchema(): array {
		return ['recipes' => ['type' => 'object', 'default' => [], 'maxItems' => self::MAX_RECIPES,
			'maxBytes' => self::MAX_CONFIGURATION_BYTES, 'label' => 'Extraction recipes']];
	}

	#[\Override]
	public function validateConfiguration(array $configuration): array {
		$recipes = array_key_exists('recipes', $configuration) ? $configuration['recipes'] : [];
		if (!is_array($recipes)) {
			return ['recipes' => 'Recipes must be an object'];
		}
		$errors = self::validateRecipes($recipes);
		return $errors === [] ? [] : ['recipes' => implode('; ', $errors)];
	}

	public function processEntry(FreshRSS_Entry $entry): FreshRSS_Entry {
		$recipes = $this->getUserConfigurationArray('recipes') ?? [];
		$recipe = $recipes[$entry->feedId()] ?? $recipes['*'] ?? null;
		if (is_array($recipe)) {
			$errors = self::applyRecipe($entry, $recipe);
			if ($errors !== []) {
				Minz_Log::warning('Web Extraction, feed ' . $entry->feedId() . ': ' . implode('; ', $errors));
			}
		}
		return $entry;
	}

	/**
	 * Process the body already fetched by FreshRSS. A failed recipe preserves the entry.
	 * @param array<array-key,mixed> $recipe
	 * @return list<string>
	 */
	public static function applyRecipe(FreshRSS_Entry $entry, array $recipe): array {
		$errors = self::validateEntryRecipe($recipe);
		if ($errors !== []) {
			return $errors;
		}
		$baseUrl = htmlspecialchars_decode($entry->link(true), ENT_QUOTES);
		$result = ExtractionEngine::extract($entry->content(false), $recipe, $baseUrl, includeItems: true);
		if (!$result['success']) {
			return $result['errors'];
		}
		$link = null;
		if (isset($result['values']['link'])) {
			$value = $result['values']['link'];
			$text = $result['formats']['link'] === 'html' ? html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8') : $value;
			try {
				$link = ExtractionEngine::resolveUrl($text, $baseUrl);
			} catch (InvalidArgumentException $exception) {
				return ['link: ' . $exception->getMessage()];
			}
		}
		// Feed update detection compares the original RSS, not locally derived presentation fields.
		$sourceHash = $entry->hash();
		foreach ($result['values'] as $field => $value) {
			$isHtml = $result['formats'][$field] === 'html';
			$text = $isHtml ? html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8') : $value;
			switch ($field) {
				case 'content':
					$entry->_content($isHtml ? $value : nl2br(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')));
					break;
				case 'title':
					$entry->_title(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
					break;
				case 'author':
					$authors = [];
					foreach ($result['items']['author'] ?? [$value] as $author) {
						$author = $isHtml ? html_entity_decode(strip_tags($author), ENT_QUOTES | ENT_HTML5, 'UTF-8') : $author;
						$authors[] = htmlspecialchars($author, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
					}
					$entry->_authors($authors);
					break;
				case 'link':
					if ($link !== null) {
						$entry->_link(htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
					}
					break;
			}
		}
		$entry->_hash($sourceHash);
		return [];
	}

	/** @param array<array-key,mixed> $recipes
	 * @return list<string>
	 */
	public static function validateRecipes(array $recipes): array {
		if (count($recipes) > self::MAX_RECIPES) {
			return ['Too many recipes; maximum is ' . self::MAX_RECIPES];
		}
		$errors = [];
		foreach ($recipes as $feed => $recipe) {
			if (($feed !== '*' && (filter_var($feed, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
				|| preg_match('/^[1-9][0-9]*$/D', (string)$feed) !== 1)) || !is_array($recipe)) {
				$errors[] = 'Each recipe must use a positive feed ID or * as its key';
				continue;
			}
			foreach (self::validateEntryRecipe($recipe, true) as $error) {
				$errors[] = $feed . ': ' . $error;
			}
		}
		return array_slice($errors, 0, ExtractionEngine::MAX_ERRORS);
	}

	/** @param array<array-key,mixed> $recipe
	 * @return list<string>
	 */
	private static function validateEntryRecipe(array $recipe, bool $checkSelectors = false): array {
		$errors = ExtractionEngine::validate($recipe, $checkSelectors);
		if ($errors === [] && is_array($recipe['fields'] ?? null)) {
			foreach (array_keys($recipe['fields']) as $field) {
				if (!in_array($field, ['title', 'content', 'author', 'link'], true)) {
					$errors[] = 'Unsupported entry field: ' . $field;
				}
			}
		}
		return $errors;
	}

	#[\Override]
	public function handleConfigureAction(): void {
		parent::handleConfigureAction();
		$this->registerTranslates();
		$this->configurationVersion = ExtensionsConfigurationService::describe($this)['version'];
		$this->configurationJson = json_encode((object)($this->getUserConfigurationArray('recipes') ?? []),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
		if (!Minz_Request::isPost()) {
			return;
		}
		$this->configurationVersion = Minz_Request::paramString('configuration_version');
		$this->previewSource = Minz_Request::paramString('source', plaintext: true);
		$this->previewFeed = Minz_Request::paramString('feed_id');
		$this->configurationJson = Minz_Request::paramString('recipes', plaintext: true);
		if (strlen($this->configurationJson) > self::MAX_CONFIGURATION_BYTES) {
			$this->configurationErrors = ['Configuration exceeds ' . self::MAX_CONFIGURATION_BYTES . ' bytes'];
			return;
		}
		try {
			$root = json_decode($this->configurationJson, false, ExtractionEngine::JSON_DEPTH, JSON_THROW_ON_ERROR);
			$recipes = json_decode($this->configurationJson, true, ExtractionEngine::JSON_DEPTH, JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			$this->configurationErrors = ['Invalid JSON: ' . $exception->getMessage()];
			return;
		}
		if (!$root instanceof stdClass || !is_array($recipes)) {
			$this->configurationErrors = ['Configuration must be a JSON object'];
			return;
		}
		$this->configurationErrors = self::validateRecipes($recipes);
		if ($this->configurationErrors !== []) {
			return;
		}
		if (Minz_Request::paramString('preview') === '1') {
			$recipe = $recipes[$this->previewFeed] ?? $recipes['*'] ?? null;
			if (!is_array($recipe)) {
				$this->configurationErrors = ['No recipe for the preview feed ID'];
				return;
			}
			$this->previewResult = json_encode(ExtractionEngine::extract($this->previewSource, $recipe),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
			return;
		}
		if ($this->configurationVersion === '') {
			$this->configurationErrors = [_t('ext.web_extraction.missing_version')];
			return;
		}
		try {
			$result = ExtensionsConfigurationService::save($this, ['recipes' => $recipes], $this->configurationVersion);
		} catch (RuntimeException $exception) {
			Minz_Log::error('Web Extraction configuration write failed: ' . get_class($exception));
			$this->configurationErrors = [_t('ext.web_extraction.save_failed')];
			return;
		}
		if (!$result['saved']) {
			$this->configurationErrors = array_values($result['errors']);
			if (isset($result['conflict'])) {
				$this->configurationVersion = $result['conflict']['version'];
				$latest = $result['conflict']['values']['recipes'] ?? [];
				$this->latestConfigurationJson = json_encode((object)(is_array($latest) ? $latest : []),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
				$this->configurationErrors = [_t('ext.web_extraction.conflict')];
			}
			return;
		}
		FreshRSS_UserDAO::touch();
		Minz_Request::good(_t('feedback.conf.updated'), [
			'c' => 'extension', 'a' => 'configure', 'params' => ['e' => $this->getName()],
		]);
	}
}
