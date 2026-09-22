<?php

declare(strict_types=1);

namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use TurmasBridge\Activation\Activation_Result;
use TurmasBridge\Activation\Form_Activation_Gateway;
use TurmasBridge\Activation\Form_Activation_Outcome;
use TurmasBridge\Activation\Form_Activation_Service;
use TurmasBridge\Activation\WordPress_Form_Activation_Gateway;
use TurmasBridge\Materialization\Materialization_Store;
use TurmasBridge\Publications\Publication_Status_Provider;
use TurmasBridge\Publications\Post_Activation_Status_Provider;

final class FormActivationServiceTest extends TestCase {
	public function test_happy_path_activates_once_and_requires_post_read(): void {
		$world = new ActivationWorld(); $gateway = new ActivationFakeGateway($world); $service = $this->service($world, $gateway);
		$result = $service->activate('2099:E2F', 10, 'publish-2099:E2F-v1', str_repeat('a', 64));
		self::assertSame(Activation_Result::ACTIVATED, $result->status()); self::assertSame('FORM_ACTIVATED', $result->code()); self::assertSame(1, $gateway->calls); self::assertSame(3, $world->reads); self::assertTrue($world->active);
	}

	public function test_form_missing_is_fail_closed_without_activation(): void {
		$world = new ActivationWorld(); $world->form_exists = false; $gateway = new ActivationFakeGateway($world); $result = $this->service($world, $gateway)->activate('2099:E2F', 10, 'op', str_repeat('b', 64));
		self::assertSame(Activation_Result::RECONCILIATION_REQUIRED, $result->status()); self::assertSame('FORM_NOT_FOUND', $result->code()); self::assertSame(0, $gateway->calls);
	}

	public function test_form_id_mismatch_is_fail_closed(): void {
		$world = new ActivationWorld(); $world->form_id = 11; $gateway = new ActivationFakeGateway($world); $result = $this->service($world, $gateway)->activate('2099:E2F', 10, 'op', str_repeat('c', 64));
		self::assertSame('FORM_ID_MISMATCH', $result->code()); self::assertSame(0, $gateway->calls);
	}

	public function test_non_materialized_publication_is_blocked(): void {
		$world = new ActivationWorld(); $world->effective_state = 'RECONCILIATION_REQUIRED'; $gateway = new ActivationFakeGateway($world); $result = $this->service($world, $gateway)->activate('2099:E2F', 10, 'op', str_repeat('d', 64));
		self::assertSame(Activation_Result::BLOCKED, $result->status()); self::assertSame('PUBLICATION_NOT_MATERIALIZED', $result->code()); self::assertSame(0, $gateway->calls);
	}

	public function test_active_form_without_operation_proof_requires_reconciliation(): void {
		$world = new ActivationWorld(); $world->active = true; $gateway = new ActivationFakeGateway($world); $result = $this->service($world, $gateway)->activate('2099:E2F', 10, 'op', str_repeat('e', 64));
		self::assertSame(Activation_Result::RECONCILIATION_REQUIRED, $result->status()); self::assertSame('FORM_ALREADY_ACTIVE_UNKNOWN', $result->code()); self::assertSame(0, $gateway->calls);
	}

	public function test_form_becoming_active_before_mutation_requires_reconciliation(): void {
		$world = new ActivationWorld(); $gateway = new ActivationFakeGateway($world); $gateway->mode = 'already_active'; $result = $this->service($world, $gateway)->activate('2099:E2F', 10, 'op', str_repeat('e', 64));
		self::assertSame(Activation_Result::RECONCILIATION_REQUIRED, $result->status()); self::assertSame('FORM_ALREADY_ACTIVE_UNKNOWN', $result->code()); self::assertSame(1, $gateway->calls); self::assertTrue($world->active);
	}

	public function test_unhealthy_inventory_blocks_before_mutation(): void {
		$world = new ActivationWorld(); $world->inventory_status = 'BLOCKED'; $gateway = new ActivationFakeGateway($world); $result = $this->service($world, $gateway)->activate('2099:E2F', 10, 'op', str_repeat('f', 64));
		self::assertSame('INVENTORY_NOT_HEALTHY', $result->code()); self::assertSame(0, $gateway->calls);
	}

	public function test_status_unavailable_blocks_before_mutation(): void {
		$world = new ActivationWorld(); $world->status_error = true; $gateway = new ActivationFakeGateway($world); $result = $this->service($world, $gateway)->activate('2099:E2F', 10, 'op', str_repeat('1', 64));
		self::assertSame(Activation_Result::BLOCKED, $result->status()); self::assertSame('STATUS_UNAVAILABLE', $result->code()); self::assertSame(0, $gateway->calls);
	}

