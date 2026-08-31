<?php
require __DIR__ . '/bootstrap.php';

use StagingSafety\Environment;
use StagingSafety\Matcher;
use StagingSafety\Settings;
use StagingSafety\Guards\Http_Guard;
use StagingSafety\Guards\Mail_Guard;
use StagingSafety\Guards\Cron_Guard;

/* ---------- Matcher ---------- */
ok( 'exacte host', Matcher::host_matches( 'example.com', 'example.com' ), true );
ok( 'geen subdomein bij exact', Matcher::host_matches( 'api.example.com', 'example.com' ), false );
ok( 'wildcard subdomein', Matcher::host_matches( 'api.example.com', '*.example.com' ), true );
ok( 'wildcard ook apex', Matcher::host_matches( 'example.com', '*.example.com' ), true );
ok( 'wildcard niet op lookalike', Matcher::host_matches( 'notexample.com', '*.example.com' ), false );
ok( 'ster matcht alles', Matcher::host_matches( 'wat.dan.ook', '*' ), true );
ok( 'hoofdletters', Matcher::host_matches( 'API.Example.COM', '*.example.com' ), true );
ok( 'host uit url', Matcher::host_from_url( 'https://api.mollie.com:443/v2/payments?x=1' ), 'api.mollie.com' );
ok( 'patroon met schema', Matcher::host_matches( 'example.com', 'https://example.com/pad' ), true );
ok( 'fqdn punt eraf', Matcher::normalise_host( 'Example.com.' ), 'example.com' );

ok( 'mail domein', Matcher::match_email( 'piet@bureau.nl', array( 'bureau.nl' ) ), 'bureau.nl' );
ok( 'mail subdomein via wildcard', Matcher::match_email( 'piet@mail.bureau.nl', array( '*.bureau.nl' ) ), '*.bureau.nl' );
ok( 'mail exact adres', Matcher::match_email( 'piet@bureau.nl', array( 'jan@bureau.nl' ) ), null );
ok( 'mail niet gematcht', Matcher::match_email( 'klant@echt.nl', array( 'bureau.nl' ) ), null );
ok( 'ontvangers uit string', Matcher::extract_recipients( 'Piet <p@a.nl>, j@b.nl' ), array( 'p@a.nl', 'j@b.nl' ) );

/* ---------- Environment ---------- */
function set_env( $value ) {
	putenv( 'WP_ENVIRONMENT_TYPE=' . $value );
	Environment::reset();
	Settings::flush();
}

set_env( '' );
ok( 'niets in wp-config: onbekend', Environment::type(), Environment::UNKNOWN );
ok( 'onbekend blokkeert niet', Environment::is_staging(), false );
ok( 'hostnaam-hint blijft een hint', Environment::looks_like_staging(), true );

// Een vinkje in de database mag de omgeving niet meer bepalen: dat reisde mee
// met een databasekopie tussen staging en productie.
$GLOBALS['ss_options'][ Settings::OPTION ] = array( 'confirmed_staging' => true );
Environment::reset();
Settings::flush();
ok( 'instelling in database telt niet mee', Environment::type(), Environment::UNKNOWN );
$GLOBALS['ss_options'] = array();

set_env( 'staging' );
ok( 'wp-config staging', Environment::is_staging(), true );

set_env( 'development' );
ok( 'development telt als staging', Environment::is_staging(), true );

set_env( 'production' );
ok( 'productie blokkeert niet', Environment::is_staging(), false );
ok( 'productie is vergrendeld', Environment::is_locked_to_production(), true );

set_env( 'staging' );

/* ---------- Http_Guard ---------- */
function set_http( array $http, $mode = 'block' ) {
	Settings::flush();
	Environment::reset();
	$GLOBALS['ss_options'][ Settings::OPTION ] = array(
		'log'  => array( 'enabled' => false ),
		'http' => array_merge( array( 'mode' => $mode ), $http ),
	);
}

$g = new Http_Guard();

set_http( array( 'policy' => 'whitelist', 'allow' => array( '*.wordpress.org' ), 'deny' => array() ) );
ok( 'dicht: onbekende host geblokkeerd', $g->decide( 'api.mollie.com' )['allow'], false );
ok( 'dicht: wordpress.org mag', $g->decide( 'api.wordpress.org' )['allow'], true );
ok( 'dicht: eigen host mag', $g->decide( 'staging.klant.nl' )['allow'], true );
ok( 'dicht: localhost mag', $g->decide( 'localhost' )['allow'], true );

set_http( array( 'policy' => 'blacklist', 'allow' => array(), 'deny' => array( 'api.mollie.com' ) ) );
ok( 'open: onbekende host mag', $g->decide( 'example.com' )['allow'], true );
ok( 'open: zwarte lijst blokkeert', $g->decide( 'api.mollie.com' )['allow'], false );

set_http( array( 'policy' => 'blacklist', 'allow' => array( 'api.mollie.com' ), 'deny' => array( 'api.mollie.com' ) ) );
ok( 'zwarte lijst wint van witte', $g->decide( 'api.mollie.com' )['allow'], false );

set_http( array( 'policy' => 'blacklist', 'allow' => array(), 'deny' => array(), 'plugin_rules' => array( 'wp-all-import' => 'deny' ) ) );
ok( 'plugin geblokkeerd', $g->decide( 'leverancier.nl', 'wp-all-import' )['allow'], false );
ok( 'andere plugin mag wel', $g->decide( 'leverancier.nl', 'woocommerce' )['allow'], true );

