<?php
declare(strict_types=1);

namespace FreshRSS\Tests\App\Services\Network;

use FreshRSS\Services\Network\BrowserNetworkConfiguration;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BrowserNetworkConfigurationTest extends TestCase {
	public function testZeroDisablesNetworkFeaturesWithoutRestoringDefaults(): void {
		$config = BrowserNetworkConfiguration::configuration(['request_timeout' => 0, 'unread_interval' => 0]);
		self::assertSame(0, $config['request_timeout']);
		self::assertSame(0, $config['unread_interval']);
		self::assertSame(15, $config['slider_timeout']);
	}

	/** @param array<string,mixed> $config */
	#[DataProvider('invalidConfigurations')]
	public function testInvalidLimitsAreRejectedExplicitly(array $config): void {
		$this->expectException(InvalidArgumentException::class);
		BrowserNetworkConfiguration::configuration($config);
	}

	/** @return array<string,array{array<string,mixed>}> */
	public static function invalidConfigurations(): array {
		return [
			'unknown' => [['unknown_timeout' => 10]],
			'negative' => [['request_timeout' => -1]],
			'null' => [['unread_timeout' => null]],
			'type' => [['unread_interval' => '30']],
			'timer overflow' => [['slider_timeout' => 2147484]],
			'jitter overflow' => [['unread_backoff_max' => 2147483, 'unread_jitter' => 1]],
		];
	}
}
