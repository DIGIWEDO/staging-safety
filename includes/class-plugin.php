<?php
/**
 * Verbindt alle onderdelen.
 *
 * @package StagingSafety
 */

namespace StagingSafety;

use StagingSafety\Admin\Admin;
use StagingSafety\Admin\Indicator;
use StagingSafety\Guards\Cron_Guard;
use StagingSafety\Guards\Http_Guard;
use StagingSafety\Guards\Mail_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Startpunt van de plugin.
 */
class Plugin {

	const PAUSE_TRANSIENT = 'staging_safety_paused';
	const CAPABILITY      = 'manage_options';

	/**
	 * Enige instantie.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * De guards, op naam.
	 *
	 * @var array
	 */
	private $guards = array();

	/**
	 * Instantie ophalen.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Alles aanhaken.
	 */
	public function boot() {
		$this->guards = array(
			'http' => new Http_Guard(),
			'mail' => new Mail_Guard(),
			'cron' => new Cron_Guard(),
		);

		// De guards moeten er zijn voordat andere plugins hun werk doen, dus
		// niet wachten op 'init'.
		foreach ( $this->guards as $guard ) {
			$guard->register();
		}

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
		add_action( Logger::CLEANUP_HOOK, array( 'StagingSafety\\Logger', 'cleanup' ) );
		add_action( 'admin_post_staging_safety_pause', array( $this, 'handle_pause' ) );

		( new Updater() )->register();
		( new Indicator() )->register();

		if ( is_admin() ) {
			( new Admin() )->register();
		}
	}

	/**
	 * Na een update van de plugin het databaseschema bijwerken. De
	 * activatiehook draait niet bij een gewone update, dus dit is de plek.
	 */
	public function maybe_upgrade() {
		if ( get_option( 'staging_safety_version' ) === STAGING_SAFETY_VERSION ) {
			return;
		}

		Logger::install_table();

		if ( ! wp_next_scheduled( Logger::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Logger::CLEANUP_HOOK );
		}

		update_option( 'staging_safety_version', STAGING_SAFETY_VERSION, false );
	}

	/**
	 * Vertalingen laden.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'staging-safety', false, dirname( plugin_basename( STAGING_SAFETY_FILE ) ) . '/languages' );
	}

	/**
	 * Een guard opvragen.
	 *
	 * @param string $name http, mail of cron.
	 * @return \StagingSafety\Guards\Guard|null
	 */
	public function guard( $name ) {
		return isset( $this->guards[ $name ] ) ? $this->guards[ $name ] : null;
	}

	/**
	 * Alle guards.
	 *
	 * @return array
	 */
	public function guards() {
		return $this->guards;
	}

	/**
	 * Staat de beveiliging op pauze?
	 *
	 * @return bool
	 */
	public static function is_paused() {
		return (bool) self::pause_info();
	}

	/**
	 * Gegevens van de lopende pauze, of null.
	 *
	 * @return array|null
	 */
	public static function pause_info() {
		$info = get_transient( self::PAUSE_TRANSIENT );

		if ( ! is_array( $info ) || empty( $info['until'] ) || $info['until'] <= time() ) {
			return null;
		}

		return $info;
	}

	/**
	 * Pauzeren. Loopt vanzelf af, zodat je niet kunt vergeten hem weer aan
	 * te zetten.
	 *
	 * @param int $minutes Aantal minuten.
	 */
	public static function pause( $minutes ) {
		$minutes = max( 1, min( 240, (int) $minutes ) );
		$until   = time() + ( $minutes * MINUTE_IN_SECONDS );

		set_transient(
			self::PAUSE_TRANSIENT,
			array(
				'until' => $until,
				'user'  => get_current_user_id(),
			),
			$minutes * MINUTE_IN_SECONDS
		);

		$user = wp_get_current_user();

		Logger::log(
			Logger::CHANNEL_SYSTEM,
			Logger::ACTION_NOTICE,
			'pauze',
			array(
				'detail' => sprintf(
					/* translators: 1: aantal minuten, 2: gebruikersnaam */
					__( 'Beveiliging %1$d minuten gepauzeerd door %2$s.', 'staging-safety' ),
					$minutes,
					$user && $user->exists() ? $user->user_login : __( 'onbekend', 'staging-safety' )
				),
				'rule'   => __( 'handmatig', 'staging-safety' ),
			)
		);
	}

	/**
	 * Pauze meteen beëindigen.
	 */
	public static function resume() {
		delete_transient( self::PAUSE_TRANSIENT );

		Logger::log(
			Logger::CHANNEL_SYSTEM,
			Logger::ACTION_NOTICE,
			'pauze',
			array(
				'detail' => __( 'Beveiliging weer aangezet.', 'staging-safety' ),
				'rule'   => __( 'handmatig', 'staging-safety' ),
			)
		);
	}

	/**
	 * Knop uit de admin bar afhandelen.
	 */
	public function handle_pause() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Je hebt geen rechten om dit te doen.', 'staging-safety' ), 403 );
		}

		check_admin_referer( 'staging_safety_pause' );

		$minutes = isset( $_GET['minutes'] ) ? (int) $_GET['minutes'] : 0;

		if ( $minutes > 0 ) {
			self::pause( $minutes );
		} else {
			self::resume();
		}

		$back = wp_get_referer();

		wp_safe_redirect( $back ? $back : admin_url( 'admin.php?page=staging-safety' ) );
		exit;
	}

	/**
	 * Bij activeren: tabel klaarzetten, opruimtaak inplannen en de omgeving
	 * alvast beoordelen.
	 */
	public static function activate() {
		Logger::install_table();

		if ( ! wp_next_scheduled( Logger::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Logger::CLEANUP_HOOK );
		}

		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}

		update_option( 'staging_safety_version', STAGING_SAFETY_VERSION, false );
	}

	/**
	 * Bij deactiveren: opruimtaak weghalen en een lopende pauze beëindigen.
	 * Instellingen en logboek blijven staan.
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( Logger::CLEANUP_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, Logger::CLEANUP_HOOK );
		}

		delete_transient( self::PAUSE_TRANSIENT );
	}
}
