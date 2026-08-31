<?php
/**
 * Herkent actieve plugins die op staging extra risico geven.
 *
 * @package StagingSafety
 */

namespace StagingSafety;

defined( 'ABSPATH' ) || exit;

/**
 * Een lijst met plugins waarvan we weten dat ze naar buiten praten, met per
 * plugin één concreet advies. Bewust niet automatisch ingrijpen: sommige van
 * deze plugins hebben een eigen testmodus die beter werkt dan alles blokkeren.
 */
class Risk_Scanner {

	/**
	 * Mapnaam => [naam, categorie, advies].
	 *
	 * @return array
	 */
	public static function catalogue() {
		return array(
			'woocommerce'                => array(
				'name'     => 'WooCommerce',
				'category' => __( 'Webshop', 'staging-safety' ),
				'advice'   => __( 'Zet alle betaalmethodes in testmodus en controleer of e-mail omgeleid wordt. Let ook op Action Scheduler: die draait achtergrondtaken zoals abonnementsbetalingen.', 'staging-safety' ),
			),
			'woocommerce-subscriptions'  => array(
				'name'     => 'WooCommerce Subscriptions',
				'category' => __( 'Betalingen', 'staging-safety' ),
				'advice'   => __( 'Kan terugkerende betalingen afschrijven bij echte klanten. Blokkeer de bijbehorende cronjobs.', 'staging-safety' ),
			),
			'mollie-payments-for-woocommerce' => array(
				'name'     => 'Mollie',
				'category' => __( 'Betalingen', 'staging-safety' ),
				'advice'   => __( 'Vervang de live-API-sleutel door een testsleutel, of blokkeer api.mollie.com.', 'staging-safety' ),
			),
			'woocommerce-gateway-stripe' => array(
				'name'     => 'Stripe',
				'category' => __( 'Betalingen', 'staging-safety' ),
				'advice'   => __( 'Zet de testmodus aan, of blokkeer api.stripe.com. Webhooks vanaf Stripe blijven binnenkomen.', 'staging-safety' ),
			),
			'woocommerce-paypal-payments' => array(
				'name'     => 'PayPal',
				'category' => __( 'Betalingen', 'staging-safety' ),
				'advice'   => __( 'Gebruik de sandbox-gegevens in plaats van de live-account.', 'staging-safety' ),
			),
			'woocommerce-buckaroo-payment-gateway' => array(
				'name'     => 'Buckaroo',
				'category' => __( 'Betalingen', 'staging-safety' ),
				'advice'   => __( 'Zet de testmodus aan of blokkeer checkout.buckaroo.nl.', 'staging-safety' ),
			),
			'adyen-payment-woocommerce'  => array(
				'name'     => 'Adyen',
				'category' => __( 'Betalingen', 'staging-safety' ),
				'advice'   => __( 'Gebruik de test-omgeving van Adyen in plaats van live.', 'staging-safety' ),
			),
			'mailchimp-for-woocommerce'  => array(
				'name'     => 'Mailchimp for WooCommerce',
				'category' => __( 'CRM en nieuwsbrief', 'staging-safety' ),
				'advice'   => __( 'Synchroniseert klantgegevens naar je live-lijst. Blokkeer *.mailchimp.com en de bijbehorende cronjobs.', 'staging-safety' ),
			),
			'mailchimp-for-wp'           => array(
				'name'     => 'MC4WP',
				'category' => __( 'CRM en nieuwsbrief', 'staging-safety' ),
				'advice'   => __( 'Testinschrijvingen komen in de echte lijst terecht. Blokkeer *.mailchimp.com.', 'staging-safety' ),
			),
			'leadin'                     => array(
				'name'     => 'HubSpot',
				'category' => __( 'CRM en nieuwsbrief', 'staging-safety' ),
				'advice'   => __( 'Schrijft contacten naar je live-CRM. Blokkeer *.hubapi.com en *.hubspot.com.', 'staging-safety' ),
			),
			'activecampaign-subscription-forms' => array(
				'name'     => 'ActiveCampaign',
				'category' => __( 'CRM en nieuwsbrief', 'staging-safety' ),
				'advice'   => __( 'Schrijft contacten naar je live-account. Blokkeer *.api-us1.com.', 'staging-safety' ),
			),
			'newsletter'                 => array(
				'name'     => 'Newsletter',
				'category' => __( 'CRM en nieuwsbrief', 'staging-safety' ),
				'advice'   => __( 'Kan een hele verzendlijst afwerken vanaf staging. Zorg dat mail omgeleid of geblokkeerd is.', 'staging-safety' ),
			),
			'mailpoet'                   => array(
				'name'     => 'MailPoet',
				'category' => __( 'CRM en nieuwsbrief', 'staging-safety' ),
				'advice'   => __( 'Verstuurt via een eigen dienst, dus niet via wp_mail. Blokkeer *.mailpoet.com en zet de verzending uit.', 'staging-safety' ),
			),
			'wp-all-import-pro'          => array(
				'name'     => 'WP All Import',
				'category' => __( 'Import en export', 'staging-safety' ),
				'advice'   => __( 'Haalt bestanden bij leveranciers op en kan producten overschrijven. Blokkeer de bronhost en de importcron.', 'staging-safety' ),
			),
			'wp-all-import'              => array(
				'name'     => 'WP All Import',
				'category' => __( 'Import en export', 'staging-safety' ),
				'advice'   => __( 'Haalt bestanden bij leveranciers op en kan producten overschrijven. Blokkeer de bronhost en de importcron.', 'staging-safety' ),
			),
			'wp-all-export-pro'          => array(
				'name'     => 'WP All Export',
				'category' => __( 'Import en export', 'staging-safety' ),
				'advice'   => __( 'Kan exports naar een externe server of FTP zetten. Controleer de geplande exports.', 'staging-safety' ),
			),
			'updraftplus'                => array(
				'name'     => 'UpdraftPlus',
				'category' => __( 'Back-up naar extern', 'staging-safety' ),
				'advice'   => __( 'Kan de staging-back-up over de productieback-up heen zetten. Koppel de externe opslag los.', 'staging-safety' ),
			),
			'backwpup'                   => array(
				'name'     => 'BackWPup',
				'category' => __( 'Back-up naar extern', 'staging-safety' ),
				'advice'   => __( 'Zelfde risico: staging schrijft naar dezelfde externe opslag als productie.', 'staging-safety' ),
			),
			'wp-webhooks'                => array(
				'name'     => 'WP Webhooks',
				'category' => __( 'Koppelingen', 'staging-safety' ),
				'advice'   => __( 'Stuurt gebeurtenissen door naar externe systemen. Zet de uitgaande webhooks uit.', 'staging-safety' ),
			),
			'uncanny-automator'          => array(
				'name'     => 'Uncanny Automator',
				'category' => __( 'Koppelingen', 'staging-safety' ),
				'advice'   => __( 'Voert recepten uit richting externe diensten. Zet de recepten op concept.', 'staging-safety' ),
			),
			'zapier'                     => array(
				'name'     => 'Zapier',
				'category' => __( 'Koppelingen', 'staging-safety' ),
				'advice'   => __( 'Triggert Zaps in je live-account. Blokkeer hooks.zapier.com.', 'staging-safety' ),
			),
			'gravityforms'               => array(
				'name'     => 'Gravity Forms',
				'category' => __( 'Formulieren', 'staging-safety' ),
				'advice'   => __( 'Notificaties en add-ons versturen naar echte ontvangers en externe diensten. Controleer de feeds.', 'staging-safety' ),
			),
			'wpforms'                    => array(
				'name'     => 'WPForms',
				'category' => __( 'Formulieren', 'staging-safety' ),
				'advice'   => __( 'Notificaties gaan naar echte ontvangers. Zorg dat mail omgeleid wordt.', 'staging-safety' ),
			),
			'contact-form-7'             => array(
				'name'     => 'Contact Form 7',
				'category' => __( 'Formulieren', 'staging-safety' ),
				'advice'   => __( 'Testinzendingen mailen naar de echte ontvanger. Zorg dat mail omgeleid wordt.', 'staging-safety' ),
			),
		);
	}

