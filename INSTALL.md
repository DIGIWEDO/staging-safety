# Staging Safety — interne handleiding

## Neerzetten op een klantsite

1. Map `staging-safety` in `wp-content/plugins/`, activeren.
2. Klik in het overzicht op **"Ja, dit is een stagingomgeving"**. Klaar.

Zonder die bevestiging doet de plugin niets.

### Waarom een knop en niet gewoon een vinkje

De bevestiging wordt vastgezet op het domein waarop je hem indrukt. Dat lost
het enige echt gevaarlijke scenario op: iemand kopieert de stagingdatabase naar
productie. Het opgeslagen domein klopt daar niet meer, dus de plugin zet
zichzelf uit en meldt in de admin dat deze database van elders komt. Zonder die
koppeling aan het domein zou hij op de live-shop Mollie gaan blokkeren.

### Wanneer tóch de regel in wp-config

```php
define( 'STAGING_SAFETY_ENV', 'staging' );
```

De knop zit in de database. Trekt de klant een verse kopie van productie naar
staging, dan is de bevestiging weg en staat staging weer open — je ziet dat
wel meteen aan de gele melding, maar je moet er dan aan denken. De regel in
`wp-config.php` staat per server en heeft dat probleem niet. Op sites waar
regelmatig ververst wordt is die dus prettiger.

Heeft de host al `WP_ENVIRONMENT_TYPE` op `staging`, `development` of `local`
staan (WP Engine, Kinsta en Pantheon doen dat), dan is er helemaal niets nodig.

Staat er `WP_ENVIRONMENT_TYPE` op `production` — vaak omdat wp-config van
productie is meegekopieerd — dan is de knop uitgeschakeld en werkt alleen
`STAGING_SAFETY_ENV`. Die wint altijd.

## Werkwijze op een nieuwe site

Niet meteen alles dichtzetten — dan weet je niet wat je breekt.

1. Alle drie de onderdelen op **meekijken**, HTTP-grondhouding op "alles open".
2. Twee tot drie dagen laten draaien, of de site zelf een rondje doorlopen:
   bestelling plaatsen, formulier versturen, import draaien.
3. Logboek doorlopen. Per host beslissen: nodig of niet.
4. Hosts die nodig zijn op de witte lijst (knop staat naast elke logregel).
5. Grondhouding op "alles dicht", stand op **blokkeren**.
6. Nog een keer de site doorlopen. Wat nu stukgaat staat in het logboek.

## Mail

Vul altijd een testadres in. Zonder testadres wordt bij de stand "omleiden"
alle mail tegengehouden — bewust, want terugvallen op het beheerdersadres zou
op een kopie van productie het adres van de klant zijn.

## Cronjobs

Een geblokkeerde taak blijft ingepland en wordt alleen niet uitgevoerd. Zet je
de blokkade weer uit, dan pakt hij zijn ritme vanzelf op. Let bij WooCommerce
op `action_scheduler_run_queue`: daar hangt het meeste achtergrondwerk onder.

## Testen tijdens een klus

Admin bar → Staging Safety → "Pauzeer 15 minuten". Loopt vanzelf af, dus je
kunt niet vergeten hem weer aan te zetten.

## Controleren via WP-CLI

```bash
# Moet een WP_Error geven zodra HTTP op blokkeren staat
wp eval 'var_dump( wp_remote_get( "https://example.com" ) );'

# Moet blijven werken, anders werken updates niet meer
wp eval 'var_dump( is_wp_error( wp_remote_get( "https://api.wordpress.org/core/version-check/1.7/" ) ) );'

# Mail
wp eval 'var_dump( wp_mail( "klant@echtdomein.nl", "test", "test" ) );'

# Cron
wp cron event list
wp cron event run --due-now

# Wat denkt de plugin dat dit is, en waarom
wp eval 'var_dump( StagingSafety\Environment::type(), StagingSafety\Environment::source() );'
```

## Updates uitrollen naar alle sites

De plugin kijkt naar de laatste **release** van een GitHub-repository en
vergelijkt de tag met het versienummer in de header. Is de tag hoger, dan
verschijnt op elke site gewoon de normale updatemelding op de pluginpagina.

Instellen per site: **Staging Safety → Instellingen → Algemeen → Updates**,
vul daar `eigenaar/staging-safety` in. Of vastzetten op de server:

```php
define( 'STAGING_SAFETY_GITHUB_REPO', 'eigenaar/staging-safety' );
```

Is de repository besloten, dan is er ook een token nodig. Die hoort niet in de
database, dus alleen in `wp-config.php`:

```php
define( 'STAGING_SAFETY_GITHUB_TOKEN', 'ghp_...' );
```

### Een nieuwe versie uitbrengen

```bash
# 1. versienummer ophogen in staging-safety.php (twee plekken) en readme.txt
# 2. zip bouwen
bin/build.sh

# 3. taggen en releasen
git tag v0.3.0 && git push origin v0.3.0
gh release create v0.3.0 dist/staging-safety.zip --notes "Wat er veranderd is"
```

Hang die zip er altijd aan. Zonder eigen zip valt de plugin terug op het
bronarchief van GitHub, en dat pakt uit naar een map met de tagnaam erin. We
hernoemen die wel, maar een eigen zip is netter en sneller.

De sites kijken hoogstens een paar keer per dag en bewaren het antwoord zes
uur. Wil je het meteen zien: **Instellingen → Algemeen → Nu bij GitHub kijken**.

## Beperkingen

- Rechtstreekse cURL- of `file_get_contents()`-aanroepen buiten de WordPress-API
  om worden niet gezien.
- E-mail via een externe API (MailPoet, sommige SMTP-plugins) gaat niet via
  `wp_mail` en wordt dus niet onderschept.
- Binnenkomende webhooks worden niet tegengehouden; die komen van buitenaf.

Voor die gevallen: de betreffende plugin in testmodus zetten, of op
serverniveau dichtzetten.
