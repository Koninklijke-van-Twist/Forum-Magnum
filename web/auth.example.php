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

// Optioneel: Asclepius-adres voor ticketverwijzingen (#1185).
// $asclepiusBaseUrl = 'https://sleutels.kvt.nl/asclepius';
