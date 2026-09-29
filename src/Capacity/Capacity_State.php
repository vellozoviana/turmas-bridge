<?php
declare(strict_types=1);
namespace TurmasBridge\Capacity;

/** Ledger transitions, independent from initial publication/activation states. */
final class Capacity_State {
	public static function allows(string $from, string $to): bool {
		$edges = array(
			'PENDING' => array('APPLYING', 'BLOCKED_FORM_ACTIVE', 'BLOCKED_BELOW_CONSUMED', 'APPLIED_VERIFIED', 'RECONCILIATION_REQUIRED', 'FAILED'),
			'BLOCKED_FORM_ACTIVE' => array('APPLYING', 'BLOCKED_FORM_ACTIVE', 'BLOCKED_BELOW_CONSUMED', 'APPLIED_VERIFIED', 'RECONCILIATION_REQUIRED', 'FAILED'),
			'BLOCKED_BELOW_CONSUMED' => array('APPLYING', 'BLOCKED_FORM_ACTIVE', 'BLOCKED_BELOW_CONSUMED', 'APPLIED_VERIFIED', 'RECONCILIATION_REQUIRED', 'FAILED'),
			'APPLYING' => array('BLOCKED_FORM_ACTIVE', 'BLOCKED_BELOW_CONSUMED', 'APPLIED_VERIFIED', 'RECONCILIATION_REQUIRED'),
			'RECONCILIATION_REQUIRED' => array('RECONCILIATION_REQUIRED', 'APPLIED_VERIFIED'),
			'APPLIED_VERIFIED' => array('APPLIED_VERIFIED', 'RECONCILIATION_REQUIRED'),
			'FAILED' => array('FAILED', 'APPLIED_VERIFIED', 'RECONCILIATION_REQUIRED', 'BLOCKED_FORM_ACTIVE', 'BLOCKED_BELOW_CONSUMED'),
		);
		return in_array($to, $edges[$from] ?? array(), true);
	}
}
