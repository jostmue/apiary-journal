<?php
/**
 * Wetter-Integration über die kostenlose Open-Meteo API (kein API-Key nötig).
 * - geocode_address(): wandelt eine Adresse/Ortsbezeichnung in Lat/Lon um
 * - fetch_weather_for_date(): liefert Wetterdaten für ein Datum an einem Ort
 *   (nutzt Vorhersage-API für heute/Zukunft/nahe Vergangenheit,
 *    Archiv-API für weiter zurückliegende Termine)
 */

require_once __DIR__ . '/config_loader.php';

function http_get_json(string $url, array $params): ?array
{
    $query = http_build_query($params);
    $full = $url . '?' . $query;

    $context = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 8,
            'header'  => "User-Agent: Imkerei-Tagebuch/1.0\r\n",
        ],
    ]);

    $response = @file_get_contents($full, false, $context);
    if ($response === false) {
        return null;
    }
    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

/**
 * Sucht Koordinaten zu einem Ortsnamen / einer Adresse.
 */
function geocode_address(string $query): ?array
{
    if (trim($query) === '') {
        return null;
    }
    $data = http_get_json(WEATHER_GEOCODE_URL, [
        'name'     => $query,
        'count'    => 1,
        'language' => 'de',
        'format'   => 'json',
    ]);

    if (!$data || empty($data['results'][0])) {
        return null;
    }
    $r = $data['results'][0];
    return [
        'lat'   => $r['latitude'],
        'lon'   => $r['longitude'],
        'label' => trim(($r['name'] ?? '') . ', ' . ($r['admin1'] ?? '') . ', ' . ($r['country'] ?? ''), ', '),
    ];
}

/**
 * WMO-Wettercodes (Open-Meteo) in verständlichen deutschen Text umwandeln.
 */
function weather_code_to_text(?int $code): string
{
    if ($code === null) return 'unbekannt';
    $map = [
        0 => 'Klarer Himmel', 1 => 'Überwiegend klar', 2 => 'Teilweise bewölkt', 3 => 'Bedeckt',
        45 => 'Nebel', 48 => 'Reifnebel',
        51 => 'Leichter Nieselregen', 53 => 'Nieselregen', 55 => 'Starker Nieselregen',
        56 => 'Gefrierender Nieselregen', 57 => 'Starker gefrierender Nieselregen',
        61 => 'Leichter Regen', 63 => 'Regen', 65 => 'Starker Regen',
        66 => 'Gefrierender Regen', 67 => 'Starker gefrierender Regen',
        71 => 'Leichter Schneefall', 73 => 'Schneefall', 75 => 'Starker Schneefall',
        77 => 'Schneekörner',
        80 => 'Leichte Regenschauer', 81 => 'Regenschauer', 82 => 'Heftige Regenschauer',
        85 => 'Leichte Schneeschauer', 86 => 'Starke Schneeschauer',
        95 => 'Gewitter', 96 => 'Gewitter mit leichtem Hagel', 99 => 'Gewitter mit starkem Hagel',
    ];
    return $map[$code] ?? 'unbekannt';
}

/**
 * Holt Wetterdaten (Temperatur, Wind, Beschreibung) für ein Datum an lat/lon.
 * Nutzt automatisch die passende Open-Meteo-API je nach Zeitraum.
 */
function fetch_weather_for_date(float $lat, float $lon, string $date): ?array
{
    $today = new DateTime('today');
    $target = DateTime::createFromFormat('Y-m-d', $date);
    if (!$target) {
        return null;
    }
    $diffDays = (int)$today->diff($target)->format('%r%a');

    // Innerhalb von -5 bis +15 Tagen: Forecast-API (enthält auch kurze Vergangenheit)
    if ($diffDays >= -5 && $diffDays <= 15) {
        $data = http_get_json(WEATHER_FORECAST_URL, [
            'latitude'       => $lat,
            'longitude'      => $lon,
            'daily'          => 'weather_code,temperature_2m_max,temperature_2m_min,wind_speed_10m_max',
            'timezone'       => 'auto',
            'start_date'     => $date,
            'end_date'       => $date,
            'past_days'      => $diffDays < 0 ? min(5, abs($diffDays)) : 0,
        ]);
    } else {
        // Weiter zurückliegend: Archiv-API
        $data = http_get_json(WEATHER_ARCHIVE_URL, [
            'latitude'   => $lat,
            'longitude'  => $lon,
            'daily'      => 'weather_code,temperature_2m_max,temperature_2m_min,wind_speed_10m_max',
            'timezone'   => 'auto',
            'start_date' => $date,
            'end_date'   => $date,
        ]);
    }

    if (!$data || empty($data['daily']['time'][0])) {
        return null;
    }
    $idx = array_search($date, $data['daily']['time']);
    if ($idx === false) {
        $idx = 0;
    }

    $tmax = $data['daily']['temperature_2m_max'][$idx] ?? null;
    $tmin = $data['daily']['temperature_2m_min'][$idx] ?? null;
    $code = $data['daily']['weather_code'][$idx] ?? null;
    $wind = $data['daily']['wind_speed_10m_max'][$idx] ?? null;

    $tempAvg = ($tmax !== null && $tmin !== null) ? round(($tmax + $tmin) / 2, 1) : $tmax;

    return [
        'temp_c'       => $tempAvg,
        'temp_max'     => $tmax,
        'temp_min'     => $tmin,
        'wind_kmh'     => $wind,
        'code'         => $code !== null ? (int)$code : null,
        'beschreibung' => weather_code_to_text($code !== null ? (int)$code : null),
    ];
}
