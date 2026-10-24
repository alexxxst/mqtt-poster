<?php
declare(strict_types=1);

const MESHCORE_PUBLIC_CHANNEL_PSK = '8b3387e9c5cdea6ac9e5edbaa115cd72';
const MESHCORE_MAX_TEXT_LEN = 160;

function meshcoreNormalizePublicKey(string $value): string
{
    $value = strtoupper(trim($value));
    if (preg_match('/^[0-9A-F]{64}$/', $value) !== 1) {
        throw new RuntimeException('identity.public_key must be 64 hexadecimal characters');
    }

    return $value;
}

function meshcoreChannel(string $secretHex): array
{
    $secretHex = strtolower(trim($secretHex));
    if (preg_match('/^[0-9a-f]{32}$/', $secretHex) !== 1) {
        throw new RuntimeException('packet.channel_secret must be 32 hexadecimal characters');
    }

    $secret16 = hex2bin($secretHex);
    if ($secret16 === false) {
        throw new RuntimeException('packet.channel_secret is not valid hex');
    }

    return [
        'secret16' => $secret16,
        'secret32' => $secret16 . str_repeat("\0", 16),
        'hash' => substr(hash('sha256', $secret16, true), 0, 1),
    ];
}

function meshcoreBuildBody(string $template, string $nick, string $text): string
{
    $body = str_replace(['{nick}', '{text}'], [$nick, $text], $template);

    if (function_exists('mb_strcut')) {
        return mb_strcut($body, 0, MESHCORE_MAX_TEXT_LEN, 'UTF-8');
    }

    return substr($body, 0, MESHCORE_MAX_TEXT_LEN);
}

function meshcoreBuildPath(array $config, ?int $hops = null, ?bool $forceDirect = null): array
{
    $direct = [
        'bytes' => '',
        'count' => 0,
        'size' => 0,
        'route' => 'D',
        'json' => null,
    ];

    $pathConfig = $config['packet']['path'] ?? null;
    if (!is_array($pathConfig) || empty($pathConfig['neighbors'])) {
        return $direct;
    }

    $directProbability = (float) ($pathConfig['direct_probability'] ?? 0.2);
    if ($forceDirect === true) {
        return $direct;
    }
    if ($forceDirect === null) {
        if ($directProbability >= 1.0) {
            return $direct;
        }
        if ($directProbability > 0.0 && random_int(0, 1000000) / 1000000 < $directProbability) {
            return $direct;
        }
    }

    $neighbors = array_values($pathConfig['neighbors']);
    $minHops = (int) ($pathConfig['min_hops'] ?? 1);
    $maxHops = (int) ($pathConfig['max_hops'] ?? min(3, count($neighbors)));
    $count = $hops ?? random_int($minHops, $maxHops);
    if ($count < 1 || $count > count($neighbors)) {
        throw new RuntimeException('Path hop count is outside the available neighbors');
    }

    $pool = $neighbors;
    shuffle($pool);
    $selected = array_slice($pool, 0, $count);
    $bytes = '';
    foreach ($selected as $prefix) {
        $bytes .= hex2bin($prefix);
    }

    return [
        'bytes' => $bytes,
        'count' => $count,
        'size' => 2,
        'route' => 'F',
        'json' => 'path_' . $count . 'x2_' . (2 * $count) . 'b',
        'prefixes' => $selected,
    ];
}

function meshcoreBuildGroupTextPayload(array $config, string $body, ?int $timestamp = null): array
{
    $timestamp ??= time();
    $channel = meshcoreChannel((string) $config['packet']['channel_secret']);

    $plain = pack('V', $timestamp) . "\x00" . $body;
    $pad = (16 - (strlen($plain) % 16)) % 16;
    $cipher = openssl_encrypt(
        $plain . str_repeat("\0", $pad),
        'AES-128-ECB',
        $channel['secret16'],
        OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING
    );
    if ($cipher === false) {
        throw new RuntimeException('AES-128-ECB encryption failed');
    }

    $mac = substr(hash_hmac('sha256', $cipher, $channel['secret32'], true), 0, 2);
    $payload = $channel['hash'] . $mac . $cipher;
    $hash = substr(hash('sha256', "\x05" . $payload, true), 0, 8);

    return [
        'body' => $body,
        'timestamp' => $timestamp,
        'payload' => $payload,
        'hash' => $hash,
        'hash_hex' => strtoupper(bin2hex($hash)),
        'payload_len' => strlen($payload),
        'packet_type' => 5,
    ];
}

