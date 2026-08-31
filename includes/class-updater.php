<?php
/**
 * Updates ophalen bij GitHub.
 *
 * @package StagingSafety
 */

namespace StagingSafety;

defined( 'ABSPATH' ) || exit;

/**
 * Laat WordPress updates zien voor een plugin die niet in de wordpress.org-
 * directory staat. We kijken naar de laatste release van een GitHub-repository
 * en vergelijken de tag met het versienummer in de pluginheader.
 *
 * Werkwijze bij het uitbrengen van een versie:
 *
 *   1. versienummer ophogen in staging-safety.php én readme.txt;
 *   2. taggen als v0.2.0 en er een release van maken;
 *   3. de plugin-zip als bestand aan de release hangen.
 *
 * Die zip is belangrijk: de automatische bronarchieven van GitHub pakken uit
 * naar een map met de tagnaam erin, en dan komt de plugin op de verkeerde plek
 * te staan. We vangen dat wel af, maar een eigen zip is netter.
 */
class Updater {

	const CACHE_KEY   = 'staging_safety_release';
	const CACHE_TTL   = 6 * HOUR_IN_SECONDS;
	const FAIL_TTL    = HOUR_IN_SECONDS;
	const API_HOST    = 'api.github.com';

	/**
	 * Aanhaken. Alleen in de beheeromgeving en tijdens cron: op de voorkant
	 * heeft niemand iets aan een updatecontrole.
	 */
	public function register() {
		if ( ! $this->repo() ) {
			return;
		}

		add_filter( 'http_request_args', array( $this, 'add_token' ), 10, 2 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_folder_name' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'flush' ), 10, 0 );

		if ( ! is_admin() && ! wp_doing_cron() ) {
			return;
		}

		add_filter( 'site_transient_update_plugins', array( $this, 'inject' ) );
		add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
	}

	/**
	 * De repository, als "eigenaar/naam". Staat vast in de pluginheader, dus
	 * hier valt niets in te stellen.
	 *
	 * @return string
	 */
	public function repo() {
		if ( ! Settings::get( 'updates.enabled', true ) ) {
			return '';
		}

		return self::normalise_repo( defined( 'STAGING_SAFETY_GITHUB_REPO' ) ? (string) constant( 'STAGING_SAFETY_GITHUB_REPO' ) : '' );
	}

	/**
	 * "eigenaar/naam" eruit halen. Een hele GitHub-URL mag ook, want die plak
	 * je nu eenmaal makkelijker over dan het pad alleen.
	 *
	 * @param string $value Ruwe waarde.
	 * @return string Leeg als het geen geldige repository is.
	 */
	public static function normalise_repo( $value ) {
		$repo = trim( (string) $value, " \t\n\r/" );

		if ( false !== strpos( $repo, 'github.com' ) ) {
			$path = (string) wp_parse_url( $repo, PHP_URL_PATH );
			$repo = trim( $path, '/' );
		}

		$repo = preg_replace( '/\.git$/', '', $repo );

		return preg_match( '#^[\w.-]+/[\w.-]+$#', $repo ) ? $repo : '';
	}

	/**
	 * Toegangstoken voor een besloten repository. Alleen via een constant in
	 * wp-config.php: een token hoort niet in de database.
	 *
	 * @return string
	 */
	private function token() {
		return defined( 'STAGING_SAFETY_GITHUB_TOKEN' ) ? (string) constant( 'STAGING_SAFETY_GITHUB_TOKEN' ) : '';
	}

	/**
	 * Token meesturen naar de GitHub-API. Bewust alleen naar api.github.com:
	 * het downloaden zelf gaat via een doorverwijzing naar een ander domein,
	 * en daar mag de header niet mee naartoe.
	 *
	 * @param array  $args Requestargumenten.
	 * @param string $url  Doel-URL.
	 * @return array
	 */
	public function add_token( $args, $url ) {
		$token = $this->token();

		if ( '' === $token || self::API_HOST !== Matcher::host_from_url( $url ) ) {
			return $args;
		}

		if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			$args['headers'] = array();
		}

		$args['headers']['Authorization'] = 'Bearer ' . $token;

		return $args;
	}

	/**
	 * De laatste release, uit de cache of vers opgehaald.
	 *
	 * @param bool $force Cache overslaan.
	 * @return array|null ['version','package','url','notes','date']
	 */
	public function release( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );

			if ( is_array( $cached ) ) {
				return $cached;
			}

