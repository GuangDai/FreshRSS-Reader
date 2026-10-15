<?php
declare(strict_types=1);

namespace FreshRSS\Services\Reader;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/** Version the whole module graph so cached relative imports cannot outlive a deployment. */
final class ModuleAssets {
	/** @return array{imports:array<string,string>} */
	public static function importMap(string $directory = PUBLIC_PATH . '/scripts/reader'): array {
		$imports = [];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
		/** @var SplFileInfo $file */
		foreach ($files as $file) {
			if (!$file->isFile() || $file->getExtension() !== 'js') {
				continue;
			}
			$relative = substr($file->getPathname(), strlen($directory) + 1);
			$url = '../scripts/reader/' . implode('/', array_map('rawurlencode', explode(DIRECTORY_SEPARATOR, $relative)));
			$version = hash_file('sha256', $file->getPathname());
			if ($version === false) {
				throw new RuntimeException('Unable to version reader module: ' . $relative);
			}
			$imports[$url] = $url . '?v=' . $version;
		}
		ksort($imports, SORT_STRING);
		return ['imports' => $imports];
	}
}
