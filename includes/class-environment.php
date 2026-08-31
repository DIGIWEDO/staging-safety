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
 * blokkeren we echte betalingen. Daarom staat het antwoord uitsluitend in
 * wp-config.php en nooit in de database: een databasekopie van productie
 * reist mee naar staging en andersom, wp-config.php niet.
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

		self::$source = __( 'niets — er staat geen omgeving in wp-config.php', 'staging-safety' );
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
