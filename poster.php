#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/meshcore.php';
require __DIR__ . '/src/messages.php';
require_once __DIR__ . '/src/advert.php';

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\Repositories\MemoryRepository;

date_default_timezone_set('UTC');

const OBSERVER_RETRY_SECONDS = 5.0;

function usage(): string
{
    return "Usage: php poster.php [--config=path] [--dry-run] [--count=N] [--reset-progress] [--help]\n";
}

function logInfo(string $message): void
{
    fwrite(STDERR, '[' . date('H:i:s') . '] ' . $message . PHP_EOL);
}

function logError(string $message): void
{
    fwrite(STDERR, '[' . date('H:i:s') . '] ERROR ' . $message . PHP_EOL);
}

function parseArgs(array $argv): array
{
    $options = [
        'config' => 'config.json',
        'dry_run' => false,
        'count' => null,
        'reset_progress' => false,
        'help' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help') {
            $options['help'] = true;
            continue;
        }
        if ($arg === '--dry-run') {
            $options['dry_run'] = true;
            continue;
        }
        if ($arg === '--reset-progress') {
            $options['reset_progress'] = true;
            continue;
        }
        if (str_starts_with($arg, '--config=')) {
            $value = substr($arg, strlen('--config='));
            if ($value === '') {
                throw new InvalidArgumentException('--config requires a path');
            }
            $options['config'] = $value;
            continue;
        }
        if (str_starts_with($arg, '--count=')) {
            $value = substr($arg, strlen('--count='));
            if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
                throw new InvalidArgumentException('--count must be an integer greater than zero');
            }
            $options['count'] = (int) $value;
            continue;
        }
        throw new InvalidArgumentException("Unknown argument: {$arg}");
    }

    return $options;
}

function substituteEnv(mixed $value): mixed
{
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[$key] = substituteEnv($item);
        }
        return $value;
    }

    if (!is_string($value)) {
        return $value;
    }

    return preg_replace_callback(
        '/\$\{([A-Z_][A-Z0-9_]*)\}/',
        static function (array $matches): string {
            $env = getenv($matches[1]);
            if ($env === false) {
                throw new RuntimeException("Environment variable {$matches[1]} is not set");
            }
            return $env;
        },
        $value
    ) ?? $value;
}

function loadConfig(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException(
            "Config file not found: {$path}. Copy config.example.json to config.json or pass --config=..."
        );
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("Cannot read config file: {$path}");
    }

    try {
        $config = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException("Invalid JSON in {$path}: {$e->getMessage()}", 0, $e);
    }

    if (!is_array($config)) {
        throw new RuntimeException('Config root must be a JSON object');
    }

    $config = substituteEnv($config);

    return normalizeConfig($config, $path);
}