	/**
	 * Welke risicoplugins staan er aan?
	 *
	 * @return array Slug => gegevens, aangevuld met 'dismissed'.
	 */
	public static function detect() {
		$catalogue = self::catalogue();
		$dismissed = (array) Settings::get( 'dismissed_warnings', array() );
		$found     = array();

		foreach ( self::active_slugs() as $slug ) {
			if ( ! isset( $catalogue[ $slug ] ) ) {
				continue;
			}

			$entry              = $catalogue[ $slug ];
			$entry['slug']      = $slug;
			$entry['dismissed'] = in_array( $slug, $dismissed, true );

			$found[ $slug ] = $entry;
		}

		/**
		 * Pas de gevonden risicoplugins aan.
		 *
		 * @param array $found Gevonden plugins.
		 */
		return apply_filters( 'staging_safety_risky_plugins', $found );
	}

	/**
	 * Alleen de waarschuwingen die nog niet weggeklikt zijn.
	 *
	 * @return array
	 */
	public static function open_warnings() {
		return array_filter(
			self::detect(),
			static function ( $entry ) {
				return empty( $entry['dismissed'] );
			}
		);
	}

	/**
	 * Mapnamen van alle actieve plugins, ook netwerkbreed.
	 *
	 * @return array
	 */
	private static function active_slugs() {
		$active = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$network = (array) get_site_option( 'active_sitewide_plugins', array() );
			$active  = array_merge( $active, array_keys( $network ) );
		}

		$slugs = array();

		foreach ( $active as $file ) {
			$dir = dirname( (string) $file );
			if ( '.' !== $dir && '' !== $dir ) {
				$slugs[] = $dir;
			}
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Een waarschuwing wegklikken.
	 *
	 * @param string $slug Mapnaam.
	 */
	public static function dismiss( $slug ) {
		$dismissed = (array) Settings::get( 'dismissed_warnings', array() );

		if ( ! in_array( $slug, $dismissed, true ) ) {
			$dismissed[] = $slug;
			Settings::set( 'dismissed_warnings', array_values( $dismissed ) );
		}
	}

	/**
	 * Alle waarschuwingen weer laten zien.
	 */
	public static function reset_dismissed() {
		Settings::set( 'dismissed_warnings', array() );
	}
}