function meshcoreBuildPacket(array $event, array $path): array
{
    $route = $path['route'] ?? 'F';
    $routeType = $route === 'D' ? 2 : 1;
    $packetType = (int) ($event['packet_type'] ?? 5);
    $header = chr(($packetType << 2) | $routeType);
    $pathCount = (int) ($path['count'] ?? 0);
    $pathSize = max(1, (int) ($path['size'] ?? 1));
    $pathLenByte = ($route === 'D' && $pathCount === 0)
        ? "\x00"
        : chr((($pathSize - 1) << 6) | ($pathCount & 63));
    $raw = $header . $pathLenByte . ($path['bytes'] ?? '') . $event['payload'];

    return $event + [
        'raw' => $raw,
        'raw_hex' => strtoupper(bin2hex($raw)),
        'len' => strlen($raw),
        'route' => $route,
        'path' => !empty($path['json']) ? $path['json'] : null,
        'path_count' => $pathCount,
        'path_size' => $pathSize,
    ];
}

function meshcoreBuildGroupTextPacket(array $config, string $body, ?int $timestamp = null, ?array $path = null): array
{
    $event = meshcoreBuildGroupTextPayload($config, $body, $timestamp);

    return meshcoreBuildPacket($event, $path ?? meshcoreBuildPath($config));
}

function meshcoreObserverConfig(array $config, array $identity): array
{
    $observer = $config;
    $observer['identity'] = [
        'public_key' => $identity['public_key'],
        'node_name' => $identity['node_name'],
    ];
    $observer['mqtt']['client_id'] = $identity['client_id'];
    $observer['packet']['snr'] = $identity['snr'];
    $observer['packet']['rssi'] = $identity['rssi'];
    $observer['status']['noise_floor'] = $identity['noise_floor'];
    $observer['status']['battery_mv'] = $identity['battery_mv'];
    $observer['status']['uptime_secs'] = $identity['uptime_secs'];

    return $observer;
}

function meshcoreDecryptGroupTextPayload(string $payload, string $secretHex): array
{
    $channel = meshcoreChannel($secretHex);
    if (strlen($payload) < 4) {
        throw new RuntimeException('Group text payload is too short');
    }
    if ($payload[0] !== $channel['hash']) {
        throw new RuntimeException('Channel hash mismatch');
    }

    $mac = substr($payload, 1, 2);
    $cipher = substr($payload, 3);
    $expectedMac = substr(hash_hmac('sha256', $cipher, $channel['secret32'], true), 0, 2);
    if (!hash_equals($expectedMac, $mac)) {
        throw new RuntimeException('Group text MAC mismatch');
    }

    $plain = openssl_decrypt(
        $cipher,
        'AES-128-ECB',
        $channel['secret16'],
        OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING
    );
    if ($plain === false) {
        throw new RuntimeException('AES-128-ECB decryption failed');
    }
    $plain = rtrim($plain, "\0");
    if (strlen($plain) < 5) {
        throw new RuntimeException('Decrypted group text is too short');
    }

    $timestamp = unpack('V', substr($plain, 0, 4))[1];

    return [
        'timestamp' => $timestamp,
        'txt_type' => ord($plain[4]),
        'body' => substr($plain, 5),
        'packet_type' => 5,
    ];
}

function meshcoreDecryptRaw(string $raw, string $secretHex): array
{
    if (strlen($raw) < 2) {
        throw new RuntimeException('Raw packet is too short');
    }

    $header = ord($raw[0]);
    $packetType = ($header >> 2) & 0x0F;
    if ($packetType !== 5) {
        throw new RuntimeException("Unexpected packet type: {$packetType}");
    }

    $pathLen = ord($raw[1]);
    $pathHashSize = ($pathLen >> 6) + 1;
    $pathCount = $pathLen & 63;
    $pathBytes = $pathHashSize * $pathCount;
    $payload = substr($raw, 2 + $pathBytes);
    $decoded = meshcoreDecryptGroupTextPayload($payload, $secretHex);
    $decoded['header'] = $header;
    $decoded['route'] = $header & 0x03;

    return $decoded;
}

function meshcoreInitialRuntime(array $config): array
{
    return [
        'started_at' => microtime(true),
        'snr' => (float) $config['packet']['snr'],
        'rssi' => (int) $config['packet']['rssi'],
    ];
}

function meshcoreNextSignal(array $signal, array $config): array
{
    $packet = $config['packet'];
    $snrStep = max(0.0, (float) ($packet['snr_step'] ?? 0.6));
    $rssiStep = max(0, (int) ($packet['rssi_step'] ?? 3));

    $snr = (float) $signal['snr'] + random_int(
        (int) round(-$snrStep * 10),
        (int) round($snrStep * 10)
    ) / 10;
    $rssi = (int) $signal['rssi'] + random_int(-$rssiStep, $rssiStep);

    $snr = max((float) $packet['snr_min'], min((float) $packet['snr_max'], $snr));
    $rssi = max((int) $packet['rssi_min'], min((int) $packet['rssi_max'], $rssi));

    return [
        'snr' => round($snr, 1),
        'rssi' => $rssi,
    ];
}

