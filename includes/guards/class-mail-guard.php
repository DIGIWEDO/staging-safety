<?php
/**
 * Houdt uitgaande e-mail tegen of leidt hem om.
 *
 * @package StagingSafety\Guards
 */

namespace StagingSafety\Guards;

use StagingSafety\Caller;
use StagingSafety\Logger;
use StagingSafety\Matcher;
use StagingSafety\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Dit is het onderdeel waar de meeste schade zit. Een staging die orderbevestigingen
 * of nieuwsbrieven naar echte klanten stuurt is meteen een incident.
 *
 * pre_wp_mail kan de hele verzending kortsluiten, wp_mail kan hem aanpassen.
 * Bij blokkeren geven we true terug ("gelukt"), zodat de aanroepende plugin niet
 * in een foutafhandeling of herhaalpoging belandt.
 */
class Mail_Guard extends Guard {

	/**
	 * Al gelogde mails binnen dit request.
	 *
	 * SMTP-plugins halen een mail geregeld meer dan eens langs de wp_mail-
	 * filter. Er gaat er dan één de deur uit, maar wij zouden er drie loggen.
	 *
	 * @var array
	 */
	private $logged = array();

	/**
	 * Logkanaal.
	 *
	 * @return string
	 */
	public function channel() {
		return 'mail';
	}

	/**
	 * Aanhaken.
	 */
	protected function hook() {
		add_filter( 'pre_wp_mail', array( $this, 'intercept' ), 5, 2 );
		add_filter( 'wp_mail', array( $this, 'rewrite' ), 5 );
	}

	/**
	 * Volledig tegenhouden als er niets overblijft om te versturen.
	 *
	 * @param null|bool $short_circuit Antwoord van een eerdere filter.
	 * @param array     $atts          Mailargumenten.
	 * @return null|bool
	 */
	public function intercept( $short_circuit, $atts ) {
		if ( null !== $short_circuit ) {
			return $short_circuit;
		}

		$plan = $this->plan( $atts );

		if ( 'send' === $plan['action'] ) {
			return null;
		}

		if ( 'rewrite' === $plan['action'] ) {
			// De aanpassing zelf gebeurt in rewrite(), die hierna langskomt.
			return null;
		}

		$this->log( $this->block_action(), $plan, $atts );

		if ( ! $this->is_blocking() ) {
			return null;
		}

		// true = "verstuurd", zodat webshops en formulieren niet gaan hertellen.
		return true;
	}

	/**
	 * Ontvangers omleiden of uitdunnen.
	 *
	 * @param array $atts Mailargumenten.
	 * @return array
	 */
	public function rewrite( $atts ) {
		$plan = $this->plan( $atts );

		if ( 'rewrite' !== $plan['action'] || ! $this->is_blocking() ) {
			if ( 'send' === $plan['action'] ) {
				$this->log( Logger::ACTION_ALLOWED, $plan, $atts );
			} elseif ( 'rewrite' === $plan['action'] ) {
				$this->log( Logger::ACTION_WOULD_BLOCK, $plan, $atts );
			}

			return $atts;
		}

		$this->log( Logger::ACTION_REDIRECTED, $plan, $atts );

		$original = Matcher::extract_recipients( isset( $atts['to'] ) ? $atts['to'] : array() );

		$atts['to']      = $plan['recipients'];
		$atts['headers'] = $this->strip_copies( isset( $atts['headers'] ) ? $atts['headers'] : array() );

		$prefix = (string) Settings::get( 'mail.subject_prefix' );
		if ( '' !== $prefix && isset( $atts['subject'] ) && 0 !== strpos( (string) $atts['subject'], $prefix ) ) {
			$atts['subject'] = $prefix . ' ' . $atts['subject'];
		}

		if ( 'redirect' === $plan['strategy'] ) {
			$atts['message'] = $this->add_notice( isset( $atts['message'] ) ? $atts['message'] : '', $original, $atts['headers'] );
		}

		return $atts;
	}

	/**
	 * Bepaalt wat er met deze mail moet gebeuren.
	 *
	 * @param array $atts Mailargumenten.
	 * @return array ['action' => send|rewrite|block, 'recipients' => array, 'rule' => string, 'strategy' => string]
	 */
	public function plan( $atts ) {
		$strategy   = (string) Settings::get( 'mail.strategy', 'redirect' );
		$recipients = Matcher::extract_recipients( isset( $atts['to'] ) ? $atts['to'] : array() );

		if ( 'allow' === $strategy ) {
			return $this->plan_result( 'send', $recipients, __( 'alle mail toegestaan', 'staging-safety' ), $strategy );
		}

		if ( 'block' === $strategy ) {
			return $this->plan_result( 'block', array(), __( 'alle mail geblokkeerd', 'staging-safety' ), $strategy );
		}

		if ( 'allow_domains' === $strategy ) {
			$patterns = (array) Settings::get( 'mail.allow_domains', array() );
			$kept     = array();

			foreach ( $recipients as $recipient ) {
				if ( null !== Matcher::match_email( $recipient, $patterns ) ) {
					$kept[] = $recipient;
				}
			}

			if ( ! $kept ) {
				return $this->plan_result( 'block', array(), __( 'geen enkele ontvanger staat op de witte lijst', 'staging-safety' ), $strategy );
			}

			if ( count( $kept ) === count( $recipients ) ) {
				return $this->plan_result( 'send', $kept, __( 'alle ontvangers op de witte lijst', 'staging-safety' ), $strategy );
			}

			return $this->plan_result( 'rewrite', $kept, __( 'ontvangers buiten de witte lijst verwijderd', 'staging-safety' ), $strategy );
		}

		// Omleiden naar de testadressen.
		$targets = array_values( array_filter( array_map( 'trim', (array) Settings::get( 'mail.redirect_to', array() ) ) ) );

		if ( ! $targets ) {
			// Zonder testadres niet terugvallen op admin_email: op een kopie van
			// productie is dat vaak het echte adres van de klant.
			return $this->plan_result( 'block', array(), __( 'geen testadres ingesteld, mail tegengehouden', 'staging-safety' ), $strategy );
		}

		return $this->plan_result( 'rewrite', $targets, __( 'omgeleid naar testadres', 'staging-safety' ), $strategy );
	}

