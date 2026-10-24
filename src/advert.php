<?php
declare(strict_types=1);

const ADVERT_PACKET_TYPE = 4;
const ADVERT_TYPE_NONE = 0;
const ADVERT_TYPE_CHAT = 1;
const ADVERT_TYPE_REPEATER = 2;
const ADVERT_TYPE_ROOM = 3;
const ADVERT_TYPE_SENSOR = 4;
const ADVERT_LATLON_FLAG = 0x10;
const ADVERT_FEAT1_FLAG = 0x20;
const ADVERT_FEAT2_FLAG = 0x40;
const ADVERT_NAME_FLAG = 0x80;
const ADVERT_MAX_DATA_LEN = 32;
const ADVERT_MAX_NAME_LEN = 23;

function advertRequireSodium(): void
{
    if (!function_exists('sodium_crypto_sign_seed_keypair')) {
        throw new RuntimeException('The sodium extension is required for advert signing');
    }
}

function advertPackSignedInt32(int $value): string
{
    return pack('V', $value & 0xFFFFFFFF);
}

function advertSignedInt32(string $bytes): int
{
    if (strlen($bytes) !== 4) {
        throw new RuntimeException('Signed int32 requires exactly 4 bytes');
    }

    $value = unpack('V', $bytes)[1];

    return $value >= 0x80000000 ? $value - 0x100000000 : $value;
}

function advertParseCoordinateFile(string $path): array
{
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException("Cannot read coordinate file: {$path}");
    }

    $points = [];
    foreach ($lines as $index => $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $parts = array_map('trim', explode(',', $line));
        if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
            fwrite(STDERR, 'warning: coordinate file line ' . ($index + 1) . " skipped: expected '<lat>, <lon>'\n");
            continue;
        }

        $lat = (float) $parts[0];
        $lon = (float) $parts[1];
        if (!is_finite($lat) || !is_finite($lon)
            || $lat < -90.0 || $lat > 90.0
            || $lon < -180.0 || $lon > 180.0
        ) {
            fwrite(STDERR, 'warning: coordinate file line ' . ($index + 1) . " skipped: coordinates out of range\n");
            continue;
        }

        $points[] = [
            'line' => $index + 1,
            'lat' => $lat,
            'lon' => $lon,
            'lat_e6' => (int) round($lat * 1000000),
            'lon_e6' => (int) round($lon * 1000000),
        ];
    }

    if ($points === []) {
        throw new RuntimeException("No valid coordinates found in {$path}");
    }

    return $points;
}

