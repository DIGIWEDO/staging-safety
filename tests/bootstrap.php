<?php
/**
 * Minimale WordPress-stubs om de beslislogica los te kunnen draaien.
 */
define( 'ABSPATH', '/tmp/sstest/wp/' );
define( 'WP_PLUGIN_DIR', '/tmp/sstest/wp/wp-content/plugins' );
define( 'WPMU_PLUGIN_DIR', '/tmp/sstest/wp/wp-content/mu-plugins' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['ss_options']    = array();
$GLOBALS['ss_transients'] = array();
$GLOBALS['ss_filters']    = array();

function __( $t, $d = null ) { return $t; }
function _n( $s, $p, $n, $d = null ) { return 1 === $n ? $s : $p; }
function esc_html( $t ) { return $t; }
function esc_attr( $t ) { return $t; }
function sanitize_text_field( $t ) { return trim( strip_tags( (string) $t ) ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_normalize_path( $p ) { return str_replace( '\\', '/', $p ); }
function wp_is_numeric_array( $d ) {
	if ( ! is_array( $d ) ) { return false; }
	foreach ( array_keys( $d ) as $k ) { if ( ! is_int( $k ) ) { return false; } }
	return true;
}
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function get_option( $n, $default = false ) { return array_key_exists( $n, $GLOBALS['ss_options'] ) ? $GLOBALS['ss_options'][ $n ] : $default; }
function update_option( $n, $v, $a = null ) { $GLOBALS['ss_options'][ $n ] = $v; return true; }
function add_option( $n, $v, $x = '', $a = null ) { $GLOBALS['ss_options'][ $n ] = $v; return true; }
function get_site_option( $n, $d = false ) { return $d; }
function get_transient( $k ) { return isset( $GLOBALS['ss_transients'][ $k ] ) ? $GLOBALS['ss_transients'][ $k ] : false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['ss_transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['ss_transients'][ $k ] ); return true; }
$GLOBALS['ss_host'] = 'staging.klant.nl';
function home_url( $p = '' ) { return 'https://' . $GLOBALS['ss_host'] . $p; }
function site_url( $p = '' ) { return home_url( $p ); }
function network_home_url( $p = '' ) { return home_url( $p ); }
function get_current_user_id() { return 1; }
function wp_get_current_user() { return null; }
function current_time( $type ) { return gmdate( 'Y-m-d H:i:s' ); }
function is_multisite() { return false; }
function add_filter( $h, $c, $p = 10, $a = 1 ) { return true; }
function add_action( $h, $c, $p = 10, $a = 1 ) { return true; }
function apply_filters( $h, $v ) { return $v; }
function is_admin() { return false; }
function get_theme_root() { return '/tmp/sstest/wp/wp-content/themes'; }
function get_plugins() { return array(); }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }
function plugin_basename( $f ) { return 'staging-safety/staging-safety.php'; }
function wp_doing_cron() { return false; }
function get_bloginfo( $x = '' ) { return '6.8'; }

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = '' ) { $this->code = $c; $this->message = $m; $this->data = $d; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}

define( 'STAGING_SAFETY_DIR', dirname( __DIR__ ) . '/' );
define( 'STAGING_SAFETY_VERSION', 'test' );
define( 'STAGING_SAFETY_GITHUB_REPO', 'DIGIWEDO/staging-safety' );
define( 'STAGING_SAFETY_FILE', STAGING_SAFETY_DIR . 'staging-safety.php' );

$base = dirname( __DIR__ );
foreach ( array(
	'includes/class-settings.php',
	'includes/class-environment.php',
	'includes/class-matcher.php',
	'includes/class-caller.php',
	'includes/class-logger.php',
	'includes/class-risk-scanner.php',
	'includes/class-updater.php',
	'includes/class-plugin.php',
	'includes/guards/class-guard.php',
	'includes/guards/class-http-guard.php',
	'includes/guards/class-mail-guard.php',
	'includes/guards/class-cron-guard.php',
) as $file ) {
	require_once $base . '/' . $file;
}

$GLOBALS['ss_pass'] = 0;
$GLOBALS['ss_fail'] = 0;

function ok( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		$GLOBALS['ss_pass']++;
		return;
	}
	$GLOBALS['ss_fail']++;
	echo "FOUT: {$label}\n  verwacht: " . var_export( $expected, true ) . "\n  kreeg:    " . var_export( $actual, true ) . "\n";
}
