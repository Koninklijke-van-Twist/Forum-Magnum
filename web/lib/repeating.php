<?php

declare(strict_types=1);

/**
 * Herhaalde eindcijfers van een berichtnummer ("gets").
 *
 * Puur: alleen het nummer, geen HTML, geen database, geen taal.
 * De labels zijn vaste Engelse tiernamen (Dubs, Trips, …); die horen bij het
 * verschijnsel en worden niet vertaald.
 *
 * @return array{tier:string,label:string,run:int,digit:?int,accent:?string}
 *   tier: none|dubs|trips|quads|quints|rainbow|nice
 *   accent: null|satanic|holy (alleen bij 3+ dezelfde, cijfer 6 of 7)
 */
function fm_repeating_digits(int $postNumber): array
{
    if ($postNumber < 0) {
        return ['tier' => 'none', 'label' => '', 'run' => 0, 'digit' => null, 'accent' => null];
    }

    $digits = (string) $postNumber;
    $last = $digits[strlen($digits) - 1];
    $digit = (int) $last;
    $run = 1;
    for ($i = strlen($digits) - 2; $i >= 0; $i--) {
        if ($digits[$i] !== $last) {
            break;
        }
        $run++;
    }

    // Eindigt op precies 69 (niet een langere reeks hetzelfde cijfer: 6 en 9 verschillen).
    if ($run < 2) {
        if ($postNumber % 100 === 69) {
            return ['tier' => 'nice', 'label' => 'Nice', 'run' => $run, 'digit' => $digit, 'accent' => null];
        }

        return ['tier' => 'none', 'label' => '', 'run' => $run, 'digit' => $digit, 'accent' => null];
    }

    $tier = match ($run) {
        2 => 'dubs',
        3 => 'trips',
        4 => 'quads',
        5 => 'quints',
        default => 'rainbow',
    };
    $label = fm_repeating_digit_name($run);
    $accent = null;
    // Satanic/Holy pas vanaf trips; dubs (66, 77) blijven gewoon dubs.
    if ($run >= 3 && $digit === 6) {
        $accent = 'satanic';
        $label = 'Satanic ' . $label;
    } elseif ($run >= 3 && $digit === 7) {
        $accent = 'holy';
        $label = 'Holy ' . $label;
    }

    return ['tier' => $tier, 'label' => $label, 'run' => $run, 'digit' => $digit, 'accent' => $accent];
}

/** Vaste tiernaam bij de lengte van de reeks. 6+ is de regenboog; de naam loopt door. */
function fm_repeating_digit_name(int $run): string
{
    static $names = [
        2 => 'Dubs',
        3 => 'Trips',
        4 => 'Quads',
        5 => 'Quints',
        6 => 'Sexts',
        7 => 'Septs',
        8 => 'Octs',
        9 => 'Nons',
        10 => 'Decs',
        11 => 'Undecs',
        12 => 'Duodecs',
        13 => 'Tredecs',
        14 => 'Quattuordecs',
        15 => 'Quindecs',
        16 => 'Sexdecs',
        17 => 'Septendecs',
        18 => 'Octodecs',
        19 => 'Novemdecs',
    ];

    return $names[$run] ?? ($run . '-gets');
}