			// Een mislukte poging onthouden we ook even, anders staat elke
			// beheerpagina op een trage of afgesloten API te wachten.
			if ( 'fail' === $cached ) {
				return null;
			}
		}

		$release = $this->fetch();

		if ( ! $release ) {
			set_transient( self::CACHE_KEY, 'fail', self::FAIL_TTL );

			return null;
		}

		set_transient( self::CACHE_KEY, $release, self::CACHE_TTL );

		return $release;
	}

	/**
	 * De release bij GitHub opvragen.
	 *
	 * @return array|null
	 */
	private function fetch() {
		$response = wp_remote_get(
			'https://' . self::API_HOST . '/repos/' . $this->repo() . '/releases/latest',
			array(
				'timeout'                 => 10,
				'headers'                 => array(
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
				),
				'user-agent'              => 'StagingSafety/' . STAGING_SAFETY_VERSION . '; ' . home_url(),
				// Onze eigen guard moet deze aanroep altijd doorlaten, anders
				// kun je de plugin niet meer bijwerken zodra hij dichtstaat.
				'staging_safety_internal' => true,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			return null;
		}

		$package = $this->package_url( $data );

		if ( ! $package ) {
			return null;
		}

		return array(
			'version' => $this->normalise_version( $data['tag_name'] ),
			'package' => $package,
			'url'     => ! empty( $data['html_url'] ) ? $data['html_url'] : 'https://github.com/' . $this->repo(),
			'notes'   => ! empty( $data['body'] ) ? (string) $data['body'] : '',
			'date'    => ! empty( $data['published_at'] ) ? (string) $data['published_at'] : '',
		);
	}

	/**
	 * Welk bestand downloaden we? Een zip die zelf aan de release hangt heeft
	 * de voorkeur; anders het bronarchief van GitHub.
	 *
	 * @param array $data Antwoord van de API.
	 * @return string
	 */
	public function package_url( array $data ) {
		$token = $this->token();

		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			if ( empty( $asset['name'] ) || '.zip' !== strtolower( substr( $asset['name'], -4 ) ) ) {
				continue;
			}

			// Bij een besloten repository moet het downloaden via de API,
			// want alleen daar kunnen we het token meesturen.
			if ( '' !== $token && ! empty( $asset['url'] ) ) {
				return (string) $asset['url'];
			}

			if ( ! empty( $asset['browser_download_url'] ) ) {
				return (string) $asset['browser_download_url'];
			}
		}

		return ! empty( $data['zipball_url'] ) ? (string) $data['zipball_url'] : '';
	}

	/**
	 * Tag naar versienummer: v0.2.0 wordt 0.2.0.
	 *
	 * @param string $tag Tagnaam.
	 * @return string
	 */
	public function normalise_version( $tag ) {
		return ltrim( trim( (string) $tag ), 'vV' );
	}

	/**
	 * De update in de lijst van WordPress zetten.
	 *
	 * @param mixed $transient Updategegevens.
	 * @return mixed
	 */
	public function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}

		$release = $this->release();

		if ( ! $release ) {
			return $transient;
		}

		$basename = plugin_basename( STAGING_SAFETY_FILE );

		$item = (object) array(
			'id'          => 'github.com/' . $this->repo(),
			'slug'        => dirname( $basename ),
			'plugin'      => $basename,
			'new_version' => $release['version'],
			'url'         => $release['url'],
			'package'     => $release['package'],
			'icons'       => array(),
			'banners'     => array(),
			'tested'      => get_bloginfo( 'version' ),
			'requires_php' => '8.0',
		);

		if ( version_compare( $release['version'], STAGING_SAFETY_VERSION, '>' ) ) {
			$transient->response[ $basename ] = $item;
			unset( $transient->no_update[ $basename ] );
		} else {
			$transient->no_update[ $basename ] = $item;
			unset( $transient->response[ $basename ] );
		}

		return $transient;
	}

	/**
	 * Het detailvenster achter "Details bekijken".
	 *
	 * @param mixed  $result Bestaand resultaat.
	 * @param string $action Gevraagde actie.
	 * @param object $args   Argumenten.
	 * @return mixed
	 */
	public function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( empty( $args->slug ) || $args->slug !== dirname( plugin_basename( STAGING_SAFETY_FILE ) ) ) {
			return $result;
		}

		$release = $this->release();

		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Staging Safety',
			'slug'          => $args->slug,
			'version'       => $release['version'],
			'requires'      => '6.0',
			'requires_php'  => '8.0',
			'tested'        => get_bloginfo( 'version' ),
			'last_updated'  => $release['date'],
			'homepage'      => $release['url'],
			'download_link' => $release['package'],
			'sections'      => array(
				'changelog' => $release['notes'] ? wpautop( esc_html( $release['notes'] ) ) : esc_html__( 'Geen omschrijving bij deze release.', 'staging-safety' ),
			),
		);
	}

	/**
	 * GitHub pakt een bronarchief uit naar een map met de tagnaam erin, zoals
	 * "DoubleWeb-staging-safety-a1b2c3". Zonder deze correctie belandt de
	 * plugin op een nieuwe plek en staat hij ineens uitgeschakeld.
	 *
	 * @param string $source        Uitgepakte map.
	 * @param string $remote_source Bovenliggende map.
	 * @param object $upgrader      Upgrader.
	 * @param array  $args          Extra gegevens.
	 * @return string|\WP_Error
	 */
	public function fix_folder_name( $source, $remote_source, $upgrader = null, $args = array() ) {
		global $wp_filesystem;

		$basename = plugin_basename( STAGING_SAFETY_FILE );

		if ( empty( $args['plugin'] ) || $args['plugin'] !== $basename ) {
			return $source;
		}

		$wanted = dirname( $basename );
		$target = trailingslashit( $remote_source ) . $wanted;

		if ( untrailingslashit( $source ) === $target ) {
			return $source;
		}

		if ( ! $wp_filesystem || ! $wp_filesystem->move( $source, $target, true ) ) {
			return new \WP_Error(
				'staging_safety_rename_failed',
				__( 'De map van de update kon niet hernoemd worden.', 'staging-safety' )
			);
		}

		return trailingslashit( $target );
	}

	/**
	 * Cache weggooien, zodat de volgende controle vers is.
	 */
	public function flush() {
		delete_transient( self::CACHE_KEY );
	}
}
