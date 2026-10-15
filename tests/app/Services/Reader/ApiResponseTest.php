<?php
declare(strict_types=1);

namespace FreshRSS\Tests\App\Services\Reader;

use FreshRSS\Services\Reader\ApiContract;
use FreshRSS\Services\Reader\ApiResponse;
use PHPUnit\Framework\TestCase;

final class ApiResponseTest extends TestCase {
	public function testAuthenticationAndControllerErrorsUseTheSameEnvelope(): void {
		self::assertSame(['version' => ApiContract::VERSION, 'error' => 'csrf_invalid',
			'errorDetails' => ['code' => 'csrf_invalid', 'status' => 403]], ApiResponse::envelope(['error' => 'csrf_invalid'], 403));
		self::assertSame(ApiContract::VERSION, ApiResponse::envelope(['version' => 999], 200)['version']);
		self::assertArrayNotHasKey('errorDetails', ApiResponse::envelope(['items' => []], 200));
	}

}