set_http( array( 'policy' => 'blacklist', 'allow' => array( 'leverancier.nl' ), 'deny' => array(), 'plugin_rules' => array( 'wp-all-import' => 'deny' ) ) );
// Vastgelegde volgorde: een plugin op blokkeren wint van de witte lijst.
ok( 'pluginblokkade wint van witte lijst', $g->decide( 'leverancier.nl', 'wp-all-import' )['allow'], false );
ok( 'zelfde host mag wel via andere plugin', $g->decide( 'leverancier.nl', 'woocommerce' )['allow'], true );

set_http( array( 'policy' => 'whitelist', 'allow' => array(), 'deny' => array(), 'plugin_rules' => array( 'woocommerce' => 'allow' ) ) );
ok( 'plugin toegestaan bij alles dicht', $g->decide( 'api.stripe.com', 'woocommerce' )['allow'], true );

/* echte intercept: geeft hij een WP_Error? */
set_http( array( 'policy' => 'whitelist', 'allow' => array() ), 'block' );
$res = $g->intercept( false, array( 'method' => 'POST' ), 'https://api.mollie.com/v2/payments' );
ok( 'blokkeren geeft WP_Error', $res instanceof WP_Error, true );
ok( 'foutcode', $res instanceof WP_Error ? $res->get_error_code() : '', 'staging_safety_blocked' );

set_http( array( 'policy' => 'whitelist', 'allow' => array() ), 'monitor' );
$res = $g->intercept( false, array( 'method' => 'GET' ), 'https://api.mollie.com/v2/payments' );
ok( 'meekijken laat door', $res, false );

set_http( array( 'policy' => 'whitelist', 'allow' => array() ), 'block' );
$res = $g->intercept( array( 'body' => 'al afgehandeld' ), array(), 'https://api.mollie.com/' );
ok( 'eerder antwoord blijft staan', $res, array( 'body' => 'al afgehandeld' ) );

/* ---------- Mail_Guard ---------- */
function set_mail( array $mail, $mode = 'block' ) {
	Settings::flush();
	Environment::reset();
	$GLOBALS['ss_options'][ Settings::OPTION ] = array(
		'log'  => array( 'enabled' => false ),
		'mail' => array_merge( array( 'mode' => $mode ), $mail ),
	);
}

$m    = new Mail_Guard();
$atts = array( 'to' => 'klant@echtdomein.nl', 'subject' => 'Je bestelling', 'message' => 'Hoi', 'headers' => array( 'Cc: baas@echtdomein.nl' ), 'attachments' => array() );

set_mail( array( 'strategy' => 'block' ) );
ok( 'blokkeren meldt succes', $m->intercept( null, $atts ), true );

set_mail( array( 'strategy' => 'redirect', 'redirect_to' => array() ) );
ok( 'omleiden zonder testadres blokkeert', $m->intercept( null, $atts ), true );

set_mail( array( 'strategy' => 'redirect', 'redirect_to' => array( 'test@bureau.nl' ), 'subject_prefix' => '[STAGING]' ) );
ok( 'omleiden laat pre_wp_mail door', $m->intercept( null, $atts ), null );
$out = $m->rewrite( $atts );
ok( 'ontvanger vervangen', $out['to'], array( 'test@bureau.nl' ) );
ok( 'cc gestript', $out['headers'], array() );
ok( 'onderwerp voorvoegsel', $out['subject'], '[STAGING] Je bestelling' );
ok( 'oorspronkelijke ontvanger in tekst', (bool) strpos( $out['message'], 'klant@echtdomein.nl' ), true );

set_mail( array( 'strategy' => 'allow_domains', 'allow_domains' => array( 'bureau.nl' ) ) );
ok( 'alles buiten witte lijst: blokkeren', $m->intercept( null, $atts ), true );
$mix = array_merge( $atts, array( 'to' => array( 'klant@echtdomein.nl', 'piet@bureau.nl' ) ) );
ok( 'gedeeltelijk: pre laat door', $m->intercept( null, $mix ), null );
ok( 'gedeeltelijk: alleen witte lijst blijft', $m->rewrite( $mix )['to'], array( 'piet@bureau.nl' ) );

set_mail( array( 'strategy' => 'allow' ) );
ok( 'alles toestaan verstuurt', $m->intercept( null, $atts ), null );
ok( 'alles toestaan laat atts intact', $m->rewrite( $atts )['to'], 'klant@echtdomein.nl' );

set_mail( array( 'strategy' => 'block' ), 'monitor' );
ok( 'meekijken verstuurt gewoon', $m->intercept( null, $atts ), null );

/* ---------- Cron_Guard ---------- */
ok( 'risicovolle hook herkend', Cron_Guard::is_risky( 'action_scheduler_run_queue' ), true );
ok( 'sync-hook herkend', Cron_Guard::is_risky( 'mijnplugin_daily_sync' ), true );
ok( 'onschuldige hook', Cron_Guard::is_risky( 'wp_scheduled_delete' ), false );

/* ---------- Uitkomst ---------- */
echo "\n{$GLOBALS['ss_pass']} geslaagd, {$GLOBALS['ss_fail']} gefaald\n";
exit( $GLOBALS['ss_fail'] > 0 ? 1 : 0 );
