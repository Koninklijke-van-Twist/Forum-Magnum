<?php

/**
 * Kopieer naar web/auth.php op de server (en lokaal). NOOIT committen:
 * web/auth.php staat in .gitignore en de deploy slaat auth.php over.
 *
 * Toegang: iedereen die via de sleutels.kvt.nl-login ingelogd is.
 */

// Beheerders: mogen threads starten in Aankondigingen en berichten bewerken/verwijderen.
// Hoofdletters maken niet uit.
$forumAdmins = [
    'tfalken@kvt.nl',
];

// Optioneel: Microsoft Graph (zelfde app-registratie als Asclepius, client_credentials).
// Nodig voor naam/functie/afdeling/bedrijf (User.Read.All, application) en
// profielfoto's (ProfilePhoto.Read.All of User.Read.All, application).
// Zonder Graph: naam uit de login, bedrijf uit het e-maildomein, initialen als avatar.
// $graphCredentials = [
//     'tenantId' => 'TENANT_ID',
//     'clientId' => 'CLIENT_ID',
//     'clientSecret' => 'CLIENT_SECRET',
// ];

// Optioneel: extra e-maildomein => bedrijfsnaam (als companyName in Entra leeg is).
// $companyDomains = ['voorbeeld.nl' => 'Voorbeeld BV'];

// Optioneel: bedrijfslogo-koppeling (bestand web/assets/company/<sleutel>.svg), zie README.
// $companyLogos = ['voorbeeld' => ['voorbeeld.nl', 'Voorbeeld BV']];

// Optioneel: Asclepius-adres voor ticketverwijzingen (#1185).
// $asclepiusBaseUrl = 'https://sleutels.kvt.nl/asclepius';

// Optioneel: automatische vertaling van berichten via een Power Automate-flow met een
// Copilot/AI Builder-prompt (zie docs/power-automate-translate.md). Zonder deze config
// wordt er niets vertaald en werkt het forum zoals altijd.
// $copilotTranslate = [
//     'flowUrl' => 'https://prod-00.westeurope.logic.azure.com:443/workflows/.../triggers/manual/paths/invoke?...',
//     'callbackSecret' => 'MINIMAAL-16-TEKENS-WILLEKEURIG', // bijv. bin2hex(random_bytes(32))
//     // 'callbackBaseUrl' => 'https://sleutels.kvt.nl/forum-magnum', // optioneel, anders uit de request
//     // 'syncTimeout' => 300, // seconden (default 300; server: max_execution_time/request_terminate_timeout ≥ 330) wachten op een synchrone Response (na het antwoord aan de browser)
// ];
