<?php
declare(strict_types=1);

final class CleanCacheTest extends \PHPUnit\Framework\TestCase {
	public function testOnlyExpiredCacheFilesAreRemoved(): void {
		$path = sys_get_temp_dir() . '/freshrss-cache-' . bin2hex(random_bytes(8));
		mkdir($path);
		try {
			foreach (['old.spc', 'recent.spc', 'index.html', '.lock', 'noextension', 'outside'] as $name) {
				file_put_contents($path . '/' . $name, 'keep unless expired cache');
				touch($path . '/' . $name, time() - 7200);
			}
			touch($path . '/recent.spc');
			mkdir($path . '/nested.cache');
			symlink($path . '/outside', $path . '/link.spc');
			cleanCache(1, $path);
			self::assertFileDoesNotExist($path . '/old.spc');
			foreach (['recent.spc', 'index.html', '.lock', 'noextension', 'outside', 'link.spc'] as $name) {
				self::assertFileExists($path . '/' . $name);
			}
			self::assertDirectoryExists($path . '/nested.cache');
		} finally {
			recursive_unlink($path);
		}
	}
}
