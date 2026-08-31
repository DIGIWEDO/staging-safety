<?php
/**
 * Logboekpagina.
 *
 * @package StagingSafety\Admin
 */

namespace StagingSafety\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Het logboek is het hart van de plugin: hier zie je wat een site werkelijk
 * naar buiten doet, en hier bouw je al testend je witte lijst op.
 */
class Log_Page {

	/**
	 * Uitvoeren.
	 */
	public function render() {
		$filters = $this->filters();
		$table   = new Log_Table( $filters );
		$table->prepare_items();

		?>
		<div class="wrap staging-safety">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Logboek', 'staging-safety' ); ?></h1>

			<form method="post" class="ss-inline-form">
				<?php wp_nonce_field( 'staging_safety_clear_log' ); ?>
				<input type="hidden" name="staging_safety_action" value="clear_log">
				<button type="submit" class="page-title-action" id="ss-clear-log"><?php esc_html_e( 'Logboek leegmaken', 'staging-safety' ); ?></button>
			</form>

			<hr class="wp-header-end">

			<p class="ss-muted">
				<?php esc_html_e( 'Klik bij een geblokkeerd request op "Op witte lijst zetten" om die host voortaan door te laten. Zo bouw je de lijst op terwijl je test.', 'staging-safety' ); ?>
			</p>

			<form method="get">
				<input type="hidden" name="page" value="staging-safety-log">
				<?php
				$table->search_box( __( 'Zoeken', 'staging-safety' ), 'staging-safety-log' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Filters uit de URL halen.
	 *
	 * @return array
	 */
	private function filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- alleen filters, geen wijzigingen.
		$channel = isset( $_GET['channel'] ) ? sanitize_key( wp_unslash( $_GET['channel'] ) ) : '';
		$action  = isset( $_GET['ss_result'] ) ? sanitize_key( wp_unslash( $_GET['ss_result'] ) ) : '';
		$source  = isset( $_GET['source'] ) ? sanitize_text_field( wp_unslash( $_GET['source'] ) ) : '';
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$days    = isset( $_GET['days'] ) ? (int) $_GET['days'] : 0;
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'logged_at';
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc';
		// phpcs:enable

		$channels = array( '', 'http', 'mail', 'cron', 'system' );
		$actions  = array( '', 'blocked', 'would_block', 'allowed', 'redirected', 'notice' );

		return array(
			'channel' => in_array( $channel, $channels, true ) ? $channel : '',
			'action'  => in_array( $action, $actions, true ) ? $action : '',
			'source'  => $source,
			'search'  => $search,
			'days'    => max( 0, min( 365, $days ) ),
			'orderby' => $orderby,
			'order'   => 'asc' === $order ? 'ASC' : 'DESC',
		);
	}

	/**
	 * Beveiligde link om een host op de witte lijst te zetten.
	 *
	 * @param string $host Hostnaam.
	 * @return string
	 */
	public static function allow_host_url( $host ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'page'      => 'staging-safety-log',
					'ss_action' => 'allow_host',
					'host'      => rawurlencode( $host ),
				),
				admin_url( 'admin.php' )
			),
			'staging_safety_allow_host'
		);
	}
}
