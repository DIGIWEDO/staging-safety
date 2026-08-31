<?php
/**
 * Zichtbaar maken dat dit staging is.
 *
 * @package StagingSafety\Admin
 */

namespace StagingSafety\Admin;

use StagingSafety\Environment;
use StagingSafety\Plugin;
use StagingSafety\Settings;
use WP_Admin_Bar;

defined( 'ABSPATH' ) || exit;

/**
 * Een gekleurde balk en een item in de admin bar. Klinkt onbenullig, maar
 * de meeste ongelukken beginnen met iemand die niet doorhad waar hij zat.
 */
class Indicator {

	/**
	 * Aanhaken.
	 */
	public function register() {
		if ( ! Settings::get( 'indicator.enabled' ) || ! Environment::is_staging() ) {
			return;
		}

		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 8 );
		add_action( 'admin_head', array( $this, 'styles' ) );

		if ( Settings::get( 'indicator.frontend' ) ) {
			add_action( 'wp_head', array( $this, 'styles' ) );
		}

		if ( Settings::get( 'indicator.login' ) ) {
			add_action( 'login_head', array( $this, 'styles' ) );
			add_action( 'login_message', array( $this, 'login_message' ) );
		}
	}

	/**
	 * Kleur uit de instellingen, met terugval.
	 *
	 * @return string
	 */
	private function color() {
		$color = (string) Settings::get( 'indicator.color', '#d63638' );

		return preg_match( '/^#[0-9a-f]{6}$/i', $color ) ? $color : '#d63638';
	}

	/**
	 * Tekst op de balk.
	 *
	 * @return string
	 */
	private function label() {
		$label = trim( (string) Settings::get( 'indicator.label', 'STAGING' ) );

		return '' !== $label ? $label : 'STAGING';
	}

	/**
	 * Korte samenvatting van de stand.
	 *
	 * @return string
	 */
	public static function status_text() {
		$pause = Plugin::pause_info();

		if ( $pause ) {
			$minutes = max( 1, (int) ceil( ( $pause['until'] - time() ) / MINUTE_IN_SECONDS ) );

			/* translators: %d: aantal minuten */
			return sprintf( _n( 'gepauzeerd, nog %d minuut', 'gepauzeerd, nog %d minuten', $minutes, 'staging-safety' ), $minutes );
		}

		$modes = array();

		foreach ( Plugin::instance()->guards() as $name => $guard ) {
			$modes[ $guard->mode() ][] = $name;
		}

		if ( isset( $modes['block'] ) && 3 === count( $modes['block'] ) ) {
			return __( 'alles beveiligd', 'staging-safety' );
		}

		if ( isset( $modes['off'] ) && 3 === count( $modes['off'] ) ) {
			return __( 'niets actief', 'staging-safety' );
		}

		$parts = array();
		foreach ( array( 'block' => __( 'blokkeert', 'staging-safety' ), 'monitor' => __( 'kijkt mee', 'staging-safety' ) ) as $mode => $word ) {
			if ( ! empty( $modes[ $mode ] ) ) {
				$parts[] = $word . ': ' . implode( ', ', $modes[ $mode ] );
			}
		}

		return $parts ? implode( ' — ', $parts ) : __( 'niets actief', 'staging-safety' );
	}

	/**
	 * Item in de admin bar, met de pauzeknoppen eronder.
	 *
	 * @param WP_Admin_Bar $bar Admin bar.
	 */
	public function admin_bar( $bar ) {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			return;
		}

		$paused = Plugin::is_paused();
		$color  = $paused ? '#996800' : $this->color();

		$bar->add_node(
			array(
				'id'    => 'staging-safety',
				'title' => '<span class="ss-bar-dot" style="background:' . esc_attr( $color ) . '"></span>'
					. '<span class="ss-bar-label">' . esc_html( $this->label() ) . '</span> '
					. '<span class="ss-bar-status">(' . esc_html( self::status_text() ) . ')</span>',
				'href'  => admin_url( 'admin.php?page=staging-safety' ),
				'meta'  => array( 'title' => __( 'Staging Safety', 'staging-safety' ) ),
			)
		);

		$bar->add_node(
			array(
				'id'     => 'staging-safety-settings',
				'parent' => 'staging-safety',
				'title'  => __( 'Instellingen', 'staging-safety' ),
				'href'   => admin_url( 'admin.php?page=staging-safety-settings' ),
			)
		);

		$bar->add_node(
			array(
				'id'     => 'staging-safety-log',
				'parent' => 'staging-safety',
				'title'  => __( 'Logboek', 'staging-safety' ),
				'href'   => admin_url( 'admin.php?page=staging-safety-log' ),
			)
		);

		if ( $paused ) {
			$bar->add_node(
				array(
					'id'     => 'staging-safety-resume',
					'parent' => 'staging-safety',
					'title'  => __( 'Beveiliging nu weer aanzetten', 'staging-safety' ),
					'href'   => self::pause_url( 0 ),
				)
			);

			return;
		}

		foreach ( array( 15, 30, 60 ) as $minutes ) {
			$bar->add_node(
				array(
					'id'     => 'staging-safety-pause-' . $minutes,
					'parent' => 'staging-safety',
					/* translators: %d: aantal minuten */
					'title'  => sprintf( __( 'Pauzeer %d minuten', 'staging-safety' ), $minutes ),
					'href'   => self::pause_url( $minutes ),
				)
			);
		}
	}

	/**
	 * Beveiligde link naar de pauzeknop.
	 *
	 * @param int $minutes Aantal minuten, 0 om te hervatten.
	 * @return string
	 */
	public static function pause_url( $minutes ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'staging_safety_pause',
					'minutes' => (int) $minutes,
				),
				admin_url( 'admin-post.php' )
			),
			'staging_safety_pause'
		);
	}

	/**
	 * De gekleurde balk zelf.
	 */
	public function styles() {
		$paused = Plugin::is_paused();
		$color  = $paused ? '#996800' : $this->color();
		$label  = $this->label();

		if ( $paused ) {
			/* translators: %s: label van de omgeving */
			$label = sprintf( __( '%s — BEVEILIGING GEPAUZEERD', 'staging-safety' ), $label );
		}

		// In een CSS content-string kunnen aanhalingstekens en backslashes de
		// hele regel breken, dus die halen we eruit.
		$label = str_replace( array( '"', "'", '\\', '<', '>' ), '', $label );

		?>
		<style id="staging-safety-indicator">
			#wpadminbar #wp-admin-bar-staging-safety > .ab-item { background: <?php echo esc_attr( $color ); ?> !important; color: #fff !important; font-weight: 600; }
			#wpadminbar .ss-bar-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #fff; margin-right: 6px; vertical-align: middle; box-shadow: 0 0 0 2px rgba(255,255,255,.6); }
			#wpadminbar .ss-bar-status { opacity: .85; font-weight: 400; }
			body::before {
				content: "<?php echo esc_html( $label ); ?>";
				position: fixed;
				top: 0; left: 0; right: 0;
				z-index: 100000;
				background: <?php echo esc_attr( $color ); ?>;
				color: #fff;
				font: 600 11px/16px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
				letter-spacing: .12em;
				text-align: center;
				padding: 3px 0;
				pointer-events: none;
			}
			html { border-top: 22px solid <?php echo esc_attr( $color ); ?>; }
			#wpadminbar { top: 22px; }
			@media screen and (max-width: 782px) { html { border-top-width: 22px; } }
		</style>
		<?php
	}

	/**
	 * Melding op het inlogscherm.
	 *
	 * @param string $message Bestaande melding.
	 * @return string
	 */
	public function login_message( $message ) {
		$text = sprintf(
			/* translators: %s: label van de omgeving */
			__( 'Let op: dit is %s, niet de live-site.', 'staging-safety' ),
			$this->label()
		);

		return '<p class="message" style="border-left-color:' . esc_attr( $this->color() ) . '">' . esc_html( $text ) . '</p>' . $message;
	}
}
