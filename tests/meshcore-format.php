#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../src/meshcore.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$publicKey = str_repeat('AB', 32);
$config = [
    'mqtt' => [
        'topic' => 'meshcore/LED',
        'client_id' => null,
    ],
    'identity' => [
        'public_key' => $publicKey,
        'node_name' => 'MeshCore-Test',
    ],
    'device' => [
        'model' => 'Heltec V4',
        'firmware_version' => 'v1.16.0',
        'client_version' => 'mqtt-poster',
        'radio' => '869.525,250,11,5',
    ],
    'status' => [
        'enabled' => true,
        'retain' => true,
        'interval_ms' => 300000,
        'battery_mv' => 4100,
        'uptime_secs' => 12345,
        'errors' => 0,
        'queue_len' => 1,
        'noise_floor' => -110,
        'tx_air_secs' => 0,
        'rx_air_secs' => 0,
        'recv_errors' => 0,
    ],
    'packet' => [
        'channel_secret' => MESHCORE_PUBLIC_CHANNEL_PSK,
        'direction' => 'rx',
        'snr' => 5.5,
        'snr_min' => 3.0,
        'snr_max' => 8.5,
        'snr_step' => 0.6,
        'rssi' => -86,
        'rssi_min' => -104,
        'rssi_max' => -74,
        'rssi_step' => 3,
        'score' => 1000,
        'duration' => 120,
    ],
];

$body = meshcoreBuildBody('{nick}: {text}', 'Alice', 'Hello');
check($body === 'Alice: Hello', 'body template replacement');

$longBody = meshcoreBuildBody('{nick}: {text}', 'Alice', str_repeat('x', 300));
check(strlen($longBody) <= MESHCORE_MAX_TEXT_LEN, 'body is limited to MAX_TEXT_LEN');
check(str_starts_with($longBody, 'Alice: '), 'long body keeps the nick prefix');

$packet = meshcoreBuildGroupTextPacket($config, $body, 1700000000);
check(str_starts_with($packet['raw_hex'], '1600'), 'raw header is GRP_TXT zero-hop direct');
check(substr($packet['raw_hex'], 4, 2) === '11', 'public channel hash');
check($packet['packet_type'] === 5, 'packet type is GRP_TXT');
check($packet['payload_len'] === 1 + 2 + strlen($packet['payload']) - 3, 'payload length');
check($packet['len'] === 2 + $packet['payload_len'], 'raw length');
check(
    $packet['hash_hex'] === strtoupper(bin2hex(substr(hash('sha256', "\x05" . $packet['payload'], true), 0, 8))),
    'packet hash'
);
check($packet['hash_hex'] === '390C9B9F78AB6E60', 'known packet hash vector');
check(str_starts_with($packet['raw_hex'], '1600114AC1E9'), 'known raw packet prefix');
check($packet['route'] === 'D' && $packet['path'] === null, 'no path means direct zero-hop');

$decoded = meshcoreDecryptRaw(hex2bin($packet['raw_hex']), MESHCORE_PUBLIC_CHANNEL_PSK);
check($decoded['body'] === 'Alice: Hello', 'decrypted body');
check($decoded['timestamp'] === 1700000000, 'decrypted timestamp');
check($decoded['packet_type'] === 5, 'decrypted packet type');

$json = meshcorePacketPayload($config, $packet, 1700000000);
$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
check($data['origin'] === 'MeshCore-Test', 'json origin');
check($data['origin_id'] === $publicKey, 'json origin_id');
check($data['type'] === 'PACKET', 'json type');
check($data['packet_type'] === '5', 'json packet_type is string');
check($data['route'] === 'F', 'json zero-hop route follows firmware');
check(!isset($data['path']), 'json zero-hop has no path field');
check($data['len'] === (string) $packet['len'], 'json len is string');
check($data['payload_len'] === (string) $packet['payload_len'], 'json payload_len is string');
check($data['SNR'] === '5.5', 'json SNR is string');
check($data['RSSI'] === '-86', 'json RSSI is string');
check($data['score'] === '1000', 'json score is string');
check($data['duration'] === '120', 'json duration is string');
check($data['raw'] === $packet['raw_hex'], 'json raw');
check($data['hash'] === $packet['hash_hex'], 'json hash');

