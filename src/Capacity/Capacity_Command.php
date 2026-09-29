<?php

declare(strict_types=1);

namespace TurmasBridge\Capacity;

final class Capacity_Command {
	/** @return array<string,mixed>|\WP_Error */
	public static function validate(mixed $input): array|\WP_Error {
		if (! is_array($input)) return self::error();
		$keys = array('schema_version', 'operation_key', 'publication_key', 'class_key', 'expected_form_id', 'source_turma_id', 'source_row_version', 'desired_capacity', 'reason');
		if (array_diff(array_keys($input), $keys) !== array() || array_diff($keys, array_keys($input)) !== array()) return self::error();
		foreach (array('expected_form_id', 'source_turma_id', 'source_row_version', 'desired_capacity') as $key) if (! is_int($input[$key]) || $input[$key] < 1 || $input[$key] > 2147483647) return self::error();
		foreach (array('schema_version', 'operation_key', 'publication_key', 'class_key', 'reason') as $key) if (! is_string($input[$key])) return self::error();
		if ($input['schema_version'] !== '1' || ! preg_match('/\A[0-9]{4}:[A-Z0-9_-]{1,50}\z/', $input['publication_key']) || ! preg_match('/\A' . preg_quote($input['publication_key'], '/') . ':\d{2}\.\d{2}\z/', $input['class_key']) || trim($input['reason']) === '' || strlen($input['reason']) > 2000) return self::error();
		if (! hash_equals(self::key($input), $input['operation_key'])) return self::error();
		$result = array(); foreach ($keys as $key) $result[$key] = $input[$key];
		return $result;
	}
	/** Shared wire identity: deliberately independent from materialization/activation keys. */
	public static function key(array $input): string {
		return 'capacity-' . hash('sha256', implode('|', array($input['publication_key'], $input['class_key'], $input['expected_form_id'], $input['source_turma_id'], $input['source_row_version'], $input['desired_capacity'])));
	}
	public static function hash(array $command): string { return hash('sha256', (string) wp_json_encode($command, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); }
	private static function error(): \WP_Error { return new \WP_Error('invalid_capacity_command', 'Contrato de capacidade inválido.', array('status' => 422)); }
}