	/**
	 * Hulpje voor een leesbaar plan.
	 *
	 * @param string $action     send, rewrite of block.
	 * @param array  $recipients Ontvangers na de bewerking.
	 * @param string $rule       Uitleg.
	 * @param string $strategy   Gekozen strategie.
	 * @return array
	 */
	private function plan_result( $action, $recipients, $rule, $strategy ) {
		return array(
			'action'     => $action,
			'recipients' => $recipients,
			'rule'       => $rule,
			'strategy'   => $strategy,
		);
	}

	/**
	 * Cc en Bcc weghalen, anders gaat de kopie alsnog naar een echte ontvanger.
	 *
	 * @param string|array $headers Headers.
	 * @return array
	 */
	private function strip_copies( $headers ) {
		if ( ! is_array( $headers ) ) {
			$headers = preg_split( '/\r\n|\r|\n/', (string) $headers );
		}

		$out = array();

		foreach ( (array) $headers as $header ) {
			$header = trim( (string) $header );
			if ( '' === $header ) {
				continue;
			}

			$lower = strtolower( $header );
			if ( 0 === strpos( $lower, 'cc:' ) || 0 === strpos( $lower, 'bcc:' ) ) {
				continue;
			}

			$out[] = $header;
		}

		return $out;
	}

	/**
	 * Blokje bovenaan de mail met de oorspronkelijke ontvangers, zodat je bij
	 * het testen ziet naar wie hij normaal gegaan was.
	 *
	 * @param string $message  Oorspronkelijke tekst.
	 * @param array  $original Oorspronkelijke ontvangers.
	 * @param array  $headers  Headers na bewerking.
	 * @return string
	 */
	private function add_notice( $message, array $original, array $headers ) {
		$is_html = false;
		foreach ( $headers as $header ) {
			if ( false !== stripos( (string) $header, 'text/html' ) ) {
				$is_html = true;
				break;
			}
		}

		$lines = array(
			__( 'Deze mail komt van een stagingomgeving en is omgeleid door Staging Safety.', 'staging-safety' ),
			sprintf(
				/* translators: %s: lijst met e-mailadressen */
				__( 'Oorspronkelijke ontvangers: %s', 'staging-safety' ),
				$original ? implode( ', ', $original ) : __( 'onbekend', 'staging-safety' )
			),
			sprintf(
				/* translators: %s: url van de site */
				__( 'Site: %s', 'staging-safety' ),
				home_url()
			),
		);

		if ( $is_html ) {
			$block = '<div style="border:2px solid #d63638;padding:12px;margin:0 0 16px;font-family:sans-serif;font-size:13px;">'
				. '<strong>' . esc_html( $lines[0] ) . '</strong><br>'
				. esc_html( $lines[1] ) . '<br>'
				. esc_html( $lines[2] )
				. '</div>';

			return $block . $message;
		}

		return implode( "\n", $lines ) . "\n\n----------------------------------------\n\n" . $message;
	}

	/**
	 * Regel in het logboek.
	 *
	 * @param string $action Actie.
	 * @param array  $plan   Beslissing.
	 * @param array  $atts   Mailargumenten.
	 */
	private function log( $action, array $plan, $atts ) {
		$original = Matcher::extract_recipients( isset( $atts['to'] ) ? $atts['to'] : array() );
		$subject  = isset( $atts['subject'] ) ? (string) $atts['subject'] : '';

		$fingerprint = md5( $action . '|' . implode( ',', $original ) . '|' . $subject . '|' . md5( (string) ( $atts['message'] ?? '' ) ) );

		if ( isset( $this->logged[ $fingerprint ] ) ) {
			return;
		}

		$this->logged[ $fingerprint ] = true;

		$detail = sprintf(
			/* translators: 1: onderwerp, 2: ontvangers */
			__( 'Onderwerp: %1$s | Naar: %2$s', 'staging-safety' ),
			'' !== $subject ? $subject : __( '(geen)', 'staging-safety' ),
			$original ? implode( ', ', $original ) : __( '(geen)', 'staging-safety' )
		);

		if ( Logger::ACTION_REDIRECTED === $action && ! empty( $plan['recipients'] ) ) {
			/* translators: %s: lijst met e-mailadressen */
			$detail .= ' | ' . sprintf( __( 'Verstuurd naar: %s', 'staging-safety' ), implode( ', ', $plan['recipients'] ) );
		}

		Logger::log(
			Logger::CHANNEL_MAIL,
			$action,
			$original ? $original[0] : '',
			array(
				'detail' => $detail,
				'rule'   => $plan['rule'],
				'source' => Caller::slug(),
			)
		);
	}
}