function normalizeIdentities(array $config, array $packet, array $stats, ?string $globalClientId): array
{
    $raw = $config['identities'] ?? null;
    if ($raw === null) {
        $single = $config['identity'] ?? null;
        if (!is_array($single)) {
            throw new RuntimeException("Config field 'identities' is required");
        }
        $raw = array_is_list($single) ? $single : [$single];
    }
    if (!is_array($raw) || $raw === [] || !array_is_list($raw)) {
        throw new RuntimeException("Config field 'identities' must be a non-empty array");
    }

    $multiple = count($raw) > 1;
    $identities = [];
    $seenKeys = [];
    $seenClientIds = [];

    foreach ($raw as $index => $entry) {
        $label = "identities[{$index}]";
        if (!is_array($entry)) {
            throw new RuntimeException("Config field '{$label}' must be an object");
        }
        if (!isset($entry['public_key']) || !is_string($entry['public_key'])) {
            throw new RuntimeException("Config field '{$label}.public_key' is required");
        }
        try {
            $publicKey = meshcoreNormalizePublicKey($entry['public_key']);
        } catch (RuntimeException $e) {
            throw new RuntimeException("Config field '{$label}.public_key' must be 64 hexadecimal characters");
        }
        if (isset($seenKeys[$publicKey])) {
            throw new RuntimeException("Duplicate identity public key: {$publicKey}");
        }
        $seenKeys[$publicKey] = true;

        $nodeName = $entry['node_name'] ?? 'MeshCore-Test';
        if (!is_string($nodeName) || trim($nodeName) === '') {
            throw new RuntimeException("Config field '{$label}.node_name' must be a non-empty string");
        }
        $nodeName = trim($nodeName);

        $clientId = $entry['client_id'] ?? null;
        if ($clientId !== null && (!is_string($clientId) || trim($clientId) === '')) {
            throw new RuntimeException("Config field '{$label}.client_id' must be a non-empty string or null");
        }
        if ($clientId === null) {
            if ($globalClientId !== null && $globalClientId !== '') {
                $clientId = $multiple ? $globalClientId . '-' . substr($publicKey, 0, 6) : $globalClientId;
            } else {
                $clientId = 'mqtt_meshcore-' . substr($publicKey, 0, 6);
            }
        }
        $clientId = trim($clientId);
        if (isset($seenClientIds[$clientId])) {
            throw new RuntimeException("Duplicate MQTT client id: {$clientId}");
        }
        $seenClientIds[$clientId] = true;

        $snr = $entry['snr'] ?? null;
        if ($snr !== null && !is_numeric($snr)) {
            throw new RuntimeException("Config field '{$label}.snr' must be a number");
        }
        $snr = $snr === null
            ? max($packet['snr_min'], min($packet['snr_max'], $packet['snr'] + random_int(-15, 15) / 10))
            : (float) $snr;
        if ($snr < $packet['snr_min'] || $snr > $packet['snr_max']) {
            throw new RuntimeException("Config field '{$label}.snr' must be between packet.snr_min and packet.snr_max");
        }

        $rssi = $entry['rssi'] ?? null;
        if ($rssi !== null && !is_numeric($rssi)) {
            throw new RuntimeException("Config field '{$label}.rssi' must be a number");
        }
        $rssi = $rssi === null
            ? max($packet['rssi_min'], min($packet['rssi_max'], $packet['rssi'] + random_int(-8, 8)))
            : (int) $rssi;
        if ($rssi < $packet['rssi_min'] || $rssi > $packet['rssi_max']) {
            throw new RuntimeException("Config field '{$label}.rssi' must be between packet.rssi_min and packet.rssi_max");
        }

        $noiseFloor = $entry['noise_floor'] ?? null;
        if ($noiseFloor !== null && !is_numeric($noiseFloor)) {
            throw new RuntimeException("Config field '{$label}.noise_floor' must be a number");
        }
        $noiseFloor = $noiseFloor === null
            ? $stats['noise_floor'] + random_int(-3, 3)
            : (int) $noiseFloor;

        $batteryMv = $entry['battery_mv'] ?? null;
        if ($batteryMv !== null && !is_numeric($batteryMv)) {
            throw new RuntimeException("Config field '{$label}.battery_mv' must be a number");
        }
        $batteryMv = $batteryMv === null
            ? max(3000, $stats['battery_mv'] + random_int(-150, 150))
            : (int) $batteryMv;
        if ($batteryMv < 1) {
            throw new RuntimeException("Config field '{$label}.battery_mv' must be positive");
        }

        $uptimeSecs = $entry['uptime_secs'] ?? null;
        if ($uptimeSecs !== null && !is_numeric($uptimeSecs)) {
            throw new RuntimeException("Config field '{$label}.uptime_secs' must be a number");
        }
        $uptimeSecs = $uptimeSecs === null
            ? $stats['uptime_secs'] + random_int(0, 7200)
            : (int) $uptimeSecs;
        if ($uptimeSecs < 0) {
            throw new RuntimeException("Config field '{$label}.uptime_secs' must not be negative");
        }
        if ($uptimeSecs > 0
            && ($stats['tx_air_secs'] >= $uptimeSecs || $stats['rx_air_secs'] >= $uptimeSecs)
        ) {
            throw new RuntimeException("Config field '{$label}.uptime_secs' must be greater than tx_air_secs/rx_air_secs");
        }

        $identities[] = [
            'public_key' => $publicKey,
            'node_name' => $nodeName,
            'client_id' => $clientId,
            'snr' => round($snr, 1),
            'rssi' => $rssi,
            'noise_floor' => $noiseFloor,
            'battery_mv' => $batteryMv,
            'uptime_secs' => $uptimeSecs,
        ];
    }

    return $identities;
}

