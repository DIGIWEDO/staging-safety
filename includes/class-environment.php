<?php
/**
 * Bepaalt of dit een stagingomgeving is.
 *
 * @package StagingSafety
 */

namespace StagingSafety;

defined( 'ABSPATH' ) || exit;

/**
 * De hele plugin hangt aan deze vraag: mogen we blokkeren of niet.
 *
 * Fout-positief is hier gevaarlijk. Als we productie voor staging aanzien
 * blokkeren we echte betalingen.
 *
 * Er zijn twee manieren om het antwoord te geven:
 *
 *  1. een regel in wp-config.php — die staat per server en overleeft dus een
 *     verse databasekopie van productie;
 *  2. een knop in de beheeromgeving — daarbij slaan we het domein op waarop
 *     je hem indrukte. Belandt die database op een ander domein, dan klopt het
 *     domein niet meer en zet de plugin zichzelf uit.
 *
 * Die tweede manier is net zo veilig tegen het gevaarlijke geval (een
 * stagingdatabase die op productie terechtkomt), zonder dat je in de code hoeft.
 */
class Environment {

	const STAGING    = 'staging';
	const PRODUCTION = 'production';
	const UNKNOWN    = 'unknown';

	/**
	 * Hostnaam-patronen die op staging wijzen. Alleen voor een suggestie,
	 * deze zetten uit zichzelf niets aan.
	 */
	const HINTS = array(
		'staging', 'stage', 'test', 'tst', 'acc', 'accept', 'dev', 'develop',
		'demo', 'preview', 'sandbox', 'local', 'beta', 'ontwikkel',
	);

	/**
	 * Waar de conclusie vandaan komt, voor uitleg in de admin.
	 *
	 * @var string
	 */
	private static $source = '';

	/**
	 * Resultaat cachen: dit wordt bij elk request meerdere keren gevraagd.
	 *
	 * @var string|null
	 */
	private static $type = null;

	/**
	 * Staging, productie of onbekend.
	 *
	 * @return string
	 */
	public static function type() {
		if ( null !== self::$type ) {
			return self::$type;
		}

		// 1. Onze eigen constant wint altijd. Die zet je bewust op de stagingserver
		// en staat dus niet in een van productie gekopieerde wp-config.php.
		if ( defined( 'STAGING_SAFETY_ENV' ) ) {
			$value        = strtolower( (string) constant( 'STAGING_SAFETY_ENV' ) );
			self::$source = __( 'de constant STAGING_SAFETY_ENV in wp-config.php', 'staging-safety' );
			self::$type   = self::PRODUCTION === $value ? self::PRODUCTION : self::STAGING;

			return self::$type;
		}

		// 2. De omgevingsinstelling van WordPress zelf, maar alleen als die
		// echt gezet is. wp_get_environment_type() geeft anders 'production'
		// terug terwijl niemand dat heeft opgegeven. Ook deze staat in
		// wp-config.php, dus hij reist niet mee met een databasekopie.
		$declared = self::declared_wp_environment();
		if ( null !== $declared ) {
			self::$source = __( 'de WordPress-omgevingsinstelling (WP_ENVIRONMENT_TYPE)', 'staging-safety' );
			self::$type   = 'production' === $declared ? self::PRODUCTION : self::STAGING;

			return self::$type;
		}

		// 3. De knop in de beheeromgeving, maar alleen op het domein waarop
		// hij is ingedrukt.
		$confirmed = self::confirmed_host();

		if ( '' !== $confirmed ) {
			if ( $confirmed === self::current_host() ) {
				self::$source = __( 'de bevestiging in de beheeromgeving', 'staging-safety' );
				self::$type   = self::STAGING;

				return self::$type;
			}

			self::$source = sprintf(
				/* translators: %s: domeinnaam */
				__( 'niets — de bevestiging hoort bij %s, dus deze database komt van een andere omgeving', 'staging-safety' ),
				$confirmed
			);
			self::$type = self::UNKNOWN;

			return self::$type;
		}

		self::$source = __( 'niets — de omgeving is nog niet vastgesteld', 'staging-safety' );
		self::$type   = self::UNKNOWN;

		return self::$type;
	}

	/**
	 * De expliciet opgegeven WP_ENVIRONMENT_TYPE, of null als niemand hem zette.
	 *
	 * @return string|null
	 */
	private static function declared_wp_environment() {
		if ( defined( 'WP_ENVIRONMENT_TYPE' ) && constant( 'WP_ENVIRONMENT_TYPE' ) ) {
			return strtolower( (string) constant( 'WP_ENVIRONMENT_TYPE' ) );
		}

		$env = getenv( 'WP_ENVIRONMENT_TYPE' );

		return $env ? strtolower( $env ) : null;
	}

	/**
	 * Het domein waarop iemand de bevestigingsknop indrukte.
	 *
	 * @return string
	 */
	public static function confirmed_host() {
		return strtolower( trim( (string) Settings::get( 'confirmed_staging', '' ) ) );
	}

	/**
	 * Het domein waarop de site nu draait.
	 *
	 * @return string
	 */
	public static function current_host() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return $host ? strtolower( $host ) : '';
	}

	/**
	 * Er is wel bevestigd, maar op een ander domein. Dat betekent bijna altijd
	 * dat deze database elders vandaan is gekopieerd.
	 *
	 * @return string Het domein van de bevestiging, of leeg.
	 */
	public static function stale_confirmation() {
		$confirmed = self::confirmed_host();

		return ( '' !== $confirmed && $confirmed !== self::current_host() ) ? $confirmed : '';
	}

	/**
	 * Deze site als staging bevestigen, vastgezet op het huidige domein.
	 */
	public static function confirm() {
		Settings::set( 'confirmed_staging', self::current_host() );
		self::reset();
	}

	/**
	 * Bevestiging weer intrekken.
	 */
	public static function revoke() {
		Settings::set( 'confirmed_staging', '' );
		self::reset();
	}

	/**
	 * Mogen de guards actief zijn?
	 *
	 * @return bool
	 */
	public static function is_staging() {
		return self::STAGING === self::type();
	}

	/**
	 * Staat WordPress erop dat dit productie is? Dan kan de handmatige
	 * bevestiging dat niet overrulen; daar is de constant voor.
	 *
	 * @return bool
	 */
	public static function is_locked_to_production() {
		return self::PRODUCTION === self::type() && ! defined( 'STAGING_SAFETY_ENV' );
	}

	/**
	 * Toelichting op de conclusie.
	 *
	 * @return string
	 */
	public static function source() {
		self::type();

		return self::$source;
	}

	/**
	 * Ziet de hostnaam eruit als staging? Puur een suggestie.
	 *
	 * @return bool
	 */
	public static function looks_like_staging() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}

		$host = strtolower( $host );

		if ( 'localhost' === $host || '127.0.0.1' === $host ) {
			return true;
		}

		// Losse labels vergelijken, zodat "bestemming.nl" niet op "test" aanslaat.
		foreach ( explode( '.', $host ) as $label ) {
			foreach ( self::HINTS as $hint ) {
				if ( $label === $hint || 0 === strpos( $label, $hint . '-' ) || substr( $label, -strlen( '-' . $hint ) ) === '-' . $hint ) {
					return true;
				}
			}
		}

		// Topleveldomeinen die nooit publiek zijn.
		foreach ( array( '.local', '.test', '.localhost', '.invalid', '.example' ) as $tld ) {
			if ( substr( $host, -strlen( $tld ) ) === $tld ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Alleen voor tests: gecachete uitkomst weggooien.
	 */
	public static function reset() {
		self::$type   = null;
		self::$source = '';
	}
}