$status = json_decode(meshcoreStatusPayload($config, true, 1700000000), true, 512, JSON_THROW_ON_ERROR);
check($status['status'] === 'online', 'status online');
check($status['origin_id'] === $publicKey, 'status origin_id');
check($status['model'] === 'Heltec V4', 'status model');
check($status['stats']['battery_mv'] === 4100, 'status stats');

$offline = json_decode(meshcoreStatusPayload($config, false, 1700000000), true, 512, JSON_THROW_ON_ERROR);
check($offline['status'] === 'offline', 'status offline');
check(!isset($offline['stats']), 'offline status has no stats');

check(
    meshcoreTopic($config, 'packets') === "meshcore/LED/{$publicKey}/packets",
    'packets topic'
);
check(
    meshcoreTopic($config, 'status') === "meshcore/LED/{$publicKey}/status",
    'status topic'
);
check(meshcoreClientId($config) === 'mqtt_meshcore-ABABAB', 'derived client id');

$directConfig = $config;
$directConfig['packet']['path'] = [
    'neighbors' => ['A1B2', 'C3D4', 'E5F6', '0A1B'],
    'min_hops' => 2,
    'max_hops' => 3,
    'direct_probability' => 1.0,
];
$directPath = meshcoreBuildPath($directConfig);
check($directPath['route'] === 'D', 'direct route is no-path');
check($directPath['count'] === 0, 'direct route has no hops');
check($directPath['json'] === null, 'direct route has no path json');

$floodConfig = $config;
$floodConfig['packet']['path'] = [
    'neighbors' => ['A1B2', 'C3D4', 'E5F6', '0A1B'],
    'min_hops' => 2,
    'max_hops' => 3,
    'direct_probability' => 0.0,
];
$floodPath = meshcoreBuildPath($floodConfig, 2);
check($floodPath['route'] === 'F', 'zero direct_probability produces flood');
check($floodPath['count'] === 2, 'flood path hop count');
check($floodPath['size'] === 2, 'flood path hash size');
check($floodPath['json'] === 'path_2x2_4b', 'flood path json');
check(strlen($floodPath['bytes']) === 4, 'flood path byte length');
check(count(array_unique($floodPath['prefixes'])) === 2, 'flood path prefixes are unique');

$floodPacket = meshcoreBuildGroupTextPacket($floodConfig, $body, 1700000000, $floodPath);
check(str_starts_with($floodPacket['raw_hex'], '1542'), 'flood packet header and path length');
check($floodPacket['route'] === 'F', 'flood packet route');
check($floodPacket['path'] === 'path_2x2_4b', 'flood packet path json');
check(
    $floodPacket['len'] === 2 + strlen($floodPath['bytes']) + $floodPacket['payload_len'],
    'flood packet raw length includes path'
);

$floodJson = json_decode(meshcorePacketPayload($floodConfig, $floodPacket, 1700000000), true, 512, JSON_THROW_ON_ERROR);
check($floodJson['route'] === 'F', 'json flood route');
check(!isset($floodJson['path']), 'json flood has no path field');
check($floodJson['len'] === (string) $floodPacket['len'], 'json flood len');

$directPathPacket = meshcoreBuildGroupTextPacket($config, $body, 1700000000, [
    'bytes' => hex2bin('A1B2'),
    'count' => 1,
    'size' => 2,
    'route' => 'D',
    'json' => 'path_1x2_2b',
]);
$directPathJson = json_decode(
    meshcorePacketPayload($config, $directPathPacket, 1700000000),
    true,
    512,
    JSON_THROW_ON_ERROR
);
check($directPathJson['route'] === 'D', 'json direct-with-path route');
check($directPathJson['path'] === 'path_1x2_2b', 'json direct-with-path path');

