<?php

declare(strict_types=1);

class WP_Error {
	private string $code;
	private string $message;
	/** @var array<string, mixed> */
	private array $data;

	/** @param array<string, mixed> $data */
	public function __construct(string $code = '', string $message = '', array $data = array()) {
		$this->code = $code;
		$this->message = $message;
		$this->data = $data;
	}

	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	/** @return array<string, mixed> */
	public function get_error_data(): array { return $this->data; }
}

class WP_REST_Request {
	/** @var array<string, string> */
	private array $headers = array();
	/** @var array<string, mixed> */
	private array $query = array();
	/** @var array<string, mixed> */
	private array $params = array();
	private string $body = '';

	public function __construct(private string $method = 'GET', private string $route = '/') {}
	public function get_method(): string { return $this->method; }
	public function set_method(string $method): void { $this->method = $method; }
	public function get_route(): string { return $this->route; }
	public function set_route(string $route): void { $this->route = $route; }
	public function get_header(string $name): string { return $this->headers[strtolower($name)] ?? ''; }
	public function set_header(string $name, string $value): void { $this->headers[strtolower($name)] = $value; }
	/** @return array<string, mixed> */
	public function get_query_params(): array { return $this->query; }
	/** @param array<string, mixed> $query */
	public function set_query_params(array $query): void { $this->query = $query; }
	public function get_body(): string { return $this->body; }
	public function set_body(string $body): void { $this->body = $body; }
	public function get_param(string $key): mixed { return $this->params[$key] ?? null; }
	public function set_param(string $key, mixed $value): void { $this->params[$key] = $value; }
}

class WP_REST_Response {
	public function __construct(private mixed $data = null, private int $status = 200) {}
	public function get_data(): mixed { return $this->data; }
	public function get_status(): int { return $this->status; }
}

class wpdb {
	public string $prefix = 'wp_';
	public string $options = 'wp_options';
	public int $insert_id = 0;
	public function get_charset_collate(): string { return ''; }
	public function prepare(string $query, mixed ...$arguments): string { return $query; }
	public function get_row(string $query, string $output = ''): mixed { return null; }
	public function get_var(string $query): mixed { return 1; }
	public function insert(string $table, array $data): int|false { return 1; }
	public function update(string $table, array $data, array $where): int|false { return 1; }
	public function query(string $query): int|false { $GLOBALS['turmas_bridge_test_database_queries'][] = $query; return 1; }
	public function esc_like(string $text): string { return addcslashes($text, '_%\\'); }
}

function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {}
function register_activation_hook(string $file, callable $callback): void {}
function add_options_page(mixed ...$arguments): void {}
function register_rest_route(string $namespace, string $route, array $arguments): void { $GLOBALS['turmas_bridge_test_routes'][] = array('namespace' => $namespace, 'route' => $route, 'arguments' => $arguments); }
function current_user_can(string $capability): bool { return true; }
function check_admin_referer(string $action): void {}
function wp_die(string $message): void { throw new RuntimeException($message); }
function wp_safe_redirect(string $location): bool { return true; }
function add_query_arg(array $args, string $url = ''): string { return $url; }
function admin_url(string $path = ''): string { return $path; }
function wp_nonce_field(string $action, string $name = '_wpnonce'): void {}
function submit_button(string $text = '', string $type = 'primary', string $name = 'submit', bool $wrap = true): void {}
function esc_html(string $text): string { return $text; }
function sanitize_text_field(string $text): string { return trim(strip_tags($text)); }
function sanitize_key(string $key): string { return strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', $key)); }
function wp_unslash(string $value): string { return stripslashes($value); }
function absint(mixed $value): int { return abs((int) $value); }
function is_ssl(): bool { return (bool) ($GLOBALS['turmas_bridge_test_ssl'] ?? true); }
function wp_get_environment_type(): string { return (string) ($GLOBALS['turmas_bridge_test_environment'] ?? 'production'); }
function get_option(string $option, mixed $default = false): mixed { return $GLOBALS['turmas_bridge_test_options'][$option] ?? $default; }
function add_option(string $option, mixed $value = '', string $deprecated = '', bool|string $autoload = true): bool {
	if ((bool) ($GLOBALS['turmas_bridge_test_add_option_failure'] ?? false) || array_key_exists($option, $GLOBALS['turmas_bridge_test_options'])) return false;
	$GLOBALS['turmas_bridge_test_options'][$option] = $value;
	return true;
}
function update_option(string $option, mixed $value, mixed $autoload = null): bool { $GLOBALS['turmas_bridge_test_options'][$option] = $value; return true; }
function current_time(string $type, bool $gmt = false): string { return '2026-01-01 00:00:00'; }
function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }
function dbDelta(string $query): array { $GLOBALS['turmas_bridge_test_dbdelta'][] = $query; return array(); }
function get_transient(string $key): mixed {
	$entry = $GLOBALS['turmas_bridge_test_transients'][$key] ?? null;
	if (! is_array($entry) || $entry['expires'] < time()) { unset($GLOBALS['turmas_bridge_test_transients'][$key]); return false; }
	return $entry['value'];
}
function set_transient(string $key, mixed $value, int $expiration): bool { $GLOBALS['turmas_bridge_test_transients'][$key] = array('value' => $value, 'expires' => time() + $expiration); return true; }
function wp_next_scheduled(string $hook): int|false { return $GLOBALS['turmas_bridge_test_scheduled'][$hook] ?? false; }
function wp_schedule_event(int $timestamp, string $recurrence, string $hook): bool { $GLOBALS['turmas_bridge_test_scheduled'][$hook] = $timestamp; return true; }
function is_wp_error(mixed $thing): bool { return $thing instanceof WP_Error; }
function wp_generate_uuid4(): string { return '11111111-1111-4111-8111-111111111111'; }
function plugin_dir_path(string $file): string { return dirname($file) . DIRECTORY_SEPARATOR; }
function get_post_type(int $post_id): string|false { return false; }
function get_posts(array $args = array()): array { return array(); }
function get_post_meta(int $post_id, string $key = '', bool $single = false): mixed { return $single ? '' : array(); }
function update_post_meta(int $post_id, string $key, mixed $value): int|bool { return 1; }
function wp_insert_post(array $postarr, bool $wp_error = false): int|WP_Error { return 1; }
function do_action(string $hook, mixed ...$args): void {}
