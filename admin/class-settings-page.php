<?php
/**
 * Instellingenpagina met tabbladen.
 *
 * @package StagingSafety\Admin
 */

namespace StagingSafety\Admin;

use StagingSafety\Environment;
use StagingSafety\Guards\Cron_Guard;
use StagingSafety\Logger;
use StagingSafety\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Per tabblad één formulier. Zo kun je nooit per ongeluk de instellingen van
 * een ander onderdeel overschrijven.
 */
class Settings_Page {

	/**
	 * Actief tabblad.
	 *
	 * @var string
	 */
	private $tab;

	/**
	 * Constructor.
	 *
	 * @param string $tab Tabblad.
	 */
	public function __construct( $tab ) {
		$this->tab = $tab;
	}

	/**
	 * Uitvoeren.
	 */
	public function render() {
		$tabs = array(
			'http'    => __( 'Uitgaande requests', 'staging-safety' ),
			'mail'    => __( 'E-mail', 'staging-safety' ),
			'cron'    => __( 'Cronjobs', 'staging-safety' ),
			'general' => __( 'Algemeen', 'staging-safety' ),
		);

		?>
		<div class="wrap staging-safety">
			<h1><?php esc_html_e( 'Staging Safety — instellingen', 'staging-safety' ); ?></h1>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a class="nav-tab <?php echo $slug === $this->tab ? 'nav-tab-active' : ''; ?>"
					   href="<?php echo esc_url( admin_url( 'admin.php?page=staging-safety-settings&tab=' . $slug ) ); ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=staging-safety-settings&tab=' . $this->tab ) ); ?>">
				<?php wp_nonce_field( 'staging_safety_save_settings' ); ?>
				<input type="hidden" name="staging_safety_action" value="save_settings">
				<input type="hidden" name="tab" value="<?php echo esc_attr( $this->tab ); ?>">

				<?php
				switch ( $this->tab ) {
					case 'mail':
						$this->mail_tab();
						break;
					case 'cron':
						$this->cron_tab();
						break;
					case 'general':
						$this->general_tab();
						break;
					default:
						$this->http_tab();
				}
				?>

				<?php submit_button( __( 'Opslaan', 'staging-safety' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * De drie standen als radioknoppen.
	 *
	 * @param string $current Huidige stand.
	 * @param string $what    Waar het over gaat, voor de uitleg.
	 */
	private function mode_field( $current, $what ) {
		$options = array(
			'off'     => array(
				__( 'Uit', 'staging-safety' ),
				__( 'Doe niets. Geen logboek, geen blokkade.', 'staging-safety' ),
			),
			'monitor' => array(
				__( 'Meekijken', 'staging-safety' ),
				sprintf(
					/* translators: %s: waar het onderdeel over gaat */
					__( 'Log wat er gebeurt, maar houd niets tegen. Handig om eerst te zien wat %s eigenlijk doet.', 'staging-safety' ),
					$what
				),
			),
			'block'   => array(
				__( 'Blokkeren', 'staging-safety' ),
				__( 'Log én houd tegen volgens de regels hieronder.', 'staging-safety' ),
			),
		);

		?>
		<fieldset class="ss-modes-field">
			<?php foreach ( $options as $value => $option ) : ?>
				<label class="ss-mode-option">
					<input type="radio" name="ss[mode]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current, $value ); ?>>
					<span class="ss-mode-name"><?php echo esc_html( $option[0] ); ?></span>
					<span class="ss-mode-desc"><?php echo esc_html( $option[1] ); ?></span>
				</label>
			<?php endforeach; ?>
		</fieldset>
		<?php
	}

	/**
	 * Tabblad uitgaande requests.
	 */
	private function http_tab() {
		$http = (array) Settings::get( 'http', array() );

		?>
		<h2><?php esc_html_e( 'Uitgaande requests', 'staging-safety' ); ?></h2>
		<p class="ss-muted">
			<?php esc_html_e( 'Alles wat de site via WordPress naar buiten stuurt: API-koppelingen, licentiechecks, webhooks, updatecontroles.', 'staging-safety' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Stand', 'staging-safety' ); ?></th>
				<td><?php $this->mode_field( $http['mode'] ?? 'off', __( 'deze site naar buiten doet', 'staging-safety' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Grondhouding', 'staging-safety' ); ?></th>
				<td>
					<label>
						<input type="radio" name="ss[policy]" value="whitelist" <?php checked( $http['policy'] ?? 'whitelist', 'whitelist' ); ?>>
						<?php esc_html_e( 'Alles dicht, behalve wat op de witte lijst staat', 'staging-safety' ); ?>
					</label><br>
					<label>
						<input type="radio" name="ss[policy]" value="blacklist" <?php checked( $http['policy'] ?? 'whitelist', 'blacklist' ); ?>>
						<?php esc_html_e( 'Alles open, behalve wat op de zwarte lijst staat', 'staging-safety' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Begin bij een onbekende site met "alles open" en de stand op meekijken. Zodra je weet wat de site nodig heeft kun je omschakelen naar "alles dicht".', 'staging-safety' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ss-allow"><?php esc_html_e( 'Witte lijst', 'staging-safety' ); ?></label></th>
				<td>
					<textarea id="ss-allow" name="ss[allow]" rows="8" class="large-text code"><?php echo esc_textarea( Settings::array_to_lines( $http['allow'] ?? array() ) ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'Eén host per regel. Wildcard mag: *.example.com dekt example.com en alle subdomeinen. Laat *.wordpress.org staan, anders werken plugin-updates niet meer.', 'staging-safety' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ss-deny"><?php esc_html_e( 'Zwarte lijst', 'staging-safety' ); ?></label></th>
				<td>
					<textarea id="ss-deny" name="ss[deny]" rows="5" class="large-text code"><?php echo esc_textarea( Settings::array_to_lines( $http['deny'] ?? array() ) ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'Deze hosts worden altijd tegengehouden, ook als ze ook op de witte lijst staan.', 'staging-safety' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Per plugin', 'staging-safety' ); ?></th>
				<td><?php $this->plugin_rules( (array) ( $http['plugin_rules'] ?? array() ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Logboek', 'staging-safety' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="ss[log_allowed]" value="1" <?php checked( ! empty( $http['log_allowed'] ) ); ?>>
						<?php esc_html_e( 'Ook doorgelaten requests loggen', 'staging-safety' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Aan laten staan zolang je in kaart brengt wat de site doet. Op een drukke site levert dit veel regels op; zet het daarna uit.', 'staging-safety' ); ?></p>
				</td>
			</tr>
		</table>

		<div class="ss-note">
			<strong><?php esc_html_e( 'Volgorde van beoordelen', 'staging-safety' ); ?></strong>
			<ol>
				<li><?php esc_html_e( 'de site zelf en localhost — altijd toegestaan', 'staging-safety' ); ?></li>
				<li><?php esc_html_e( 'zwarte lijst', 'staging-safety' ); ?></li>
				<li><?php esc_html_e( 'plugin op blokkeren', 'staging-safety' ); ?></li>
				<li><?php esc_html_e( 'witte lijst', 'staging-safety' ); ?></li>
				<li><?php esc_html_e( 'plugin op toestaan', 'staging-safety' ); ?></li>
				<li><?php esc_html_e( 'de grondhouding', 'staging-safety' ); ?></li>
			</ol>
		</div>
		<?php
	}

	/**
	 * Lijstje met actieve plugins en een regel per plugin.
	 *
	 * @param array $rules Huidige regels.
	 */
	private function plugin_rules( array $rules ) {
		$plugins = $this->active_plugins();
		$sources = Logger::known_sources();

		// Plugins die al eens iets deden bovenaan: die zijn relevant.
		uksort(
			$plugins,
			static function ( $a, $b ) use ( $sources ) {
				$sa = in_array( $a, $sources, true ) ? 0 : 1;
				$sb = in_array( $b, $sources, true ) ? 0 : 1;

				return $sa === $sb ? strcasecmp( $a, $b ) : $sa - $sb;
			}
		);

		?>
		<div class="ss-plugin-rules">
			<table class="widefat striped">
				<thead>
				<tr>
					<th><?php esc_html_e( 'Plugin', 'staging-safety' ); ?></th>
					<th><?php esc_html_e( 'Standaard', 'staging-safety' ); ?></th>
					<th><?php esc_html_e( 'Altijd toestaan', 'staging-safety' ); ?></th>
					<th><?php esc_html_e( 'Altijd blokkeren', 'staging-safety' ); ?></th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ( $plugins as $slug => $name ) : ?>
					<?php $rule = $rules[ $slug ] ?? ''; ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $name ); ?></strong>
							<?php if ( in_array( $slug, $sources, true ) ) : ?>
								<span class="ss-tag"><?php esc_html_e( 'actief in logboek', 'staging-safety' ); ?></span>
							<?php endif; ?>
							<br><code><?php echo esc_html( $slug ); ?></code>
						</td>
						<td><input type="radio" name="ss[plugin_rules][<?php echo esc_attr( $slug ); ?>]" value="" <?php checked( $rule, '' ); ?>></td>
						<td><input type="radio" name="ss[plugin_rules][<?php echo esc_attr( $slug ); ?>]" value="allow" <?php checked( $rule, 'allow' ); ?>></td>
						<td><input type="radio" name="ss[plugin_rules][<?php echo esc_attr( $slug ); ?>]" value="deny" <?php checked( $rule, 'deny' ); ?>></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Actieve plugins als slug => naam.
	 *
	 * @return array
	 */
	private function active_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$out    = array();
		$active = (array) get_option( 'active_plugins', array() );

		foreach ( get_plugins() as $file => $data ) {
			if ( ! in_array( $file, $active, true ) ) {
				continue;
			}

			$slug = dirname( $file );

			if ( '.' === $slug || '' === $slug ) {
				continue;
			}

			$out[ $slug ] = $data['Name'];
		}

		return $out;
	}

	/**
	 * Tabblad e-mail.
	 */
	private function mail_tab() {
		$mail = (array) Settings::get( 'mail', array() );

		?>
		<h2><?php esc_html_e( 'E-mail', 'staging-safety' ); ?></h2>
		<p class="ss-muted">
			<?php esc_html_e( 'Alles wat via wp_mail verstuurd wordt: bestelbevestigingen, formulieren, wachtwoordherstel, notificaties.', 'staging-safety' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Stand', 'staging-safety' ); ?></th>
				<td><?php $this->mode_field( $mail['mode'] ?? 'off', __( 'de site aan mail verstuurt', 'staging-safety' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Wat er met mail gebeurt', 'staging-safety' ); ?></th>
				<td>
					<?php
					$strategies = array(
						'redirect'      => array(
							__( 'Omleiden naar een testadres', 'staging-safety' ),
							__( 'Alle mail komt bij jou terecht, met de oorspronkelijke ontvangers in de tekst. Meestal de beste keuze.', 'staging-safety' ),
						),
						'block'         => array(
							__( 'Alles tegenhouden', 'staging-safety' ),
							__( 'Er gaat niets weg. De verzendende plugin krijgt te horen dat het gelukt is, zodat een bestelling niet vastloopt.', 'staging-safety' ),
						),
						'allow_domains' => array(
							__( 'Alleen naar bepaalde adressen of domeinen', 'staging-safety' ),
							__( 'Bijvoorbeeld alleen je eigen bureau-domein. Ontvangers daarbuiten worden uit de mail gehaald.', 'staging-safety' ),
						),
						'allow'         => array(
							__( 'Alles gewoon versturen', 'staging-safety' ),
							__( 'Alleen loggen. Gebruik dit uitsluitend als je zeker weet dat het mag.', 'staging-safety' ),
						),
					);

					foreach ( $strategies as $value => $option ) :
						?>
						<label class="ss-mode-option">
							<input type="radio" name="ss[strategy]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $mail['strategy'] ?? 'redirect', $value ); ?>>
							<span class="ss-mode-name"><?php echo esc_html( $option[0] ); ?></span>
							<span class="ss-mode-desc"><?php echo esc_html( $option[1] ); ?></span>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ss-redirect"><?php esc_html_e( 'Testadres(sen)', 'staging-safety' ); ?></label></th>
				<td>
					<textarea id="ss-redirect" name="ss[redirect_to]" rows="3" class="large-text code"><?php echo esc_textarea( Settings::array_to_lines( $mail['redirect_to'] ?? array() ) ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'Eén adres per regel. Staat hier niets, dan wordt alle mail tegengehouden — we vallen bewust niet terug op het beheerdersadres, want dat is op een kopie van productie vaak het adres van de klant.', 'staging-safety' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ss-domains"><?php esc_html_e( 'Toegestane ontvangers', 'staging-safety' ); ?></label></th>
				<td>
					<textarea id="ss-domains" name="ss[allow_domains]" rows="4" class="large-text code"><?php echo esc_textarea( Settings::array_to_lines( $mail['allow_domains'] ?? array() ) ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'Alleen van toepassing bij de derde optie. Een heel adres (piet@bureau.nl) of een domein (bureau.nl of *.bureau.nl).', 'staging-safety' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ss-prefix"><?php esc_html_e( 'Voorvoegsel onderwerp', 'staging-safety' ); ?></label></th>
				<td>
					<input type="text" id="ss-prefix" name="ss[subject_prefix]" class="regular-text" value="<?php echo esc_attr( $mail['subject_prefix'] ?? '[STAGING]' ); ?>">
					<p class="description"><?php esc_html_e( 'Komt voor het onderwerp van omgeleide mail te staan. Laat leeg om niets toe te voegen.', 'staging-safety' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Tabblad cronjobs.
	 */
	private function cron_tab() {
		$cron    = (array) Settings::get( 'cron', array() );
		$blocked = (array) ( $cron['blocked_hooks'] ?? array() );
		$hooks   = Cron_Guard::scheduled_hooks();

		?>
		<h2><?php esc_html_e( 'Cronjobs', 'staging-safety' ); ?></h2>
		<p class="ss-muted">
			<?php esc_html_e( 'Achtergrondtaken die vanzelf draaien. Een geblokkeerde taak blijft ingepland staan maar wordt niet uitgevoerd; zet je de blokkade weer uit, dan pakt hij zijn ritme vanzelf op.', 'staging-safety' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Stand', 'staging-safety' ); ?></th>
				<td><?php $this->mode_field( $cron['mode'] ?? 'off', __( 'er op de achtergrond draait', 'staging-safety' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Nieuwe taken', 'staging-safety' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="ss[block_new_schedules]" value="1" <?php checked( ! empty( $cron['block_new_schedules'] ) ); ?>>
						<?php esc_html_e( 'Voorkom ook dat geblokkeerde taken opnieuw ingepland worden', 'staging-safety' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<h3><?php esc_html_e( 'Welke taken blokkeren', 'staging-safety' ); ?></h3>

		<?php if ( ! $hooks ) : ?>
			<p class="ss-muted"><?php esc_html_e( 'Er staan op dit moment geen taken ingepland.', 'staging-safety' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<p>
			<button type="button" class="button button-small" id="ss-select-risky"><?php esc_html_e( 'Alle risicovolle taken aanvinken', 'staging-safety' ); ?></button>
			<button type="button" class="button button-small" id="ss-select-none"><?php esc_html_e( 'Alles uitvinken', 'staging-safety' ); ?></button>
		</p>

		<table class="widefat striped ss-cron">
			<thead>
			<tr>
				<th class="check-column"></th>
				<th><?php esc_html_e( 'Taak', 'staging-safety' ); ?></th>
				<th><?php esc_html_e( 'Herhaling', 'staging-safety' ); ?></th>
				<th><?php esc_html_e( 'Eerstvolgend', 'staging-safety' ); ?></th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $hooks as $hook => $info ) : ?>
				<tr class="<?php echo $info['risky'] ? 'ss-risky' : ''; ?>">
					<td class="check-column">
						<input type="checkbox"
							   name="ss[blocked_hooks][]"
							   value="<?php echo esc_attr( $hook ); ?>"
							   data-risky="<?php echo $info['risky'] ? '1' : '0'; ?>"
							<?php checked( in_array( $hook, $blocked, true ) ); ?>>
					</td>
					<td>
						<code><?php echo esc_html( $hook ); ?></code>
						<?php if ( $info['risky'] ) : ?>
							<span class="ss-tag ss-tag-warn"><?php esc_html_e( 'risicovol', 'staging-safety' ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $info['schedule'] ? $info['schedule'] : __( 'eenmalig', 'staging-safety' ) ); ?></td>
					<td>
						<?php
						echo esc_html(
							$info['next']
								? wp_date( 'j M Y H:i', $info['next'] )
								: '—'
						);
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php
		// Taken die geblokkeerd zijn maar niet meer ingepland staan, zodat de
		// instelling niet stilletjes verdwijnt bij het opslaan.
		$orphans = array_diff( $blocked, array_keys( $hooks ) );

		if ( $orphans ) :
			?>
			<h4><?php esc_html_e( 'Geblokkeerd, maar op dit moment niet ingepland', 'staging-safety' ); ?></h4>
			<ul class="ss-orphans">
				<?php foreach ( $orphans as $hook ) : ?>
					<li>
						<label>
							<input type="checkbox" name="ss[blocked_hooks][]" value="<?php echo esc_attr( $hook ); ?>" checked>
							<code><?php echo esc_html( $hook ); ?></code>
						</label>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php
		endif;
	}

	/**
	 * Tabblad algemeen.
	 */
	private function general_tab() {
		$indicator = (array) Settings::get( 'indicator', array() );
		$log       = (array) Settings::get( 'log', array() );

		?>
		<h2><?php esc_html_e( 'Omgeving', 'staging-safety' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Vastgesteld als', 'staging-safety' ); ?></th>
				<td>
					<p>
						<strong><?php echo esc_html( Environment::type() ); ?></strong> —
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: bron */
								__( 'op basis van %s', 'staging-safety' ),
								Environment::source()
							)
						);
						?>
					</p>
					<?php if ( ! Environment::is_staging() ) : ?>
						<p><?php esc_html_e( 'Zet deze regel in wp-config.php van de stagingserver:', 'staging-safety' ); ?></p>
						<p><code>define( 'STAGING_SAFETY_ENV', 'staging' );</code></p>
					<?php endif; ?>
					<p class="description">
						<?php esc_html_e( 'Dit staat bewust niet in de instellingen maar in wp-config.php. Een instelling zit in de database, en die wordt tussen staging en productie heen en weer gekopieerd — dan blokkeert de plugin op de verkeerde plek, of juist nergens meer.', 'staging-safety' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Staging-indicator', 'staging-safety' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Tonen', 'staging-safety' ); ?></th>
				<td>
					<label><input type="checkbox" name="ss[indicator][enabled]" value="1" <?php checked( ! empty( $indicator['enabled'] ) ); ?>> <?php esc_html_e( 'Balk en admin bar-item tonen', 'staging-safety' ); ?></label><br>
					<label><input type="checkbox" name="ss[indicator][frontend]" value="1" <?php checked( ! empty( $indicator['frontend'] ) ); ?>> <?php esc_html_e( 'Ook op de voorkant', 'staging-safety' ); ?></label><br>
					<label><input type="checkbox" name="ss[indicator][login]" value="1" <?php checked( ! empty( $indicator['login'] ) ); ?>> <?php esc_html_e( 'Ook op het inlogscherm', 'staging-safety' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ss-label"><?php esc_html_e( 'Tekst', 'staging-safety' ); ?></label></th>
				<td><input type="text" id="ss-label" name="ss[indicator][label]" class="regular-text" value="<?php echo esc_attr( $indicator['label'] ?? 'STAGING' ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="ss-color"><?php esc_html_e( 'Kleur', 'staging-safety' ); ?></label></th>
				<td><input type="color" id="ss-color" name="ss[indicator][color]" value="<?php echo esc_attr( $indicator['color'] ?? '#d63638' ); ?>"></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Logboek', 'staging-safety' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Bijhouden', 'staging-safety' ); ?></th>
				<td>
					<label><input type="checkbox" name="ss[log][enabled]" value="1" <?php checked( ! empty( $log['enabled'] ) ); ?>> <?php esc_html_e( 'Logboek bijhouden', 'staging-safety' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ss-retention"><?php esc_html_e( 'Bewaren', 'staging-safety' ); ?></label></th>
				<td>
					<input type="number" id="ss-retention" name="ss[log][retention_days]" min="1" max="365" value="<?php echo esc_attr( (int) ( $log['retention_days'] ?? 30 ) ); ?>" class="small-text">
					<?php esc_html_e( 'dagen', 'staging-safety' ); ?>
					<p class="description"><?php esc_html_e( 'Oudere regels worden dagelijks opgeruimd.', 'staging-safety' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}
}
