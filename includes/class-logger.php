<?php
/**
 * Logboek van alle beslissingen.
 *
 * @package StagingSafety
 */

namespace StagingSafety;

defined( 'ABSPATH' ) || exit;

/**
 * Eigen tabel in plaats van een option of een custom post type: het worden er
 * op een drukke site duizenden per dag en je wilt erop kunnen filteren.
 */
class Logger {

	const CHANNEL_HTTP   = 'http';
	const CHANNEL_MAIL   = 'mail';
	const CHANNEL_CRON   = 'cron';
	const CHANNEL_SYSTEM = 'system';

	const ACTION_BLOCKED    = 'blocked';
	const ACTION_ALLOWED    = 'allowed';
	const ACTION_WOULD_BLOCK = 'would_block';
	const ACTION_REDIRECTED = 'redirected';
	const ACTION_NOTICE     = 'notice';

	const CLEANUP_HOOK = 'staging_safety_cleanup_log';

	/**
	 * Tabelnaam.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'staging_safety_log';
	}

	/**
	 * Tabel aanmaken of bijwerken.
	 */
	public static function install_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			logged_at datetime NOT NULL,
			channel varchar(20) NOT NULL,
			action varchar(20) NOT NULL,
			target varchar(255) NOT NULL DEFAULT '',
			detail text NULL,
			rule varchar(191) NOT NULL DEFAULT '',
			source varchar(191) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY logged_at (logged_at),
			KEY channel_action (channel,action),
			KEY target (target)
		) {$collate};";

		dbDelta( $sql );
	}

	/**
	 * Bestaat de tabel? Antwoord onthouden, dit mag geen query per regel worden.
	 *
	 * @return bool
	 */
	private static function table_exists() {
		static $exists = null;

		if ( null !== $exists ) {
			return $exists;
		}

		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$found  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$exists = ( $found === $table );

		return $exists;
	}

	/**
	 * Regel wegschrijven.
	 *
	 * @param string $channel Soort: http, mail, cron, system.
	 * @param string $action  Wat er gebeurde.
	 * @param string $target  Host, e-mailadres of hooknaam.
	 * @param array  $args    Optioneel: detail, rule, source.
	 * @return void
	 */
	public static function log( $channel, $action, $target, array $args = array() ) {
		if ( ! Settings::get( 'log.enabled' ) || ! self::table_exists() ) {
			return;
		}

		global $wpdb;

		$row = array(
			'logged_at' => current_time( 'mysql' ),
			'channel'   => substr( (string) $channel, 0, 20 ),
			'action'    => substr( (string) $action, 0, 20 ),
			'target'    => substr( (string) $target, 0, 255 ),
			'detail'    => isset( $args['detail'] ) ? substr( (string) $args['detail'], 0, 2000 ) : '',
			'rule'      => isset( $args['rule'] ) ? substr( (string) $args['rule'], 0, 191 ) : '',
			'source'    => isset( $args['source'] ) ? substr( (string) $args['source'], 0, 191 ) : '',
			'user_id'   => get_current_user_id(),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( self::table(), $row, array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ) );
	}

	/**
	 * Regels ophalen voor de logboekpagina.
	 *
	 * @param array $args Filters: channel, action, source, search, days, orderby, order, per_page, page.
	 * @return array
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		$args = wp_parse_args(
			$args,
			array(
				'channel'  => '',
				'action'   => '',
				'source'   => '',
				'search'   => '',
				'days'     => 0,
				'orderby'  => 'logged_at',
				'order'    => 'DESC',
				'per_page' => 50,
				'page'     => 1,
			)
		);

		list( $where, $params ) = self::build_where( $args );

		$orderby = in_array( $args['orderby'], array( 'logged_at', 'channel', 'action', 'target', 'source' ), true )
			? $args['orderby']
			: 'logged_at';
		$order   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$per_page = max( 1, min( 500, (int) $args['per_page'] ) );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		$table = self::table();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count_sql = "SELECT COUNT(*) FROM {$table} {$where}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );

		$sql        = "SELECT * FROM {$table} {$where} ORDER BY {$orderby} {$order}, id {$order} LIMIT %d OFFSET %d";
		$sql_params = array_merge( $params, array( $per_page, $offset ) );
		$items      = $wpdb->get_results( $wpdb->prepare( $sql, $sql_params ), ARRAY_A );
		// phpcs:enable

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * WHERE-clausule opbouwen met placeholders.
	 *
	 * @param array $args Filters.
	 * @return array [ string $where, array $params ]
	 */
	private static function build_where( array $args ) {
		global $wpdb;

		$clauses = array();
		$params  = array();

		if ( $args['channel'] ) {
			$clauses[] = 'channel = %s';
			$params[]  = $args['channel'];
		}

		if ( $args['action'] ) {
			$clauses[] = 'action = %s';
			$params[]  = $args['action'];
		}

		if ( $args['source'] ) {
			$clauses[] = 'source = %s';
			$params[]  = $args['source'];
		}

		if ( $args['search'] ) {
			$clauses[] = '(target LIKE %s OR detail LIKE %s)';
			$like      = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$params[]  = $like;
			$params[]  = $like;
		}

		if ( (int) $args['days'] > 0 ) {
			$clauses[] = 'logged_at >= %s';
			$params[]  = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( (int) $args['days'] * DAY_IN_SECONDS ) );
		}

		$where = $clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '';

		return array( $where, $params );
	}

	/**
	 * Aantallen per actie sinds X uur, voor het overzicht.
	 *
	 * @param int $hours Aantal uren terug.
	 * @return array
	 */
	public static function counts_since( $hours = 24 ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array();
		}

		$since = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( (int) $hours * HOUR_IN_SECONDS ) );
		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT channel, action, COUNT(*) AS total FROM {$table} WHERE logged_at >= %s GROUP BY channel, action", $since ),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['channel'] . ':' . $row['action'] ] = (int) $row['total'];
		}

		return $out;
	}

	/**
	 * Meest geblokkeerde doelen, voor het overzicht.
	 *
	 * @param int $limit Aantal regels.
	 * @param int $hours Periode.
	 * @return array
	 */
	public static function top_blocked( $limit = 10, $hours = 168 ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array();
		}

		$since = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( (int) $hours * HOUR_IN_SECONDS ) );
		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT channel, target, source, COUNT(*) AS total
				 FROM {$table}
				 WHERE logged_at >= %s AND action IN ('blocked','would_block')
				 GROUP BY channel, target, source
				 ORDER BY total DESC
				 LIMIT %d",
				$since,
				(int) $limit
			),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}

	/**
	 * Bekende bronnen, voor het filter in het logboek.
	 *
	 * @return array
	 */
	public static function known_sources() {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array();
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col( "SELECT DISTINCT source FROM {$table} WHERE source <> '' ORDER BY source ASC LIMIT 200" );

		return $rows ? $rows : array();
	}

	/**
	 * Logboek leegmaken.
	 *
	 * @return int Aantal verwijderde regels.
	 */
	public static function clear() {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		return (int) $wpdb->query( "DELETE FROM {$table}" );
	}

	/**
	 * Oude regels opruimen. Draait dagelijks via cron.
	 */
	public static function cleanup() {
		global $wpdb;

		$days = (int) Settings::get( 'log.retention_days', 30 );
		if ( $days < 1 || ! self::table_exists() ) {
			return;
		}

		$before = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( $days * DAY_IN_SECONDS ) );
		$table  = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE logged_at < %s", $before ) );
	}
}
