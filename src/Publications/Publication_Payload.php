<?php

declare(strict_types=1);

namespace TurmasBridge\Publications;

final class Publication_Payload {
	public const SCHEMA_VERSION = '1';

	/** @param mixed $payload @return array<string, mixed>|\WP_Error */
	public static function validate(mixed $payload): array|\WP_Error {
		if (! is_array($payload) || (string) ($payload['schema_version'] ?? '') !== self::SCHEMA_VERSION) return self::error('turmas_bridge_invalid_schema_version', 'A versão do schema da Publicação não é suportada.');
		$publication = $payload['publication'] ?? null;
		$classes = $payload['classes'] ?? null;
		if (! is_array($publication) || ! is_array($classes) || $classes === array()) return self::error('turmas_bridge_invalid_publication_payload', 'A Publicação deve conter ao menos uma Turma.');
		$year = (int) ($publication['year'] ?? 0);
		$formation = (string) ($publication['formation_code'] ?? '');
		$key = (string) ($publication['publication_key'] ?? '');
		if ($year < 2000 || $year > 9999 || ! preg_match('/^[A-Z0-9_-]{1,50}$/', $formation) || $key !== $year . ':' . $formation) return self::error('turmas_bridge_invalid_publication_key', 'A identidade da Publicação é inválida.');
		$seen = array();
		foreach ($classes as $class) {
			if (! is_array($class)) return self::error('turmas_bridge_invalid_class', 'Uma Turma do contrato é inválida.');
			$code = (string) ($class['class_code'] ?? '');
			$class_key = (string) ($class['class_key'] ?? '');
			if (! preg_match('/^\d{2}\.\d{2}$/', $code) || $class_key !== $key . ':' . $code || isset($seen[$class_key])) return self::error('turmas_bridge_invalid_class_key', 'A identidade de uma Turma é inválida ou repetida.');
			$seen[$class_key] = true;
			if (! is_string($class['short_name'] ?? null) || ! is_string($class['full_name'] ?? null) || trim((string) $class['short_name']) === '' || trim((string) $class['full_name']) === '') return self::error('turmas_bridge_invalid_class_name', 'Os nomes derivados da Turma são obrigatórios.');
			if (filter_var($class['capacity'] ?? null, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1))) === false) return self::error('turmas_bridge_invalid_capacity', 'A capacidade da Turma deve ser um inteiro positivo.');
			$cres = $class['cres'] ?? null;
			if (! is_array($cres) || $cres === array() || count($cres) !== count(array_unique($cres)) || array_filter($cres, static fn (mixed $item): bool => ! is_string($item) || ! preg_match('/^\d{2}$/', $item))) return self::error('turmas_bridge_invalid_cres', 'As CRES da Turma são inválidas.');
			$cycles = $class['cycles'] ?? null;
			if (! is_array($cycles) || count($cycles) !== 8 || array_filter($cycles, static fn (mixed $date): bool => ! is_string($date) || ! self::date_is_iso($date))) return self::error('turmas_bridge_invalid_cycles', 'A Turma deve possuir exatamente oito ciclos em formato ISO.');
		}
		return $payload;
	}

	private static function date_is_iso(string $value): bool {
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
	}

	private static function error(string $code, string $message): \WP_Error { return new \WP_Error($code, $message, array('status' => 422)); }
}
