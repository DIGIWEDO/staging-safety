=== Staging Safety ===
Contributors: intern
Tags: staging, veiligheid, http, e-mail, cron
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Extra veiligheidslaag voor stagingomgevingen. Houdt uitgaande requests, e-mail
en cronjobs tegen en laat zien wat een site werkelijk naar buiten doet.

== Beschrijving ==

Een stagingkopie draait met een echte database vol echte koppelingen. Zonder
maatregelen belt WooCommerce de betaalprovider, schrijft een CRM-koppeling naar
het live-systeem en mailt een cronjob facturen naar echte klanten.

Staging Safety is een interne tool die dat zichtbaar en beheersbaar maakt.
De plugin lost niets automatisch op; hij geeft je de rem en het dashboard.

Drie onderdelen, elk met dezelfde drie standen (uit / meekijken / blokkeren):

* Uitgaande requests — witte en zwarte lijst per host, plus regels per plugin.
* E-mail — blokkeren, omleiden naar een testadres, of alleen bepaalde ontvangers.
* Cronjobs — per taak aan of uit, met een voorstel voor de risicovolle taken.

Verder: een logboek van alles wat er gebeurt, een staging-indicator in de
beheeromgeving en op het inlogscherm, waarschuwingen bij bekende risicoplugins,
en een pauzeknop die vanzelf afloopt.

= Veiligheid voorop =

De plugin doet niets tot je bevestigt dat dit staging is. Dat kan met een knop
in de beheeromgeving: de bevestiging wordt vastgezet op het domein waarop je
hem indrukt. Belandt die database later op de live-site, dan klopt het domein
niet meer en zet de plugin zichzelf uit. Zo kan hij nooit per ongeluk een
productiesite platleggen.

Wil je dat het ook een verse databasekopie van productie overleeft, zet dan
`define( 'STAGING_SAFETY_ENV', 'staging' );` in wp-config.php. Die wint altijd.

= Wat de plugin niet ziet =

Alles wat via WordPress loopt wordt onderschept: `wp_remote_*`, `wp_mail` en
WP-Cron. Een plugin die zelf rechtstreeks cURL of `file_get_contents()` gebruikt
gaat hier niet langs, net als e-mail die via een externe API verstuurd wordt
(MailPoet, sommige SMTP-plugins). Een leeg logboek betekent dus niet
automatisch dat er niets naar buiten gaat.

== Installatie ==

1. Zet de map `staging-safety` in `wp-content/plugins/` en activeer de plugin.
2. Klik in het overzicht op "Ja, dit is een stagingomgeving". Zonder die
   bevestiging doet de plugin niets. Staat er al een `WP_ENVIRONMENT_TYPE` op
   staging of development, of de constant hierboven, dan is de knop niet nodig.
3. Zet de onderdelen eerst een paar dagen op "meekijken".
4. Kijk in het logboek wat de site nodig heeft, zet die hosts op de witte lijst
   en schakel daarna over naar "blokkeren".

== Veelgestelde vragen ==

= Waarom werken plugin-updates niet meer? =

Zorg dat `*.wordpress.org` op de witte lijst staat. Die staat er standaard op.

= Een plugin krijgt foutmeldingen bij het blokkeren. =

Dat klopt: geblokkeerde requests geven een `WP_Error` terug. Wil je dat niet,
zet de betreffende host of plugin dan op toestaan.

= Waarom lijkt geblokkeerde e-mail toch te lukken? =

Bij blokkeren melden we aan de verzendende plugin dat het gelukt is. Anders
loopt een bestelling of formulier vast op een verzendfout. In het logboek zie je
dat de mail is tegengehouden.

== Changelog ==

= 1.0.1 =
* Melding over het ontbrekende testadres zei ten onrechte dat mail al
  tegengehouden werd, ook als mail nog op meekijken stond.

= 1.0.0 =
* Bevestigen dat een site staging is kan nu met een knop, vastgezet op het
  domein. Landt de database op een ander domein, dan zet de plugin zichzelf uit.
* Updates via GitHub Releases: nieuwe versies verschijnen gewoon op de
  pluginpagina.

= 0.1.0 =
* Eerste interne versie.
