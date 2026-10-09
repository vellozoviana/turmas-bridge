<?php

declare(strict_types=1);

namespace TurmasBridge\Inventory;

/** Explicit allowlist for compatibility-sensitive GP Inventory internals. */
final class GP_Inventory_Compatibility_Policy {
	public const VALIDATED_VERSIONS = array('1.0.29', '1.0.32');

	public function __construct(private ?string $version_override = null, private bool $read_runtime = true) {}

	public function runtime_version(): ?string {
		if (! $this->read_runtime) return $this->version_override;
		return defined('GP_INVENTORY_VERSION') && is_string(GP_INVENTORY_VERSION) ? GP_INVENTORY_VERSION : null;
	}

	public function is_supported(): bool {
		$version = $this->runtime_version();
		return $version !== null && in_array($version, self::VALIDATED_VERSIONS, true);
	}
}