function meshcoreRuntimeStats(array $runtime, array $config, ?float $now = null): array
{
    $now ??= microtime(true);
    $elapsed = max(0.0, $now - (float) $runtime['started_at']);
    $status = $config['status'];

    return [
        'battery_mv' => max(3000, (int) $status['battery_mv'] - (int) floor($elapsed / 600)),
        'uptime_secs' => (int) $status['uptime_secs'] + (int) floor($elapsed),
        'errors' => (int) $status['errors'],
        'queue_len' => (int) $status['queue_len'],
        'noise_floor' => (int) $status['noise_floor'] + random_int(-2, 2),
        'tx_air_secs' => (int) $status['tx_air_secs'] + (int) floor($elapsed * 0.01),
        'rx_air_secs' => (int) $status['rx_air_secs'] + (int) floor($elapsed * 0.05),
        'recv_errors' => (int) $status['recv_errors'],
    ];
}

function meshcorePacketPayload(array $config, array $packet, ?int $timestamp = null): string
{
    $timestamp ??= time();
    $identity = $config['identity'];
    $packetConfig = $config['packet'];

    $hasDirectPath = ($packet['route'] ?? 'F') === 'D' && !empty($packet['path']);
    $data = [
        'origin' => $identity['node_name'],
        'origin_id' => $identity['public_key'],
        'timestamp' => gmdate('Y-m-d\TH:i:s.000000', $timestamp),
        'type' => 'PACKET',
        'direction' => $packetConfig['direction'],
        'time' => gmdate('H:i:s', $timestamp),
        'date' => gmdate('d/m/Y', $timestamp),
        'len' => (string) $packet['len'],
        'packet_type' => (string) $packet['packet_type'],
        'route' => $hasDirectPath ? 'D' : 'F',
        'payload_len' => (string) $packet['payload_len'],
        'raw' => $packet['raw_hex'],
        'SNR' => number_format((float) ($packet['snr'] ?? $packetConfig['snr']), 1, '.', ''),
        'RSSI' => (string) (int) ($packet['rssi'] ?? $packetConfig['rssi']),
    ];

    if ($packetConfig['score'] !== null) {
        $data['score'] = (string) (int) $packetConfig['score'];
    }
    if ($packetConfig['duration'] !== null) {
        $data['duration'] = (string) (int) $packetConfig['duration'];
    }
    $data['hash'] = $packet['hash_hex'];
    if ($hasDirectPath) {
        $data['path'] = $packet['path'];
    }

    return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function meshcoreStatusPayload(array $config, bool $online, ?int $timestamp = null, ?array $stats = null): string
{
    $timestamp ??= time();
    $identity = $config['identity'];
    $device = $config['device'];
    $status = $config['status'];

    $data = [
        'status' => $online ? 'online' : 'offline',
        'timestamp' => gmdate('Y-m-d\TH:i:s.000000', $timestamp),
        'origin' => $identity['node_name'],
        'origin_id' => $identity['public_key'],
        'model' => $device['model'],
        'firmware_version' => $device['firmware_version'],
        'radio' => $device['radio'],
        'client_version' => $device['client_version'],
    ];

    if ($online) {
        $stats ??= [
            'battery_mv' => (int) $status['battery_mv'],
            'uptime_secs' => (int) $status['uptime_secs'],
            'errors' => (int) $status['errors'],
            'queue_len' => (int) $status['queue_len'],
            'noise_floor' => (int) $status['noise_floor'],
            'tx_air_secs' => (int) $status['tx_air_secs'],
            'rx_air_secs' => (int) $status['rx_air_secs'],
            'recv_errors' => (int) $status['recv_errors'],
        ];
        $data['stats'] = [
            'battery_mv' => (int) $stats['battery_mv'],
            'uptime_secs' => (int) $stats['uptime_secs'],
            'errors' => (int) $stats['errors'],
            'queue_len' => (int) $stats['queue_len'],
            'noise_floor' => (int) $stats['noise_floor'],
            'tx_air_secs' => (int) $stats['tx_air_secs'],
            'rx_air_secs' => (int) $stats['rx_air_secs'],
            'recv_errors' => (int) $stats['recv_errors'],
        ];
    }

    return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function meshcoreTopic(array $config, string $leaf, ?string $baseTopic = null): string
{
    $topic = $baseTopic ?? (string) $config['mqtt']['topic'];

    return rtrim($topic, '/')
        . '/' . $config['identity']['public_key']
        . '/' . $leaf;
}

function meshcoreClientId(array $config): string
{
    $configured = $config['mqtt']['client_id'] ?? null;
    if (is_string($configured) && trim($configured) !== '') {
        return $configured;
    }

    return 'mqtt_meshcore-' . substr($config['identity']['public_key'], 0, 6);
}
