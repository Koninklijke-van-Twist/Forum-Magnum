# Forum Magnum

Het interne forum van KVT op https://sleutels.kvt.nl/forum-magnum/. Iedereen die via de
gedeelde sleutels.kvt.nl-login (Microsoft/Entra SSO) ingelogd is, kan meedoen.

Het vorige Forum Magnum (een forum voor AI-assistenten) is volledig verwijderd; alleen
het logo (`web/thumbnail.png`) is gebleven.

## Functies

- Drie secties: **Aankondigingen** (alleen beheerders starten threads, iedereen reageert),
  **Werkgerelateerd** en **Algemeen** (iedereen start threads).
- Taalkeuze bij de eerste login via vlaggen: Nederlands, Français, Deutsch, English (UK).
  Per gebruiker opgeslagen; later te wijzigen via de vlag rechtsboven.
- Startpagina: de drie threadlijsten onder elkaar, elk met eigen kop, nieuwe-thread-knop
  (waar toegestaan) en eigen paginering (`?p_ann=`, `?p_work=`, `?p_gen=`): Aankondigingen
  per 5, Werkgerelateerd en Algemeen per 10. Klik op een sectiekop voor de losse
  sectiepagina (`section.php?s=<sectie>&p=<n>`, alleen die lijst, 20 per pagina). Een nieuwe
  thread maak je op `new.php?s=<sectie>`; de terug-link in een thread wijst naar de
  sectiepagina waarop die thread staat.
- Vermeldingen: `@` in een tekstvak opent een lijst met collega's (iedereen die ooit is
  ingelogd), die `@gebruiker:domein` invoegt (bv. `@tfalken:kvt` voor tfalken@kvt.nl). In een
  bericht staat de voornaam; klikken toont het posterblok. Je eigen vermeldingen zijn gemarkeerd.
- Notificaties (bel rechtsboven): tabbladen *Vermeldingen* en *Berichten* (nieuwe berichten
  in threads waarin je hebt gepost). Ongelezen threads en berichten hebben een eigen kleur of rand.
- Threadlijsten: laatste bericht eerst, ongelezen-indicator.
  Binnen een thread: oudste bovenaan, 50 per pagina. Een thread opent op de pagina met
  je eerste ongelezen bericht (en scrolt ernaartoe), anders op de laatste pagina.
- Berichtweergave: links een compact posterblok (bedrijfslogo linksboven, vierkant avatar
  van 90px, naam, e-mail, afdeling · bedrijf) in een kleur die deterministisch uit de
  afdeling volgt (hash → HSL, wit op donkere tint, contrast ≥ 4.5). Onder het bericht staat
  een voetregel met links de permalink (`#123`, te citeren als `post#123`) en rechts datum
  en (bewerkt …). Op mobiel klapt het posterblok in tot een balk boven het bericht.
- Bedrijfslogo: wit voor iedereen, glimmend goud (gradient) voor beheerders.

### Bedrijfslogo toevoegen

1. Zet een SVG in `web/assets/company/<sleutel>.svg` (sleutel: kleine letters, cijfers,
   `-` of `_`, bv. `hunter.svg`). Het logo wordt als masker gebruikt: alleen de vorm telt,
   kleuren in het bestand worden genegeerd. Vierkant (bv. `viewBox="0 0 28 28"`) werkt het best.
2. De koppeling staat in `FM_COMPANY_LOGOS` in `web/lib/users.php`: sleutel => e-maildomeinen
   en/of bedrijfsnamen (`companyName` uit Entra), bv. `'hunter' => ['hunter.be', 'Hunter']`.
   Voor kvt, vantwistvastgoed, hunter, kvtgermany, nhmr en alistar staat die er al; dan is alleen het
   SVG-bestand nodig. Extra koppelingen kunnen ook zonder codewijziging in `auth.php`:
   `$companyLogos = ['voorbeeld' => ['voorbeeld.nl', 'Voorbeeld BV']];`.
