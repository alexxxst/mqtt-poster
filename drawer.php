#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/poster.php';

function drawerUsage(): string
{
    return "Usage: php drawer.php [--config=path] [--dry-run] [--count=N] [--reset-progress] [--help]\n";
}

function drawerClientId(array $identity): string
{
    return 'mqtt_mc_drawer_' . substr($identity['public_key'], 0, 8);
}

function drawerNextPoint(array $points, int $nextLine, bool $loop): ?array
{
    foreach ($points as $point) {
        if ($point['line'] >= $nextLine) {
            return $point;
        }
    }

    if ($loop) {
        return $points[0] ?? null;
    }

    return null;
}

function drawerApplyConfig(array $config, array $advert): array
{
    if (isset($config['packet']['path']) && is_array($config['packet']['path'])) {
        $config['packet']['path']['direct_probability'] = (float) $advert['direct_probability'];
    }

    return $config;
}

function drawerRegionTopic(string $topic, string $regionCode): string
{
    $topic = rtrim($topic, '/');
    $position = strrpos($topic, '/');
    if ($position === false) {
        return $regionCode;
    }

    return substr($topic, 0, $position + 1) . $regionCode;
}

function drawerDirectPath(): array
{
    return [
        'bytes' => '',
        'count' => 0,
        'size' => 0,
        'route' => 'D',
        'json' => null,
    ];
}

function drawerObserverPath(array $observer, int $index): array
{
    $config = $observer['config'];
    $pathConfig = $config['packet']['path'] ?? null;
    if (!is_array($pathConfig) || empty($pathConfig['neighbors'])) {
        return drawerDirectPath();
    }
    if ((float) ($pathConfig['direct_probability'] ?? 0.0) >= 1.0) {
        return drawerDirectPath();
    }

    $publicKey = strtoupper((string) ($config['identity']['public_key'] ?? ''));
    $ownPrefix = substr($publicKey, 0, 4);
    $neighbors = [];
    foreach ($pathConfig['neighbors'] as $prefix) {
        $prefix = strtoupper(trim((string) $prefix));
        if (preg_match('/^[0-9A-F]{4}$/', $prefix) !== 1 || $prefix === $ownPrefix) {
            continue;
        }
        $neighbors[$prefix] = $prefix;
    }
    $neighbors = array_values($neighbors);
    if ($neighbors === []) {
        return drawerDirectPath();
    }

    $count = (int) ($pathConfig['min_hops'] ?? 1);
    $count = max(1, min($count, count($neighbors)));
    $offset = ((int) hexdec(substr($publicKey, 0, 2)) + $index) % count($neighbors);
    $ordered = array_merge(array_slice($neighbors, $offset), array_slice($neighbors, 0, $offset));
    $selected = array_slice($ordered, 0, $count);

    $bytes = '';
    foreach ($selected as $prefix) {
        $bytes .= hex2bin($prefix);
    }

    return [
        'bytes' => $bytes,
        'count' => $count,
        'size' => 2,
        'route' => 'F',
        'json' => null,
        'prefixes' => $selected,
    ];
}

function drawerBuildObserverPaths(array $observers): array
{
    $paths = [];
    $seen = [];
    foreach ($observers as $index => $observer) {
        $attempt = 0;
        do {
            $path = drawerObserverPath($observer, $index + $attempt);
            $key = ($path['route'] ?? 'F') . ':' . ($path['bytes'] ?? '');
            $attempt++;
        } while (isset($seen[$key]) && $attempt < 32);

        $seen[$key] = true;
        $paths[$index] = $path;
    }

    return $paths;
}

