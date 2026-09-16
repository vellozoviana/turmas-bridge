<?php

declare(strict_types=1);

namespace TurmasBridge\Auth;

final class Nonce_Store {
	public const TTL_SECONDS = 300;
	public const CLEANUP_HOOK = 'turmas_bridge_nonce_cleanup';
	private const OPTION_PREFIX = 'turmas_bridge_nonce_';
	/** @var callable():int */
	private $clock;

	public function __construct(?callable $clock = null) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	public function claim(string $nonce, int $expires_at): bool {
		if ($expires_at <= (int) call_user_func($this->clock)) return false;
		return add_option(self::OPTION_PREFIX . hash('sha256', $nonce), (string) $expires_at, '', false);
	}

	public static function schedule_cleanup(): void {
		if (! wp_next_scheduled(self::CLEANUP_HOOK)) wp_schedule_event(time() + 3600, 'daily', self::CLEANUP_HOOK);
	}

	public static function cleanup_expired(): void {
		global $wpdb;
		$pattern = $wpdb->esc_like(self::OPTION_PREFIX) . '%';
		$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d", $pattern, time()));
	}
}
