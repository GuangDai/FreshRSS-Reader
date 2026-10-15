<?php
declare(strict_types=1);

use FreshRSS\Exceptions\Rules\RuleException;
use FreshRSS\Services\Extensions\ConfigurationService as ExtensionsConfigurationService;
use FreshRSS\Services\Rules\RuleEngine;

final class RuleEngineExtension extends Minz_Extension {
	public const DEFAULT_HOOK_PRIORITY = 100;
	public const EMPTY_RULES = '{"version":1,"rules":[]}';
	public string $rulesJson = self::EMPTY_RULES;
	public string $sampleJson = '{"title":"Release 1.0","content":"New features","author":["Example Author"]}';
	public string $result = '';
	public bool $sampleUpdated = false;
	public string $settingsVersion = '';
	public string $currentRulesJson = '';
	public string $operationsJson = '[]';
	private ?RuleEngine $engine = null;

	#[\Override]
	public function getConfigurationSchema(): array {
		return [
			'rules_json' => ['type' => 'string', 'default' => self::EMPTY_RULES,
				'maxLength' => RuleEngine::DEFAULT_JSON_BYTES, 'label' => 'Rules JSON'],
			'hook_priority' => ['type' => 'integer', 'default' => self::DEFAULT_HOOK_PRIORITY,
				'min' => PHP_INT_MIN, 'max' => PHP_INT_MAX, 'label' => 'Hook priority'],
		];
	}

	/** @param array<string,mixed> $configuration
	 * @return array<string,string>
	 */
	#[\Override]
	public function validateConfiguration(array $configuration): array {
		$json = $configuration['rules_json'] ?? self::EMPTY_RULES;
		if (!is_string($json)) {
			return ['rules_json' => 'Expected JSON text'];
		}
		$errors = RuleEngine::validate($json);
		return $errors === [] ? [] : ['rules_json' => implode('; ', $errors)];
	}

	#[\Override]
	public function init(): void {
		parent::init();
		$this->registerTranslates();
		try {
			$this->engine = RuleEngine::fromJson($this->getUserConfigurationString('rules_json') ?? self::EMPTY_RULES);
		} catch (RuleException $e) {
			Minz_Log::warning('Rule Engine configuration: ' . $e->getMessage());
			return;
		}
		$priority = $this->getUserConfigurationInt('hook_priority') ?? self::DEFAULT_HOOK_PRIORITY;
		$this->registerHook(Minz_HookType::EntryBeforeAdd, $this->filter(...), $priority);
		$this->registerHook(Minz_HookType::EntryBeforeUpdate, $this->filterUpdated(...), $priority);
	}

	public function filterUpdated(FreshRSS_Entry $entry): FreshRSS_Entry {
		// Import updates can reach this hook without setting the model flag.
		$entry->_isUpdated(true);
		return $this->filter($entry);
	}

	public function filter(FreshRSS_Entry $entry): FreshRSS_Entry {
		try {
			return $this->engine?->apply($entry) ?? $entry;
		} catch (RuleException $e) {
			Minz_Log::warning('Rule Engine article ' . $entry->guid() . ': ' . $e->getMessage());
			return $entry;
		}
	}

	#[\Override]
	public function handleConfigureAction(): void {
		parent::handleConfigureAction();
		$this->registerTranslates();
		$this->rulesJson = $this->getUserConfigurationString('rules_json') ?? self::EMPTY_RULES;
		$snapshot = ExtensionsConfigurationService::describe($this);
		$this->settingsVersion = $snapshot['version'];
		if (!Minz_Request::isPost()) {
			return;
		}
		$this->rulesJson = Minz_Request::paramString('rules_json', plaintext: true);
		$this->settingsVersion = Minz_Request::paramString('settings_version', plaintext: true);
		$this->operationsJson = Minz_Request::paramString('rule_operations', plaintext: true) ?: '[]';
		$this->sampleJson = Minz_Request::paramString('sample_json', plaintext: true);
		$this->sampleUpdated = Minz_Request::paramBoolean('sample_updated');
		$action = Minz_Request::paramString('rule_action');
		if ($action === 'preset') {
			$this->rulesJson = RuleEngine::presets()[Minz_Request::paramString('preset')] ?? $this->rulesJson;
			return;
		}
		try {
			if ($action === 'patch') {
				if (strlen($this->operationsJson) > RuleEngine::DEFAULT_JSON_BYTES) {
					throw new RuleException('operations: JSON exceeds maxJsonBytes');
				}
				$operations = json_decode($this->operationsJson, true, RuleEngine::JSON_DEPTH, JSON_THROW_ON_ERROR);
				if (!is_array($operations)) {
					throw new RuleException('operations: expected JSON list');
				}
				$this->rulesJson = RuleEngine::patch($this->rulesJson, $operations);
				$this->result = _t('ext.rule_engine.patched');
				return;
			}
			$engine = RuleEngine::fromJson($this->rulesJson);
			if ($action === 'preview') {
				if (strlen($this->sampleJson) > RuleEngine::DEFAULT_JSON_BYTES) {
					throw new InvalidArgumentException('sample: JSON exceeds maxJsonBytes');
				}
				$sample = json_decode($this->sampleJson, false, RuleEngine::JSON_DEPTH, JSON_THROW_ON_ERROR);
				if (!$sample instanceof stdClass) {
					throw new InvalidArgumentException('sample: expected JSON object');
				}
				$this->result = json_encode($engine->preview(get_object_vars($sample), explain: true, updated: $this->sampleUpdated),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
				return;
			}
			if ($action === 'save') {
				if ($this->settingsVersion === '') {
					$this->result = _t('ext.rule_engine.missing_version');
					$this->settingsVersion = $snapshot['version'];
					$current = $snapshot['values']['rules_json'] ?? self::EMPTY_RULES;
					$this->currentRulesJson = is_string($current) ? $current : self::EMPTY_RULES;
					return;
				}
				$saved = ExtensionsConfigurationService::save($this, ['rules_json' => $this->rulesJson], $this->settingsVersion);
				if (!$saved['saved']) {
					if (isset($saved['conflict'])) {
						$this->result = _t('ext.rule_engine.conflict');
						$this->settingsVersion = $saved['conflict']['version'];
						$current = $saved['conflict']['values']['rules_json'] ?? self::EMPTY_RULES;
						$this->currentRulesJson = is_string($current) ? $current : self::EMPTY_RULES;
					} else {
						$this->result = implode('; ', $saved['errors']);
					}
					return;
				}
				$this->engine = $engine;
				FreshRSS_UserDAO::touch();
				Minz_Request::good(_t('feedback.conf.updated'), [
					'c' => 'extension', 'a' => 'configure', 'params' => ['e' => $this->getName()],
				]);
			}
		} catch (InvalidArgumentException | JsonException | RuntimeException $e) {
			$this->result = $e->getMessage();
		}
	}
}