3. Zonder bestand valt een bedrijf terug op het KVT-logo (`kvt.svg`). Aanwezig: `kvt.svg`,
   `vantwistvastgoed.svg`.
- Berichtopmaak: overgenomen uit Asclepius (`web/lib/markdown.php`): vet/cursief (`**`/`__` en `*`/`_`), code,
  codeblokken, lijsten, citaten, tabellen, toetsen (`[Ctrl]`), autodetectie van URL's,
  e-mail en telefoonnummers (URL's, e-mails, markdownlinks en code gaan niet nog eens door
  de telefoondetector). Alle tekst wordt eerst ge-escaped; alleen http(s)/mailto-links.
  - Fenced blokken (` ```js `, php, sql, html, css, json, bash, xml, powershell) krijgen na het
    renderen syntaxiskleuring in de browser via lokaal highlight.js (geen CDN, geen inline script).
    ` ```al ` blijft platte tekst. Kleuring werkt alleen op de al ge-escapete code.
    Bij vertalen gaat de broncode eruit (placeholder) en komt het originele blok terug.
  - `#1185` → ticket 1185 in Asclepius; Asclepius-URL's krijgen het label `Ticket #1185`.
  - `post#123` → forumbericht 123: `post.php?id=123` stuurt door naar de juiste thread,
    de juiste pagina en het bericht (`#p123`). Elk bericht toont zijn eigen `post#…`-link.
- Berichtmenu (⋯) rechtsboven in een bericht, zichtbaar bij hover (op touch altijd):
  eigen bericht = Bewerken + Verwijderen, beheerder op andermans bericht = Verwijderen,
  anders geen menu. Bewerken kan alleen bij je eigen berichten, ook als beheerder
  (server-side afgedwongen). Bij verwijderen wordt de tekst echt leeggemaakt in de database;
  het bericht blijft staan als "Bericht verwijderd" (lichtrood, met posterblok) en de
  thread-metadata (laatste bericht, aantal, ongelezen) wordt in dezelfde `BEGIN IMMEDIATE`-
  transactie herberekend. Alle formulieren hebben CSRF-bescherming.

- Bijlagen in Asclepius-stijl: max. 20 MB per bestand (zoals `MAX_ATTACHMENT_BYTES` in
  Asclepius) en max. 10 per bericht. Een klik op de miniatuur of de bestandsnaam opent
  eerst een voorbeeld (afbeelding, video, pdf, Office als Markdown, code met syntaxiskleuring,
  Markdown gerenderd). Onderaan staat Downloaden. Met `[[attachment:naam]]` zet je een
  bijlage op een vaste plek in de tekst. Bij bewerken kun je bijlagen weghalen en toevoegen.
  - Opslag content-addressed: `web/data/files/ab/cd/<sha256>`, plus koppeltabel
    `post_attachments` (post_id, hash, originele naam, mime, grootte). Hetzelfde bestand
    twee keer uploaden levert één bestand op de server op.
  - Een bericht verwijderen of een bijlage weghalen verwijdert de koppeling. Het bestand
    zelf gaat pas weg als geen enkel bericht er nog naar verwijst; dat opruimen gebeurt na
    de transactie, in een eigen `BEGIN IMMEDIATE`.
  - Downloaden alleen via `file.php` (vereist login), het voorbeeld via `preview.php`
    met dezelfde controle (geen bijlage van een verwijderd bericht). Rasterafbeeldingen,
    video en PDF mogen inline; al het andere (ook SVG) als `application/octet-stream`
    met `attachment`, `nosniff` en `CSP: sandbox`. `data/` zelf is via `.htaccess`
    afgeschermd.
  - Let op de PHP-limieten op de server: `upload_max_filesize` ≥ 20M en `post_max_size`
    groot genoeg voor meerdere bestanden. Een te grote POST geeft een nette melding.

## Op de server

Kopieer `web/auth.example.php` naar `web/auth.php` en vul in (nooit committen; de deploy
slaat `auth.php` over):

```php
<?php
$forumAdmins = [
    'tfalken@kvt.nl',          // hoofdletters maken niet uit
];

// Optioneel, aanbevolen: dezelfde Graph-app als Asclepius ($graphCredentials uit
// Asclepius' auth.php). Application-permissies met admin consent:
//   User.Read.All            naam, functie, afdeling, bedrijf
//   ProfilePhoto.Read.All    profielfoto's (of User.Read.All)
$graphCredentials = [
    'tenantId' => '…',
    'clientId' => '…',
    'clientSecret' => '…',
];
```

De oude server-`auth.php` blijft werken (die includet `authhelper.php`, dat daarom als leeg
bestand bestaat), maar heeft geen `$forumAdmins`: zonder beheerders kan niemand een
aankondiging starten.

Zonder Graph: naam uit het e-mailadres (of de login), bedrijf uit het e-maildomein
(`kvt.nl` → Koninklijke van Twist, `hunter.be`, `kvtgermany.de`, `nhmr.nl`, `alistar.net`;
uit te breiden met `$companyDomains`), en initialen als avatar. Lukt de foto niet
(bijvoorbeeld HTTP 403 door ontbrekende rechten), dan blijven de initialen staan en staat
de laatste Graph-status in `web/data/graph_status.json`.

Gebruikers buiten de tenant van de Graph-app (andere KVT-tenants) vallen terug op
e-mail/domein.

## Opslag

SQLite in `web/data/forum.sqlite` (afgeschermd met `web/data/.htaccess`, die Forum Magnum
ook zelf neerzet). Schemaversie in `PRAGMA user_version`; migraties draaien in één
`BEGIN IMMEDIATE`-transactie. Gebruikersinfo wordt 1 dag gecachet (tabel `directory`),
foto's 1 week in `web/data/avatars/` (een mislukte foto wordt pas na een dag opnieuw
geprobeerd). De oude forumdatabase (`data/.ht-forum.sqlite`) blijft ongebruikt staan.

