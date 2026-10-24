#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../poster.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$config = [
    'mqtt' => [
        'host' => '127.0.0.1',
        'topic' => 'meshcore/TEST',
        'qos' => 0,
        'interval_min_ms' => 10,
        'interval_max_ms' => 20,
        'queue_max' => 2,
    ],
    'identities' => [
        [
            'public_key' => str_repeat('AB', 32),
            'node_name' => 'Observer-A',
            'snr' => 5.5,
            'rssi' => -86,
        ],
        [
            'public_key' => str_repeat('CD', 32),
            'node_name' => 'Observer-B',
            'snr' => 4.1,
            'rssi' => -95,
        ],
    ],
    'status' => [
        'enabled' => true,
        'errors' => 0,
        'queue_len' => 1,
        'tx_air_secs' => 0,
        'rx_air_secs' => 0,
        'recv_errors' => 0,
    ],
    'packet' => [
        'message_file' => '/tmp/meshcore-messages.txt',
        'progress_file' => '/tmp/meshcore-messages.progress',
        'loop' => false,
        'retain' => true,
        'direction' => 'rx',
        'snr_min' => -3.0,
        'snr_max' => 8.5,
        'snr_step' => 0.6,
        'rssi_min' => -104,
        'rssi_max' => -74,
        'rssi_step' => 3,
        'score' => 1000,
        'duration' => 120,
        'observer_delay_min_ms' => 10,
        'observer_delay_max_ms' => 20,
        'path' => [
            'neighbors' => ['A1B2', 'C3D4'],
            'min_hops' => 1,
            'max_hops' => 2,
            'direct_probability' => 0.0,
        ],
    ],
    'template_file' => '/tmp/meshcore-chat.tpl',
];

$normalized = normalizeConfig($config, '/tmp/meshcore-config.json');

check(count($normalized['identities']) === 2, 'two identities survive normalization');
check($normalized['identities'][0]['snr'] === 5.5, 'identity A snr override');
check($normalized['identities'][0]['rssi'] === -86, 'identity A rssi override');
check($normalized['identities'][1]['snr'] === 4.1, 'identity B snr override');
check($normalized['identities'][1]['rssi'] === -95, 'identity B rssi override');

check($normalized['packet']['snr'] === 7.5, 'default snr is used when the global base is absent');
check($normalized['packet']['rssi'] === -74, 'default rssi is clamped to the configured range');
check($normalized['status']['battery_mv'] === 4100, 'default battery is used');
check($normalized['status']['uptime_secs'] === 0, 'default uptime is used');
check($normalized['status']['noise_floor'] === -110, 'default noise floor is used');
check($normalized['packet']['channel_secret'] === MESHCORE_PUBLIC_CHANNEL_PSK, 'default public channel');

$legacy = $config;
unset($legacy['identities']);
$legacy['identity'] = $config['identities'][0];
$normalizedLegacy = normalizeConfig($legacy, '/tmp/meshcore-config.json');
check(count($normalizedLegacy['identities']) === 1, 'legacy single identity still works');

$withAdvert = $config;
$withAdvert['advert'] = [
    'coordinate_file' => 'dick.txt',
    'progress_file' => 'dick.txt.progress',
    'key_seed' => str_repeat('22', 32),
    'node_type' => 2,
    'loop' => false,
    'publish_status' => false,
    'direct_probability' => 0.0,
];
$normalizedAdvert = normalizeConfig($withAdvert, '/tmp/meshcore-config.json');
check($normalizedAdvert['advert']['coordinate_file'] === '/tmp/dick.txt', 'advert coordinate path is resolved');
check($normalizedAdvert['advert']['progress_file'] === '/tmp/dick.txt.progress', 'advert progress path is resolved');
check($normalizedAdvert['advert']['key_seed'] === str_repeat('22', 32), 'advert key seed is normalized');
check($normalizedAdvert['advert']['node_type'] === 2, 'advert node type');

$badAdvert = $withAdvert;
$badAdvert['advert']['key_seed'] = 'short';
try {
    normalizeConfig($badAdvert, '/tmp/meshcore-config.json');
    check(false, 'short advert key seed must be rejected');
} catch (RuntimeException $e) {
    check(true, 'short advert key seed is rejected');
}

require_once __DIR__ . '/../drawer.php';
check(function_exists('drawerMain'), 'drawer loads after poster');
check(function_exists('drawerClientId'), 'drawer client id helper exists');
$drawerIdentity = [
    'public_key' => str_repeat('AB', 32),
    'client_id' => 'mqtt_meshcore-ABABAB',
];
$drawerClientId = drawerClientId($drawerIdentity);
check(strlen($drawerClientId) <= 23, 'drawer client id fits MQTT 3.1.1');
check($drawerClientId === 'mqtt_mc_drawer_ABABABAB', 'drawer client id is distinct and stable');
check(drawerClientId(['public_key' => str_repeat('CD', 32), 'client_id' => 'short']) === 'mqtt_mc_drawer_CDCDCDCD', 'drawer client id is independent of poster');
check(drawerRegionTopic('meshcore/LED', 'CEE') === 'meshcore/CEE', 'drawer region topic replaces IATA code');
check(drawerRegionTopic('meshcore/LED', 'LED') === 'meshcore/LED', 'drawer region topic keeps LED');
$topicConfig = [
    'mqtt' => ['topic' => 'meshcore/LED'],
    'identity' => ['public_key' => str_repeat('AB', 32)],
];
check(
    meshcoreTopic($topicConfig, 'packets', 'meshcore/CEE') === 'meshcore/CEE/' . str_repeat('AB', 32) . '/packets',
    'packet topic can override region'
);

echo "config-clean: PASS\n";