function normalizeAdvertConfig(array $advert, string $baseDir): array
{
    $coordinateFile = $advert['coordinate_file'] ?? null;
    if (!is_string($coordinateFile) || trim($coordinateFile) === '') {
        throw new RuntimeException("Config field 'advert.coordinate_file' is required");
    }
    $coordinateFile = trim($coordinateFile);
    if (!str_starts_with($coordinateFile, '/')) {
        $coordinateFile = $baseDir . DIRECTORY_SEPARATOR . $coordinateFile;
    }

    $progressFile = $advert['progress_file'] ?? ($coordinateFile . '.progress');
    if (!is_string($progressFile) || trim($progressFile) === '') {
        throw new RuntimeException("Config field 'advert.progress_file' must be a non-empty string");
    }
    $progressFile = trim($progressFile);
    if (!str_starts_with($progressFile, '/')) {
        $progressFile = $baseDir . DIRECTORY_SEPARATOR . $progressFile;
    }

    $keySeed = $advert['key_seed'] ?? null;
    if (!is_string($keySeed) || preg_match('/^[0-9A-Fa-f]{64}$/', trim($keySeed)) !== 1) {
        throw new RuntimeException("Config field 'advert.key_seed' must be 64 hexadecimal characters");
    }

    $nodeType = (int) ($advert['node_type'] ?? ADVERT_TYPE_REPEATER);
    if ($nodeType < 0 || $nodeType > 0x0F) {
        throw new RuntimeException("Config field 'advert.node_type' must be between 0 and 15");
    }

    $directProbability = (float) ($advert['direct_probability'] ?? 0.0);
    if ($directProbability < 0.0 || $directProbability > 1.0) {
        throw new RuntimeException("Config field 'advert.direct_probability' must be between 0 and 1");
    }

    return [
        'coordinate_file' => $coordinateFile,
        'progress_file' => $progressFile,
        'key_seed' => strtolower(trim($keySeed)),
        'node_type' => $nodeType,
        'loop' => (bool) ($advert['loop'] ?? false),
        'publish_status' => (bool) ($advert['publish_status'] ?? true),
        'direct_probability' => $directProbability,
    ];
}