function drawerMain(array $argv): int
{
    try {
        $options = parseArgs($argv);
        if ($options['help']) {
            fwrite(STDOUT, drawerUsage());
            return 0;
        }

        $config = loadConfig($options['config']);
        $advert = $config['advert'] ?? null;
        if (!is_array($advert)) {
            throw new RuntimeException("Config field 'advert' is required for drawer.php");
        }

        $points = advertParseCoordinateFile($advert['coordinate_file']);
        $config = drawerApplyConfig($config, $advert);

        $dryRun = $options['dry_run'];
        $count = $options['count'];
        $sequence = 0;
        $queueMax = $config['mqtt']['queue_max'];
        $progressFile = $advert['progress_file'];
        $loopPoints = $advert['loop'];

        $observers = [];
        foreach ($config['identities'] as $identity) {
            $observer = buildObserver($config, $identity);
            $observer['config']['mqtt']['client_id'] = drawerClientId($identity);
            $observer['config']['mqtt']['qos'] = 1;
            $observer['config']['packet']['retain'] = false;
            $observer['config']['packet']['direction'] = 'rx';
            if (!$advert['publish_status']) {
                $observer['config']['status']['enabled'] = false;
                $observer['next_status_at'] = INF;
            }
            $observers[] = $observer;
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

                $point = drawerNextPoint($points, $selectionLine, $loopPoints);
                if ($point === null) {
                    logInfo('coordinate file exhausted, exiting');
                    break;
                }

                $sequence++;
                $timestamp = time();
                $regionCode = advertRegionCode($point['lat'], $point['lon']);
                $name = advertName(
                    $point['line'],
                    $point['lat'],
                    $point['lon'],
                    $advert['key_seed'],
                    $regionCode
                );
                $identity = advertDeriveIdentity(
                    $advert['key_seed'],
                    $point['line'],
                    $point['lat_e6'],
                    $point['lon_e6']
                );
                $appData = advertBuildAppData(
                    $advert['node_type'],
                    $point['lat_e6'],
                    $point['lon_e6'],
                    $name
                );
                $eventPayload = advertBuildPayload($identity, $appData, $timestamp);
                advertVerifyPayload($eventPayload['payload']);
                $baseTopic = drawerRegionTopic($config['mqtt']['topic'], $regionCode);
                $summary = "line {$point['line']}: {$name} ({$point['lat']}, {$point['lon']}) region {$regionCode}";

                if ($dryRun) {
                    $observerPaths = drawerBuildObserverPaths($observers);
                    foreach ($observers as $index => &$observer) {
                        if ($observer['sent'] > 0) {
                            $observer['signal'] = meshcoreNextSignal($observer['signal'], $observer['config']);
                        }
                        $packet = meshcoreBuildPacket($eventPayload, $observerPaths[$index]);
                        $packet['snr'] = $observer['signal']['snr'];
                        $packet['rssi'] = $observer['signal']['rssi'];
                        fwrite(
                            STDOUT,
                            meshcorePacketPayload($observer['config'], $packet, time()) . PHP_EOL
                        );
                        logInfo("dry-run observer={$observer['name']} {$summary}");
                        $observer['sent']++;
                    }
                    unset($observer);
                    $selectionLine = $point['line'] + 1;
                } else {
                    $event = (object) [
                        'pending' => count($observers),
                        'line' => $point['line'],
                    ];

                    $observerPaths = drawerBuildObserverPaths($observers);
                    foreach ($observers as $index => &$observer) {
                        $now = microtime(true);
                        if ($observer['config']['status']['enabled'] && $now >= $observer['next_status_at']) {
                            enqueueObserverStatus($observer);
                        }
                        enqueueObserverRegionStatus($observer, $baseTopic, $regionCode);
                        if ($observer['sent'] > 0) {
                            $observer['signal'] = meshcoreNextSignal($observer['signal'], $observer['config']);
                        }
                        enqueueObserverPacket(
                            $observer,
                            $eventPayload,
                            $observerPaths[$index],
                            time(),
                            $event,
                            $summary,
                            $baseTopic
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
    } catch (Throwable $e) {
        logError($e->getMessage());
        return 1;
    }

    return 0;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(drawerMain($argv));
}
