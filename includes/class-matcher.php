<?php
/**
 * Vergelijkt hosts en e-mailadressen met de regels van de gebruiker.
 *
 * @package StagingSafety
 */

namespace StagingSafety;

defined( 'ABSPATH' ) || exit;

/**
 * Patroonvormen die we ondersteunen:
 *
 *   example.com     precies deze host
 *   *.example.com   example.com en alle subdomeinen
 *   *               alles
 *   *.nl            alles op .nl
 */
class Matcher {

	/**
	 * Eerste patroon dat op deze host past, of null.
	 *
	 * @param string $host     Hostnaam.
	 * @param array  $patterns Lijst met patronen.
	 * @return string|null
	 */
	public static function match_host( $host, $patterns ) {
		$host = self::normalise_host( $host );
		if ( '' === $host || ! is_array( $patterns ) ) {
			return null;
		}

		foreach ( $patterns as $pattern ) {
			if ( self::host_matches( $host, $pattern ) ) {
				return $pattern;
			}
		}

		return null;
	}

	/**
	 * Past één patroon op deze host?
	 *
	 * @param string $host    Genormaliseerde hostnaam.
	 * @param string $pattern Patroon.
	 * @return bool
	 */
	public static function host_matches( $host, $pattern ) {
		// Ook de host normaliseren: deze methode wordt ook los aangeroepen,
		// en dan is hij nog niet langs match_host() geweest.
		$host    = self::normalise_host( $host );
		$pattern = self::normalise_host( $pattern );

		if ( '' === $pattern || '' === $host ) {
			return false;
		}

		if ( '*' === $pattern ) {
			return true;
		}

		if ( 0 === strpos( $pattern, '*.' ) ) {
			$base = substr( $pattern, 2 );

			// Zowel het domein zelf als alles eronder.
			return $host === $base || substr( $host, -strlen( '.' . $base ) ) === '.' . $base;
		}

		return $host === $pattern;
	}

	/**
	 * Hostnaam opschonen: kleine letters, geen schema, poort of pad,
	 * en de punt aan het eind van een FQDN weg.
	 *
	 * @param string $value Ruwe invoer.
	 * @return string
	 */
	public static function normalise_host( $value ) {
		$value = strtolower( trim( (string) $value ) );

		if ( '' === $value ) {
			return '';
		}

		if ( false !== strpos( $value, '://' ) ) {
			$parsed = wp_parse_url( $value, PHP_URL_HOST );
			$value  = $parsed ? strtolower( $parsed ) : $value;
		}

		// Eventueel achtergebleven pad of poort.
		$value = strtok( $value, '/' );
		if ( false !== strpos( $value, ':' ) && false === strpos( $value, '[' ) ) {
			$value = strtok( $value, ':' );
		}

		return rtrim( (string) $value, '.' );
	}

	/**
	 * Host uit een URL halen.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function host_from_url( $url ) {
		$host = wp_parse_url( (string) $url, PHP_URL_HOST );

		return $host ? self::normalise_host( $host ) : '';
	}

	/**
	 * Past dit e-mailadres bij een van de regels? Toegestaan is een heel
	 * adres (piet@klant.nl) of een domein (klant.nl / *.klant.nl).
	 *
	 * @param string $email    E-mailadres.
	 * @param array  $patterns Regels.
	 * @return string|null
	 */
	public static function match_email( $email, $patterns ) {
		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email || ! is_array( $patterns ) ) {
			return null;
		}

		$domain = self::email_domain( $email );

		foreach ( $patterns as $pattern ) {
			$clean = strtolower( trim( (string) $pattern ) );

			if ( '' === $clean ) {
				continue;
			}

			if ( false !== strpos( $clean, '@' ) ) {
				if ( $clean === $email ) {
					return $pattern;
				}
				continue;
			}

			if ( $domain && self::host_matches( $domain, $clean ) ) {
				return $pattern;
			}
		}

		return null;
	}

	/**
	 * Domeindeel van een e-mailadres.
	 *
	 * @param string $email E-mailadres.
	 * @return string
	 */
	public static function email_domain( $email ) {
		$at = strrpos( (string) $email, '@' );

		return false === $at ? '' : self::normalise_host( substr( $email, $at + 1 ) );
	}

	/**
	 * Ontvangers uit de wp_mail-invoer halen. Die mag een string met komma's
	 * zijn of een array, en soms staat er "Naam <adres@x.nl>".
	 *
	 * @param string|array $to Ontvangers.
	 * @return array
	 */
	public static function extract_recipients( $to ) {
		if ( ! is_array( $to ) ) {
			$to = explode( ',', (string) $to );
		}

		$out = array();

		foreach ( $to as $entry ) {
			$entry = trim( (string) $entry );
			if ( '' === $entry ) {
				continue;
			}

			if ( preg_match( '/<([^>]+)>/', $entry, $m ) ) {
				$entry = trim( $m[1] );
			}

			$out[] = $entry;
		}

		return $out;
	}
}
