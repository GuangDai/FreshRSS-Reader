<?php
declare(strict_types=1);

final class ReaderComfortExtension extends Minz_Extension {
	/** @var array<string,array{min:int,max:int,default:int,unit:string}> */
	public const SETTINGS = [
		'font_size' => ['min' => 14, 'max' => 26, 'default' => 18, 'unit' => 'px'],
		'line_height' => ['min' => 140, 'max' => 220, 'default' => 175, 'unit' => '%'],
		'width' => ['min' => 560, 'max' => 1200, 'default' => 800, 'unit' => 'px'],
		'density' => ['min' => 4, 'max' => 16, 'default' => 8, 'unit' => 'px'],
	];

	#[\Override]
	public function init(): void {
		parent::init();
		$this->registerTranslates();
		Minz_View::appendStyle($this->getFileUrl('reading.css'));
		if ($this->hasFile('preferences.css')) {
			Minz_View::appendStyle($this->getFileUrl('preferences.css', isStatic: false));
		}
	}

	/** @return array<key-of<self::SETTINGS>,int> */
	public function preferences(): array {
		return self::normalise($this->getUserConfigurationArray('reading') ?? []);
	}

	/** @param array<array-key,mixed> $values
	 * @return array<key-of<self::SETTINGS>,int>
	 */
	public static function normalise(array $values): array {
		$result = [];
		foreach (self::SETTINGS as $key => $setting) {
			$value = filter_var($values[$key] ?? $setting['default'], FILTER_VALIDATE_INT);
			$result[$key] = $value === false ? $setting['default'] : max($setting['min'], min($setting['max'], $value));
		}
		return $result;
	}

	/** @param array<string,int> $values */
	public static function stylesheet(array $values): string {
		$css = ':root {';
		foreach (self::normalise($values) as $key => $value) {
			$unit = self::SETTINGS[$key]['unit'];
			// Unitless line height scales with headings and nested text.
			$css .= '--reader-' . str_replace('_', '-', $key) . ':' .
				($key === 'line_height' ? $value / 100 : $value . $unit) . ';';
		}
		return $css . '}';
	}

	#[\Override]
	public function handleConfigureAction(): void {
		parent::handleConfigureAction();
		$this->registerTranslates();
		Minz_View::appendStyle($this->getFileUrl('reading.css'));
		if (!Minz_Request::isPost()) {
			return;
		}
		$values = [];
		foreach (self::SETTINGS as $key => $setting) {
			$values[$key] = Minz_Request::paramString($key);
		}
		$values = self::normalise($values);
		$this->saveFile('preferences.css', self::stylesheet($values));
		$this->setUserConfigurationValue('reading', $values);
		FreshRSS_UserDAO::touch();
		Minz_Request::good(_t('feedback.conf.updated'), [
			'c' => 'extension', 'a' => 'configure', 'params' => ['e' => $this->getName()],
		]);
	}
}
