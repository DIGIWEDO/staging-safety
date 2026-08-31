<?php
/**
 * Overzichtspagina.
 *
 * @package StagingSafety\Admin
 */

namespace StagingSafety\Admin;

use StagingSafety\Environment;
use StagingSafety\Logger;
use StagingSafety\Plugin;
use StagingSafety\Risk_Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Eén scherm dat de vraag beantwoordt: is deze staging veilig, en wat gebeurt
 * er nu eigenlijk.
 */
class Dashboard_Page {

	/**
	 * Uitvoeren.
	 */
	public function render() {
		$counts = Logger::counts_since( 24 );

		?>
		<div class="wrap staging-safety">
			<h1><?php esc_html_e( 'Staging Safety', 'staging-safety' ); ?></h1>

			<?php $this->status_card(); ?>
			<?php $this->counters( $counts ); ?>
			<?php $this->warnings(); ?>
			<?php $this->top_blocked(); ?>
			<?php $this->limitations(); ?>
		</div>
		<?php
	}

	/**
	 * Bovenste kaart met de stand van zaken.
	 */
	private function status_card() {
		$is_staging = Environment::is_staging();
		$paused     = Plugin::pause_info();

		$state  = $is_staging ? 'ok' : 'off';
		$state  = $paused ? 'warn' : $state;
		$labels = array(
			'ok'   => __( 'Actief', 'staging-safety' ),
			'warn' => __( 'Gepauzeerd', 'staging-safety' ),
			'off'  => __( 'Uit', 'staging-safety' ),
		);

		?>
		<div class="ss-card ss-card-<?php echo esc_attr( $state ); ?>">
			<div class="ss-card-head">
				<span class="ss-badge ss-badge-<?php echo esc_attr( $state ); ?>"><?php echo esc_html( $labels[ $state ] ); ?></span>
				<h2>
					<?php
					echo esc_html(
						$is_staging
							? __( 'Deze site draait als stagingomgeving', 'staging-safety' )
							: __( 'Deze site geldt nog niet als staging', 'staging-safety' )
					);
					?>
				</h2>
			</div>

			<p class="ss-muted">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: bron van de conclusie */
						__( 'Vastgesteld op basis van %s.', 'staging-safety' ),
						Environment::source()
					)
				);
				?>
			</p>

			<?php if ( ! $is_staging && ! Environment::is_locked_to_production() ) : ?>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( Admin::env_url( 'confirm_staging' ) ); ?>">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: domeinnaam */
								__( 'Ja, %s is een stagingomgeving', 'staging-safety' ),
								Environment::current_host()
							)
						);
						?>
					</a>
				</p>
			<?php endif; ?>

			<table class="ss-modes">
				<tbody>
				<?php foreach ( Plugin::instance()->guards() as $name => $guard ) : ?>
					<tr>
						<th><?php echo esc_html( $this->guard_label( $name ) ); ?></th>
						<td><?php $this->mode_pill( $guard ); ?></td>
						<td class="ss-muted"><?php echo esc_html( $this->mode_explanation( $guard ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=staging-safety-settings' ) ); ?>">
					<?php esc_html_e( 'Instellingen aanpassen', 'staging-safety' ); ?>
				</a>
				<?php if ( $paused ) : ?>
					<a class="button" href="<?php echo esc_url( Indicator::pause_url( 0 ) ); ?>">
						<?php esc_html_e( 'Beveiliging nu weer aanzetten', 'staging-safety' ); ?>
					</a>
				<?php elseif ( $is_staging ) : ?>
					<a class="button" href="<?php echo esc_url( Indicator::pause_url( 15 ) ); ?>">
						<?php esc_html_e( 'Pauzeer 15 minuten', 'staging-safety' ); ?>
					</a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Nette naam bij een guard.
	 *
	 * @param string $name Sleutel.
	 * @return string
	 */
	private function guard_label( $name ) {
		$labels = array(
			'http' => __( 'Uitgaande requests', 'staging-safety' ),
			'mail' => __( 'E-mail', 'staging-safety' ),
			'cron' => __( 'Cronjobs', 'staging-safety' ),
		);

		return isset( $labels[ $name ] ) ? $labels[ $name ] : $name;
	}

	/**
	 * Gekleurd labeltje met wat er werkelijk gebeurt. Staat een guard op
	 * blokkeren terwijl de omgeving niet bevestigd is, dan blokkeert hij niets
	 * — en dan mag er hier ook geen rood "blokkeert" staan.
	 *
	 * @param \StagingSafety\Guards\Guard $guard Guard.
	 */
	private function mode_pill( $guard ) {
		$mode = $guard->mode();

		if ( ! $guard->is_active() ) {
			$state = 'off';
			$label = 'off' === $mode ? __( 'uit', 'staging-safety' ) : __( 'slaapt', 'staging-safety' );
		} elseif ( 'block' === $mode && Plugin::is_paused() ) {
			$state = 'monitor';
			$label = __( 'gepauzeerd', 'staging-safety' );
		} else {
			$labels = array(
				'off'     => __( 'uit', 'staging-safety' ),
				'monitor' => __( 'kijkt mee', 'staging-safety' ),
				'block'   => __( 'blokkeert', 'staging-safety' ),
			);

			$state = $mode;
			$label = isset( $labels[ $mode ] ) ? $labels[ $mode ] : $mode;
		}

		printf(
			'<span class="ss-pill ss-pill-%1$s">%2$s</span>',
			esc_attr( $state ),
			esc_html( $label )
		);
	}

	/**
	 * Uitleg in gewone taal bij wat die guard nu doet.
	 *
	 * @param \StagingSafety\Guards\Guard $guard Guard.
	 * @return string
	 */
	private function mode_explanation( $guard ) {
		$mode = $guard->mode();

		if ( ! $guard->is_active() ) {
			if ( 'off' === $mode ) {
				return __( 'niets wordt bijgehouden', 'staging-safety' );
			}

			$word = 'block' === $mode
				? __( 'blokkeren', 'staging-safety' )
				: __( 'meekijken', 'staging-safety' );

			return sprintf(
				/* translators: %s: ingestelde stand */
				__( 'staat op %s, maar doet niets zolang de omgeving niet bevestigd is', 'staging-safety' ),
				$word
			);
		}

		if ( 'block' === $mode && Plugin::is_paused() ) {
			return __( 'tijdelijk gepauzeerd, alles gaat er nu gewoon doorheen', 'staging-safety' );
		}

		switch ( $mode ) {
			case 'block':
				return __( 'wordt tegengehouden en gelogd', 'staging-safety' );
			case 'monitor':
				return __( 'wordt alleen gelogd, niets wordt tegengehouden', 'staging-safety' );
			default:
				return __( 'niets wordt bijgehouden', 'staging-safety' );
		}
	}

	/**
	 * Tellers van de afgelopen 24 uur.
	 *
	 * @param array $counts Aantallen per kanaal en actie.
	 */
	private function counters( array $counts ) {
		$sum = static function ( $counts, $suffix ) {
			$total = 0;
			foreach ( $counts as $key => $value ) {
				if ( substr( $key, -strlen( $suffix ) ) === $suffix ) {
					$total += $value;
				}
			}

			return $total;
		};

		$tiles = array(
			array(
				'label' => __( 'Geblokkeerd', 'staging-safety' ),
				'value' => $sum( $counts, ':blocked' ),
				'tone'  => 'block',
			),
			array(
				'label' => __( 'Zou geblokkeerd zijn', 'staging-safety' ),
				'value' => $sum( $counts, ':would_block' ),
				'tone'  => 'monitor',
			),
			array(
				'label' => __( 'Omgeleid', 'staging-safety' ),
				'value' => $sum( $counts, ':redirected' ),
				'tone'  => 'monitor',
			),
			array(
				'label' => __( 'Doorgelaten', 'staging-safety' ),
				'value' => $sum( $counts, ':allowed' ),
				'tone'  => 'ok',
			),
		);

		?>
		<h2><?php esc_html_e( 'Afgelopen 24 uur', 'staging-safety' ); ?></h2>
		<div class="ss-tiles">
			<?php foreach ( $tiles as $tile ) : ?>
				<div class="ss-tile ss-tile-<?php echo esc_attr( $tile['tone'] ); ?>">
					<span class="ss-tile-value"><?php echo esc_html( number_format_i18n( $tile['value'] ) ); ?></span>
					<span class="ss-tile-label"><?php echo esc_html( $tile['label'] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=staging-safety-log' ) ); ?>">
				<?php esc_html_e( 'Naar het volledige logboek', 'staging-safety' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Waarschuwingen over risicoplugins. Niet weg te klikken: ze beschrijven
	 * wat er op deze site kan misgaan, en dat verandert niet doordat je het
	 * een keer gelezen hebt.
	 */
	private function warnings() {
		$warnings = Risk_Scanner::detect();

		?>
		<h2><?php esc_html_e( 'Risicoplugins', 'staging-safety' ); ?></h2>
		<?php

		if ( ! $warnings ) {
			?>
			<p class="ss-muted"><?php esc_html_e( 'Geen bekende risicoplugins actief.', 'staging-safety' ); ?></p>
			<?php

			return;
		}

		?>
		<div class="ss-warnings">
			<?php foreach ( $warnings as $warning ) : ?>
				<div class="ss-warning">
					<div class="ss-warning-head">
						<strong><?php echo esc_html( $warning['name'] ); ?></strong>
						<span class="ss-tag"><?php echo esc_html( $warning['category'] ); ?></span>
					</div>
					<p><?php echo esc_html( $warning['advice'] ); ?></p>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Meest geblokkeerde doelen.
	 */
	private function top_blocked() {
		$rows = Logger::top_blocked( 10, 168 );

		?>
		<h2><?php esc_html_e( 'Meest tegengehouden, afgelopen week', 'staging-safety' ); ?></h2>
		<?php

		if ( ! $rows ) {
			?>
			<p class="ss-muted"><?php esc_html_e( 'Nog niets tegengehouden.', 'staging-safety' ); ?></p>
			<?php

			return;
		}

		?>
		<table class="widefat striped ss-top">
			<thead>
			<tr>
				<th><?php esc_html_e( 'Soort', 'staging-safety' ); ?></th>
				<th><?php esc_html_e( 'Doel', 'staging-safety' ); ?></th>
				<th><?php esc_html_e( 'Aanroeper', 'staging-safety' ); ?></th>
				<th><?php esc_html_e( 'Aantal', 'staging-safety' ); ?></th>
				<th></th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row['channel'] ); ?></td>
					<td><code><?php echo esc_html( $row['target'] ); ?></code></td>
					<td><?php echo esc_html( $row['source'] ? \StagingSafety\Caller::name_for_slug( $row['source'] ) : '—' ); ?></td>
					<td><?php echo esc_html( number_format_i18n( (int) $row['total'] ) ); ?></td>
					<td>
						<?php if ( 'http' === $row['channel'] ) : ?>
							<a href="<?php echo esc_url( Log_Page::allow_host_url( $row['target'] ) ); ?>">
								<?php esc_html_e( 'Toestaan', 'staging-safety' ); ?>
							</a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Eerlijk zijn over wat de plugin niet ziet. Anders denkt iemand dat een
	 * leeg logboek betekent dat er niets gebeurt.
	 */
	private function limitations() {
		?>
		<h2><?php esc_html_e( 'Wat deze plugin niet ziet', 'staging-safety' ); ?></h2>
		<div class="ss-note">
			<p>
				<?php esc_html_e( 'Staging Safety onderschept alles wat via WordPress zelf loopt: wp_remote_get en verwanten, wp_mail en WP-Cron. Dat is verreweg het meeste, inclusief WooCommerce, betaalproviders en de bekende koppelingen.', 'staging-safety' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'Een plugin die zelf rechtstreeks cURL of file_get_contents gebruikt gaat hier niet langs. Ook e-mail die via een externe verzenddienst gaat in plaats van via wp_mail (MailPoet, sommige SMTP-plugins met eigen API) blijft buiten beeld. Een leeg logboek betekent dus niet automatisch dat er niets naar buiten gaat.', 'staging-safety' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'Voor die gevallen blijft het handwerk: de betreffende plugin in testmodus zetten, of het op serverniveau dichtzetten.', 'staging-safety' ); ?>
			</p>
		</div>
		<?php
	}
}
