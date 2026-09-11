<?php

declare(strict_types=1);

namespace TurmasBridge\Admin;

use TurmasBridge\Config\Secret_Provider;

final class Settings_Page {
	private const SLUG = 'turmas-bridge';

	public static function register_page(): void {
		add_options_page('Turmas Bridge', 'Turmas Bridge', 'manage_options', self::SLUG, array(self::class, 'render'));
	}

	public static function handle_submission(): void {
		if (! isset($_POST['turmas_bridge_save_secret'])) {
			return;
		}
		if (! current_user_can('manage_options')) {
			wp_die('Você não tem permissão para configurar a ponte.');
		}
		check_admin_referer('turmas_bridge_save_secret');

		$provider = new Secret_Provider();
		$secret = isset($_POST['turmas_bridge_secret']) ? trim((string) wp_unslash($_POST['turmas_bridge_secret'])) : '';
		if (! $provider->uses_constant() && $secret !== '') {
			$provider->save($secret);
		}

		wp_safe_redirect(add_query_arg(array('page' => self::SLUG, 'updated' => '1'), admin_url('options-general.php')));
		exit;
	}

	public static function render(): void {
		if (! current_user_can('manage_options')) {
			return;
		}
		$provider = new Secret_Provider();
		?>
		<div class="wrap">
			<h1>Turmas Bridge</h1>
			<p>Configure a chave compartilhada usada apenas para autenticação servidor a servidor.</p>
			<?php if ($provider->uses_constant()) : ?>
				<p><strong>Chave fornecida pelo ambiente.</strong> A constante de ambiente tem precedência e não é exibida nesta tela.</p>
			<?php elseif ($provider->is_configured()) : ?>
				<p><strong>Chave configurada.</strong> Informe outra chave abaixo somente para substituí-la.</p>
			<?php endif; ?>
			<form method="post">
				<?php wp_nonce_field('turmas_bridge_save_secret'); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="turmas_bridge_secret">Chave compartilhada</label></th>
						<td><input name="turmas_bridge_secret" id="turmas_bridge_secret" type="password" class="regular-text" autocomplete="new-password" value="" /> <p class="description">A chave nunca é exibida novamente após ser salva.</p></td>
					</tr>
				</table>
				<?php submit_button('Salvar chave', 'primary', 'turmas_bridge_save_secret', false); ?>
			</form>
		</div>
		<?php
	}
}