function advertNameCenters(): array
{
    return [
        // Санкт-Петербург, районы города.
        ['scope' => 'city', 'code' => 'ADM', 'lat' => 59.925, 'lon' => 30.300],
        ['scope' => 'city', 'code' => 'VAS', 'lat' => 59.940, 'lon' => 30.270],
        ['scope' => 'city', 'code' => 'VYB', 'lat' => 60.030, 'lon' => 30.320],
        ['scope' => 'city', 'code' => 'KLN', 'lat' => 59.990, 'lon' => 30.400],
        ['scope' => 'city', 'code' => 'KIR', 'lat' => 59.850, 'lon' => 30.250],
        ['scope' => 'city', 'code' => 'KOL', 'lat' => 59.750, 'lon' => 30.600],
        ['scope' => 'city', 'code' => 'KRG', 'lat' => 59.970, 'lon' => 30.450],
        ['scope' => 'city', 'code' => 'KRS', 'lat' => 59.830, 'lon' => 30.150],
        ['scope' => 'city', 'code' => 'KRT', 'lat' => 59.990, 'lon' => 29.770],
        ['scope' => 'city', 'code' => 'KUR', 'lat' => 60.150, 'lon' => 29.900],
        ['scope' => 'city', 'code' => 'MOS', 'lat' => 59.850, 'lon' => 30.350],
        ['scope' => 'city', 'code' => 'NEV', 'lat' => 59.900, 'lon' => 30.450],
        ['scope' => 'city', 'code' => 'PET', 'lat' => 59.960, 'lon' => 30.300],
        ['scope' => 'city', 'code' => 'PDO', 'lat' => 59.850, 'lon' => 29.900],
        ['scope' => 'city', 'code' => 'PRI', 'lat' => 60.000, 'lon' => 30.200],
        ['scope' => 'city', 'code' => 'PUS', 'lat' => 59.720, 'lon' => 30.400],
        ['scope' => 'city', 'code' => 'FRU', 'lat' => 59.850, 'lon' => 30.400],
        ['scope' => 'city', 'code' => 'CEN', 'lat' => 59.930, 'lon' => 30.350],

        // Ленинградская область, районы.
        ['scope' => 'lo', 'code' => 'BOX', 'lat' => 59.470, 'lon' => 33.850],
        ['scope' => 'lo', 'code' => 'VOL', 'lat' => 59.450, 'lon' => 29.480],
        ['scope' => 'lo', 'code' => 'VX', 'lat' => 59.920, 'lon' => 32.350],
        ['scope' => 'lo', 'code' => 'VSE', 'lat' => 60.020, 'lon' => 30.650],
        ['scope' => 'lo', 'code' => 'VYB', 'lat' => 60.710, 'lon' => 28.750],
        ['scope' => 'lo', 'code' => 'GAT', 'lat' => 59.560, 'lon' => 30.120],
        ['scope' => 'lo', 'code' => 'KIN', 'lat' => 59.370, 'lon' => 28.600],
        ['scope' => 'lo', 'code' => 'KSH', 'lat' => 59.450, 'lon' => 32.020],
        ['scope' => 'lo', 'code' => 'KIR', 'lat' => 59.870, 'lon' => 31.000],
        ['scope' => 'lo', 'code' => 'LOD', 'lat' => 60.730, 'lon' => 33.550],
        ['scope' => 'lo', 'code' => 'LOM', 'lat' => 59.900, 'lon' => 29.770],
        ['scope' => 'lo', 'code' => 'LUZ', 'lat' => 58.740, 'lon' => 29.850],
        ['scope' => 'lo', 'code' => 'POD', 'lat' => 60.910, 'lon' => 34.170],
        ['scope' => 'lo', 'code' => 'PRZ', 'lat' => 61.030, 'lon' => 30.120],
        ['scope' => 'lo', 'code' => 'SLA', 'lat' => 59.120, 'lon' => 28.090],
        ['scope' => 'lo', 'code' => 'TIH', 'lat' => 59.640, 'lon' => 33.500],
        ['scope' => 'lo', 'code' => 'TOS', 'lat' => 59.540, 'lon' => 30.880],
    ];
}

function advertDistanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $lat1 = deg2rad($lat1);
    $lat2 = deg2rad($lat2);
    $dLat = $lat2 - $lat1;
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLon / 2) ** 2;

    return 6371.0 * 2.0 * atan2(sqrt($a), sqrt(1.0 - $a));
}

function advertRegionCenters(): array
{
    return [
        'LED' => [59.800, 30.300],
        'PKV' => [57.817, 28.333],
        'VLU' => [56.340, 30.530],
        'CEE' => [59.133, 37.900],
        'VGD' => [59.221, 39.892],
        'PES' => [61.785, 34.347],
        'MOW' => [55.756, 37.617],
        'KLD' => [56.859, 35.918],
        'LNX' => [54.782, 32.040],
        'VKV' => [64.539, 40.518],
        'MMK' => [68.959, 33.083],
        'KGD' => [54.710, 20.452],
        'GOJ' => [56.327, 44.006],
        'KZN' => [55.796, 49.109],
        'KUF' => [53.196, 50.100],
        'ROV' => [47.236, 39.701],
        'KRR' => [45.036, 38.975],
        'AER' => [43.603, 39.734],
        'UFA' => [54.739, 55.972],
        'OVB' => [55.008, 82.936],
        'KJA' => [56.015, 92.893],
        'IKT' => [52.287, 104.305],
        'VVO' => [43.116, 131.886],
        'UUD' => [51.833, 107.583],
        'YKS' => [62.036, 129.676],
        'KHV' => [48.480, 135.070],
        'MSQ' => [53.900, 27.566],
        'BQT' => [52.097, 23.700],
        'GME' => [52.442, 30.987],
        'GNA' => [53.677, 23.829],
        'MVQ' => [53.900, 30.333],
        'VTB' => [55.190, 30.200],
    ];
}

