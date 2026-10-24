#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../src/meshcore.php';
require __DIR__ . '/../src/advert.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$seedHex = str_repeat('11', 32);
$line = 1;
$lat = 59.708585;
$lon = 33.270746;
$latE6 = (int) round($lat * 1000000);
$lonE6 = (int) round($lon * 1000000);

$name = advertName($line, $lat, $lon, $seedHex);
check($name === 'LO-TIH-Veronika-NW', 'known name vector');
check(preg_match('/^(?:LO-[A-Z]{3}|[A-Z]{3})-[A-Za-z]+-(?:N|NE|E|SE|S|SW|W|NW|Omni)$/', $name) === 1, 'name format');
check(strlen($name) <= ADVERT_MAX_NAME_LEN, 'name fits advert data');
check(advertName($line, $lat, $lon, $seedHex) === $name, 'name is stable');
check(advertName(2, $lat, $lon, $seedHex) !== $name, 'name differs per line');

$cityName = advertName(1, 59.930, 30.350, $seedHex);
check($cityName === 'CEN-Milana-Omni', 'city name uses district code and Omni');
check(!str_starts_with($cityName, 'LO-'), 'city name has no LO prefix');

check(advertRegionCode(59.708585, 33.270746) === 'LED', 'Leningrad oblast uses LED');
check(advertRegionCode(59.281691, 34.368877) === 'LED', 'eastern Leningrad oblast uses LED');
check(advertRegionCode(57.721436, 34.667995) === 'KLD', 'Tver oblast uses KLD');
check(advertRegionCode(59.9, 37.5) === 'CEE', 'Cherepovets area uses CEE');
check(advertRegionCode(57.8, 28.3) === 'PKV', 'Pskov area uses PKV');
check(advertRegionCode(59.9, 30.3) === 'LED', 'St Petersburg uses LED');
check(advertRegionCode(58.521, 31.271) === 'LED', 'Veliky Novgorod uses LED');
check(advertRegionCode(58.0, 31.3) === 'LED', 'Novgorod oblast uses LED');
check(advertRegionCode(58.39, 33.9) === 'LED', 'Borovichi uses LED');
check(
    advertName(557, 58.586456, 31.245173, $seedHex) === 'LED-Tatiana-N',
    'Novgorod advert name uses LED code'
);
check(
    advertName(500, 57.721436, 34.667995, $seedHex) === 'KLD-Lilia-NW',
    'non-LED advert name uses IATA region code'
);

$names = [];
for ($i = 1; $i <= 1000; $i++) {
    $names[] = advertName($i, $lat, $lon, $seedHex);
}
check(count(array_unique($names)) >= 50, 'female names repeat but provide variety');

$identity = advertDeriveIdentity($seedHex, $line, $latE6, $lonE6);
check(strlen($identity['public_key']) === 32, 'public key is 32 bytes');
check(strlen($identity['private_key']) === 64, 'private key is 64 bytes');
check(sodium_crypto_sign_publickey_from_secretkey($identity['private_key']) === $identity['public_key'], 'key pair matches');
check(ord($identity['public_key'][0]) !== 0x00 && ord($identity['public_key'][0]) !== 0xFF, 'public key prefix is allowed');
check($identity['public_key_hex'] === '75469DA65C7EEE3E9A7E19A36CC0B2A7207E8C945D32AB45698B5C645760C725', 'known identity vector');
check(advertDeriveIdentity($seedHex, $line, $latE6, $lonE6)['public_key'] === $identity['public_key'], 'identity is stable');
check(advertDeriveIdentity($seedHex, 2, $latE6, $lonE6)['public_key'] !== $identity['public_key'], 'identity differs per line');

$appData = advertBuildAppData(ADVERT_TYPE_REPEATER, $latE6, $lonE6, $name);
check(strlen($appData) === 1 + 4 + 4 + strlen($name), 'app data length');
check(ord($appData[0]) === (ADVERT_TYPE_REPEATER | ADVERT_LATLON_FLAG | ADVERT_NAME_FLAG), 'app data flags');
check(advertSignedInt32(substr($appData, 1, 4)) === $latE6, 'latitude encoding');
check(advertSignedInt32(substr($appData, 5, 4)) === $lonE6, 'longitude encoding');
check(substr($appData, 9) === $name, 'name encoding');

$timestamp = 1700000000;
$payload = advertBuildPayload($identity, $appData, $timestamp);
check($payload['packet_type'] === ADVERT_PACKET_TYPE, 'packet type is advert');
check($payload['payload_len'] === 32 + 4 + 64 + strlen($appData), 'payload length');
check(substr($payload['payload'], 0, 32) === $identity['public_key'], 'payload starts with public key');
check(advertSignedInt32(substr($payload['payload'], 32, 4)) === $timestamp, 'payload timestamp');
check(
    $payload['hash_hex'] === strtoupper(bin2hex(substr(hash('sha256', chr(ADVERT_PACKET_TYPE) . $payload['payload'], true), 0, 8))),
    'packet hash uses advert type'
);
check($payload['hash_hex'] === '4A8EA7298D5E280D', 'known advert hash vector');
check(
    strtoupper(bin2hex($payload['signature'])) === '74B98CF6C19BA1C265007D31E9C6BE24DEC871AF813D9B26A8E92F8607148139105575236276748F5DE17ADD53870496D26A6AD570B0E5B0038732841BFD530C',
    'known advert signature vector'
);