function normalizeConfig(array $config, string $path): array
{
    $mqtt = $config['mqtt'] ?? null;
    if (!is_array($mqtt)) {
        throw new RuntimeException("Config field 'mqtt' is required");
    }

    foreach (['host', 'topic'] as $key) {
        if (!isset($mqtt[$key]) || !is_string($mqtt[$key]) || trim($mqtt[$key]) === '') {
            throw new RuntimeException("Config field 'mqtt.{$key}' is required and must be a non-empty string");
        }
    }

    $tls = (bool) ($mqtt['tls'] ?? false);
    $port = (int) ($mqtt['port'] ?? ($tls ? 8883 : 1883));
    if ($port < 1 || $port > 65535) {
        throw new RuntimeException("Config field 'mqtt.port' must be between 1 and 65535");
    }

    $clientId = $mqtt['client_id'] ?? null;
    if ($clientId !== null && !is_string($clientId)) {
        throw new RuntimeException("Config field 'mqtt.client_id' must be a string or null");
    }

    foreach (['username', 'password'] as $key) {
        $value = $mqtt[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new RuntimeException("Config field 'mqtt.{$key}' must be a string or null");
        }
        $mqtt[$key] = $value;
    }

    $qos = (int) ($mqtt['qos'] ?? 0);
    if (!in_array($qos, [0, 1, 2], true)) {
        throw new RuntimeException("Config field 'mqtt.qos' must be 0, 1 or 2");
    }

    $intervalMin = (int) ($mqtt['interval_min_ms'] ?? ($mqtt['interval_ms'] ?? 500));
    $intervalMax = (int) ($mqtt['interval_max_ms'] ?? $intervalMin);
    if ($intervalMin < 1 || $intervalMax < $intervalMin) {
        throw new RuntimeException("Config fields 'mqtt.interval_min_ms'/'interval_max_ms' are inconsistent");
    }

    $queueMax = (int) ($mqtt['queue_max'] ?? 10);
    if ($queueMax < 1) {
        throw new RuntimeException("Config field 'mqtt.queue_max' must be at least 1");
    }

    $device = $config['device'] ?? [];
    if (!is_array($device)) {
        throw new RuntimeException("Config field 'device' must be an object");
    }
    $device = [
        'model' => (string) ($device['model'] ?? 'MeshCore'),
        'firmware_version' => (string) ($device['firmware_version'] ?? 'v1.16.0'),
        'client_version' => (string) ($device['client_version'] ?? 'mqtt-poster'),
        'radio' => (string) ($device['radio'] ?? '869.525,250,11,5'),
    ];

    $status = $config['status'] ?? [];
    if (!is_array($status)) {
        throw new RuntimeException("Config field 'status' must be an object");
    }
    $statusEnabled = (bool) ($status['enabled'] ?? true);
    $statusRetain = (bool) ($status['retain'] ?? true);
    $statusInterval = (int) ($status['interval_ms'] ?? 300000);
    if ($statusInterval < 1) {
        throw new RuntimeException("Config field 'status.interval_ms' must be at least 1");
    }
    $stats = [];
    foreach ([
        'battery_mv' => 4100,
        'uptime_secs' => 0,
        'errors' => 0,
        'queue_len' => 1,
        'noise_floor' => -110,
        'tx_air_secs' => 0,
        'rx_air_secs' => 0,
        'recv_errors' => 0,
    ] as $key => $default) {
        $stats[$key] = (int) ($status[$key] ?? $default);
    }
    if ($stats['uptime_secs'] > 0
        && ($stats['tx_air_secs'] >= $stats['uptime_secs'] || $stats['rx_air_secs'] >= $stats['uptime_secs'])
    ) {
        throw new RuntimeException("Config fields 'tx_air_secs'/'rx_air_secs' must be less than 'uptime_secs'");
    }

    $packet = $config['packet'] ?? [];
    if (!is_array($packet)) {
        throw new RuntimeException("Config field 'packet' must be an object");
    }
    $channelSecret = (string) ($packet['channel_secret'] ?? MESHCORE_PUBLIC_CHANNEL_PSK);
    meshcoreChannel($channelSecret);

    $messageFile = $packet['message_file'] ?? null;
    if (!is_string($messageFile) || trim($messageFile) === '') {
        throw new RuntimeException("Config field 'packet.message_file' is required");
    }
    if (!str_starts_with($messageFile, '/')) {
        $messageFile = dirname($path) . DIRECTORY_SEPARATOR . $messageFile;
    }

    $progressFile = $packet['progress_file'] ?? ($messageFile . '.progress');
    if (!is_string($progressFile) || trim($progressFile) === '') {
        throw new RuntimeException("Config field 'packet.progress_file' must be a non-empty string");
    }
    if (!str_starts_with($progressFile, '/')) {
        $progressFile = dirname($path) . DIRECTORY_SEPARATOR . $progressFile;
    }

    $loop = (bool) ($packet['loop'] ?? true);
    $retain = (bool) ($packet['retain'] ?? true);

    $direction = (string) ($packet['direction'] ?? 'rx');
    if (!in_array($direction, ['rx', 'tx'], true)) {
        throw new RuntimeException("Config field 'packet.direction' must be 'rx' or 'tx'");
    }
    $snr = isset($packet['snr']) ? (float) $packet['snr'] : null;
    $snrMin = (float) ($packet['snr_min'] ?? (($snr ?? 7.5) - 2.0));
    $snrMax = (float) ($packet['snr_max'] ?? (($snr ?? 7.5) + 2.0));
    $snrStep = max(0.0, (float) ($packet['snr_step'] ?? 0.6));
    if ($snr === null) {
        $snr = max($snrMin, min($snrMax, 7.5));
    }
    if ($snrMin > $snrMax || $snr < $snrMin || $snr > $snrMax) {
        throw new RuntimeException("Config fields 'packet.snr', 'snr_min' and 'snr_max' are inconsistent");
    }

    $rssi = isset($packet['rssi']) ? (int) $packet['rssi'] : null;
    $rssiMin = (int) ($packet['rssi_min'] ?? (($rssi ?? -70) - 15));
    $rssiMax = (int) ($packet['rssi_max'] ?? (($rssi ?? -70) + 15));
    $rssiStep = max(0, (int) ($packet['rssi_step'] ?? 3));
    if ($rssi === null) {
        $rssi = max($rssiMin, min($rssiMax, -70));
    }
    if ($rssiMin > $rssiMax || $rssi < $rssiMin || $rssi > $rssiMax) {
        throw new RuntimeException("Config fields 'packet.rssi', 'rssi_min' and 'rssi_max' are inconsistent");
    }

    $score = array_key_exists('score', $packet)
        ? ($packet['score'] === null ? null : (int) $packet['score'])
        : 1000;
    $duration = array_key_exists('duration', $packet)
        ? ($packet['duration'] === null ? null : (int) $packet['duration'])
        : 120;

    $pathConfig = null;
    if (array_key_exists('path', $packet)) {
        $pathOptions = $packet['path'];
        if (!is_array($pathOptions)) {
            throw new RuntimeException("Config field 'packet.path' must be an object");
        }
        $neighbors = $pathOptions['neighbors'] ?? [];
        if (!is_array($neighbors)) {
            throw new RuntimeException("Config field 'packet.path.neighbors' must be an array");
        }
        $normalizedNeighbors = [];
        foreach ($neighbors as $prefix) {
            if (!is_string($prefix) || preg_match('/^[0-9A-Fa-f]{4}$/', trim($prefix)) !== 1) {
                throw new RuntimeException("Each packet.path.neighbors entry must be a 2-byte hex prefix");
            }
            $normalizedNeighbors[] = strtoupper(trim($prefix));
        }
        $normalizedNeighbors = array_values(array_unique($normalizedNeighbors));
        if ($normalizedNeighbors !== []) {
            $minHops = (int) ($pathOptions['min_hops'] ?? 1);
            $maxHops = (int) ($pathOptions['max_hops'] ?? min(3, count($normalizedNeighbors)));
            if ($minHops < 1 || $maxHops < $minHops || $maxHops > count($normalizedNeighbors)) {
                throw new RuntimeException("Config fields 'packet.path.min_hops'/'max_hops' are inconsistent with neighbors");
            }
            $directProbability = (float) ($pathOptions['direct_probability'] ?? 0.5);
            if ($directProbability < 0.0 || $directProbability > 1.0) {
                throw new RuntimeException("Config field 'packet.path.direct_probability' must be between 0 and 1");
            }
            $pathConfig = [
                'neighbors' => $normalizedNeighbors,
                'min_hops' => $minHops,
                'max_hops' => $maxHops,
                'direct_probability' => $directProbability,
            ];
        }
    }

    $observerDelayMin = (int) ($packet['observer_delay_min_ms'] ?? 150);
    $observerDelayMax = (int) ($packet['observer_delay_max_ms'] ?? max($observerDelayMin, 450));
    if ($observerDelayMin < 0 || $observerDelayMax < $observerDelayMin) {
        throw new RuntimeException("Config fields 'packet.observer_delay_min_ms'/'observer_delay_max_ms' are inconsistent");
    }

    $identities = normalizeIdentities($config, [
        'snr' => $snr,
        'snr_min' => $snrMin,
        'snr_max' => $snrMax,
        'rssi' => $rssi,
        'rssi_min' => $rssiMin,
        'rssi_max' => $rssiMax,
    ], $stats, $clientId);

    $templateFile = $config['template_file'] ?? 'chat.tpl';
    if (!is_string($templateFile) || trim($templateFile) === '') {
        throw new RuntimeException("Config field 'template_file' must be a non-empty string");
    }
    if (!str_starts_with($templateFile, '/')) {
        $templateFile = dirname($path) . DIRECTORY_SEPARATOR . $templateFile;
    }

    $advert = null;
    if (array_key_exists('advert', $config)) {
        if (!is_array($config['advert'])) {
            throw new RuntimeException("Config field 'advert' must be an object");
        }
        $advert = normalizeAdvertConfig($config['advert'], dirname($path));
    }

    return [
        'mqtt' => [
            'host' => $mqtt['host'],
            'port' => $port,
            'tls' => $tls,
            'tls_verify' => (bool) ($mqtt['tls_verify'] ?? true),
            'client_id' => $clientId,
            'username' => $mqtt['username'],
            'password' => $mqtt['password'],
            'topic' => rtrim($mqtt['topic'], '/'),
            'qos' => $qos,
            'interval_min_ms' => $intervalMin,
            'interval_max_ms' => $intervalMax,
            'queue_max' => $queueMax,
        ],
        'identities' => $identities,
        'device' => $device,
        'status' => [
            'enabled' => $statusEnabled,
            'retain' => $statusRetain,
            'interval_ms' => $statusInterval,
        ] + $stats,
        'packet' => [
            'channel_secret' => $channelSecret,
            'message_file' => $messageFile,
            'progress_file' => $progressFile,
            'loop' => $loop,
            'retain' => $retain,
            'direction' => $direction,
            'snr' => $snr,
            'snr_min' => $snrMin,
            'snr_max' => $snrMax,
            'snr_step' => $snrStep,
            'rssi' => $rssi,
            'rssi_min' => $rssiMin,
            'rssi_max' => $rssiMax,
            'rssi_step' => $rssiStep,
            'score' => $score,
            'duration' => $duration,
            'observer_delay_min_ms' => $observerDelayMin,
            'observer_delay_max_ms' => $observerDelayMax,
            'path' => $pathConfig,
        ],
        'advert' => $advert,
        'template_file' => $templateFile,
    ];
}