## Deploy

GitHub Actions (`.github/workflows/deploy-ftp.yml`) spiegelt `web/` bij elke push op
`master` via FTP. Uitgesloten: `auth.php`, `cfg.php`, `data`, `data/`, `data/**`, `cache`,
de root-`.htaccess` en de analytics-database. Na de deploy volgt een rooktest op de URL en
op `data/forum.sqlite` (moet 403/404 geven).

`web/analytics/analytics.sqlite` (en `*.sqlite-*`) blijft op de server staan: de FTP-mirror
slaat die bestanden al over, en ook `analytics/.htaccess`. Een gecommit bestand in die map
komt dus niet mee en een bestaand `.htaccess` wordt niet gewist. `analytics.php` schrijft
`web/analytics/.htaccess` als die ontbreekt (of vult hem aan als het woord `sqlite` er nog
niet in staat), zodat Apache de database weigert en `analytics.php` zelf bereikbaar blijft.

## Lokaal en tests

```bash
cp web/auth.example.php web/auth.php
php -S 127.0.0.1:8080 -t web          # ingelogd als de eerste beheerder (of FM_LOCAL_USER)
for f in tests/*_test.php; do php "$f" || exit 1; done
```

Tests (PHP CLI, ook in `.github/workflows/tests.yml`): `attachments_test.php` (dedupe, referentietelling, opruimen na rollback, rechten, limieten, inline/SVG), `store_test.php` (rechten,
sortering, paginering, ongelezen/open-pagina, bewerken/verwijderen, taal, schema,
vertalingen compleet), `markdown_test.php` (XSS, links, telefoonregressie, `#1185`,
`post#123`), `users_test.php` (Graph gemockt: velden, terugval, foto-403, cache,
afdelingskleur/contrast), `page_smoke_test.php` (alle pagina's via `php -S`, CSRF, rechten,
taalkeuze, avatars), `analytics_protect_test.php` (`.htaccess` voor de analytics-sqlite,
endpoint blijft bereikbaar).
