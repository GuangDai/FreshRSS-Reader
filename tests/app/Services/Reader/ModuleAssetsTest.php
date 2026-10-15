<?php
declare(strict_types=1);

namespace FreshRSS\Tests\App\Services\Reader;

use FreshRSS\Services\Reader\ModuleAssets;
use PHPUnit\Framework\TestCase;

final class ModuleAssetsTest extends TestCase {
	public function testAllPublishedModulesHaveContentVersionedUrls(): void {
		$map = ModuleAssets::importMap()['imports'];
		self::assertArrayHasKey('../scripts/reader/app.js', $map);
		self::assertArrayHasKey('../scripts/reader/core/store.js', $map);
		foreach ($map as $source => $target) {
			$file = PUBLIC_PATH . '/' . substr($source, 3);
			self::assertSame($source . '?v=' . hash_file('sha256', $file), $target);
		}
	}

	public function testContentChangesInvalidateAModuleEvenWhenItsModificationTimeIsPreserved(): void {
		$directory = sys_get_temp_dir() . '/freshrss-modules-' . bin2hex(random_bytes(8));
		mkdir($directory);
		mkdir($directory . '/core');
		try {
			file_put_contents($directory . '/app.js', 'import "./core/state.js";');
			file_put_contents($directory . '/core/state.js', 'export const value = 1;');
			touch($directory . '/core/state.js', 1700000000);
			$before = ModuleAssets::importMap($directory)['imports'];
			file_put_contents($directory . '/core/state.js', 'export const value = 2;');
			touch($directory . '/core/state.js', 1700000000);
			file_put_contents($directory . '/core/new.js', 'export const added = true;');
			file_put_contents($directory . '/core/ignored.json', '{}');
			$after = ModuleAssets::importMap($directory)['imports'];
			self::assertSame($before['../scripts/reader/app.js'], $after['../scripts/reader/app.js']);
			self::assertNotSame($before['../scripts/reader/core/state.js'], $after['../scripts/reader/core/state.js']);
			self::assertArrayHasKey('../scripts/reader/core/new.js', $after);
			self::assertArrayNotHasKey('../scripts/reader/core/ignored.json', $after);
		} finally {
			foreach (glob($directory . '/core/*') ?: [] as $file) {
				unlink($file);
			}
			rmdir($directory . '/core');
			unlink($directory . '/app.js');
			rmdir($directory);
		}
	}
}