function advertIsNovgorod(float $lat, float $lon): bool
{
    // В списке регионов нет отдельного региона Великого Новгорода.
    // Примерная область Новгородской области (радиус 160 км) идёт в LED.
    return advertDistanceKm($lat, $lon, 58.521, 31.271) <= 160.0;
}

function advertRegionCode(float $lat, float $lon, string $default = 'LED'): string
{
    if (advertIsNovgorod($lat, $lon)) {
        return $default;
    }

    $leningradDistance = INF;
    foreach (advertNameCenters() as $center) {
        if ($center['scope'] !== 'lo') {
            continue;
        }
        $distance = advertDistanceKm($lat, $lon, $center['lat'], $center['lon']);
        if ($distance < $leningradDistance) {
            $leningradDistance = $distance;
        }
    }

    $nearestCode = null;
    $nearestDistance = INF;
    foreach (advertRegionCenters() as $code => $center) {
        if ($code === 'LED') {
            continue;
        }
        $distance = advertDistanceKm($lat, $lon, $center[0], $center[1]);
        if ($distance < $nearestDistance) {
            $nearestDistance = $distance;
            $nearestCode = $code;
        }
    }

    if ($nearestCode === null || $leningradDistance <= $nearestDistance) {
        return $default;
    }

    return $nearestCode;
}

function advertDirection(float $fromLat, float $fromLon, float $toLat, float $toLon): string
{
    if (advertDistanceKm($fromLat, $fromLon, $toLat, $toLon) < 1.0) {
        return 'Omni';
    }

    $fromLat = deg2rad($fromLat);
    $toLat = deg2rad($toLat);
    $dLon = deg2rad($toLon - $fromLon);
    $bearing = rad2deg(atan2(
        sin($dLon) * cos($toLat),
        cos($fromLat) * sin($toLat) - sin($fromLat) * cos($toLat) * cos($dLon)
    ));
    $bearing = fmod($bearing + 360.0, 360.0);
    $directions = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];

    return $directions[(int) round($bearing / 45.0) % 8];
}

function advertFemaleNames(): array
{
    return [
        'Alina', 'Alisa', 'Alla', 'Alyona', 'Anastasia', 'Angelica', 'Angelina',
        'Anna', 'Antonina', 'Arina', 'Bogdana', 'Bronislava', 'Daria', 'Darina',
        'Diana', 'Ekaterina', 'Elena', 'Elizaveta', 'Evgenia', 'Eva', 'Faina',
        'Galina', 'Inga', 'Inna', 'Irina', 'Karina', 'Kira', 'Kristina', 'Ksenia',
        'Lada', 'Larisa', 'Lidia', 'Lilia', 'Lyubov', 'Lyudmila', 'Maria', 'Marina',
        'Maya', 'Milana', 'Miroslava', 'Nadezhda', 'Natalia', 'Natalya', 'Nika',
        'Nina', 'Oksana', 'Olesya', 'Olga', 'Polina', 'Raisa', 'Regina',
        'Rostislava', 'Ruslana', 'Snezhana', 'Sofia', 'Svetlana', 'Taisia', 'Tamara',
        'Tatiana', 'Ulyana', 'Ustinya', 'Valentina', 'Valeria', 'Varvara', 'Vasilisa',
        'Vera', 'Veronika', 'Viktoria', 'Vladislava', 'Vseslava', 'Yana', 'Yaroslava',
        'Yekaterina', 'Yulia', 'Zhanna', 'Zinaida', 'Zlata', 'Zoya',
    ];
}

function advertFemaleName(int $line, int $latE6, int $lonE6, string $seedHex = ''): string
{
    $names = advertFemaleNames();
    $seedHex = strtolower(trim($seedHex));
    if (preg_match('/^[0-9a-f]{64}$/', $seedHex) === 1) {
        $hash = hash_hmac(
            'sha256',
            "advert-name-v1|{$line}|{$latE6}|{$lonE6}",
            hex2bin($seedHex),
            true
        );
    } else {
        $hash = hash('sha256', "advert-name-v1|{$line}|{$latE6}|{$lonE6}", true);
    }

    $index = unpack('N', substr($hash, 0, 4))[1] % count($names);

    return $names[$index];
}

