# Staging Safety — interne handleiding

## Neerzetten op een klantsite

1. Map `staging-safety` in `wp-content/plugins/`, activeren.
2. In `wp-config.php` van de **stagingserver**:

   ```php
   define( 'STAGING_SAFETY_ENV', 'staging' );
   ```

   Dat is de hele instelling. Staat die regel er niet, dan doet de plugin niets.

Waarom in wp-config en niet als vinkje in de admin: een instelling zit in de
database, en die wordt tussen staging en productie heen en weer gekopieerd.
Zet je het vinkje op staging en trekt iemand die database naar productie, dan
blokkeert de plugin daar echte betalingen. Andersom: trekt de klant een verse
kopie van productie naar staging, dan is het vinkje weg en staat staging weer
open. `wp-config.php` blijft per server staan en heeft dat probleem niet.

Heeft de host al `WP_ENVIRONMENT_TYPE` op `staging`, `development` of `local`
staan (WP Engine, Kinsta en Pantheon doen dat), dan is dat genoeg en hoef je
niets toe te voegen.

Staat er `WP_ENVIRONMENT_TYPE` op `production` — vaak omdat wp-config van
productie is meegekopieerd — dan blokkeert de plugin niets tenzij je
`STAGING_SAFETY_ENV` erbij zet. Die wint altijd.

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

## Beperkingen

- Rechtstreekse cURL- of `file_get_contents()`-aanroepen buiten de WordPress-API
  om worden niet gezien.
- E-mail via een externe API (MailPoet, sommige SMTP-plugins) gaat niet via
  `wp_mail` en wordt dus niet onderschept.
- Binnenkomende webhooks worden niet tegengehouden; die komen van buitenaf.

Voor die gevallen: de betreffende plugin in testmodus zetten, of op
serverniveau dichtzetten.
