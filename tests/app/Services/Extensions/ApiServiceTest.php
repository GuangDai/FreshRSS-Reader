<?php
declare(strict_types=1);

namespace FreshRSS\Tests\App\Services\Extensions;

use FreshRSS\Exceptions\Reader\ApiException;
use FreshRSS\Services\Extensions\ApiService;
use Minz_Extension;
use PHPUnit\Framework\TestCase;

final class ApiServiceTest extends TestCase {
	/** @param 'user'|'system' $type */
	private function extension(string $type = 'user'): Minz_Extension {
		return new class (['name' => 'API fixture', 'entrypoint' => 'Fixture', 'path' => __DIR__, 'type' => $type]) extends Minz_Extension {
			#[\Override]
			public function getConfigurationSchema(): array {
				return ['enabled' => ['type' => 'boolean', 'default' => false]];
			}
		};
	}

	public function testPreparationPreservesFalseAndOpaqueVersion(): void {
		$extension = $this->extension();
		$service = new ApiService([$extension]);
		$changes = $service->prepare([['extension' => 'Fixture', 'configuration' => ['enabled' => false], 'expectedVersion' => 'opaque-revision']], 1);
		self::assertSame($extension, $changes[0]['extension']);
		self::assertSame(['enabled' => false], $changes[0]['configuration']);
		self::assertSame('opaque-revision', $changes[0]['expectedVersion'] ?? null);
	}

	public function testSystemConfigurationCannotEnterTheUserSettingsBatch(): void {
		$service = new ApiService([$this->extension('system')]);
		try {
			$service->prepare([['extension' => 'Fixture', 'configuration' => [], 'expectedVersion' => 'x']], 1);
			self::fail('System extension must be rejected');
		} catch (ApiException $exception) {
			self::assertSame(403, $exception->status);
			self::assertSame('user_extension_required', $exception->getMessage());
		}
	}

	public function testMissingVersionIsRejectedBeforeAnySave(): void {
		$service = new ApiService([$this->extension()]);
		$this->expectExceptionMessage('invalid_version');
		$service->prepare([['extension' => 'Fixture', 'configuration' => []]], 1);
	}

	public function testBatchLimitIsSharedRatherThanUsingAnExtraPluginLimit(): void {
		$service = new ApiService([]);
		try {
			$service->prepare([[], []], 1);
			self::fail('Batch limit expected');
		} catch (ApiException $exception) {
			self::assertSame(413, $exception->status);
			self::assertSame('batch_too_large', $exception->getMessage());
		}
	}

	public function testDuplicateExtensionAndUnknownPropertiesAreRejected(): void {
		$service = new ApiService([$this->extension()]);
		$item = ['extension' => 'Fixture', 'configuration' => [], 'expectedVersion' => 'x'];
		try {
			$service->prepare([$item, $item], 2);
			self::fail('Duplicate extension expected');
		} catch (ApiException $exception) {
			self::assertSame('duplicate_extension', $exception->getMessage());
		}
		$this->expectExceptionMessage('invalid_item');
		$service->prepare([$item + ['user' => 'another']], 1);
	}

	public function testConfigurationListIsNotAcceptedAsAnObject(): void {
		$service = new ApiService([$this->extension()]);
		$this->expectExceptionMessage('invalid_configuration');
		$service->prepare([['extension' => 'Fixture', 'configuration' => [true], 'expectedVersion' => 'x']], 1);
	}
}
