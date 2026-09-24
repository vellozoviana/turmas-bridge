<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TurmasBridge\Auth\Canonical_Request;

final class CanonicalHmacV2Test extends TestCase {
	public function test_shared_post_vector_has_exact_eight_lines_and_independent_hash(): void {
		$v = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/docs/fixtures/hmac-v2-contract.json'), true, 512, JSON_THROW_ON_ERROR);
		$canonical = Canonical_Request::build($v['method'], $v['route'], $v['query'], $v['timestamp'], $v['nonce'], $v['body'], $v['idempotency_key']);
		self::assertSame($v['body_hash'], hash('sha256', $v['body']));
		self::assertSame($v['canonical_request'], $canonical);
		self::assertSame($v['canonical_sha256'], hash('sha256', $canonical));
		self::assertCount(8, explode("\n", $canonical));
		self::assertFalse(str_ends_with($canonical, "\n"));
		self::assertSame('v2', Canonical_Request::signature_version('post'));
		$query = array_reverse($v['query'], true);
		$query['nested'] = array_reverse($query['nested'], true);
		self::assertSame($canonical, Canonical_Request::build('POST', '/turmas-bridge/v1/publicacoes', $query, $v['timestamp'], $v['nonce'], $v['body'], " \t" . $v['idempotency_key'] . "\t "));
	}

	#[DataProvider('invalid_keys')]
	public function test_post_cannot_fall_back_to_v1_with_a_missing_or_invalid_key(?string $key): void {
		self::assertSame('v2', Canonical_Request::signature_version('POST'));
		$this->expectException(InvalidArgumentException::class);
		Canonical_Request::build('POST', '/turmas-bridge/v1/publicacoes', array(), '1760000000', 'synthetic-nonce-00001', '{}', $key);
	}

	public static function invalid_keys(): iterable {
		yield 'missing' => array(null);
		yield 'empty' => array('');
		yield 'whitespace' => array(" \t");
		yield 'newline' => array("valid-command-key-0001\n");
		yield 'null byte' => array("valid-command-key-0001\0");
		yield 'internal space' => array('valid command key');
		yield 'comma merged headers' => array('valid-command-key-0001,valid-command-key-0001');
		yield 'long' => array(str_repeat('a', 129));
	}

	public function test_get_stays_v1_and_does_not_depend_on_a_business_key(): void {
		$expected = "v1\nGET\n/turmas-bridge/v1/publicacoes/2097:E2F\n\n1760000000\nsynthetic-nonce-00001\n" . hash('sha256', '');
		self::assertSame('v1', Canonical_Request::signature_version('GET'));
		self::assertSame($expected, Canonical_Request::build('GET', '/turmas-bridge/v1/publicacoes/2097:E2F', array(), '1760000000', 'synthetic-nonce-00001', ''));
		self::assertSame($expected, Canonical_Request::build('GET', '/turmas-bridge/v1/publicacoes/2097:E2F', array(), '1760000000', 'synthetic-nonce-00001', '', 'ignored-business-key'));
	}

	public function test_key_normalization_is_bounded_and_case_sensitive(): void {
		self::assertSame('MiXeD:_-.~', Canonical_Request::normalise_idempotency_key(" \tMiXeD:_-.~\t "));
		self::assertSame(str_repeat('a', 128), Canonical_Request::normalise_idempotency_key(str_repeat('a', 128)));
		self::assertNotSame(Canonical_Request::normalise_idempotency_key('CaseKey'), Canonical_Request::normalise_idempotency_key('casekey'));
	}
}