	public function test_gateway_failure_before_mutation_is_failed(): void {
		$world = new ActivationWorld(); $gateway = new ActivationFakeGateway($world); $gateway->mode = 'before_failure'; $result = $this->service($world, $gateway)->activate('2099:E2F', 10, 'op', str_repeat('2', 64));
		self::assertSame(Activation_Result::FAILED, $result->status()); self::assertSame('FORM_ACTIVATION_FAILED', $result->code()); self::assertFalse($world->active);
	}

	public function test_lost_response_after_mutation_is_unknown_and_never_retried(): void {
		$world = new ActivationWorld(); $gateway = new ActivationFakeGateway($world); $gateway->mode = 'lost_response'; $result = $this->service($world, $gateway)->activate('2099:E2F', 10, 'op', str_repeat('3', 64));
		self::assertSame(Activation_Result::UNKNOWN, $result->status()); self::assertSame('FORM_ACTIVATION_UNKNOWN', $result->code()); self::assertTrue($world->active); self::assertSame(1, $gateway->calls);
	}

	public function test_activation_not_confirmed_is_not_success(): void {
		$world = new ActivationWorld(); $gateway = new ActivationFakeGateway($world); $gateway->mode = 'no_confirm'; $result = $this->service($world, $gateway)->activate('2099:E2F', 10, 'op', str_repeat('4', 64));
		self::assertSame(Activation_Result::FAILED, $result->status()); self::assertSame('FORM_ACTIVATION_NOT_CONFIRMED', $result->code()); self::assertFalse($world->active);
	}

	public function test_post_activation_inventory_drift_requires_reconciliation(): void {
		$world = new ActivationWorld(); $gateway = new ActivationFakeGateway($world); $world->drift_after_activation = true; $result = $this->service($world, $gateway)->activate('2099:E2F', 10, 'op', str_repeat('5', 64));
		self::assertSame(Activation_Result::RECONCILIATION_REQUIRED, $result->status()); self::assertSame('POST_ACTIVATION_DRIFT', $result->code()); self::assertTrue($world->active); self::assertSame('MUTATION_CONFIRMED', $result->mutation_evidence()->state()); self::assertSame(10, $result->mutation_evidence()->to_array()['form_id']);
	}

	public function test_post_activation_wrong_form_identity_requires_reconciliation(): void {
		$world = new ActivationWorld(); $world->post_form_id = 11; $gateway = new ActivationFakeGateway($world); $result = $this->service($world, $gateway)->activate('2099:E2F', 10, 'op', str_repeat('8', 64));
		self::assertSame(Activation_Result::RECONCILIATION_REQUIRED, $result->status()); self::assertSame('POST_ACTIVATION_DRIFT', $result->code());
	}

	public function test_post_activation_missing_form_requires_reconciliation(): void {
		$world = new ActivationWorld(); $gateway = new ActivationFakeGateway($world); $world->post_form_state = 'missing'; $result = $this->service($world, $gateway)->activate('2099:E2F', 10, 'op', str_repeat('9', 64));
		self::assertSame(Activation_Result::RECONCILIATION_REQUIRED, $result->status()); self::assertSame('POST_ACTIVATION_DRIFT', $result->code());
	}

	public function test_post_activation_binding_drift_requires_reconciliation(): void {
		$world = new ActivationWorld(); $world->post_binding_healthy = false; $gateway = new ActivationFakeGateway($world); $result = $this->service($world, $gateway)->activate('2099:E2F', 10, 'op', str_repeat('a', 64));
		self::assertSame(Activation_Result::RECONCILIATION_REQUIRED, $result->status()); self::assertSame('POST_ACTIVATION_DRIFT', $result->code());
	}

	public function test_post_activation_capacity_drift_requires_reconciliation(): void {
		$world = new ActivationWorld(); $world->post_capacity = 4; $gateway = new ActivationFakeGateway($world); $result = $this->service($world, $gateway)->activate('2099:E2F', 10, 'op', str_repeat('b', 64));
		self::assertSame(Activation_Result::RECONCILIATION_REQUIRED, $result->status()); self::assertSame('POST_ACTIVATION_DRIFT', $result->code());
	}

	public function test_legitimate_post_activation_consumption_change_is_not_structural_drift(): void {
		$world = new ActivationWorld(); $world->post_consumed = 1; $gateway = new ActivationFakeGateway($world); $result = $this->service($world, $gateway)->activate('2099:E2F', 10, 'op', str_repeat('d', 64));
		self::assertSame(Activation_Result::ACTIVATED, $result->status()); self::assertSame('FORM_ACTIVATED', $result->code());
	}