function loadTemplate(string $path): string
{
    $template = @file_get_contents($path);
    if ($template === false) {
        throw new RuntimeException("Cannot read template file: {$path}");
    }

    return rtrim($template, "\r\n");
}

function connectMqtt(array $config): array
{
    $mqtt = $config['mqtt'];
    $clientId = meshcoreClientId($config);

    $repository = new MemoryRepository();
    $client = new MqttClient(
        $mqtt['host'],
        $mqtt['port'],
        $clientId,
        MqttClient::MQTT_3_1_1,
        $repository
    );

    $settings = (new ConnectionSettings())
        ->setKeepAliveInterval(60)
        ->setConnectTimeout(5)
        ->setUseTls($mqtt['tls']);

    if ($mqtt['username'] !== null && $mqtt['username'] !== '') {
        $settings = $settings->setUsername($mqtt['username']);
    }
    if ($mqtt['password'] !== null && $mqtt['password'] !== '') {
        $settings = $settings->setPassword($mqtt['password']);
    }
    if ($mqtt['tls']) {
        $settings = $settings
            ->setTlsVerifyPeer($mqtt['tls_verify'])
            ->setTlsVerifyPeerName($mqtt['tls_verify'])
            ->setTlsSelfSignedAllowed(!$mqtt['tls_verify']);
    }

    if ($config['status']['enabled']) {
        $settings = $settings
            ->setLastWillTopic(meshcoreTopic($config, 'status'))
            ->setLastWillMessage(meshcoreStatusPayload($config, false))
            ->setLastWillQualityOfService(1)
            ->setRetainLastWill($config['status']['retain']);
    }

    $client->connect($settings, true);

    return ['client' => $client, 'repository' => $repository];
}