$decoded = advertVerifyPayload($payload['payload']);
check($decoded['name'] === $name, 'decoded name');
check($decoded['lat'] === $lat, 'decoded latitude');
check($decoded['lon'] === $lon, 'decoded longitude');
check($decoded['node_type'] === ADVERT_TYPE_REPEATER, 'decoded node type');
check($decoded['timestamp'] === $timestamp, 'decoded timestamp');
check($decoded['public_key_hex'] === $identity['public_key_hex'], 'decoded public key');

$tampered = $payload['payload'];
$tampered[strlen($tampered) - 1] = chr(ord($tampered[strlen($tampered) - 1]) ^ 0x01);
try {
    advertVerifyPayload($tampered);
    check(false, 'tampered advert must not verify');
} catch (RuntimeException $e) {
    check(true, 'tampered advert is rejected');
}

$floodPath = [
    'bytes' => hex2bin('A1B2C3D4'),
    'count' => 2,
    'size' => 2,
    'route' => 'F',
    'json' => 'path_2x2_4b',
];
$packet = meshcoreBuildPacket($payload, $floodPath);
check(str_starts_with($packet['raw_hex'], '1142'), 'advert flood header and path length');
check($packet['route'] === 'F', 'advert flood route');
check($packet['packet_type'] === ADVERT_PACKET_TYPE, 'advert packet type');
check($packet['len'] === 2 + 4 + $payload['payload_len'], 'advert raw length');
check($packet['hash_hex'] === $payload['hash_hex'], 'advert hash survives packet build');

$directPath = ['bytes' => '', 'count' => 0, 'size' => 0, 'route' => 'D', 'json' => null];
$directPacket = meshcoreBuildPacket($payload, $directPath);
check(str_starts_with($directPacket['raw_hex'], '1200'), 'advert direct header');
check($directPacket['path'] === null, 'advert direct has no path');

$baseConfig = [
    'mqtt' => ['topic' => 'meshcore/LED', 'client_id' => null],
    'identity' => [
        'public_key' => str_repeat('AB', 32),
        'node_name' => 'Observer-A',
    ],
    'packet' => [
        'direction' => 'rx',
        'score' => 1000,
        'duration' => 120,
        'snr' => 5.5,
        'rssi' => -86,
    ],
];
$json = json_decode(meshcorePacketPayload($baseConfig, $packet, $timestamp), true, 512, JSON_THROW_ON_ERROR);
check($json['packet_type'] === '4', 'json packet type');
check($json['hash'] === $payload['hash_hex'], 'json hash');
check($json['route'] === 'F', 'json route');
check(!isset($json['path']), 'json flood advert has no path field');

$directJson = json_decode(meshcorePacketPayload($baseConfig, $directPacket, $timestamp), true, 512, JSON_THROW_ON_ERROR);
check($directJson['route'] === 'F', 'json zero-hop advert route follows firmware');
check(!isset($directJson['path']), 'json zero-hop advert has no path field');

$zeroHopFloodPacket = meshcoreBuildPacket($payload, [
    'bytes' => '',
    'count' => 0,
    'size' => 2,
    'route' => 'F',
    'json' => null,
]);
check(str_starts_with($zeroHopFloodPacket['raw_hex'], '1140'), 'flood zero-hop keeps 2-byte path hash size');
check($zeroHopFloodPacket['path'] === null, 'flood zero-hop has no path json');

$directPathPacket = meshcoreBuildPacket($payload, [
    'bytes' => hex2bin('A1B2'),
    'count' => 1,
    'size' => 2,
    'route' => 'D',
    'json' => 'path_1x2_2b',
]);
$directPathJson = json_decode(
    meshcorePacketPayload($baseConfig, $directPathPacket, $timestamp),
    true,
    512,
    JSON_THROW_ON_ERROR
);
check($directPathJson['route'] === 'D', 'json direct-with-path advert route');
check($directPathJson['path'] === 'path_1x2_2b', 'json direct-with-path advert path');

$tmp = tempnam(sys_get_temp_dir(), 'advert-coords-');
file_put_contents($tmp, "59.708585, 33.270746\n\nbad line\n91.0, 33.0\n59.696392,33.290327\n");
$points = advertParseCoordinateFile($tmp);
unlink($tmp);
check(count($points) === 2, 'coordinate file skips invalid lines');
check($points[0]['line'] === 1 && $points[1]['line'] === 5, 'coordinate file keeps source lines');
check(abs($points[0]['lat'] - 59.708585) < 0.000001, 'parsed latitude');
check(abs($points[1]['lon'] - 33.290327) < 0.000001, 'parsed longitude');

echo "advert-format: PASS\n";