	public function test_post_activation_reader_failure_requires_reconciliation(): void {
		$world = new ActivationWorld(); $world->post_status_error = true; $gateway = new ActivationFakeGateway($world); $result = $this->service($world, $gateway)->activate('2099:E2F', 10, 'op', str_repeat('c', 64));
		self::assertSame(Activation_Result::UNKNOWN, $result->status()); self::assertSame('POST_ACTIVATION_STATUS_UNAVAILABLE', $result->code()); self::assertSame('OUTCOME_UNCERTAIN', $result->mutation_evidence()->state());
	}

	public function test_lock_unavailable_prevents_activation(): void {
		$world = new ActivationWorld(); $gateway = new ActivationFakeGateway($world); $lock = new ActivationLockStore(); $lock->available = false; $result = (new Form_Activation_Service(new ActivationStatusProvider($world), $gateway, $lock))->activate('2099:E2F', 10, 'op', str_repeat('6', 64));
		self::assertSame('LOCK_UNAVAILABLE', $result->code()); self::assertSame(0, $gateway->calls);
	}

	public function test_gateway_availability_is_checked(): void {
		$world = new ActivationWorld(); $gateway = new ActivationFakeGateway($world); $gateway->available = false; $result = $this->service($world, $gateway)->activate('2099:E2F', 10, 'op', str_repeat('7', 64));
		self::assertSame('GRAVITY_FORMS_UNAVAILABLE', $result->code()); self::assertSame(0, $gateway->calls);
	}

	#[RunInSeparateProcess]
	public function test_wordpress_gateway_updates_only_active_flag_and_confirms(): void {
		require_once __DIR__ . '/../stubs/gravityforms.php'; \GFAPI::reset();
		\GFAPI::$form = array('id' => 10, 'title' => 'LAB', 'is_active' => false); \GFAPI::$update_property_result = 1;
		$outcome = (new WordPress_Form_Activation_Gateway())->activate(10);
		self::assertInstanceOf(Form_Activation_Outcome::class, $outcome); self::assertSame('ACTIVE', $outcome->after_state()); self::assertTrue($outcome->verification_complete()); self::assertCount(1, \GFAPI::$updated_properties); self::assertSame(array('form_id' => 10, 'property' => 'is_active', 'value' => 1), \GFAPI::$updated_properties[0]); self::assertSame('LAB', \GFAPI::$form['title']); self::assertTrue(\GFAPI::$form['is_active']);
	}

	#[RunInSeparateProcess]
	public function test_wordpress_gateway_maps_update_failure_as_unknown_mutation(): void {
		require_once __DIR__ . '/../stubs/gravityforms.php'; \GFAPI::reset();
		\GFAPI::$form = array('id' => 10, 'is_active' => false); \GFAPI::$update_property_result = 0;
		$result = (new WordPress_Form_Activation_Gateway())->activate(10);
		self::assertInstanceOf(\WP_Error::class, $result); self::assertTrue((bool) ($result->get_error_data()['mutation_attempted'] ?? false)); self::assertSame('turmas_bridge_form_activation_unknown', $result->get_error_code());
	}

	public function test_b2_primitive_is_not_wired_directly_to_plugin_bootstrap(): void {
		$plugin = (string) file_get_contents(__DIR__ . '/../../src/Plugin.php'); $controller = (string) file_get_contents(__DIR__ . '/../../src/Api/Bridge_Controller.php');
		self::assertStringNotContainsString('Form_Activation_Service', $plugin); self::assertStringContainsString('Remote_Activation_Command_Service', $controller);
	}

	public function test_existing_materialization_and_status_contracts_are_not_wired_to_activation(): void {
		$controller = (string) file_get_contents(__DIR__ . '/../../src/Publications/Publication_Controller.php');
		self::assertStringNotContainsString('Form_Activation_Service', $controller); self::assertStringNotContainsString('activate(', $controller);
	}

	private function service(ActivationWorld $world, ActivationFakeGateway $gateway): Form_Activation_Service { return new Form_Activation_Service(new ActivationStatusProvider($world), $gateway, new ActivationLockStore()); }
}

final class ActivationWorld {
	public bool $active = false; public bool $form_exists = true; public int $form_id = 10; public string $effective_state = 'MATERIALIZED'; public string $inventory_status = 'READY'; public bool $status_error = false; public bool $post_status_error = false; public bool $drift_after_activation = false; public int $post_form_id = 10; public string $post_form_state = 'active'; public bool $post_binding_healthy = true; public int $post_capacity = 5; public int $post_consumed = 0; public int $reads = 0;
}