function publishPayload(array $connection, string $topic, string $payload, int $qos, bool $retain): void
{
    $connection['client']->publish($topic, $payload, $qos, $retain);

    if ($qos > 0) {
        $connection['client']->loop(true, true, 5);
        if ($connection['repository']->countPendingOutgoingMessages() !== 0) {
            throw new RuntimeException('QoS acknowledgement timeout');
        }
    }
}

function buildObserver(array $config, array $identity): array
{
    $observerConfig = meshcoreObserverConfig($config, $identity);

    return [
        'config' => $observerConfig,
        'name' => $identity['node_name'],
        'connection' => null,
        'queue' => [],
        'signal' => [
            'snr' => (float) $identity['snr'],
            'rssi' => (int) $identity['rssi'],
        ],
        'runtime' => meshcoreInitialRuntime($observerConfig),
        'next_status_at' => 0.0,
        'retry_at' => 0.0,
        'needs_announce' => false,
        'regions' => [],
        'sent' => 0,
    ];
}

function observerDisconnect(array &$observer): void
{
    if ($observer['connection'] === null) {
        return;
    }

    try {
        $observer['connection']['client']->disconnect();
    } catch (Throwable) {
        // Already disconnected.
    }
    $observer['connection'] = null;
}

function observerFail(array &$observer, string $message): void
{
    logError("observer {$observer['name']}: {$message}");
    observerDisconnect($observer);
    $observer['retry_at'] = microtime(true) + OBSERVER_RETRY_SECONDS;
    $observer['needs_announce'] = false;
}

function observerConnect(array &$observer): bool
{
    if ($observer['connection'] !== null && $observer['connection']['client']->isConnected()) {
        return true;
    }
    if (microtime(true) < $observer['retry_at']) {
        return false;
    }

    try {
        $observer['connection'] = connectMqtt($observer['config']);
        $observer['needs_announce'] = true;
        logInfo("connected observer={$observer['name']}");
        return true;
    } catch (Throwable $e) {
        observerFail($observer, 'connect failed: ' . $e->getMessage());
        return false;
    }
}

function observerPublishStatus(array &$observer): void
{
    $config = $observer['config'];
    if (!$config['status']['enabled']) {
        $observer['next_status_at'] = INF;
        return;
    }

    $payload = meshcoreStatusPayload(
        $config,
        true,
        time(),
        meshcoreRuntimeStats($observer['runtime'], $config)
    );
    publishPayload(
        $observer['connection'],
        meshcoreTopic($config, 'status'),
        $payload,
        1,
        $config['status']['retain']
    );
    logInfo("status online observer={$observer['name']}");
    $observer['next_status_at'] = microtime(true) + $config['status']['interval_ms'] / 1000;
}

function observerAnnounce(array &$observer): bool
{
    if (!observerConnect($observer)) {
        return false;
    }
    if (!$observer['needs_announce']) {
        return true;
    }

    try {
        observerPublishStatus($observer);
        $observer['needs_announce'] = false;
        return true;
    } catch (Throwable $e) {
        observerFail($observer, 'status failed: ' . $e->getMessage());
        return false;
    }
}

function enqueueObserverStatus(array &$observer): void
{
    $config = $observer['config'];
    if (!$config['status']['enabled']) {
        $observer['next_status_at'] = INF;
        return;
    }

    $observer['queue'][] = [
        'targets' => [[
            'topic' => meshcoreTopic($config, 'status'),
            'qos' => 1,
            'retain' => $config['status']['retain'],
        ]],
        'payload' => meshcoreStatusPayload(
            $config,
            true,
            time(),
            meshcoreRuntimeStats($observer['runtime'], $config)
        ),
        'summary' => 'status online',
        'event' => null,
    ];
    $observer['next_status_at'] = microtime(true) + $config['status']['interval_ms'] / 1000;
}

