#!/usr/bin/env bash
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"

PORT=18887
TMP=$(mktemp -d)
BROKER_PID=
SUB_PID=
PREFIX="meshcore/IDTEST$(date +%s)$$"
PUBKEY1=$(php -r 'echo strtoupper(bin2hex(random_bytes(32)));')
PUBKEY2=$(php -r 'echo strtoupper(bin2hex(random_bytes(32)));')

fail() { echo "FAIL: $*" >&2; exit 1; }

cleanup() {
  if [ -n "${SUB_PID:-}" ]; then kill "$SUB_PID" 2>/dev/null || true; fi
  if [ -n "${BROKER_PID:-}" ]; then
    kill "$BROKER_PID" 2>/dev/null || true
    wait "$BROKER_PID" 2>/dev/null || true
  fi
  rm -rf "$TMP"
}
trap cleanup EXIT

for bin in php mosquitto mosquitto_sub nc; do
  command -v "$bin" >/dev/null 2>&1 || fail "missing $bin"
done
if nc -z 127.0.0.1 "$PORT"; then fail "port $PORT is already in use"; fi

cat > "$TMP/messages.txt" <<'EOF'
Alice hello 1
Bob hello 2
EOF

cat > "$TMP/config.json" <<JSON
{
  "mqtt": {
    "host": "127.0.0.1",
    "port": $PORT,
    "client_id": null,
    "username": null,
    "password": null,
    "tls": false,
    "topic": "$PREFIX",
    "qos": 0,
    "interval_min_ms": 50,
    "interval_max_ms": 100,
    "queue_max": 10
  },
  "identities": [
    {
      "public_key": "$PUBKEY1",
      "node_name": "Observer-One",
      "snr": 5.5,
      "rssi": -86,
      "noise_floor": -110,
      "battery_mv": 4100,
      "uptime_secs": 12345
    },
    {
      "public_key": "$PUBKEY2",
      "node_name": "Observer-Two",
      "snr": 4.1,
      "rssi": -95,
      "noise_floor": -106,
      "battery_mv": 3950,
      "uptime_secs": 54321
    }
  ],
  "device": {
    "model": "Heltec V4",
    "firmware_version": "v1.16.0",
    "client_version": "mqtt-poster",
    "radio": "869.525,250,11,5"
  },
  "status": {
    "enabled": true,
    "retain": true,
    "interval_ms": 300000,
    "battery_mv": 4000,
    "uptime_secs": 10000,
    "errors": 0,
    "queue_len": 1,
    "noise_floor": -108,
    "tx_air_secs": 0,
    "rx_air_secs": 0,
    "recv_errors": 0
  },
  "packet": {
    "channel_secret": "8b3387e9c5cdea6ac9e5edbaa115cd72",
    "message_file": "$TMP/messages.txt",
    "progress_file": "$TMP/messages.progress",
    "loop": false,
    "retain": true,
    "direction": "rx",
    "snr": 5.0,
    "snr_min": 3.0,
    "snr_max": 8.5,
    "snr_step": 0.6,
    "rssi": -88,
    "rssi_min": -104,
    "rssi_max": -74,
    "rssi_step": 3,
    "score": 1000,
    "duration": 120,
    "observer_delay_min_ms": 20,
    "observer_delay_max_ms": 40,
    "path": {
      "neighbors": ["A1B2", "C3D4", "E5F6", "0A1B"],
      "min_hops": 2,
      "max_hops": 3,
      "direct_probability": 0.0
    }
  },
  "template_file": "$ROOT/chat.tpl"
}
JSON

mosquitto -p "$PORT" >"$TMP/broker.log" 2>&1 &
BROKER_PID=$!
for _ in $(seq 1 50); do
  nc -z 127.0.0.1 "$PORT" && break
  sleep 0.1
done
nc -z 127.0.0.1 "$PORT" || fail "broker did not start: $(cat "$TMP/broker.log")"

mosquitto_sub -h 127.0.0.1 -p "$PORT" -t "$PREFIX/#" -C 6 -W 20 -v >"$TMP/sub.log" 2>&1 &
SUB_PID=$!
sleep 0.3

set +e
php poster.php --config="$TMP/config.json" --count=2 >"$TMP/poster.out" 2>"$TMP/poster.err"
POSTER_RC=$?
set -e
wait "$SUB_PID" || true
SUB_PID=

[ "$POSTER_RC" -eq 0 ] || fail "poster exited $POSTER_RC: $(cat "$TMP/poster.err")"
[ "$(grep -c '^' "$TMP/sub.log")" -eq 6 ] || fail "expected 6 MQTT messages: $(cat "$TMP/sub.log")"

STATUS_ONE="$PREFIX/$PUBKEY1/status"
STATUS_TWO="$PREFIX/$PUBKEY2/status"
PACKETS_ONE="$PREFIX/$PUBKEY1/packets"
PACKETS_TWO="$PREFIX/$PUBKEY2/packets"

grep -q "^$STATUS_ONE .*\"origin\":\"Observer-One\"" "$TMP/sub.log" || fail "missing status for observer one"
grep -q "^$STATUS_TWO .*\"origin\":\"Observer-Two\"" "$TMP/sub.log" || fail "missing status for observer two"
head -2 "$TMP/sub.log" | grep -q "^$STATUS_ONE " || fail "observer one did not announce before packets"
head -2 "$TMP/sub.log" | grep -q "^$STATUS_TWO " || fail "observer two did not announce before packets"

