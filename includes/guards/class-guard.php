<?php
/**
 * Basis voor alle guards.
 *
 * @package StagingSafety\Guards
 */

namespace StagingSafety\Guards;

use StagingSafety\Environment;
use StagingSafety\Plugin;
use StagingSafety\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Elk onderdeel kent drie standen:
 *
 *   off      niets doen
 *   monitor  wel loggen, niets tegenhouden
 *   block    loggen en tegenhouden
 *
 * "monitor" is de stand waarmee je op een nieuwe site begint. Je ziet dan een
 * week lang wat de site allemaal naar buiten doet voordat je iets dichtzet.
 */
abstract class Guard {

	const MODE_OFF     = 'off';
	const MODE_MONITOR = 'monitor';
	const MODE_BLOCK   = 'block';

	/**
	 * Instellingengroep en logkanaal, bijvoorbeeld 'http'.
	 *
	 * @return string
	 */
	abstract public function channel();

	/**
	 * Hooks aanhaken.
	 */
	abstract protected function hook();

	/**
	 * Aanhaken als deze guard iets te doen heeft.
	 */
	public function register() {
		if ( $this->is_active() ) {
			$this->hook();
		}
	}

	/**
	 * Huidige stand.
	 *
	 * @return string
	 */
	public function mode() {
		$mode = Settings::get( $this->channel() . '.mode', self::MODE_OFF );

		return in_array( $mode, array( self::MODE_OFF, self::MODE_MONITOR, self::MODE_BLOCK ), true )
			? $mode
			: self::MODE_OFF;
	}

	/**
	 * Doet deze guard iets? Buiten staging altijd nee.
	 *
	 * @return bool
	 */
	public function is_active() {
		return self::MODE_OFF !== $this->mode() && Environment::is_staging();
	}

	/**
	 * Mag er daadwerkelijk geblokkeerd worden? Tijdens een pauze niet.
	 *
	 * @return bool
	 */
	public function is_blocking() {
		return self::MODE_BLOCK === $this->mode() && ! Plugin::is_paused();
	}

	/**
	 * Wat we in het logboek zetten als de beoordeling "blokkeren" was.
	 * In monitorstand is dat "zou geblokkeerd zijn".
	 *
	 * @return string
	 */
	protected function block_action() {
		return $this->is_blocking()
			? \StagingSafety\Logger::ACTION_BLOCKED
			: \StagingSafety\Logger::ACTION_WOULD_BLOCK;
	}
}
