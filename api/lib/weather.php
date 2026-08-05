<?php
/**
 * Weather lookup for a coordinate and point in time.
 *
 * Uses Open-Meteo (no API key required):
 *   - forecast endpoint for today, the last few days and the near future
 *   - archive endpoint for older dates (reanalysis data, ~5 days delay)
 *
 * One API response holds a whole day of hourly values and is cached in the
 * weather_cache table, so repeated entries for the same apiary and day cost
 * nothing.
 */

declare(strict_types=1);

function http_get_json(string $url, int $timeout): ?array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'BeekeepingJournal/1.0',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code >= 400) {
            return null;
        }
    } else {
        $ctx  = stream_context_create(['http' => ['timeout' => $timeout, 'header' => "User-Agent: BeekeepingJournal/1.0\r\n"]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return null;
        }
    }
    $data = json_decode((string)$body, true);
    return is_array($data) ? $data : null;
}

function weather_cache_get(float $lat, float $lon, string $date, int $maxAgeHours): ?array
{
    $stmt = db()->prepare('SELECT payload, fetched_at FROM weather_cache WHERE lat = ? AND lon = ? AND obs_date = ?');
    $stmt->execute([round($lat, 3), round($lon, 3), $date]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    // Data for past days never changes; today's data is refreshed regularly.
    if ($date >= date('Y-m-d') && (time() - strtotime($row['fetched_at'])) > $maxAgeHours * 3600) {
        return null;
    }
    $data = json_decode($row['payload'], true);
    return is_array($data) ? $data : null;
}

function weather_cache_put(float $lat, float $lon, string $date, array $payload): void
{
    $stmt = db()->prepare(
        'INSERT INTO weather_cache (lat, lon, obs_date, payload, fetched_at)
         VALUES (?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = NOW()'
    );
    $stmt->execute([round($lat, 3), round($lon, 3), $date, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
}

/**
 * @return array{temp:?float,humidity:?int,wind:?float,wind_dir:?int,cloud:?int,
 *               precip:?float,pressure:?float,code:?int,source:string}|null
 */
function weather_for(float $lat, float $lon, string $datetime): ?array
{
    $cfg = config()['weather'];
    if (empty($cfg['enabled'])) {
        return null;
    }

    $date = substr($datetime, 0, 10);
    $hour = (int)substr($datetime, 11, 2);
    $daysBack = (int)floor((strtotime(date('Y-m-d')) - strtotime($date)) / 86400);
    $source   = $daysBack > 6 ? 'archive' : 'forecast';

    $day = weather_cache_get($lat, $lon, $date, (int)($cfg['cache_hours'] ?? 3));
    if ($day === null) {
        $hourly = 'temperature_2m,relative_humidity_2m,wind_speed_10m,wind_direction_10m,'
                . 'cloud_cover,precipitation,pressure_msl,weather_code';
        $query  = http_build_query([
            'latitude'   => $lat,
            'longitude'  => $lon,
            'hourly'     => $hourly,
            'timezone'   => $cfg['timezone'] ?? 'auto',
            'start_date' => $date,
            'end_date'   => $date,
            'wind_speed_unit' => 'kmh',
        ]);
        $url  = ($source === 'archive' ? $cfg['archive_url'] : $cfg['forecast_url']) . '?' . $query;
        $resp = http_get_json($url, (int)($cfg['timeout'] ?? 8));
        if (!$resp || empty($resp['hourly']['time'])) {
            return null;
        }
        $day = $resp['hourly'];
        $day['_source'] = $source;
        weather_cache_put($lat, $lon, $date, $day);
    }

    // Find the hourly slot closest to the requested time.
    $index = null;
    foreach ($day['time'] as $i => $t) {
        if ((int)substr($t, 11, 2) === $hour) {
            $index = $i;
            break;
        }
    }
    if ($index === null) {
        $index = min($hour, count($day['time']) - 1);
    }

    $pick = function (string $key) use ($day, $index) {
        return isset($day[$key][$index]) ? $day[$key][$index] : null;
    };

    return [
        'temp'     => $pick('temperature_2m'),
        'humidity' => $pick('relative_humidity_2m'),
        'wind'     => $pick('wind_speed_10m'),
        'wind_dir' => $pick('wind_direction_10m'),
        'cloud'    => $pick('cloud_cover'),
        'precip'   => $pick('precipitation'),
        'pressure' => $pick('pressure_msl'),
        'code'     => $pick('weather_code'),
        'source'   => $day['_source'] ?? $source,
        'time'     => $day['time'][$index] ?? $datetime,
    ];
}

/** POST /api?r=weather/get  { colony_id | apiary_id | lat+lon, at } */
function handle_weather(): void
{
    require_login();
    $at = (string)param('at', date('Y-m-d H:i:s'));
    $at = str_replace('T', ' ', $at);
    if (strlen($at) === 10) {
        $at .= ' 12:00:00';
    }

    $lat = param('lat');
    $lon = param('lon');

    if ($lat === null || $lon === null) {
        $colonyId = param_int('colony_id');
        $apiaryId = param_int('apiary_id');
        if ($colonyId) {
            $stmt = db()->prepare('SELECT a.latitude, a.longitude FROM colonies c JOIN apiaries a ON a.id = c.apiary_id WHERE c.id = ?');
            $stmt->execute([$colonyId]);
        } elseif ($apiaryId) {
            $stmt = db()->prepare('SELECT latitude, longitude FROM apiaries WHERE id = ?');
            $stmt->execute([$apiaryId]);
        } else {
            fail('missing_location');
        }
        $row = $stmt->fetch();
        if (!$row || $row['latitude'] === null || $row['longitude'] === null) {
            fail('apiary_without_coordinates');
        }
        $lat = $row['latitude'];
        $lon = $row['longitude'];
    }

    $w = weather_for((float)$lat, (float)$lon, $at);
    if ($w === null) {
        fail('weather_unavailable', 503);
    }
    ok($w);
}

/** POST /api?r=geo/search  { q } - find coordinates for a place name. */
function handle_geo_search(): void
{
    require_login();
    $cfg = config()['weather'];
    $q   = trim((string)param('q', ''));
    if ($q === '') {
        fail('missing_query');
    }
    $url  = $cfg['geocode_url'] . '?' . http_build_query(['name' => $q, 'count' => 8, 'language' => 'de', 'format' => 'json']);
    $resp = http_get_json($url, (int)($cfg['timeout'] ?? 8));
    if (!$resp) {
        fail('weather_unavailable', 503);
    }
    $out = [];
    foreach (($resp['results'] ?? []) as $r) {
        $out[] = [
            'name'      => $r['name'] ?? '',
            'admin'     => trim(($r['admin1'] ?? '') . ' ' . ($r['country'] ?? '')),
            'latitude'  => $r['latitude'] ?? null,
            'longitude' => $r['longitude'] ?? null,
            'altitude'  => $r['elevation'] ?? null,
        ];
    }
    ok($out);
}