function advertName(
    int $line,
    float $lat,
    float $lon,
    string $seedHex = '',
    ?string $regionCode = null
): string {
    if ($line < 1) {
        throw new RuntimeException('Advert line number must be positive');
    }

    $regionCode ??= advertRegionCode($lat, $lon);
    if ($regionCode === 'LED' && advertIsNovgorod($lat, $lon)) {
        $codePrefix = 'LED-';
        $fromLat = 58.521;
        $fromLon = 31.271;
    } elseif ($regionCode === 'LED') {
        $best = null;
        $bestDistance = INF;
        foreach (advertNameCenters() as $center) {
            $distance = advertDistanceKm($lat, $lon, $center['lat'], $center['lon']);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $center;
            }
        }
        if ($best === null) {
            throw new RuntimeException('Unable to resolve advert name center');
        }
        $codePrefix = ($best['scope'] === 'lo' ? 'LO-' : '') . $best['code'] . '-';
        $fromLat = $best['lat'];
        $fromLon = $best['lon'];
    } else {
        $centers = advertRegionCenters();
        if (!isset($centers[$regionCode])) {
            throw new RuntimeException("Unknown advert region code: {$regionCode}");
        }
        $codePrefix = $regionCode . '-';
        $fromLat = $centers[$regionCode][0];
        $fromLon = $centers[$regionCode][1];
    }

    $direction = advertDirection($fromLat, $fromLon, $lat, $lon);
    $suffix = '-' . $direction;
    $maxBaseLength = ADVERT_MAX_NAME_LEN - strlen($codePrefix) - strlen($suffix);
    if ($maxBaseLength < 1) {
        throw new RuntimeException('Advert name does not fit into 23 bytes');
    }

    $base = advertFemaleName(
        $line,
        (int) round($lat * 1000000),
        (int) round($lon * 1000000),
        $seedHex
    );
    if (strlen($base) > $maxBaseLength) {
        $base = substr($base, 0, $maxBaseLength);
    }

    return $codePrefix . $base . $suffix;
}

function advertDeriveIdentity(string $seedHex, int $line, int $latE6, int $lonE6): array
{
    advertRequireSodium();

    $seedHex = strtolower(trim($seedHex));
    if (preg_match('/^[0-9a-f]{64}$/', $seedHex) !== 1) {
        throw new RuntimeException('advert.key_seed must be 64 hexadecimal characters');
    }
    $masterSeed = hex2bin($seedHex);
    if ($masterSeed === false) {
        throw new RuntimeException('advert.key_seed is not valid hex');
    }

    $counter = 0;
    do {
        $nodeSeed = hash_hmac(
            'sha256',
            "advert-v1|{$line}|{$latE6}|{$lonE6}|{$counter}",
            $masterSeed,
            true
        );
        $keypair = sodium_crypto_sign_seed_keypair($nodeSeed);
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $privateKey = sodium_crypto_sign_secretkey($keypair);
        $counter++;
    } while ($publicKey[0] === "\x00" || $publicKey[0] === "\xFF");

    return [
        'seed' => $nodeSeed,
        'seed_hex' => strtoupper(bin2hex($nodeSeed)),
        'public_key' => $publicKey,
        'public_key_hex' => strtoupper(bin2hex($publicKey)),
        'private_key' => $privateKey,
        'private_key_hex' => strtoupper(bin2hex($privateKey)),
    ];
}

function advertBuildAppData(int $nodeType, int $latE6, int $lonE6, string $name): string
{
    if ($nodeType < 0 || $nodeType > 0x0F) {
        throw new RuntimeException('Advert node type must be between 0 and 15');
    }

    $name = trim($name);
    if ($name === '') {
        throw new RuntimeException('Advert name must not be empty');
    }
    if (strlen($name) > ADVERT_MAX_NAME_LEN) {
        throw new RuntimeException('Advert name is longer than 23 bytes');
    }

    $flags = ($nodeType & 0x0F) | ADVERT_LATLON_FLAG | ADVERT_NAME_FLAG;

    return chr($flags)
        . advertPackSignedInt32($latE6)
        . advertPackSignedInt32($lonE6)
        . $name;
}

