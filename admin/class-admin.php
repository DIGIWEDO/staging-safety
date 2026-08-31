<?php
/**
 * Admin: menu, formulieren en meldingen.
 *
 * @package StagingSafety\Admin
 */

namespace StagingSafety\Admin;

use StagingSafety\Environment;
use StagingSafety\Logger;
use StagingSafety\Plugin;
use StagingSafety\Risk_Scanner;
use StagingSafety\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Alles wat alleen in de beheeromgeving nodig is.
 */
class Admin {

	const SLUG = 'staging-safety';

	/**
	 * Aanhaken.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'handle_forms' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( STAGING_SAFETY_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Menu-items.
	 */
	public function menu() {
		$blocked = $this->blocked_today();
		$title   = __( 'Staging Safety', 'staging-safety' );

		if ( $blocked > 0 ) {
			$title .= ' <span class="update-plugins count-' . (int) $blocked . '"><span class="update-count">' . number_format_i18n( $blocked ) . '</span></span>';
		}

		add_menu_page(
			__( 'Staging Safety', 'staging-safety' ),
			$title,
			Plugin::CAPABILITY,
			self::SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-shield-alt',
			80
		);

		add_submenu_page(
			self::SLUG,
			__( 'Overzicht', 'staging-safety' ),
			__( 'Overzicht', 'staging-safety' ),
			Plugin::CAPABILITY,
			self::SLUG,
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Instellingen', 'staging-safety' ),
			__( 'Instellingen', 'staging-safety' ),
			Plugin::CAPABILITY,
			self::SLUG . '-settings',
			array( $this, 'render_settings' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Logboek', 'staging-safety' ),
			__( 'Logboek', 'staging-safety' ),
			Plugin::CAPABILITY,
			self::SLUG . '-log',
			array( $this, 'render_log' )
		);
	}

	/**
	 * Aantal blokkades van vandaag, voor het bolletje in het menu.
	 *
	 * @return int
	 */
	private function blocked_today() {
		$counts = Logger::counts_since( 24 );
		$total  = 0;

		foreach ( $counts as $key => $value ) {
			if ( false !== strpos( $key, ':blocked' ) ) {
				$total += $value;
			}
		}

		return $total;
	}

	/**
	 * Snelkoppeling op de pluginlijst.
	 *
	 * @param array $links Bestaande links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-settings' ) ) . '">' . esc_html__( 'Instellingen', 'staging-safety' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Stijlen en scripts.
	 *
	 * @param string $hook Huidige pagina.
	 */
	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'staging-safety-admin',
			STAGING_SAFETY_URL . 'assets/css/admin.css',
			array(),
			STAGING_SAFETY_VERSION
		);

		wp_enqueue_script(
			'staging-safety-admin',
			STAGING_SAFETY_URL . 'assets/js/admin.js',
			array(),
			STAGING_SAFETY_VERSION,
			true
		);

