<?php
declare(strict_types=1);
namespace TurmasBridge\Reconciliation;
final class Reconciliation_Attempt_State {
	public const PENDING = 'PENDING'; public const IN_PROGRESS = 'IN_PROGRESS'; public const CONFIRMED_SUCCESS = 'CONFIRMED_SUCCESS'; public const INCONCLUSIVE = 'INCONCLUSIVE'; public const TECHNICAL_FAILURE = 'TECHNICAL_FAILURE';
	/** @return list<string> */ public static function terminal(): array { return array(self::CONFIRMED_SUCCESS, self::INCONCLUSIVE, self::TECHNICAL_FAILURE); }
}