function advertBuildPayload(array $identity, string $appData, ?int $timestamp = null): array
{
    advertRequireSodium();

    if (!isset($identity['public_key'], $identity['private_key'])
        || strlen($identity['public_key']) !== 32
        || strlen($identity['private_key']) !== 64
    ) {
        throw new RuntimeException('Advert identity requires a 32-byte public and 64-byte private key');
    }
    if (strlen($appData) < 1 || strlen($appData) > ADVERT_MAX_DATA_LEN) {
        throw new RuntimeException('Advert app data must be between 1 and 32 bytes');
    }

    $timestamp ??= time();
    if ($timestamp < 0 || $timestamp > 0xFFFFFFFF) {
        throw new RuntimeException('Advert timestamp is outside the uint32 range');
    }

    $timestampBytes = pack('V', $timestamp);
    $signed = $identity['public_key'] . $timestampBytes . $appData;
    $signature = sodium_crypto_sign_detached($signed, $identity['private_key']);
    $payload = $identity['public_key'] . $timestampBytes . $signature . $appData;
    $hash = substr(hash('sha256', chr(ADVERT_PACKET_TYPE) . $payload, true), 0, 8);

    return [
        'payload' => $payload,
        'hash' => $hash,
        'hash_hex' => strtoupper(bin2hex($hash)),
        'payload_len' => strlen($payload),
        'packet_type' => ADVERT_PACKET_TYPE,
        'timestamp' => $timestamp,
        'app_data' => $appData,
        'signature' => $signature,
        'public_key' => $identity['public_key'],
        'public_key_hex' => strtoupper(bin2hex($identity['public_key'])),
    ];
}

function advertParseAppData(string $appData): array
{
    if (strlen($appData) < 1) {
        throw new RuntimeException('Advert app data is empty');
    }

    $flags = ord($appData[0]);
    $offset = 1;
    $latE6 = null;
    $lonE6 = null;

    if (($flags & ADVERT_LATLON_FLAG) !== 0) {
        if (strlen($appData) < $offset + 8) {
            throw new RuntimeException('Advert app data has incomplete coordinates');
        }
        $latE6 = advertSignedInt32(substr($appData, $offset, 4));
        $offset += 4;
        $lonE6 = advertSignedInt32(substr($appData, $offset, 4));
        $offset += 4;
    }
    if (($flags & ADVERT_FEAT1_FLAG) !== 0) {
        $offset += 2;
    }
    if (($flags & ADVERT_FEAT2_FLAG) !== 0) {
        $offset += 2;
    }
    if (strlen($appData) < $offset) {
        throw new RuntimeException('Advert app data is truncated');
    }

    $name = ($flags & ADVERT_NAME_FLAG) !== 0 ? substr($appData, $offset) : '';

    return [
        'node_type' => $flags & 0x0F,
        'lat_e6' => $latE6,
        'lon_e6' => $lonE6,
        'lat' => $latE6 === null ? null : $latE6 / 1000000.0,
        'lon' => $lonE6 === null ? null : $lonE6 / 1000000.0,
        'name' => $name,
    ];
}

function advertVerifyPayload(string $payload): array
{
    advertRequireSodium();

    if (strlen($payload) < 32 + 4 + 64 + 1) {
        throw new RuntimeException('Advert payload is too short');
    }

    $publicKey = substr($payload, 0, 32);
    $timestampBytes = substr($payload, 32, 4);
    $signature = substr($payload, 36, 64);
    $appData = substr($payload, 100);
    $signed = $publicKey . $timestampBytes . $appData;

    if (!sodium_crypto_sign_verify_detached($signature, $signed, $publicKey)) {
        throw new RuntimeException('Advert signature is invalid');
    }

    $decoded = advertParseAppData($appData);
    $decoded['public_key'] = $publicKey;
    $decoded['public_key_hex'] = strtoupper(bin2hex($publicKey));
    $decoded['timestamp'] = unpack('V', $timestampBytes)[1];
    $decoded['signature'] = $signature;

    return $decoded;
}
