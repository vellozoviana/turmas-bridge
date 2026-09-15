<?php

declare(strict_types=1);

namespace TurmasBridge\Materialization;

final class Template_Config {
	public const OPTION = 'turmas_bridge_template_id';
	public const CONSTANT = 'TURMAS_BRIDGE_TEMPLATE_ID';

	public function id(): int {
		$value = defined(self::CONSTANT) ? constant(self::CONSTANT) : get_option(self::OPTION, 0);
		return absint($value);
	}
}
