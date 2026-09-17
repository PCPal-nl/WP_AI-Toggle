=== AI Toggle ===
Contributors: jmvdpal
Tags: categories, filter, toggle, menu
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Een schakelaar in de menubalk waarmee bezoekers posts uit een gekozen categorie uit de feed kunnen verbergen.

== Description ==

AI Toggle zet een schakelaar achter de items van een gekozen menulocatie. Staat
de schakelaar aan, dan verdwijnen alle posts uit de ingestelde categorie uit de
blogpagina, de archieven en de zoekresultaten. De keuze wordt in een cookie
onthouden en geldt voor volgende paginabezoeken.

Het filteren gebeurt serverzijdig in `pre_get_posts`. Er wordt niets met CSS
verborgen, zodat de paginering, `max_num_pages` en een eventuele load-more of
infinite scroll blijven kloppen met wat er werkelijk getoond wordt.

De schakelaar filtert de feed; hij blokkeert niets. Losse berichten blijven
bereikbaar, en het archief van de categorie zelf wordt niet leeggemaakt.

De plugin gebruikt geen JavaScript: de schakelaar is een formulier dat post en
daarna terugstuurt naar dezelfde pagina.

== Installation ==

1. Upload de map `ai-toggle` naar `wp-content/plugins/`.
2. Activeer de plugin.
3. Ga naar Instellingen > AI Toggle en kies de categorie en de menulocatie.

== Shortcode ==

`[ai_toggle]` plaatst de schakelaar buiten het menu. `[pcpal_ai_toggle]` werkt
als alias voor de oude conceptversie.

== Changelog ==

= 1.0 =
* Eerste werkende versie.
