<?php
/**
 * Zoekt uit welke plugin of thema een aanroep deed.
 *
 * @package StagingSafety
 */

namespace StagingSafety;

defined( 'ABSPATH' ) || exit;

/**
 * Zonder deze informatie is het logboek een lijst losse URL's. Met deze
 * informatie zie je "wp-all-import belt elke minuut naar de leverancier".
 *
 * We lopen de backtrace af tot het eerste bestand dat in een plugin- of
 * themamap staat, en slaan onze eigen bestanden en WordPress zelf over.
 */
class Caller {

	/**
	 * Naam van de plugin per map, om herhaald inlezen te voorkomen.
	 *
	 * @var array
	 */
	private static $names = array();

	/**
	 * Korte aanduiding van de aanroeper, bijvoorbeeld "WooCommerce".
	 *
	 * @return string
	 */
	public static function identify() {
		$slug = self::slug();

		if ( '' === $slug ) {
			return '';
		}

		return self::name_for_slug( $slug );
	}

	/**
	 * Map- of themanaam van de aanroeper. Dit is wat we in regels gebruiken.
	 *
	 * @return string
	 */
	public static function slug() {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 30 );

		$plugin_dir = self::normalise_path( WP_PLUGIN_DIR );
		$mu_dir     = defined( 'WPMU_PLUGIN_DIR' ) ? self::normalise_path( WPMU_PLUGIN_DIR ) : '';
		$theme_dir  = self::normalise_path( get_theme_root() );
		$own_dir    = self::normalise_path( STAGING_SAFETY_DIR );

		foreach ( $frames as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}

			$file = self::normalise_path( $frame['file'] );

			if ( 0 === strpos( $file, $own_dir ) ) {
				continue;
			}

			$slug = self::relative_slug( $file, $plugin_dir );
			if ( $slug ) {
				return $slug;
			}

			if ( $mu_dir ) {
				$slug = self::relative_slug( $file, $mu_dir );
				if ( $slug ) {
					return 'mu:' . $slug;
				}
			}

			$slug = self::relative_slug( $file, $theme_dir );
			if ( $slug ) {
				return 'theme:' . $slug;
			}
		}

		return '';
	}

	/**
	 * Eerste mapnaam onder een basismap, of leeg als het bestand er niet in zit.
	 *
	 * @param string $file Bestandspad.
	 * @param string $base Basismap.
	 * @return string
	 */
	private static function relative_slug( $file, $base ) {
		if ( '' === $base || 0 !== strpos( $file, $base . '/' ) ) {
			return '';
		}

		$rest  = substr( $file, strlen( $base ) + 1 );
		$parts = explode( '/', $rest );

		// Bij een los bestand direct in de map (mu-plugins) is dit de bestandsnaam.
		return $parts[0];
	}

	/**
	 * Slashes gelijktrekken zodat Windows-paden ook vergelijkbaar zijn.
	 *
	 * @param string $path Pad.
	 * @return string
	 */
	private static function normalise_path( $path ) {
		return rtrim( wp_normalize_path( (string) $path ), '/' );
	}

	/**
	 * Leesbare naam bij een slug. Valt terug op de slug zelf.
	 *
	 * @param string $slug Map- of themanaam.
	 * @return string
	 */
	public static function name_for_slug( $slug ) {
		if ( isset( self::$names[ $slug ] ) ) {
			return self::$names[ $slug ];
		}

		$name = $slug;

		if ( 0 === strpos( $slug, 'theme:' ) ) {
			$name = sprintf( /* translators: %s: themanaam */ __( 'Thema %s', 'staging-safety' ), substr( $slug, 6 ) );
		} elseif ( function_exists( 'get_plugins' ) ) {
			$found = self::lookup_plugin_name( $slug );
			if ( $found ) {
				$name = $found;
			}
		}

		self::$names[ $slug ] = $name;

		return $name;
	}

	/**
	 * Pluginnaam opzoeken. Alleen in de admin beschikbaar, dus op de voorkant
	 * blijft de slug staan. Dat is geen probleem: de admin toont de nette naam.
	 *
	 * @param string $slug Mapnaam.
	 * @return string
	 */
	private static function lookup_plugin_name( $slug ) {
		$plugins = get_plugins();

		foreach ( $plugins as $file => $data ) {
			if ( dirname( $file ) === $slug ) {
				return $data['Name'];
			}
		}

		return '';
	}
}