function enqueueObserverRegionStatus(array &$observer, string $baseTopic, string $regionCode): void
{
    $config = $observer['config'];
    if (!$config['status']['enabled']) {
        return;
    }

    $homeTopic = rtrim((string) $config['mqtt']['topic'], '/');
    if (rtrim($baseTopic, '/') === $homeTopic) {
        return;
    }
    if (isset($observer['regions'][$regionCode])) {
        return;
    }
    $observer['regions'][$regionCode] = true;

    $observer['queue'][] = [
        'targets' => [[
            'topic' => meshcoreTopic($config, 'status', $baseTopic),
            'qos' => 1,
            'retain' => $config['status']['retain'],
        ]],
        'payload' => meshcoreStatusPayload(
            $config,
            true,
            time(),
            meshcoreRuntimeStats($observer['runtime'], $config)
        ),
        'summary' => "status online region {$regionCode}",
        'event' => null,
    ];
}

function enqueueObserverPacket(
    array &$observer,
    array $eventPayload,
    array $path,
    int $timestamp,
    object $event,
    string $summary,
    ?string $baseTopic = null
): void
{
    $config = $observer['config'];
    $packet = meshcoreBuildPacket($eventPayload, $path);
    $packet['snr'] = $observer['signal']['snr'];
    $packet['rssi'] = $observer['signal']['rssi'];
    $payload = meshcorePacketPayload($config, $packet, $timestamp);
    $packetsTopic = meshcoreTopic($config, 'packets', $baseTopic);

    $targets = [[
        'topic' => $packetsTopic,
        'qos' => $config['mqtt']['qos'],
        'retain' => $config['packet']['retain'],
    ]];

    $observer['queue'][] = [
        'targets' => $targets,
        'payload' => $payload,
        'summary' => $summary,
        'event' => $event,
    ];
}

function drainObserverQueue(array &$observer, ?callable $onPublished = null): bool
{
    while ($observer['queue'] !== []) {
        if (!observerAnnounce($observer)) {
            return false;
        }

        $item = $observer['queue'][0];

        try {
            foreach ($item['targets'] as $target) {
                publishPayload(
                    $observer['connection'],
                    $target['topic'],
                    $item['payload'],
                    $target['qos'],
                    $target['retain']
                );
            }
        } catch (Throwable $e) {
            observerFail($observer, 'publish failed: ' . $e->getMessage());
            return false;
        }

        array_shift($observer['queue']);
        $summary = $item['summary'] ?? 'message';
        logInfo("sent observer={$observer['name']}: {$summary}");

        $completed = null;
        if (($item['event'] ?? null) !== null) {
            $event = $item['event'];
            $event->pending--;
            if ($event->pending === 0) {
                $completed = $event;
            }
        }
        if ($completed !== null && $onPublished !== null) {
            $onPublished($completed);
        }
    }

    return true;
}

function drainAllObserverQueues(
    array &$observers,
    ?callable $onPublished,
    bool &$stopRequested,
    ?callable $drain = null,
    int $retryDelayMicroseconds = 500000,
    float $retryTimeoutSeconds = 10.0
): bool {
    $drain ??= static function (array &$observer) use ($onPublished): bool {
        return drainObserverQueue($observer, $onPublished);
    };
    $deadline = microtime(true) + $retryTimeoutSeconds;

    while (!$stopRequested) {
        $pending = false;
        foreach ($observers as &$observer) {
            if (!$drain($observer)) {
                $pending = true;
            }
        }
        unset($observer);

        if (!$pending) {
            return true;
        }
        if (microtime(true) >= $deadline) {
            return false;
        }

        usleep($retryDelayMicroseconds);
    }

    return false;
}

