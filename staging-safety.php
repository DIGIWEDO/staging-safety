<?php
/**
 * Plugin Name:       Staging Safety
 * Plugin URI:        https://example.internal/staging-safety
 * Description:       Extra veiligheidslaag voor stagingomgevingen. Blokkeert en logt uitgaande requests, e-mail en cronjobs, zodat een stagingkopie geen live systemen aanroept.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Intern
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://github.com/DIGIWEDO/staging-safety
 * Text Domain:       staging-safety
 *
 * @package StagingSafety
 */

defined( 'ABSPATH' ) || exit;

define( 'STAGING_SAFETY_VERSION', '1.0.1' );
define( 'STAGING_SAFETY_FILE', __FILE__ );
define( 'STAGING_SAFETY_DIR', plugin_dir_path( __FILE__ ) );
define( 'STAGING_SAFETY_URL', plugin_dir_url( __FILE__ ) );

// Waar de updates vandaan komen. Kan per server overschreven worden door de
// constant vóór het laden van de plugin te zetten, maar dat is zelden nodig.
if ( ! defined( 'STAGING_SAFETY_GITHUB_REPO' ) ) {
	define( 'STAGING_SAFETY_GITHUB_REPO', 'DIGIWEDO/staging-safety' );
}

/**
 * Autoloader. StagingSafety\Guards\Http_Guard => includes/guards/class-http-guard.php
 */
spl_autoload_register(
	static function ( $class ) {
		$prefix = 'StagingSafety\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$parts = explode( '\\', substr( $class, strlen( $prefix ) ) );
		$name  = array_pop( $parts );
		$file  = 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		$dirs  = array_map( 'strtolower', $parts );

		if ( isset( $dirs[0] ) && 'admin' === $dirs[0] ) {
			$path = STAGING_SAFETY_DIR . implode( '/', $dirs ) . '/' . $file;
		} else {
			$sub  = $dirs ? implode( '/', $dirs ) . '/' : '';
			$path = STAGING_SAFETY_DIR . 'includes/' . $sub . $file;
		}

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( 'StagingSafety\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'StagingSafety\\Plugin', 'deactivate' ) );

/**
 * Zo vroeg mogelijk starten: de guards moeten al staan voordat andere plugins
 * hun eerste request of mail doen.
 */
StagingSafety\Plugin::instance()->boot();
