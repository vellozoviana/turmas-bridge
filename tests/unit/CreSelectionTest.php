<?php

declare(strict_types=1);

namespace TurmasBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurmasBridge\Choices\Cre_Selection_Contract;
use TurmasBridge\Choices\Cre_Submission_Guard;
use TurmasBridge\Choices\Template_Field_Map;
use TurmasBridge\Inventory\Entry_Consumption_Aggregator;

final class CreSelectionTest extends TestCase {
	/** @return array<string,mixed> */
	private function form(): array {
		$fields = array();
		foreach (array('04' => 3, '05' => 4, '11' => 5) as $cre => $id) {
			$cre = (string) $cre;
			$fields[] = array('id' => $id, 'type' => 'select', 'adminLabel' => 'turma_cre_' . $cre, 'isRequired' => true,
				'conditionalLogic' => array('actionType' => 'show', 'logicType' => 'all', 'rules' => array(array('fieldId' => 6, 'operator' => 'is', 'value' => $cre))),
				'gpiInventory' => 'advanced', 'gpiResource' => '19', 'choices' => array(array('text' => 'Turma', 'value' => '2093:E2F:01.01')));
		}
		$fields[] = array('id' => 6, 'type' => 'select', 'adminLabel' => Cre_Selection_Contract::CONTROLLER_LABEL, 'isRequired' => true, 'placeholder' => 'Selecione a CRE',
			'choices' => array(array('text' => 'CRE 04', 'value' => '04'), array('text' => 'CRE 05', 'value' => '05'), array('text' => 'CRE 11', 'value' => '11')));
		return array('id' => 18, 'turmasBridgeCreExclusive' => true, 'fields' => $fields);
	}

	public function test_multi_cre_contract_is_valid_and_old_template_is_rejected(): void {
		$form = $this->form(); $map = (new Template_Field_Map())->resolve($form);
		self::assertNull((new Cre_Selection_Contract())->validate($form, array('04', '05', '11'), $map));
		array_pop($form['fields']);
		self::assertSame(Cre_Selection_Contract::ERROR_CODE, (new Cre_Selection_Contract())->validate($form, array('04', '05', '11'), (new Template_Field_Map())->resolve($form))->get_error_code());
	}

	public function test_single_cre_does_not_require_controller(): void {
		$form = $this->form(); array_pop($form['fields']);
		self::assertNull((new Cre_Selection_Contract())->validate($form, array('04'), (new Template_Field_Map())->resolve($form)));
	}

	/** @dataProvider chosen_cre */
	public function test_each_cre_yields_exactly_one_effective_claim(string $cre, string $input): void {
		$result = Cre_Submission_Guard::inspect($this->form(), array('input_6' => $cre, $input => '2093:E2F:01.01'));
		self::assertTrue($result['valid']);
		self::assertCount(2, $result['post']);
		self::assertSame('2093:E2F:01.01', $result['post'][$input]);
	}

	public static function chosen_cre(): iterable {
		yield 'CRE 04' => array('04', 'input_3');
		yield 'CRE 05' => array('05', 'input_4');
		yield 'CRE 11' => array('11', 'input_5');
	}

	public function test_tampered_multiple_cre_claims_fail_closed_before_inventory(): void {
		$result = Cre_Submission_Guard::inspect($this->form(), array('input_6' => '04', 'input_3' => '2093:E2F:01.01', 'input_4' => '2093:E2F:01.01'));
		self::assertFalse($result['valid']);
		self::assertSame(array('input_6' => '04'), $result['post']);
	}

	public function test_missing_or_invalid_controller_fails_closed(): void {
		foreach (array('', '99') as $cre) {
			$result = Cre_Submission_Guard::inspect($this->form(), array('input_6' => $cre, 'input_3' => '2093:E2F:01.01'));
			self::assertFalse($result['valid']);
			self::assertArrayNotHasKey('input_3', $result['post']);
		}
	}

	public function test_ambiguous_managed_fields_clear_all_claims(): void {
		$form = $this->form(); $form['fields'][] = array('id' => 7, 'type' => 'select', 'adminLabel' => 'turma_cre_04');
		$result = Cre_Submission_Guard::inspect($form, array('input_6' => '04', 'input_3' => '2093:E2F:01.01', 'input_7' => '2093:E2F:01.01'));
		self::assertFalse($result['valid']);
		self::assertSame(array('input_6' => '04'), $result['post']);
	}

	public function test_two_real_entry_rows_model_consumes_two_while_quantity_is_preserved(): void {
		$value = '2093:E2F:01.01';
		$rows = array(array('entry_id' => 1, 'class_choice' => $value, 'consumed_quantity' => 1, 'malformed_quantity_count' => 0), array('entry_id' => 2, 'class_choice' => $value, 'consumed_quantity' => 1, 'malformed_quantity_count' => 0));
		self::assertSame(2, Entry_Consumption_Aggregator::aggregate(array(array('choice_value' => $value, 'rows' => $rows))));
		$rows[0]['consumed_quantity'] = 2;
		self::assertSame(3, Entry_Consumption_Aggregator::aggregate(array(array('choice_value' => $value, 'rows' => $rows))));
	}

	public function test_submission_guard_precedes_gp_inventory_priorities(): void {
		self::assertSame(0, $GLOBALS['turmas_bridge_test_filters']['gform_pre_validation'][0]['priority']);
		self::assertSame(0, $GLOBALS['turmas_bridge_test_filters']['gform_validation'][0]['priority']);
	}
}
