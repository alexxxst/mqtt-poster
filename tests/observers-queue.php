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

final class FakeMqttClient
{
    public bool $connected = true;
    public array $published = [];

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function publish(string $topic, string $payload, int $qos, bool $retain): void
    {
        if (!$this->connected) {
            throw new RuntimeException('not connected');
        }
        $this->published[] = compact('topic', 'payload', 'qos', 'retain');
    }

    public function loop(bool $allowSleep = true, bool $exitOnMessage = true, int $timeout = 5): void
    {
    }

    public function disconnect(): void
    {
        $this->connected = false;
    }
}

final class FakeRepository
{
    public function countPendingOutgoingMessages(): int
    {
        return 0;
    }
}

$base = [
    'mqtt' => [
        'topic' => 'meshcore/LED',
        'client_id' => null,
        'qos' => 0,
    ],
    'device' => [
        'model' => 'Heltec V4',
        'firmware_version' => 'v1.16.0',
        'client_version' => 'mqtt-poster',
        'radio' => '869.525,250,11,5',
    ],
    'status' => [
        'enabled' => false,
        'retain' => true,
        'interval_ms' => 300000,
        'battery_mv' => 4000,
        'uptime_secs' => 10000,
        'errors' => 0,
        'queue_len' => 1,
        'noise_floor' => -108,
        'tx_air_secs' => 0,
        'rx_air_secs' => 0,
        'recv_errors' => 0,
    ],
    'packet' => [
        'channel_secret' => MESHCORE_PUBLIC_CHANNEL_PSK,
        'direction' => 'rx',
        'retain' => true,
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

$keyA = str_repeat('AB', 32);
$keyB = str_repeat('CD', 32);
$identityA = [
    'public_key' => $keyA,
    'node_name' => 'Observer-A',
    'client_id' => 'client-a',
    'snr' => 5.5,
    'rssi' => -86,
    'noise_floor' => -110,
    'battery_mv' => 4100,
    'uptime_secs' => 12345,
];
$identityB = [
    'public_key' => $keyB,
    'node_name' => 'Observer-B',
    'client_id' => 'client-b',
    'snr' => 4.1,
    'rssi' => -95,
    'noise_floor' => -106,
    'battery_mv' => 3950,
    'uptime_secs' => 54321,
];

$configA = meshcoreObserverConfig($base, $identityA);
$configB = meshcoreObserverConfig($base, $identityB);
check($configA['identity']['public_key'] === $keyA, 'observer A key');
check($configA['mqtt']['client_id'] === 'client-a', 'observer A client id');
check($configA['packet']['snr'] === 5.5 && $configA['packet']['rssi'] === -86, 'observer A signal');
check($configA['status']['noise_floor'] === -110, 'observer A noise floor');
check($configB['packet']['snr'] === 4.1 && $configB['packet']['rssi'] === -95, 'observer B signal');
check($configB['status']['battery_mv'] === 3950, 'observer B battery');

$body = 'Alice: Hello';
$eventPayload = meshcoreBuildGroupTextPayload($base, $body, 1700000000);
$pathA = meshcoreBuildPath(['packet' => ['path' => [
    'neighbors' => ['A1B2', 'C3D4'],
    'min_hops' => 2,
    'max_hops' => 2,
    'direct_probability' => 0.0,
]]]);
$pathB = meshcoreBuildPath(['packet' => ['path' => [
    'neighbors' => ['A1B2', 'C3D4', 'E5F6', '0A1B', '2C3D', '4E5F', '6A7B'],
    'min_hops' => 7,
    'max_hops' => 7,
    'direct_probability' => 0.0,
]]]);
$packetA = meshcoreBuildPacket($eventPayload, $pathA);
$packetB = meshcoreBuildPacket($eventPayload, $pathB);
check($packetA['payload'] === $packetB['payload'], 'observers share the same payload');
check($packetA['hash_hex'] === $packetB['hash_hex'], 'observers share the same hash');
check($packetA['raw_hex'] !== $packetB['raw_hex'], 'observers have different raw packets');

$observerA = buildObserver($base, $identityA);
$observerB = buildObserver($base, $identityB);
$clientA = new FakeMqttClient();
$clientB = new FakeMqttClient();
$observerA['connection'] = ['client' => $clientA, 'repository' => new FakeRepository()];
$observerB['connection'] = ['client' => $clientB, 'repository' => new FakeRepository()];

$publishedLines = [];
$onPublished = static function (object $event) use (&$publishedLines): void {
    $publishedLines[] = $event->line;
};

$event = (object) ['pending' => 2, 'line' => 7];
enqueueObserverPacket($observerA, $eventPayload, $pathA, 1700000000, $event, 'line 7');
enqueueObserverPacket($observerB, $eventPayload, $pathB, 1700000000, $event, 'line 7');

check(drainObserverQueue($observerA, $onPublished) === true, 'observer A queue drains');
check($event->pending === 1, 'progress waits for observer B');
check($publishedLines === [], 'progress does not advance before all observers');
check(count($clientA->published) === 1, 'observer A publishes one packet');
check($clientA->published[0]['topic'] === "meshcore/LED/{$keyA}/packets", 'observer A main topic');

check(drainObserverQueue($observerB, $onPublished) === true, 'observer B queue drains');
check($event->pending === 0, 'event is complete after all observers');
check($publishedLines === [7], 'progress advances after all observers');
check(count($clientB->published) === 1, 'observer B publishes one packet');
check($clientB->published[0]['topic'] === "meshcore/LED/{$keyB}/packets", 'observer B main topic');

$event2 = (object) ['pending' => 2, 'line' => 8];
enqueueObserverPacket($observerA, $eventPayload, $pathA, 1700000001, $event2, 'line 8');
enqueueObserverPacket($observerB, $eventPayload, $pathB, 1700000001, $event2, 'line 8');

$clientA->connected = false;
$observerA['retry_at'] = microtime(true) + 3600;
check(drainObserverQueue($observerA, $onPublished) === false, 'down observer keeps its queue');
check($event2->pending === 2, 'down observer blocks progress');
check(count($observerA['queue']) === 1, 'down observer queue is preserved');

check(drainObserverQueue($observerB, $onPublished) === true, 'other observer keeps publishing');
check($event2->pending === 1, 'event waits for the down observer');
check($publishedLines === [7], 'progress still waits for the down observer');

$clientA->connected = true;
$observerA['retry_at'] = 0.0;
check(drainObserverQueue($observerA, $onPublished) === true, 'recovered observer drains its queue');
check($event2->pending === 0, 'event completes after recovery');
check($publishedLines === [7, 8], 'progress advances after recovery');
check(count($clientA->published) === 2, 'recovered observer published both queued packets');

$observerRegion = buildObserver($base, $identityA);
$regionEvent = (object) ['pending' => 1, 'line' => 9];
enqueueObserverPacket(
    $observerRegion,
    $eventPayload,
    $pathA,
    1700000002,
    $regionEvent,
    'line 9',
    'meshcore/CEE'
);
check(
    $observerRegion['queue'][0]['targets'][0]['topic'] === "meshcore/CEE/{$keyA}/packets",
    'packet topic can use a per-point region'
);

$regionStatusObserver = buildObserver($base, $identityA);
$regionStatusObserver['config']['status']['enabled'] = true;
enqueueObserverRegionStatus($regionStatusObserver, 'meshcore/LED', 'LED');
check($regionStatusObserver['queue'] === [], 'home region status is not duplicated');
enqueueObserverRegionStatus($regionStatusObserver, 'meshcore/CEE', 'CEE');
check(count($regionStatusObserver['queue']) === 1, 'foreign region status is enqueued');
check(
    $regionStatusObserver['queue'][0]['targets'][0]['topic'] === "meshcore/CEE/{$keyA}/status",
    'foreign region status uses region topic'
);
check($regionStatusObserver['queue'][0]['event'] === null, 'region status does not affect progress');
enqueueObserverRegionStatus($regionStatusObserver, 'meshcore/CEE', 'CEE');
check(count($regionStatusObserver['queue']) === 1, 'foreign region status is enqueued once');

$retryObservers = [[
    'name' => 'Retry-A',
    'queue' => [['id' => 1]],
]];
$retryAttempts = 0;
$retryDrain = static function (array &$observer) use (&$retryAttempts): bool {
    $retryAttempts++;
    if ($retryAttempts === 1) {
        return false;
    }

    array_shift($observer['queue']);

    return true;
};
$retryStop = false;
check(
    drainAllObserverQueues($retryObservers, null, $retryStop, $retryDrain, 1000) === true,
    'retry helper drains after a failed publish'
);
check($retryAttempts === 2, 'retry helper repeats the same queue item');
check($retryObservers[0]['queue'] === [], 'retry helper leaves no duplicate queue items');

$stoppedObservers = [[
    'name' => 'Retry-B',
    'queue' => [['id' => 2]],
]];
$stoppedAttempts = 0;
$neverDrain = static function (array &$observer) use (&$stoppedAttempts): bool {
    $stoppedAttempts++;
    return false;
};
$retryStop = true;
check(
    drainAllObserverQueues($stoppedObservers, null, $retryStop, $neverDrain, 1000) === false,
    'retry helper stops on signal'
);
check($stoppedAttempts === 0, 'retry helper does not drain after stop');

$timeoutObservers = [[
    'name' => 'Retry-C',
    'queue' => [['id' => 3]],
]];
$timeoutAttempts = 0;
$alwaysFail = static function (array &$observer) use (&$timeoutAttempts): bool {
    $timeoutAttempts++;
    return false;
};
$timeoutStop = false;
$timeoutStarted = microtime(true);
check(
    drainAllObserverQueues($timeoutObservers, null, $timeoutStop, $alwaysFail, 1000, 0.01) === false,
    'retry helper respects its timeout'
);
check(microtime(true) - $timeoutStarted < 1.0, 'retry helper timeout is bounded');
check($timeoutAttempts > 0, 'retry helper tried to drain before timing out');

echo "observers-queue: PASS\n";
