#!/usr/bin/env php
<?php
declare(strict_types=1);

$port = (int) ($argv[1] ?? 18886);
$server = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "cannot listen on {$port}: {$errstr} ({$errno})\n");
    exit(1);
}

fwrite(STDOUT, "listening {$port}\n");
fflush(STDOUT);

$deadline = time() + 25;
$connection = 0;

while (time() < $deadline) {
    $client = @stream_socket_accept($server, 1);
    if ($client === false) {
        continue;
    }

    $connection++;
    fwrite(STDOUT, "connection {$connection}\n");
    fflush(STDOUT);

    // Read CONNECT and accept the session.
    $connect = fread($client, 8192);
    if ($connect === false || $connect === '') {
        fclose($client);
        continue;
    }

    // CONNACK: session present = 0, return code = 0.
    fwrite($client, "\x20\x02\x00\x00");
    fflush($client);

    // Read PUBLISH and deliberately never acknowledge it.
    fread($client, 8192);

    // Keep the socket open longer than the client's 5-second ack timeout.
    sleep(6);
    fclose($client);
}

fclose($server);