final class ActivationStatusProvider implements Publication_Status_Provider, Post_Activation_Status_Provider {
	public function __construct(private ActivationWorld $world) {}
	public function read(string $publication_key): array|\WP_Error {
		$this->world->reads++; if ($this->world->status_error) return new \WP_Error('transport_error', 'status unavailable'); if (! $this->world->form_exists) return array('publication_key' => $publication_key, 'effective_state' => 'MATERIALIZED', 'form_id' => $this->world->form_id, 'form_state' => 'missing', 'inventory' => array('status' => 'READY', 'resources' => array()));
		$drift = $this->world->drift_after_activation && $this->world->active && $this->world->reads > 2;
		return array('publication_key' => $publication_key, 'effective_state' => $this->world->active ? 'RECONCILIATION_REQUIRED' : $this->world->effective_state, 'form_id' => $this->world->form_id, 'form_state' => $this->world->active ? 'active' : 'inactive', 'inventory' => array('status' => $drift ? 'BLOCKED' : $this->world->inventory_status, 'resources' => array(array('resource_id' => 11, 'capacity' => 5, 'consumed' => 0, 'healthy' => true), array('resource_id' => 12, 'capacity' => 4, 'consumed' => 0, 'healthy' => true))));
	}
	public function read_post_activation(string $publication_key): array|\WP_Error {
		$this->world->reads++; if ($this->world->post_status_error) return new \WP_Error('transport_error', 'status unavailable'); if ($this->world->post_form_state === 'missing') return array('publication_key' => $publication_key, 'effective_state' => 'RECONCILIATION_REQUIRED', 'form_id' => $this->world->post_form_id, 'form_state' => 'missing', 'inventory' => array('status' => 'READY', 'resources' => array()));
		$healthy = $this->world->active && $this->world->post_form_state === 'active' && $this->world->post_binding_healthy && $this->world->post_capacity === 5 && $this->world->post_consumed <= $this->world->post_capacity && ! $this->world->drift_after_activation;
		return array('publication_key' => $publication_key, 'effective_state' => $healthy ? 'POST_ACTIVATION_VERIFIED' : 'RECONCILIATION_REQUIRED', 'form_id' => $this->world->post_form_id, 'form_state' => $this->world->post_form_state, 'inventory' => array('status' => $healthy ? 'READY' : 'BLOCKED', 'resources' => array(array('resource_id' => 11, 'capacity' => $this->world->post_capacity, 'consumed' => $this->world->post_consumed, 'healthy' => $healthy))));
	}
}

final class ActivationFakeGateway implements Form_Activation_Gateway {
	public bool $available = true; public int $calls = 0; public string $mode = 'success';
	public function __construct(private ActivationWorld $world) {}
	public function is_available(): bool { return $this->available; }
	public function read(int $form_id): ?array { return $this->world->form_exists ? array('id' => $form_id, 'is_active' => $this->world->active) : null; }
	public function activate(int $form_id): Form_Activation_Outcome|\WP_Error { $this->calls++; if ($this->mode === 'before_failure') return new \WP_Error('failed', 'before', array('mutation_attempted' => false)); if ($this->mode === 'lost_response') { $this->world->active = true; return new \WP_Error('unknown', 'lost', array('mutation_attempted' => true)); } if ($this->mode === 'no_confirm') return new Form_Activation_Outcome('INACTIVE', true, 'INACTIVE', true, 'FORM_ACTIVATION_NOT_CONFIRMED'); if ($this->mode === 'already_active') { $this->world->active = true; return new Form_Activation_Outcome('ACTIVE', false, 'ACTIVE', true, 'FORM_ALREADY_ACTIVE'); } $this->world->active = true; return new Form_Activation_Outcome('INACTIVE', true, 'ACTIVE', true); }
}

final class ActivationLockStore implements Materialization_Store {
	public bool $available = true;
	public function find(string $publication_key): ?array { return null; }
	public function reserve(string $publication_key, int $template_id, string $payload_hash): bool { return false; }
	public function materialized(string $publication_key, int $form_id, array $field_ids): bool { return false; }
	public function failed(string $publication_key, ?int $form_id, string $error_code): bool { return false; }
	public function choice_fingerprint(string $publication_key, string $fingerprint): bool { return false; }
	public function acquire_choice_lock(string $publication_key): bool { return $this->available; }
	public function release_choice_lock(string $publication_key): void {}
}
