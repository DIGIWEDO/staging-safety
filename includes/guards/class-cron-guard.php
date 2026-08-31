<?php
/**
 * Houdt geselecteerde cronjobs tegen.
 *
 * @package StagingSafety\Guards
 */

namespace StagingSafety\Guards;

use StagingSafety\Logger;
use StagingSafety\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Cronjobs zijn op staging berucht: ze draaien zonder dat iemand kijkt en
 * synchroniseren of mailen vrolijk door.
 *
 * We filteren de lijst met te draaien taken vlak voordat WordPress hem afwerkt.
 * De taak blijft ingepland staan, hij wordt alleen niet uitgevoerd. Zet je de
 * blokkade weer uit, dan pakt hij vanzelf zijn oude ritme op.
 */
class Cron_Guard extends Guard {

	/**
	 * Hooknamen die vrijwel altijd risico geven.
	 */
	const RISKY_EXACT = array(
		'action_scheduler_run_queue',
		'woocommerce_scheduled_sales',
		'woocommerce_cleanup_sessions',
		'wc_admin_daily',
		'woocommerce_scheduled_subscription_payment',
	);

	/**
	 * Woorden in een hooknaam die op koppelingen of verzending wijzen.
	 */
	const RISKY_WORDS = array(
		'sync', 'import', 'export', 'feed', 'mail', 'newsletter', 'campaign',
		'payment', 'invoice', 'subscription', 'order', 'stock', 'inventory',
		'backup', 'mailchimp', 'hubspot', 'sendgrid', 'webhook', 'api',
	);

	/**
	 * Logkanaal.
	 *
	 * @return string
	 */
	public function channel() {
		return 'cron';
	}

	/**
	 * Aanhaken.
	 */
	protected function hook() {
		add_filter( 'pre_get_ready_cron_jobs', array( $this, 'filter_ready_jobs' ) );

		if ( Settings::get( 'cron.block_new_schedules' ) ) {
			add_filter( 'pre_schedule_event', array( $this, 'block_scheduling' ), 10, 2 );
			add_filter( 'pre_reschedule_event', array( $this, 'block_scheduling' ), 10, 2 );
		}
	}

	/**
	 * De lijst met nu te draaien taken opnieuw samenstellen, zonder de
	 * geblokkeerde hooks.
	 *
	 * @param null|array $pre Waarde van een eerdere filter.
	 * @return null|array
	 */
	public function filter_ready_jobs( $pre ) {
		if ( null !== $pre && false !== $pre ) {
			return $pre;
		}

		$blocked = $this->blocked_hooks();
		if ( ! $blocked ) {
			return $pre;
		}

		$crons = _get_cron_array();
		if ( ! is_array( $crons ) || ! $crons ) {
			return $pre;
		}

		$now     = microtime( true );
		$ready   = array();
		$skipped = array();

		foreach ( $crons as $timestamp => $hooks ) {
			if ( $timestamp > $now ) {
				break;
			}

			$keep = array();

			foreach ( (array) $hooks as $hook => $events ) {
				if ( in_array( $hook, $blocked, true ) ) {
					$skipped[ $hook ] = true;
					continue;
				}

				$keep[ $hook ] = $events;
			}

			if ( $keep ) {
				$ready[ $timestamp ] = $keep;
			}
		}

		foreach ( array_keys( $skipped ) as $hook ) {
			$this->log_once( $hook );
		}

		// In monitorstand alleen kijken, niet ingrijpen.
		if ( ! $this->is_blocking() ) {
			return $pre;
		}

		return $ready;
	}

	/**
	 * Nieuwe inplanning van een geblokkeerde hook tegenhouden.
	 *
	 * @param null|bool|\WP_Error $pre   Waarde van een eerdere filter.
	 * @param object              $event Het in te plannen event.
	 * @return null|bool|\WP_Error
	 */
	public function block_scheduling( $pre, $event ) {
		if ( null !== $pre ) {
			return $pre;
		}

		if ( ! is_object( $event ) || empty( $event->hook ) ) {
			return $pre;
		}

		if ( ! in_array( $event->hook, $this->blocked_hooks(), true ) ) {
			return $pre;
		}

		$this->log_once( $event->hook, __( 'nieuwe inplanning tegengehouden', 'staging-safety' ), 'schedule' );

		if ( ! $this->is_blocking() ) {
			return $pre;
		}

		return false;
	}

	/**
	 * De geblokkeerde hooks uit de instellingen.
	 *
	 * @return array
	 */
	public function blocked_hooks() {
		$hooks = (array) Settings::get( 'cron.blocked_hooks', array() );

		return array_values( array_filter( array_map( 'strval', $hooks ) ) );
	}

	/**
	 * Een geblokkeerde hook blijft due staan en komt dus bij elk request weer
	 * langs. Zonder rem zou het logboek daarvan vollopen, dus maximaal één
	 * regel per hook per uur.
	 *
	 * @param string $hook   Hooknaam.
	 * @param string $rule   Uitleg.
	 * @param string $bucket Aparte teller, zodat inplannen en uitvoeren elkaar niet dempen.
	 */
	private function log_once( $hook, $rule = '', $bucket = 'run' ) {
		$key = 'ss_cron_' . $bucket . '_' . md5( $hook );

		if ( get_transient( $key ) ) {
			return;
		}

		set_transient( $key, 1, HOUR_IN_SECONDS );

		Logger::log(
			Logger::CHANNEL_CRON,
			$this->block_action(),
			$hook,
			array(
				'detail' => __( 'Verdere meldingen voor deze hook worden een uur onderdrukt.', 'staging-safety' ),
				'rule'   => $rule ? $rule : __( 'hook staat op de blokkeerlijst', 'staging-safety' ),
				'source' => '',
			)
		);
	}

	/**
	 * Alle ingeplande hooks, voor het instellingenscherm.
	 *
	 * @return array Hooknaam => ['count' => int, 'next' => int, 'schedule' => string, 'risky' => bool]
	 */
	public static function scheduled_hooks() {
		$crons = _get_cron_array();
		$out   = array();

		if ( ! is_array( $crons ) ) {
			return $out;
		}

		foreach ( $crons as $timestamp => $hooks ) {
			foreach ( (array) $hooks as $hook => $events ) {
				if ( ! isset( $out[ $hook ] ) ) {
					$out[ $hook ] = array(
						'count'    => 0,
						'next'     => (int) $timestamp,
						'schedule' => '',
						'risky'    => self::is_risky( $hook ),
					);
				}

				$out[ $hook ]['count'] += count( (array) $events );

				foreach ( (array) $events as $event ) {
					if ( ! empty( $event['schedule'] ) && '' === $out[ $hook ]['schedule'] ) {
						$out[ $hook ]['schedule'] = (string) $event['schedule'];
					}
				}
			}
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Ziet deze hooknaam er risicovol uit? Alleen een voorstel in het scherm.
	 *
	 * @param string $hook Hooknaam.
	 * @return bool
	 */
	public static function is_risky( $hook ) {
		$hook = strtolower( (string) $hook );

		if ( in_array( $hook, self::RISKY_EXACT, true ) ) {
			return true;
		}

		foreach ( self::RISKY_WORDS as $word ) {
			if ( false !== strpos( $hook, $word ) ) {
				return true;
			}
		}

		return false;
	}
}
