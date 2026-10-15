<?php
declare(strict_types=1);
require_once CORE_EXTENSIONS_PATH . '/ReaderComfort/extension.php';

final class ReaderComfortTest extends \PHPUnit\Framework\TestCase {
	public function testPreferencesAreBoundedAndCssCannotBeInjected(): void {
		$values = ReaderComfortExtension::normalise([
			'font_size' => '99999', 'line_height' => -1, 'width' => '800px;}body{display:none', 'density' => [],
		]);
		self::assertSame(['font_size' => 26, 'line_height' => 140, 'width' => 800, 'density' => 8], $values);
		$css = ReaderComfortExtension::stylesheet($values);
		self::assertStringContainsString('--reader-line-height:1.4;', $css);
		self::assertStringNotContainsString('display', $css);
	}
}
