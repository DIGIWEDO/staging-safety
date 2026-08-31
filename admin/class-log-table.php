<?php
/**
 * Tabel met logregels.
 *
 * @package StagingSafety\Admin
 */

namespace StagingSafety\Admin;

use StagingSafety\Caller;
use StagingSafety\Logger;
use WP_List_Table;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Gewone WP_List_Table, zodat sorteren, pagineren en zoeken eruitzien zoals
 * beheerders gewend zijn.
 */
class Log_Table extends WP_List_Table {

	/**
	 * Actieve filters.
	 *
	 * @var array
	 */
	private $filters;

	/**
	 * Constructor.
	 *
	 * @param array $filters Filters.
	 */
	public function __construct( array $filters ) {
		$this->filters = $filters;

		parent::__construct(
			array(
				'singular' => 'staging_safety_log',
				'plural'   => 'staging_safety_logs',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Kolommen.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'logged_at' => __( 'Wanneer', 'staging-safety' ),
			'channel'   => __( 'Soort', 'staging-safety' ),
			'action'    => __( 'Resultaat', 'staging-safety' ),
			'target'    => __( 'Doel', 'staging-safety' ),
			'detail'    => __( 'Details', 'staging-safety' ),
			'source'    => __( 'Aanroeper', 'staging-safety' ),
			'rule'      => __( 'Regel', 'staging-safety' ),
		);
	}

	/**
	 * Sorteerbare kolommen.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'logged_at' => array( 'logged_at', true ),
			'channel'   => array( 'channel', false ),
			'action'    => array( 'action', false ),
			'target'    => array( 'target', false ),
		);
	}

	/**
	 * Gegevens ophalen.
	 */
	public function prepare_items() {
		$per_page = 50;
		$page     = $this->get_pagenum();

		$result = Logger::query(
			array_merge(
				$this->filters,
				array(
					'orderby'  => $this->filters['orderby'],
					'order'    => $this->filters['order'],
					'per_page' => $per_page,
					'page'     => $page,
				)
			)
		);

		$this->items = $result['items'];

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'target' );
	}

	/**
	 * Melding bij een lege tabel.
	 */
	public function no_items() {
		esc_html_e( 'Nog geen regels. Zet een onderdeel op "meekijken" om te zien wat de site doet.', 'staging-safety' );
	}

	/**
	 * Terugval voor kolommen zonder eigen methode.
	 *
	 * @param array  $item   Regel.
	 * @param string $column Kolomnaam.
	 * @return string
	 */
	public function column_default( $item, $column ) {
		return isset( $item[ $column ] ) ? esc_html( $item[ $column ] ) : '';
	}

	/**
	 * Tijdstip.
	 *
	 * @param array $item Regel.
	 * @return string
	 */
	public function column_logged_at( $item ) {
		$timestamp = strtotime( $item['logged_at'] );

		return esc_html( $timestamp ? wp_date( 'j M H:i:s', $timestamp ) : $item['logged_at'] );
	}

	/**
	 * Soort.
	 *
	 * @param array $item Regel.
	 * @return string
	 */
	public function column_channel( $item ) {
		$labels = array(
			'http'   => __( 'request', 'staging-safety' ),
			'mail'   => __( 'e-mail', 'staging-safety' ),
			'cron'   => __( 'cronjob', 'staging-safety' ),
			'system' => __( 'systeem', 'staging-safety' ),
		);

		return esc_html( $labels[ $item['channel'] ] ?? $item['channel'] );
	}

	/**
	 * Resultaat, als gekleurd labeltje.
	 *
	 * @param array $item Regel.
	 * @return string
	 */
	public function column_action( $item ) {
		$labels = array(
			Logger::ACTION_BLOCKED     => __( 'geblokkeerd', 'staging-safety' ),
			Logger::ACTION_WOULD_BLOCK => __( 'zou geblokkeerd zijn', 'staging-safety' ),
			Logger::ACTION_ALLOWED     => __( 'doorgelaten', 'staging-safety' ),
			Logger::ACTION_REDIRECTED  => __( 'omgeleid', 'staging-safety' ),
			Logger::ACTION_NOTICE      => __( 'melding', 'staging-safety' ),
		);

		return sprintf(
			'<span class="ss-pill ss-pill-%1$s">%2$s</span>',
			esc_attr( $item['action'] ),
			esc_html( $labels[ $item['action'] ] ?? $item['action'] )
		);
	}

	/**
	 * Doel, met de knop om een host toe te staan.
	 *
	 * @param array $item Regel.
	 * @return string
	 */
	public function column_target( $item ) {
		$target = '<code>' . esc_html( $item['target'] ) . '</code>';
		$blocked = in_array( $item['action'], array( Logger::ACTION_BLOCKED, Logger::ACTION_WOULD_BLOCK ), true );

		if ( Logger::CHANNEL_HTTP !== $item['channel'] || ! $blocked || '' === $item['target'] ) {
			return $target;
		}

		$actions = array(
			'allow' => sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( Log_Page::allow_host_url( $item['target'] ) ),
				esc_html__( 'Op witte lijst zetten', 'staging-safety' )
			),
		);

		return $target . $this->row_actions( $actions );
	}

	/**
	 * Aanroeper met de nette pluginnaam.
	 *
	 * @param array $item Regel.
	 * @return string
	 */
	public function column_source( $item ) {
		if ( '' === $item['source'] ) {
			return '—';
		}

		return esc_html( Caller::name_for_slug( $item['source'] ) );
	}

	/**
	 * Filters boven de tabel.
	 *
	 * @param string $which top of bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$channels = array(
			''       => __( 'Alle soorten', 'staging-safety' ),
			'http'   => __( 'Requests', 'staging-safety' ),
			'mail'   => __( 'E-mail', 'staging-safety' ),
			'cron'   => __( 'Cronjobs', 'staging-safety' ),
			'system' => __( 'Systeem', 'staging-safety' ),
		);

		$actions = array(
			''            => __( 'Alle resultaten', 'staging-safety' ),
			'blocked'     => __( 'Geblokkeerd', 'staging-safety' ),
			'would_block' => __( 'Zou geblokkeerd zijn', 'staging-safety' ),
			'redirected'  => __( 'Omgeleid', 'staging-safety' ),
			'allowed'     => __( 'Doorgelaten', 'staging-safety' ),
		);

		$days = array(
			'0' => __( 'Alle periodes', 'staging-safety' ),
			'1' => __( 'Laatste 24 uur', 'staging-safety' ),
			'7' => __( 'Laatste week', 'staging-safety' ),
			'30' => __( 'Laatste maand', 'staging-safety' ),
		);

		?>
		<div class="alignleft actions">
			<select name="channel">
				<?php foreach ( $channels as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $this->filters['channel'], $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>

			<select name="ss_result">
				<?php foreach ( $actions as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $this->filters['action'], $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>

			<select name="days">
				<?php foreach ( $days as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) $this->filters['days'], $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>

			<?php submit_button( __( 'Filteren', 'staging-safety' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}
}