		wp_localize_script(
			'staging-safety-admin',
			'stagingSafety',
			array(
				'confirmClear' => __( 'Weet je zeker dat je het hele logboek wilt wissen?', 'staging-safety' ),
			)
		);
	}

	/**
	 * Alle formulieren van de plugin.
	 */
	public function handle_forms() {
		if ( ! isset( $_POST['staging_safety_action'] ) || ! current_user_can( Plugin::CAPABILITY ) ) {
			$this->handle_get_actions();

			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['staging_safety_action'] ) );
		check_admin_referer( 'staging_safety_' . $action );

		switch ( $action ) {
			case 'confirm_staging':
				Environment::confirm();
				$this->redirect( self::SLUG, 'confirmed' );
				break;

			case 'revoke_staging':
				Environment::revoke();
				$this->redirect( self::SLUG, 'revoked' );
				break;

			case 'save_settings':
				$this->save_settings();
				$this->redirect( self::SLUG . '-settings', 'saved', array( 'tab' => $this->current_tab() ) );
				break;

			case 'clear_log':
				Logger::clear();
				$this->redirect( self::SLUG . '-log', 'cleared' );
				break;

			case 'reset_warnings':
				Risk_Scanner::reset_dismissed();
				$this->redirect( self::SLUG, 'warnings-reset' );
				break;
		}
	}

	/**
	 * Kleine acties via een link: waarschuwing wegklikken, host toestaan.
	 */
	private function handle_get_actions() {
		if ( ! isset( $_GET['ss_action'] ) || ! current_user_can( Plugin::CAPABILITY ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_GET['ss_action'] ) );

		if ( 'dismiss_warning' === $action ) {
			check_admin_referer( 'staging_safety_dismiss' );
			$slug = isset( $_GET['slug'] ) ? sanitize_key( wp_unslash( $_GET['slug'] ) ) : '';

			if ( $slug ) {
				Risk_Scanner::dismiss( $slug );
			}

			$this->redirect( self::SLUG, 'dismissed' );
		}

		if ( 'confirm_staging' === $action || 'revoke_staging' === $action ) {
			check_admin_referer( 'staging_safety_env' );

			if ( 'confirm_staging' === $action ) {
				Environment::confirm();
				$this->redirect( self::SLUG, 'confirmed' );
			}

			Environment::revoke();
			$this->redirect( self::SLUG, 'revoked' );
		}

		if ( 'check_update' === $action ) {
			check_admin_referer( 'staging_safety_check_update' );

			( new \StagingSafety\Updater() )->release( true );
			delete_site_transient( 'update_plugins' );

			$this->redirect( self::SLUG . '-settings', 'update-checked', array( 'tab' => 'general' ) );
		}

		if ( 'allow_host' === $action ) {
			check_admin_referer( 'staging_safety_allow_host' );
			$host = isset( $_GET['host'] ) ? sanitize_text_field( wp_unslash( $_GET['host'] ) ) : '';

			if ( $host ) {
				$allow = (array) Settings::get( 'http.allow', array() );

				if ( ! in_array( $host, $allow, true ) ) {
					$allow[] = $host;
					Settings::set_group( 'http', array( 'allow' => array_values( $allow ) ) );
				}
			}

			$this->redirect( self::SLUG . '-log', 'host-allowed' );
		}
	}

	/**
	 * Beveiligde link om de omgeving te bevestigen of in te trekken.
	 *
	 * @param string $action confirm_staging of revoke_staging.
	 * @return string
	 */
	public static function env_url( $action ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'page'      => self::SLUG,
					'ss_action' => $action,
				),
				admin_url( 'admin.php' )
			),
			'staging_safety_env'
		);
	}

	/**
	 * Beveiligde link om nu bij GitHub te kijken.
	 *
	 * @return string
	 */
	public static function check_update_url() {
		return wp_nonce_url(
			add_query_arg(
				array(
					'page'      => self::SLUG . '-settings',
					'tab'       => 'general',
					'ss_action' => 'check_update',
				),
				admin_url( 'admin.php' )
			),
			'staging_safety_check_update'
		);
	}

	/**
	 * Terug naar een pagina met een melding.
	 *
	 * @param string $page   Paginaslug.
	 * @param string $notice Meldingcode.
	 * @param array  $extra  Extra queryargumenten.
	 */
	private function redirect( $page, $notice, array $extra = array() ) {
		$args = array_merge(
			array(
				'page'       => $page,
				'ss_notice'  => $notice,
			),
			$extra
		);

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Welk tabblad is er open?
	 *
	 * @return string
	 */
	public function current_tab() {
		$tabs = array( 'http', 'mail', 'cron', 'general' );
		$tab  = isset( $_REQUEST['tab'] ) ? sanitize_key( wp_unslash( $_REQUEST['tab'] ) ) : 'http'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return in_array( $tab, $tabs, true ) ? $tab : 'http';
	}

	/**
	 * Instellingen opslaan. Per tabblad, zodat je nooit per ongeluk de
	 * instellingen van een ander onderdeel leegmaakt.
	 */
	private function save_settings() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- gedaan in handle_forms().
		$tab   = $this->current_tab();
		$input = isset( $_POST['ss'] ) && is_array( $_POST['ss'] ) ? wp_unslash( $_POST['ss'] ) : array();
		// phpcs:enable

		switch ( $tab ) {
			case 'http':
				Settings::set_group(
					'http',
					array(
						'mode'         => $this->sanitize_mode( $input['mode'] ?? '' ),
						'policy'       => 'blacklist' === ( $input['policy'] ?? '' ) ? 'blacklist' : 'whitelist',
						'allow'        => $this->sanitize_hosts( $input['allow'] ?? '' ),
						'deny'         => $this->sanitize_hosts( $input['deny'] ?? '' ),
						'plugin_rules' => $this->sanitize_plugin_rules( $input['plugin_rules'] ?? array() ),
						'log_allowed'  => ! empty( $input['log_allowed'] ),
					)
				);
				break;

			case 'mail':
				Settings::set_group(
					'mail',
					array(
						'mode'           => $this->sanitize_mode( $input['mode'] ?? '' ),
						'strategy'       => $this->sanitize_strategy( $input['strategy'] ?? '' ),
						'redirect_to'    => $this->sanitize_emails( $input['redirect_to'] ?? '' ),
						'allow_domains'  => Settings::lines_to_array( $input['allow_domains'] ?? '' ),
						'subject_prefix' => sanitize_text_field( $input['subject_prefix'] ?? '' ),
					)
				);
				break;

			case 'cron':
				$hooks = isset( $input['blocked_hooks'] ) ? (array) $input['blocked_hooks'] : array();

				Settings::set_group(
					'cron',
					array(
						'mode'                => $this->sanitize_mode( $input['mode'] ?? '' ),
						'blocked_hooks'       => array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $hooks ) ) ) ),
						'block_new_schedules' => ! empty( $input['block_new_schedules'] ),
					)
				);
				break;

			case 'general':
				Settings::set_group(
					'indicator',
					array(
						'enabled'  => ! empty( $input['indicator']['enabled'] ),
						'label'    => sanitize_text_field( $input['indicator']['label'] ?? 'STAGING' ),
						'color'    => $this->sanitize_color( $input['indicator']['color'] ?? '' ),
						'frontend' => ! empty( $input['indicator']['frontend'] ),
						'login'    => ! empty( $input['indicator']['login'] ),
					)
				);

				Settings::set_group(
					'updates',
					array(
						'enabled' => ! empty( $input['updates']['enabled'] ),
						'repo'    => sanitize_text_field( $input['updates']['repo'] ?? '' ),
					)
				);

				Settings::set_group(
					'log',
					array(
						'enabled'        => ! empty( $input['log']['enabled'] ),
						'retention_days' => max( 1, min( 365, (int) ( $input['log']['retention_days'] ?? 30 ) ) ),
					)
				);

				break;
		}
	}

	/**
	 * Stand controleren.
	 *
	 * @param string $value Invoer.
	 * @return string
	 */
	private function sanitize_mode( $value ) {
		return in_array( $value, array( 'off', 'monitor', 'block' ), true ) ? $value : 'off';
	}

	/**
	 * Mailstrategie controleren.
	 *
	 * @param string $value Invoer.
	 * @return string
	 */
	private function sanitize_strategy( $value ) {
		return in_array( $value, array( 'block', 'redirect', 'allow_domains', 'allow' ), true ) ? $value : 'redirect';
	}

	/**
	 * Hostpatronen opschonen.
	 *
	 * @param string $text Tekstvak.
	 * @return array
	 */
	private function sanitize_hosts( $text ) {
		$out = array();

		foreach ( Settings::lines_to_array( $text ) as $line ) {
			$clean = \StagingSafety\Matcher::normalise_host( $line );

			// De ster overleeft normalise_host niet altijd, dus apart houden.
			if ( 0 === strpos( trim( strtolower( $line ) ), '*.' ) ) {
				$clean = '*.' . \StagingSafety\Matcher::normalise_host( substr( trim( $line ), 2 ) );
			} elseif ( '*' === trim( $line ) ) {
				$clean = '*';
			}

			if ( '' !== $clean && '*.' !== $clean ) {
				$out[] = $clean;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * E-mailadressen opschonen.
	 *
	 * @param string $text Tekstvak.
	 * @return array
	 */
	private function sanitize_emails( $text ) {
		$out = array();

		foreach ( Settings::lines_to_array( $text ) as $line ) {
			$email = sanitize_email( $line );

			if ( $email && is_email( $email ) ) {
				$out[] = $email;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Regels per plugin opschonen.
	 *
	 * @param array $rules Invoer.
	 * @return array
	 */
	private function sanitize_plugin_rules( $rules ) {
		$out = array();

		foreach ( (array) $rules as $slug => $rule ) {
			$slug = sanitize_text_field( $slug );

			if ( '' === $slug || ! in_array( $rule, array( 'allow', 'deny' ), true ) ) {
				continue;
			}

			$out[ $slug ] = $rule;
		}

		return $out;
	}

	/**
	 * Kleurcode controleren.
	 *
	 * @param string $value Invoer.
	 * @return string
	 */
	private function sanitize_color( $value ) {
		$color = sanitize_hex_color( $value );

		return $color ? $color : '#d63638';
	}

	/**
	 * Meldingen bovenaan de beheeromgeving.
	 */
	public function notices() {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			return;
		}

		$this->form_notice();

		if ( Environment::is_locked_to_production() ) {
			$this->notice(
				'error',
				__( 'WordPress meldt dat dit een productieomgeving is, dus Staging Safety blokkeert niets. Is dit tóch een stagingkopie? Zet dan <code>define( \'STAGING_SAFETY_ENV\', \'staging\' );</code> in wp-config.php.', 'staging-safety' )
			);

			return;
		}

		if ( ! Environment::is_staging() ) {
			$this->setup_notice();

			return;
		}

		if ( Plugin::is_paused() ) {
			$pause   = Plugin::pause_info();
			$minutes = max( 1, (int) ceil( ( $pause['until'] - time() ) / MINUTE_IN_SECONDS ) );

			$this->notice(
				'warning',
				sprintf(
					/* translators: 1: aantal minuten, 2: link */
					__( 'De beveiliging staat op pauze: uitgaande requests, mail en cronjobs gaan er nu gewoon doorheen. Nog %1$d minuten. %2$s', 'staging-safety' ),
					$minutes,
					'<a href="' . esc_url( Indicator::pause_url( 0 ) ) . '">' . esc_html__( 'Nu weer aanzetten', 'staging-safety' ) . '</a>'
				)
			);
		}

		if ( 'redirect' === Settings::get( 'mail.strategy' ) && ! Settings::get( 'mail.redirect_to' ) && 'off' !== Settings::get( 'mail.mode' ) ) {
			$this->notice(
				'warning',
				sprintf(
					/* translators: %s: link naar de instellingen */
					__( 'Mail moet omgeleid worden, maar er staat geen testadres ingesteld. Tot die tijd wordt alle mail tegengehouden. %s', 'staging-safety' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-settings&tab=mail' ) ) . '">' . esc_html__( 'Testadres invullen', 'staging-safety' ) . '</a>'
				)
			);
		}
	}

	/**
	 * Melding na het opslaan van een formulier.
	 */
	private function form_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET['ss_notice'] ) ? sanitize_key( wp_unslash( $_GET['ss_notice'] ) ) : '';

		$messages = array(
			'saved'          => __( 'Instellingen opgeslagen.', 'staging-safety' ),
			'confirmed'      => __( 'Bevestigd: deze site geldt vanaf nu als staging.', 'staging-safety' ),
			'revoked'        => __( 'Bevestiging ingetrokken. De plugin blokkeert niets meer.', 'staging-safety' ),
			'cleared'        => __( 'Logboek geleegd.', 'staging-safety' ),
			'dismissed'      => __( 'Waarschuwing weggeklikt.', 'staging-safety' ),
			'warnings-reset' => __( 'Alle waarschuwingen staan weer aan.', 'staging-safety' ),
			'host-allowed'   => __( 'Host op de witte lijst gezet.', 'staging-safety' ),
			'update-checked' => __( 'Opnieuw bij GitHub gekeken.', 'staging-safety' ),
		);

		if ( isset( $messages[ $code ] ) ) {
			$this->notice( 'success', $messages[ $code ] );
		}
	}

	/**
	 * De vraag of dit staging is. Zolang die niet beantwoord is doet de plugin
	 * niets, en dat is met opzet.
	 */
	private function setup_notice() {
		$stale = Environment::stale_confirmation();

		?>
		<div class="notice notice-warning staging-safety-notice">
			<?php if ( $stale ) : ?>
				<p>
					<strong><?php esc_html_e( 'Staging Safety heeft zichzelf uitgezet.', 'staging-safety' ); ?></strong><br>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: domein van de bevestiging, 2: huidig domein */
							__( 'Deze site was bevestigd als staging op %1$s, maar draait nu op %2$s. Deze database komt dus van een andere omgeving. Zolang dat niet klopt blokkeert de plugin niets.', 'staging-safety' ),
							$stale,
							Environment::current_host()
						)
					);
					?>
				</p>
			<?php else : ?>
				<p>
					<strong><?php esc_html_e( 'Staging Safety staat nog uit.', 'staging-safety' ); ?></strong>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: toelichting op de hostnaam */
							__( 'Er wordt niets geblokkeerd of gelogd. %s', 'staging-safety' ),
							Environment::looks_like_staging()
								? __( 'De hostnaam ziet er wel uit als een testomgeving.', 'staging-safety' )
								: __( 'De hostnaam geeft geen uitsluitsel.', 'staging-safety' )
						)
					);
					?>
				</p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>">
				<?php wp_nonce_field( 'staging_safety_confirm_staging' ); ?>
				<input type="hidden" name="staging_safety_action" value="confirm_staging">
				<p>
					<button type="submit" class="button button-primary">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: domeinnaam */
								__( 'Ja, %s is een stagingomgeving', 'staging-safety' ),
								Environment::current_host()
							)
						);
						?>
					</button>
				</p>
			</form>

			<p class="description">
				<?php esc_html_e( 'De bevestiging wordt vastgezet op dit domein. Komt deze database ooit op een ander domein terecht, dan zet de plugin zichzelf weer uit — zo kan een stagingdatabase nooit per ongeluk de live-site blokkeren.', 'staging-safety' ); ?>
			</p>
			<p class="description">
				<?php
				echo wp_kses(
					__( 'Wil je dat het ook een verse databasekopie van productie overleeft, zet dan <code>define( \'STAGING_SAFETY_ENV\', \'staging\' );</code> in wp-config.php. Dat is niet nodig, wel steviger.', 'staging-safety' ),
					array( 'code' => array() )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Meldingblok.
	 *
	 * @param string $type    success, warning of error.
	 * @param string $message Tekst, mag beperkte HTML bevatten.
	 */
	private function notice( $type, $message ) {
		printf(
			'<div class="notice notice-%1$s staging-safety-notice"><p>%2$s</p></div>',
			esc_attr( $type ),
			wp_kses( $message, array( 'a' => array( 'href' => array() ), 'code' => array(), 'strong' => array() ) )
		);
	}

	/**
	 * Overzichtspagina.
	 */
	public function render_dashboard() {
		( new Dashboard_Page() )->render();
	}

	/**
	 * Instellingenpagina.
	 */
	public function render_settings() {
		( new Settings_Page( $this->current_tab() ) )->render();
	}

	/**
	 * Logboekpagina.
	 */
	public function render_log() {
		( new Log_Page() )->render();
	}
}
