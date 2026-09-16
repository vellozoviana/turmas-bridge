<?php

declare(strict_types=1);

namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurmasBridge\Auth\Nonce_Store;

final class NonceStoreTest extends TestCase {
	private const NOW = 1760000000;

	protected function setUp(): void {
		$GLOBALS['turmas_bridge_test_options'] = array();
		$GLOBALS['turmas_bridge_test_add_option_failure'] = false;
	}

	public function test_new_nonce_is_acquired_once_using_the_unique_option_name(): void {
		$store = new Nonce_Store(static fn (): int => self::NOW);

		self::assertTrue($store->claim('nonce-store-valid-0001', self::NOW + Nonce_Store::TTL_SECONDS));
		self::assertFalse($store->claim('nonce-store-valid-0001', self::NOW + Nonce_Store::TTL_SECONDS));
		self::assertCount(1, $GLOBALS['turmas_bridge_test_options']);
	}

	public function test_expired_claim_and_storage_failure_fail_closed(): void {
		$store = new Nonce_Store(static fn (): int => self::NOW);

		self::assertFalse($store->claim('nonce-store-expired-0001', self::NOW));
		$GLOBALS['turmas_bridge_test_add_option_failure'] = true;
		self::assertFalse($store->claim('nonce-store-storage-fail', self::NOW + Nonce_Store::TTL_SECONDS));
	}
}