$longPathConfig = $config;
$longPathConfig['packet']['path'] = [
    'neighbors' => ['A1B2', 'C3D4', 'E5F6', '0A1B', '2C3D', '4E5F', '6A7B', '8C9D'],
    'min_hops' => 3,
    'max_hops' => 7,
    'direct_probability' => 0.0,
];
$longPath = meshcoreBuildPath($longPathConfig, 7);
check($longPath['route'] === 'F', 'seven-hop path is flood');
check($longPath['count'] === 7, 'seven-hop path count');
check(strlen($longPath['bytes']) === 14, 'seven-hop path bytes');
check($longPath['json'] === 'path_7x2_14b', 'seven-hop path json');
check(count(array_unique($longPath['prefixes'])) === 7, 'seven-hop prefixes are unique');
$longPacket = meshcoreBuildGroupTextPacket($longPathConfig, $body, 1700000000, $longPath);
check(str_starts_with($longPacket['raw_hex'], '1547'), 'seven-hop flood raw header and path length');
check($longPacket['len'] === 2 + 14 + $longPacket['payload_len'], 'seven-hop raw length includes path');

$probabilityConfig = $directConfig;
$probabilityConfig['packet']['path']['direct_probability'] = 0.2;
$directCount = 0;
for ($i = 0; $i < 1000; $i++) {
    if (meshcoreBuildPath($probabilityConfig)['route'] === 'D') {
        $directCount++;
    }
}
check($directCount >= 100 && $directCount <= 300, 'direct_probability controls direct no-path share');

$signal = ['snr' => 5.5, 'rssi' => -86];
$snrPrevious = $signal['snr'];
$rssiPrevious = $signal['rssi'];
for ($i = 0; $i < 100; $i++) {
    $signal = meshcoreNextSignal($signal, $config);
    check($signal['snr'] >= 3.0 && $signal['snr'] <= 8.5, 'snr stays in range');
    check($signal['rssi'] >= -104 && $signal['rssi'] <= -74, 'rssi stays in range');
    check(abs($signal['snr'] - $snrPrevious) <= 0.6 + 0.0001, 'snr step');
    check(abs($signal['rssi'] - $rssiPrevious) <= 3, 'rssi step');
    $snrPrevious = $signal['snr'];
    $rssiPrevious = $signal['rssi'];
}

$runtime = meshcoreInitialRuntime($config);
$runtime['started_at'] = microtime(true) - 120;
$runtimeStats = meshcoreRuntimeStats($runtime, $config);
check($runtimeStats['uptime_secs'] >= 12345 + 120, 'uptime grows');
check($runtimeStats['battery_mv'] <= 4100, 'battery does not grow');
check($runtimeStats['errors'] === 0, 'errors stay zero');
check($runtimeStats['queue_len'] === 1, 'queue is one');
check($runtimeStats['recv_errors'] === 0, 'recv errors stay zero');
check($runtimeStats['tx_air_secs'] >= 1, 'tx air grows');
check($runtimeStats['rx_air_secs'] >= 6, 'rx air grows');
check($runtimeStats['tx_air_secs'] < $runtimeStats['uptime_secs'], 'tx air is less than uptime');
check($runtimeStats['rx_air_secs'] < $runtimeStats['uptime_secs'], 'rx air is less than uptime');
check($runtimeStats['noise_floor'] >= -112 && $runtimeStats['noise_floor'] <= -108, 'noise floor jitter');

$runtimeStatus = json_decode(
    meshcoreStatusPayload($config, true, 1700000000, $runtimeStats),
    true,
    512,
    JSON_THROW_ON_ERROR
);
check($runtimeStatus['stats']['uptime_secs'] === $runtimeStats['uptime_secs'], 'status uses runtime stats');
check($runtimeStatus['stats']['queue_len'] === 1, 'status queue is one');

echo "meshcore-format: PASS\n";