php -r '
$lines = array_values(array_filter(array_map("trim", file($argv[1])), static fn(string $line): bool => $line !== ""));
$expected = [
    $argv[2] => ["origin" => "Observer-One", "noise_floor" => -110, "battery_mv" => 4100, "uptime_secs" => 12345],
    $argv[3] => ["origin" => "Observer-Two", "noise_floor" => -106, "battery_mv" => 3950, "uptime_secs" => 54321],
];
foreach ($lines as $line) {
    [$topic, $json] = preg_split("/ /", $line, 2);
    if (!str_ends_with($topic, "/status")) {
        continue;
    }
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $key = $data["origin_id"] ?? "";
    if (!isset($expected[$key])) {
        continue;
    }
    $want = $expected[$key];
    unset($expected[$key]);
    if ($data["origin"] !== $want["origin"]) {
        fwrite(STDERR, "FAIL: wrong status origin\n");
        exit(1);
    }
    if (abs($data["stats"]["noise_floor"] - $want["noise_floor"]) > 2) {
        fwrite(STDERR, "FAIL: noise floor override is not applied\n");
        exit(1);
    }
    if ($data["stats"]["battery_mv"] !== $want["battery_mv"]) {
        fwrite(STDERR, "FAIL: battery override is not applied\n");
        exit(1);
    }
    if ($data["stats"]["uptime_secs"] < $want["uptime_secs"] || $data["stats"]["uptime_secs"] > $want["uptime_secs"] + 5) {
        fwrite(STDERR, "FAIL: uptime override is not applied\n");
        exit(1);
    }
}
if ($expected !== []) {
    fwrite(STDERR, "FAIL: missing status for observer\n");
    exit(1);
}
' "$TMP/sub.log" "$PUBKEY1" "$PUBKEY2" || fail "status assertions failed"

[ "$(grep -c "^$PACKETS_ONE " "$TMP/sub.log")" -eq 2 ] || fail "expected 2 packets for observer one"
[ "$(grep -c "^$PACKETS_TWO " "$TMP/sub.log")" -eq 2 ] || fail "expected 2 packets for observer two"
[ "$(grep -c "^$PACKETS_ONE/" "$TMP/sub.log")" -eq 0 ] || fail "archive subtopics must not be published"
[ "$(grep -c "^$PACKETS_TWO/" "$TMP/sub.log")" -eq 0 ] || fail "archive subtopics must not be published"

php -r '
require "src/meshcore.php";
$lines = array_values(array_filter(array_map("trim", file($argv[1])), static fn(string $line): bool => $line !== ""));
$pk1 = $argv[2];
$pk2 = $argv[3];
$byBody = [];
foreach ($lines as $line) {
    [$topic, $json] = preg_split("/ /", $line, 2);
    if (!str_ends_with($topic, "/packets")) {
        continue;
    }
    $parts = explode("/", $topic);
    $observer = $parts[count($parts) - 2];
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $body = meshcoreDecryptRaw(hex2bin($data["raw"]), MESHCORE_PUBLIC_CHANNEL_PSK)["body"];
    $byBody[$body][$observer] = $data;
}
if (count($byBody) !== 2) {
    fwrite(STDERR, "FAIL: expected packets for 2 messages\n");
    exit(1);
}
foreach ($byBody as $body => $observers) {
    if (!isset($observers[$pk1], $observers[$pk2])) {
        fwrite(STDERR, "FAIL: message {$body} is missing an observer\n");
        exit(1);
    }
    $one = $observers[$pk1];
    $two = $observers[$pk2];
    if ($one["hash"] !== $two["hash"]) {
        fwrite(STDERR, "FAIL: message {$body} has different packet hashes\n");
        exit(1);
    }
    if ($one["origin_id"] !== $pk1 || $two["origin_id"] !== $pk2) {
        fwrite(STDERR, "FAIL: message {$body} has wrong origin ids\n");
        exit(1);
    }
    if ($one["origin"] !== "Observer-One" || $two["origin"] !== "Observer-Two") {
        fwrite(STDERR, "FAIL: message {$body} has wrong origins\n");
        exit(1);
    }
    if ($one["route"] !== "F" || $two["route"] !== "F") {
        fwrite(STDERR, "FAIL: message {$body} is not flood\n");
        exit(1);
    }
    if (isset($one["path"]) || isset($two["path"])) {
        fwrite(STDERR, "FAIL: message {$body} flood JSON must not contain a path field\n");
        exit(1);
    }
    foreach ([$one, $two] as $packet) {
        $raw = hex2bin($packet["raw"]);
        $pathLen = ord($raw[1]);
        if (($pathLen & 63) < 2) {
            fwrite(STDERR, "FAIL: message {$body} raw flood path is missing\n");
            exit(1);
        }
    }
}
$first = $byBody["Alice: hello 1"][$pk1];
$second = $byBody["Alice: hello 1"][$pk2];
if ($first["SNR"] !== "5.5" || $first["RSSI"] !== "-86") {
    fwrite(STDERR, "FAIL: observer one signal override is not applied\n");
    exit(1);
}
if ($second["SNR"] !== "4.1" || $second["RSSI"] !== "-95") {
    fwrite(STDERR, "FAIL: observer two signal override is not applied\n");
    exit(1);
}
' "$TMP/sub.log" "$PUBKEY1" "$PUBKEY2" || fail "packet assertions failed"

PROGRESS=$(php -r '$d=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); echo $d["next_line"];' "$TMP/messages.progress")
[ "$PROGRESS" -eq 3 ] || fail "expected progress 3, got $PROGRESS"

echo "identities-integration: PASS"
