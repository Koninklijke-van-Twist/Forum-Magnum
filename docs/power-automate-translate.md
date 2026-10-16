# Automatische vertaling met Power Automate (stap voor stap)

Forum Magnum stuurt elk nieuw of bewerkt bericht naar een Power Automate-flow. Die laat een
AI-prompt (AI Builder / Copilot) de tekst vertalen naar Nederlands, Engels, Frans en Duits en
geeft het resultaat terug. Lezers zien daarna het bericht in hun gekozen taal, met een knopje
"Origineel tonen".

**Kosten en licentie:** de trigger *When an HTTP request is received* is een **Premium**-connector
(je hebt een Power Automate Premium-licentie nodig) en elke prompt-run kost **AI Builder-credits**.

## Wat het forum verstuurt

`POST` met JSON:

```json
{
  "id": "123",
  "text": "Hallo @jan, zie post#45 en `code`",
  "sourceHint": "nl",
  "languages": ["nl", "en", "fr", "de"],
  "callbackUrl": "https://sleutels.kvt.nl/forum-magnum/translate_callback.php?id=123&h=...",
  "callbackToken": "..."
}
```

Het forum verwacht als antwoord (synchroon) of als callback (asynchroon) alleen:

```json
{"source": "nl", "nl": "...", "en": "...", "fr": "...", "de": "..."}
```

Rommel eromheen (uitleg, ```json-blokken, of het antwoord ingepakt in `{"text": "..."}`) is geen probleem.

## Stap 1: nieuwe flow

1. Ga naar <https://make.powerautomate.com> en kies links **Mijn stromen** → **Nieuwe stroom** → **Directe cloudstroom** (*Instant cloud flow*).
2. Geef een naam, bijvoorbeeld `Forum Magnum vertaling`, kies als trigger **When an HTTP request is received** (zoek op "HTTP request") en klik **Maken**.

## Stap 2: de trigger instellen

1. Open de trigger. Zet **Who can trigger the flow?** op **Anyone** (het forum logt niet in bij Microsoft; de URL zelf is het geheim).
2. Plak bij **Request Body JSON Schema**:

```json
{
  "type": "object",
  "properties": {
    "id": { "type": "string" },
    "text": { "type": "string" },
    "sourceHint": { "type": "string" },
    "languages": { "type": "array", "items": { "type": "string" } },
    "callbackUrl": { "type": "string" },
    "callbackToken": { "type": "string" }
  },
  "required": ["id", "text", "callbackUrl", "callbackToken"]
}
```

## Stap 3: de prompt

1. Klik **+** → **Een actie toevoegen** → zoek **Run a prompt** (AI Builder). Kies **Nieuwe prompt** (of *Custom prompt*).
2. Maak in de prompt twee invoervariabelen (*Input*, type Tekst): `brontekst` en `taalhint`.
3. Plak deze prompttekst (zet op de plekken `[brontekst]` en `[taalhint]` de invoervariabelen via **Insert**/**/**):

```text
Je bent een professionele vertaler voor een intern bedrijfsforum.
Vertaal de BRONTEKST naar Nederlands (nl), Engels (Brits, en), Frans (fr) en Duits (de).
Bepaal ook de brontaal (nl, en, fr of de). Hint van de schrijver (mag fout zijn): [taalhint]

Regels:
- Antwoord met ALLEEN één JSON-object, zonder uitleg en zonder codeblok:
  {"source":"..","nl":"..","en":"..","fr":"..","de":".."}
- Laat alle opmaak (Markdown zoals **vet**, *schuin*, lijsten, koppen, tabellen, links) exact staan.
- Laat @-vermeldingen (zoals @jan.jansen), post#123-verwijzingen, #1234, URL's, e-mailadressen,
  bestandsnamen en alles tussen `backticks` of in ```codeblokken``` ONGEWIJZIGD.
  Het forum haalt fenced codeblokken vóór verzenden al uit de tekst en zet er een placeholder
  voor in de plaats (`FMCODEBLOCKnX……` in een eigen codeblok). Laat die placeholder staan;
  het forum zet daarna het originele blok terug.
- Behoud regeleinden. Voeg niets toe en laat niets weg.
- Voor de brontaal zelf: geef de originele tekst ongewijzigd terug.

BRONTEKST:
[brontekst]
```

4. Zet bij het model (indien te kiezen) de uitvoer op **Tekst** en sla de prompt op.
5. Vul in de actie **brontekst** met dynamische inhoud `text` (van de trigger) en **taalhint** met `sourceHint`.

> Gebruik je in plaats van AI Builder een Copilot-actie (bijv. *Copilot Studio – Execute agent*),
> geef dan dezelfde prompttekst mee met `text` erin. Het forum haalt de JSON zelf uit het antwoord.

## Stap 4a: synchroon antwoorden (eenvoudigst)

1. **+** → **Een actie toevoegen** → **Response** (*Antwoord*, onder "Request").
2. **Status Code**: `200`. **Headers**: `Content-Type` = `application/json`.
3. **Body**: kies dynamische inhoud **Text** van *Run a prompt* (het antwoord van de prompt).

Het forum wacht (nadat de gebruiker zijn pagina al heeft gekregen) maximaal 300 seconden (syncTimeout; serverlimieten max_execution_time en request_terminate_timeout moeten ≥ 330 s zijn) op dit antwoord.

## Stap 4b: asynchroon via callback (optioneel, robuuster bij trage prompts)

Gebruik dit in plaats van, of naast, stap 4a:

1. Zet eventueel direct na de trigger al een **Response** met status `202` en lege body (dan wacht het forum niet).
2. Voeg na de prompt de actie **HTTP** toe (ook Premium):
   - **Method**: `POST`
   - **URI**: dynamische inhoud `callbackUrl` (van de trigger)
   - **Headers**: `Content-Type` = `application/json` en `X-Callback-Token` = dynamische inhoud `callbackToken`
   - **Body**: dynamische inhoud **Text** van *Run a prompt*

Het forum controleert het token, negeert vertalingen van een inmiddels bewerkt bericht en antwoordt altijd met JSON
(bijv. `{"ok":true,"stored":true}`).

## Stap 5: URL kopiëren en het forum koppelen

1. Klik **Opslaan**. Open daarna de trigger opnieuw: bij **HTTP POST URL** staat nu de URL. Klik het kopieer-icoon.
2. Zet op de server in `web/auth.php` (niet in git):

```php
$copilotTranslate = [
    'flowUrl' => 'https://...plak hier de HTTP POST URL...',
    'callbackSecret' => 'een-lange-willekeurige-string',   // bijv. 64 hex-tekens
];
```

3. Plaats een testbericht en kijk in Power Automate bij **Uitvoeringsgeschiedenis** (28 dagen) of de flow liep.
   Wissel daarna rechtsboven de taal (vlag): het bericht staat in die taal met "Origineel tonen" eronder.

Werkt het niet? Dan blijft het forum gewoon werken en zie je het origineel. Controleer de flowgeschiedenis
(fout in de prompt? credits op?) en of `translate_callback.php` bereikbaar is.
