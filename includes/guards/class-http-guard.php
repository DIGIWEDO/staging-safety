<?php
/**
 * Houdt uitgaande HTTP-requests tegen.
 *
 * @package StagingSafety\Guards
 */

namespace StagingSafety\Guards;

use StagingSafety\Caller;
use StagingSafety\Logger;
use StagingSafety\Matcher;
use StagingSafety\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Haakt op pre_http_request. Geef je daar iets anders dan false terug, dan
 * slaat WordPress het echte verzoek over en gebruikt het jouw antwoord.
 *
 * Let op: dit vangt alles wat via wp_remote_* loopt, en dat is verreweg het
 * meeste. Een plugin die zelf rechtstreeks cURL aanroept komt hier niet langs.
 */
class Http_Guard extends Guard {

	/**
	 * Logkanaal.
	 *
	 * @return string
	 */
	public function channel() {
		return 'http';
	}

	/**
	 * Aanhaken.
	 */
	protected function hook() {
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 5, 3 );
	}

	/**
	 * Beoordeelt één uitgaand verzoek.
	 *
	 * @param false|array|WP_Error $preempt Antwoord van een eerdere filter.
	 * @param array                $args    Argumenten van het verzoek.
	 * @param string               $url     Doel-URL.
	 * @return false|array|WP_Error
	 */
	public function intercept( $preempt, $args, $url ) {
		// Iemand anders heeft dit verzoek al afgehandeld.
		if ( false !== $preempt ) {
			return $preempt;
		}

		// Onze eigen updatecontrole moet er altijd langs kunnen, anders kun je
		// de plugin niet meer bijwerken zodra hij alles dichtzet.
		if ( ! empty( $args['staging_safety_internal'] ) ) {
			return $preempt;
		}

		$host = Matcher::host_from_url( $url );
		if ( '' === $host ) {
			return $preempt;
		}

		$slug     = Caller::slug();
		$decision = $this->decide( $host, $slug );

		/**
		 * Laat de beslissing overrulen, bijvoorbeeld vanuit een mu-plugin.
		 *
		 * @param array  $decision ['allow' => bool, 'rule' => string]
		 * @param string $url      Doel-URL.
		 * @param string $host     Hostnaam.
		 * @param string $slug     Aanroepende plugin.
		 * @param array  $args     Requestargumenten.
		 */
		$decision = apply_filters( 'staging_safety_http_decision', $decision, $url, $host, $slug, $args );

		if ( ! empty( $decision['allow'] ) ) {
			// Aanroepen naar de site zelf (wp-cron, admin-ajax, loopbacks) niet
			// loggen. Dat zijn er honderden per dag en ze zeggen niets over wat
			// er naar buiten gaat; ze verdrinken alleen de regels die er wel
			// toe doen.
			if ( Settings::get( 'http.log_allowed' ) && empty( $decision['internal'] ) ) {
				$this->log( Logger::ACTION_ALLOWED, $host, $url, $decision['rule'], $slug, $args );
			}

			return $preempt;
		}

		$this->log( $this->block_action(), $host, $url, $decision['rule'], $slug, $args );

		if ( ! $this->is_blocking() ) {
			return $preempt;
		}

		return new WP_Error(
			'staging_safety_blocked',
			sprintf(
				/* translators: %s: hostnaam */
				__( 'Staging Safety heeft dit uitgaande verzoek naar %s geblokkeerd. Zet de host op de witte lijst als hij nodig is.', 'staging-safety' ),
				$host
			),
			array(
				'url'  => $url,
				'host' => $host,
				'rule' => $decision['rule'],
			)
		);
	}

	/**
	 * De beoordeling zelf. Volgorde staat vast en wordt zo ook in de admin
	 * uitgelegd: eerst wat expliciet dicht moet, dan wat expliciet open mag,
	 * dan de grondhouding.
	 *
	 * @param string $host Hostnaam.
	 * @param string $slug Aanroepende plugin.
	 * @return array ['allow' => bool, 'rule' => string]
	 */
	public function decide( $host, $slug = '' ) {
		if ( $this->is_internal( $host ) ) {
			return $this->result( true, __( 'eigen omgeving', 'staging-safety' ), true );
		}

		$deny = Matcher::match_host( $host, (array) Settings::get( 'http.deny', array() ) );
		if ( null !== $deny ) {
			/* translators: %s: patroon */
			return $this->result( false, sprintf( __( 'zwarte lijst: %s', 'staging-safety' ), $deny ) );
		}

		$plugin_rules = (array) Settings::get( 'http.plugin_rules', array() );
		if ( $slug && isset( $plugin_rules[ $slug ] ) && 'deny' === $plugin_rules[ $slug ] ) {
			/* translators: %s: plugin */
			return $this->result( false, sprintf( __( 'plugin geblokkeerd: %s', 'staging-safety' ), $slug ) );
		}

		$allow = Matcher::match_host( $host, (array) Settings::get( 'http.allow', array() ) );
		if ( null !== $allow ) {
			/* translators: %s: patroon */
			return $this->result( true, sprintf( __( 'witte lijst: %s', 'staging-safety' ), $allow ) );
		}

		if ( $slug && isset( $plugin_rules[ $slug ] ) && 'allow' === $plugin_rules[ $slug ] ) {
			/* translators: %s: plugin */
			return $this->result( true, sprintf( __( 'plugin toegestaan: %s', 'staging-safety' ), $slug ) );
		}

		if ( 'blacklist' === Settings::get( 'http.policy' ) ) {
			return $this->result( true, __( 'grondhouding: alles open', 'staging-safety' ) );
		}

		return $this->result( false, __( 'grondhouding: alles dicht', 'staging-safety' ) );
	}

	/**
	 * Hulpje voor een leesbaar resultaat.
	 *
	 * @param bool   $allow    Toestaan?
	 * @param string $rule     Welke regel greep.
	 * @param bool   $internal Gaat dit naar de site zelf?
	 * @return array
	 */
	private function result( $allow, $rule, $internal = false ) {
		return array(
			'allow'    => (bool) $allow,
			'rule'     => $rule,
			'internal' => (bool) $internal,
		);
	}

	/**
	 * Verzoeken naar de site zelf of naar de eigen machine laten we altijd door.
	 * Blokkeer je die, dan breken loopbacks en de site-health-check.
	 *
	 * @param string $host Hostnaam.
	 * @return bool
	 */
	private function is_internal( $host ) {
		$internal = array( 'localhost', '127.0.0.1', '::1', '0.0.0.0' );

		foreach ( array( home_url(), site_url(), network_home_url() ) as $url ) {
			$own = Matcher::host_from_url( $url );
			if ( $own ) {
				$internal[] = $own;
			}
		}

		/**
		 * Hosts die nooit geblokkeerd worden.
		 *
		 * @param array $internal Lijst met hostnamen.
		 */
		$internal = apply_filters( 'staging_safety_internal_hosts', array_unique( $internal ) );

		return in_array( $host, $internal, true );
	}

	/**
	 * Regel in het logboek.
	 *
	 * @param string $action Actie.
	 * @param string $host   Hostnaam.
	 * @param string $url    URL.
	 * @param string $rule   Regel die greep.
	 * @param string $slug   Aanroepende plugin.
	 * @param array  $args   Requestargumenten.
	 */
	private function log( $action, $host, $url, $rule, $slug, $args ) {
		$method = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';

		Logger::log(
			Logger::CHANNEL_HTTP,
			$action,
			$host,
			array(
				'detail' => $method . ' ' . $this->safe_url( $url ),
				'rule'   => $rule,
				'source' => $slug,
			)
		);
	}

	/**
	 * URL zonder querystring loggen. Daar staan geregeld API-sleutels in en
	 * die wil je niet in de database hebben staan.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function safe_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! $parts || empty( $parts['host'] ) ) {
			return (string) $url;
		}

		$clean = ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . $parts['host'];

		if ( isset( $parts['port'] ) ) {
			$clean .= ':' . $parts['port'];
		}

		if ( isset( $parts['path'] ) ) {
			$clean .= $parts['path'];
		}

		if ( ! empty( $parts['query'] ) ) {
			$clean .= '?…';
		}

		return $clean;
	}
}
