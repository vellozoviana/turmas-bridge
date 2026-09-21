<?php

declare(strict_types=1);

namespace TurmasBridge\Activation;

final class Activation_Operation_State {
	public const PENDING = 'PENDING';
	public const IN_PROGRESS = 'IN_PROGRESS';
	public const SUCCEEDED = 'SUCCEEDED';
	public const FAILED = 'FAILED';
	public const RECONCILIATION_REQUIRED = 'RECONCILIATION_REQUIRED';

	/** @return list<string> */
	public static function values(): array { return array(self::PENDING, self::IN_PROGRESS, self::SUCCEEDED, self::FAILED, self::RECONCILIATION_REQUIRED); }
	public static function can_transition(string $from, string $to): bool {
		$allowed = array(
			self::PENDING => array(self::IN_PROGRESS, self::FAILED, self::RECONCILIATION_REQUIRED),
			self::IN_PROGRESS => array(self::SUCCEEDED, self::FAILED, self::RECONCILIATION_REQUIRED),
			self::SUCCEEDED => array(),
			self::FAILED => array(),
			self::RECONCILIATION_REQUIRED => array(),
		);
		return in_array($to, $allowed[$from] ?? array(), true);
	}
}
