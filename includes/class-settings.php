<?php
/**
 * Alle instellingen in één option.
 *
 * @package StagingSafety
 */

namespace StagingSafety;

defined( 'ABSPATH' ) || exit;

/**
 * Eén option-array in plaats van tientallen losse options: scheelt queries en
 * houdt het geheel makkelijk te exporteren naar een volgende site.
 */
class Settings {

	const OPTION = 'staging_safety_settings';

	/**
	 * Gecachete instellingen.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Standaardwaarden. Bewust voorzichtig: niets blokkeert uit zichzelf,
	 * je begint met meekijken.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'http'               => array(
				'mode'         => 'monitor',
				'policy'       => 'whitelist',
				'allow'        => array( '*.wordpress.org', '*.w.org' ),
				'deny'         => array(),
				'plugin_rules' => array(),
				'log_allowed'  => true,
			),
			'mail'               => array(
				'mode'           => 'monitor',
				'strategy'       => 'redirect',
				'redirect_to'    => array(),
				'allow_domains'  => array(),
				'subject_prefix' => '[STAGING]',
			),
			'cron'               => array(
				'mode'                 => 'monitor',
				'blocked_hooks'        => array(),
				'block_new_schedules'  => false,
			),
			'indicator'          => array(
				'enabled'  => true,
				'label'    => 'STAGING',
				'color'    => '#d63638',
				'frontend' => true,
				'login'    => true,
			),
			'log'                => array(
				'enabled'        => true,
				'retention_days' => 30,
			),
			'dismissed_warnings' => array(),
		);
	}

	/**
	 * Alle instellingen, aangevuld met de defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		self::$cache = self::merge( self::defaults(), $stored );

		return self::$cache;
	}

	/**
	 * Defaults aanvullen, maar één niveau diep: lijsten van de gebruiker
	 * mogen niet met defaults vermengd raken.
	 *
	 * @param array $defaults Standaardwaarden.
	 * @param array $stored   Opgeslagen waarden.
	 * @return array
	 */
	private static function merge( array $defaults, array $stored ) {
		$out = $defaults;

		foreach ( $stored as $key => $value ) {
			if ( isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) && is_array( $value ) && ! wp_is_numeric_array( $defaults[ $key ] ) ) {
				$out[ $key ] = array_merge( $defaults[ $key ], $value );
			} else {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}

	/**
	 * Eén waarde ophalen. Puntnotatie: get('http.mode').
	 *
	 * @param string $path    Sleutel, eventueel met punten.
	 * @param mixed  $default Terugvalwaarde.
	 * @return mixed
	 */
	public static function get( $path, $default = null ) {
		$value = self::all();

		foreach ( explode( '.', $path ) as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				return $default;
			}
			$value = $value[ $key ];
		}

		return $value;
	}

	/**
	 * Een hele groep opslaan.
	 *
	 * @param string $group Groepsnaam, bijvoorbeeld 'http'.
	 * @param array  $value Nieuwe waarden.
	 */
	public static function set_group( $group, array $value ) {
		$all = self::all();

		$all[ $group ] = isset( $all[ $group ] ) && is_array( $all[ $group ] )
			? array_merge( $all[ $group ], $value )
			: $value;

		self::save( $all );
	}

	/**
	 * Eén waarde op het bovenste niveau opslaan.
	 *
	 * @param string $key   Sleutel.
	 * @param mixed  $value Waarde.
	 */
	public static function set( $key, $value ) {
		$all         = self::all();
		$all[ $key ] = $value;

		self::save( $all );
	}

	/**
	 * Wegschrijven en cache verversen.
	 *
	 * @param array $all Volledige instellingen.
	 */
	public static function save( array $all ) {
		self::$cache = $all;
		update_option( self::OPTION, $all, false );
		Environment::reset();
	}

	/**
	 * Cache leegmaken.
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Regels uit een tekstvak: één per regel, lege regels en # weg.
	 *
	 * @param string $text Ruwe invoer.
	 * @return array
	 */
	public static function lines_to_array( $text ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $text );
		$out   = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			$out[] = $line;
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Andersom, voor in het formulier.
	 *
	 * @param mixed $value Lijst.
	 * @return string
	 */
	public static function array_to_lines( $value ) {
		return is_array( $value ) ? implode( "\n", $value ) : '';
	}
}