function runMeshcore(array $config, string $template, array $options): int
{
    $dryRun = $options['dry_run'];
    $count = $options['count'];
    $sequence = 0;
    $queueMax = $config['mqtt']['queue_max'];
    $progressFile = $config['packet']['progress_file'];
    $loopMessages = $config['packet']['loop'];
    $messages = loadMessageFile($config['packet']['message_file']);
    $observers = [];
    foreach ($config['identities'] as $identity) {
        $observers[] = buildObserver($config, $identity);
    }

    if ($options['reset_progress']) {
        resetProgress($progressFile);
        logInfo('progress reset');
    }

    $nextLine = loadProgress($progressFile);
    $selectionLine = $nextLine;
    saveProgress($progressFile, $nextLine);

    $stopRequested = false;
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        $signalHandler = static function () use (&$stopRequested): void {
            $stopRequested = true;
        };
        pcntl_signal(SIGINT, $signalHandler);
        pcntl_signal(SIGTERM, $signalHandler);
    }

    $onPublished = static function (object $event) use (&$nextLine, &$selectionLine, $progressFile): void {
        $nextLine = (int) $event->line + 1;
        $selectionLine = $nextLine;
        saveProgress($progressFile, $nextLine);
    };

    try {
        if (!$dryRun) {
            $connected = 0;
            foreach ($observers as &$observer) {
                if (observerAnnounce($observer)) {
                    $connected++;
                }
            }
            unset($observer);
            if ($connected === 0) {
                logError('no observers connected, exiting');
                return 1;
            }
        }

        while (!$stopRequested && ($count === null || $sequence < $count)) {
            if (!$dryRun) {
                foreach ($observers as &$observer) {
                    if (count($observer['queue']) >= $queueMax) {
                        drainObserverQueue($observer, $onPublished);
                        if (count($observer['queue']) >= $queueMax) {
                            logError("observer {$observer['name']} queue overflow (max {$queueMax}), exiting");
                            return 1;
                        }
                    }
                }
                unset($observer);
            }

            $message = nextMessage($messages, $selectionLine, $loopMessages);
            if ($message === null) {
                logInfo('message file exhausted, exiting');
                break;
            }

            $sequence++;
            $timestamp = time();
            $body = meshcoreBuildBody($template, $message['nick'], $message['text']);
            $eventPayload = meshcoreBuildGroupTextPayload($config, $body, $timestamp);

            $textPreview = function_exists('mb_strimwidth')
                ? mb_strimwidth($message['text'], 0, 100, '…', 'UTF-8')
                : substr($message['text'], 0, 100);
            $summary = "line {$message['line']}: {$message['nick']}: {$textPreview}";

            if ($dryRun) {
                foreach ($observers as &$observer) {
                    if ($observer['sent'] > 0) {
                        $observer['signal'] = meshcoreNextSignal($observer['signal'], $observer['config']);
                    }
                    $packet = meshcoreBuildPacket($eventPayload, meshcoreBuildPath($observer['config']));
                    $packet['snr'] = $observer['signal']['snr'];
                    $packet['rssi'] = $observer['signal']['rssi'];
                    fwrite(STDOUT, meshcorePacketPayload($observer['config'], $packet, time()) . PHP_EOL);
                    logInfo("dry-run observer={$observer['name']} {$summary}");
                    $observer['sent']++;
                }
                unset($observer);
                $selectionLine = $message['line'] + 1;
            } else {
                $event = (object) [
                    'pending' => count($observers),
                    'line' => $message['line'],
                ];

                foreach ($observers as &$observer) {
                    $now = microtime(true);
                    if ($observer['config']['status']['enabled'] && $now >= $observer['next_status_at']) {
                        enqueueObserverStatus($observer);
                    }
                    if ($observer['sent'] > 0) {
                        $observer['signal'] = meshcoreNextSignal($observer['signal'], $observer['config']);
                    }
                    enqueueObserverPacket(
                        $observer,
                        $eventPayload,
                        meshcoreBuildPath($observer['config']),
                        time(),
                        $event,
                        $summary
                    );
                    $observer['sent']++;
                }
                unset($observer);

                $drained = true;
                foreach ($observers as $index => &$observer) {
                    if ($index > 0) {
                        $delayMs = random_int(
                            $config['packet']['observer_delay_min_ms'],
                            $config['packet']['observer_delay_max_ms']
                        );
                        if ($delayMs > 0) {
                            usleep($delayMs * 1000);
                        }
                    }
                    if (!drainObserverQueue($observer, $onPublished)) {
                        $drained = false;
                    }
                }
                unset($observer);
                if (!$drained && !drainAllObserverQueues($observers, $onPublished, $stopRequested)) {
                    if ($stopRequested) {
                        break;
                    }
                    logError('messages not delivered after retries, exiting');
                    return 1;
                }
            }

            if ($count !== null && $sequence >= $count) {
                break;
            }
            $delayMs = random_int($config['mqtt']['interval_min_ms'], $config['mqtt']['interval_max_ms']);
            usleep($delayMs * 1000);
        }

        if (!$dryRun && !$stopRequested) {
            foreach ($observers as &$observer) {
                drainObserverQueue($observer, $onPublished);
            }
            unset($observer);

            foreach ($observers as $observer) {
                if ($observer['queue'] !== []) {
                    logError(
                        count($observer['queue'])
                        . " message(s) not delivered for observer {$observer['name']}, exiting"
                    );
                    return 1;
                }
            }
        }
    } finally {
        saveProgress($progressFile, $nextLine);
        foreach ($observers as &$observer) {
            observerDisconnect($observer);
        }
        unset($observer);
    }

    return 0;
}

function main(array $argv): int
{
    try {
        $options = parseArgs($argv);
        if ($options['help']) {
            fwrite(STDOUT, usage());
            return 0;
        }

        $config = loadConfig($options['config']);
        $template = loadTemplate($config['template_file']);

        return runMeshcore($config, $template, $options);
    } catch (Throwable $e) {
        logError($e->getMessage());
        return 1;
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(main($argv));
}
