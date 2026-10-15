<?php
declare(strict_types=1);

namespace FreshRSS\Tests\App\Services\Reader;

use FreshRSS\Services\Reader\Preferences;
use PHPUnit\Framework\TestCase;

final class PreferencesTest extends TestCase {
	public function testSchemaOffersAllMetaCubeXDThemesAndDayNightDefaults(): void {
		$themes = [
			'acid', 'aqua', 'autumn', 'black', 'bumblebee', 'business', 'cmyk', 'coffee',
			'corporate', 'cupcake', 'cyberpunk', 'dark', 'dim', 'dracula', 'emerald', 'fantasy',
			'forest', 'garden', 'halloween', 'lemonade', 'light', 'lofi', 'luxury', 'night',
			'nord', 'pastel', 'retro', 'sunset', 'synthwave', 'valentine', 'winter', 'wireframe',
		];
		$schema = Preferences::schema();
		self::assertArrayHasKey('options', $schema['theme']);
		self::assertSame(['auto', ...$themes], $schema['theme']['options']);
		self::assertSame('sunset', $schema['theme']['default']);
		self::assertSame('nord', $schema['lightTheme']['default']);
		self::assertSame('sunset', $schema['darkTheme']['default']);
		foreach (['theme', 'lightTheme', 'darkTheme'] as $field) {
			self::assertArrayHasKey('group', $schema[$field]);
			self::assertSame('appearance', $schema[$field]['group']);
			foreach ($themes as $theme) {
				self::assertSame([], Preferences::preferenceErrors([$field => $theme], $schema));
				self::assertSame($theme, Preferences::preferences([$field => $theme], $schema)[$field]);
			}
		}
		self::assertArrayHasKey('options', $schema['lightTheme']);
		self::assertSame($themes, $schema['lightTheme']['options']);
		self::assertArrayHasKey('options', $schema['darkTheme']);
		self::assertSame($themes, $schema['darkTheme']['options']);
	}

	public function testLegacyAppearancePreferencesKeepTheirSelection(): void {
		foreach (['auto', 'light', 'dark'] as $theme) {
			$preferences = Preferences::preferences(['theme' => $theme, 'style' => 'paper']);
			self::assertSame($theme, $preferences['theme']);
			self::assertSame('paper', $preferences['style']);
			self::assertSame('nord', $preferences['lightTheme']);
			self::assertSame('sunset', $preferences['darkTheme']);
		}
		self::assertSame(['lightTheme', 'darkTheme'], Preferences::preferenceErrors([
			'lightTheme' => 'auto', 'darkTheme' => 'unrecognised-theme',
		], Preferences::schema()));
	}

	public function testSchemaExposesAdministratorLimitsAndNormalisesDefaults(): void {
		$schema = Preferences::schema(['max_page_size' => 25, 'max_cache_mib' => 0]);
		self::assertArrayHasKey('max', $schema['pageSize']);
		self::assertSame(25, $schema['pageSize']['max']);
		self::assertSame(25, $schema['pageSize']['default']);
		self::assertSame(0, Preferences::preferences([], $schema)['cacheMiB']);
		self::assertSame(['pageSize'], Preferences::preferenceErrors(['pageSize' => 26], $schema));
	}

	public function testInvalidWritesAreReportedInsteadOfSilentlyClamped(): void {
		$schema = Preferences::schema();
		self::assertSame(['pageSize', 'theme', 'unknown', 'remoteImages'], Preferences::preferenceErrors([
			'pageSize' => '50', 'theme' => 'url(javascript:evil)', 'unknown' => 1, 'remoteImages' => 1,
		], $schema));
		self::assertSame([], Preferences::preferenceErrors(['cacheArticles' => 0, 'cacheMiB' => 0,
			'refreshInterval' => 0, 'requestTimeout' => 0], $schema));
	}

}
