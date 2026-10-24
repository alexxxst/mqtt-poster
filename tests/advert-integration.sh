#!/usr/bin/env bash
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"

PORT=18888
TMP=$(mktemp -d)
BROKER_PID=
SUB_PID=
PREFIX="meshcore/ADTEST$(date +%s)$$"
PUBKEY1=$(php -r 'echo strtoupper(bin2hex(random_bytes(32)));')
PUBKEY2=$(php -r 'echo strtoupper(bin2hex(random_bytes(32)));')
SEED=$(php -r 'echo str_repeat("22", 32);')

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

cat > "$TMP/dick.txt" <<'EOF'
59.708585, 33.270746
59.696392, 33.290327
59.686220, 33.314417
59.677400, 33.340563
57.721436, 34.667995
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
    "topic": "$PREFIX/LED",
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
      "rssi": -86
    },
    {
      "public_key": "$PUBKEY2",
      "node_name": "Observer-Two",
      "snr": 4.2,
      "rssi": -95
    }
  ],
  "device": {
    "model": "Heltec V4",
    "firmware_version": "v1.16.0",
    "client_version": "mqtt-poster",
    "radio": "868.856,62.5,7,7"
  },
  "status": {
    "enabled": true,
    "retain": true,
    "interval_ms": 300000,
    "errors": 0,
    "queue_len": 1,
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
    "snr_min": -3.0,
    "snr_max": 8.5,
    "snr_step": 0.6,
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
      "direct_probability": 0.5
    }
  },
  "advert": {
    "coordinate_file": "$TMP/dick.txt",
    "progress_file": "$TMP/dick.txt.progress",
    "key_seed": "$SEED",
    "node_type": 2,
    "loop": false,
    "publish_status": false,
    "direct_probability": 0.0
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

run_with_subscriber() {
  local count=$1
  shift
  mosquitto_sub -h 127.0.0.1 -p "$PORT" -R -t "$PREFIX/#" -C "$count" -W 20 -v >"$TMP/sub.log" 2>&1 &
  SUB_PID=$!
  sleep 0.3
  set +e
  php drawer.php "$@" >"$TMP/drawer.out" 2>"$TMP/drawer.err"
  local rc=$?
  set -e
  wait "$SUB_PID" || true
  SUB_PID=
  [ "$rc" -eq 0 ] || fail "drawer exited $rc: $(cat "$TMP/drawer.err")"
}

run_with_subscriber 6 --config="$TMP/config.json" --count=3
[ "$(grep -c '^' "$TMP/sub.log")" -eq 6 ] || fail "expected 6 MQTT messages: $(cat "$TMP/sub.log")"
grep -q '/status ' "$TMP/sub.log" && fail "drawer must publish only rx packets, not status"
grep -q '/packets/' "$TMP/sub.log" && fail "drawer must not publish archive subtopics"
grep -q '"direction":"tx"' "$TMP/sub.log" && fail "drawer must not publish fake-node tx packets"
[ "$(grep -c "^$PREFIX/LED/$PUBKEY1/packets " "$TMP/sub.log")" -eq 3 ] || fail "expected LED topic for observer one"
[ "$(grep -c "^$PREFIX/LED/$PUBKEY2/packets " "$TMP/sub.log")" -eq 3 ] || fail "expected LED topic for observer two"

php -r '
require "src/advert.php";
$log = array_values(array_filter(array_map("trim", file($argv[1])), static fn(string $line): bool => $line !== ""));
$points = array_values(array_filter(array_map("trim", file($argv[5])), static fn(string $line): bool => $line !== ""));
$seed = $argv[4];
$expected = [];
foreach (array_slice($points, 0, 3) as $index => $point) {
    [$lat, $lon] = array_map("trim", explode(",", $point));
    $lineNo = $index + 1;
    $latE6 = (int) round(((float) $lat) * 1000000);
    $lonE6 = (int) round(((float) $lon) * 1000000);
    $identity = advertDeriveIdentity($seed, $lineNo, $latE6, $lonE6);
    $expected[$identity["public_key_hex"]] = [
        "line" => $lineNo,
        "name" => advertName($lineNo, (float) $lat, (float) $lon, $seed),
        "lat" => $latE6 / 1000000.0,
        "lon" => $lonE6 / 1000000.0,
    ];
}
$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};
$byNode = [];
foreach ($log as $line) {
    [$topic, $json] = preg_split("/ /", $line, 2);
    if (!str_ends_with($topic, "/packets")) {
        $fail("unexpected topic: {$topic}");
    }
    $parts = explode("/", $topic);
    $observer = $parts[count($parts) - 2];
    if ($observer !== $argv[2] && $observer !== $argv[3]) {
        $fail("packet is published under an unexpected topic: {$topic}");
    }
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (($data["direction"] ?? null) !== "rx") {
        $fail("observer must publish rx packets");
    }
    if (($data["origin_id"] ?? null) !== $observer) {
        $fail("packet has the wrong origin_id");
    }
    $raw = hex2bin($data["raw"]);
    $pathLen = ord($raw[1]);
    $pathCount = $pathLen & 63;
    $pathSize = ($pathLen >> 6) + 1;
    $pathBytes = $pathSize * $pathCount;
    if ($pathSize !== 2) {
        $fail("flood advert path must use 2-byte hashes");
    }
    if ($pathCount < 2 || $pathCount > 3) {
        $fail("flood advert path has an unexpected hop count");
    }
    $payload = substr($raw, 2 + $pathBytes);
    $decoded = advertVerifyPayload($payload);
    $pub = $decoded["public_key_hex"];
    if (!isset($expected[$pub])) {
        $fail("advert from an unexpected node: {$pub}");
    }
    if ($observer === $pub) {
        $fail("fake node must not publish under its own topic");
    }
    $exp = $expected[$pub];
    if ($decoded["name"] !== $exp["name"]) {
        $fail("wrong name for line {$exp["line"]}");
    }
    if ($decoded["lat"] !== $exp["lat"] || $decoded["lon"] !== $exp["lon"]) {
        $fail("wrong coordinates for line {$exp["line"]}");
    }
    if ($decoded["node_type"] !== 2) {
        $fail("wrong node type for line {$exp["line"]}");
    }
    $expectedHash = strtoupper(bin2hex(substr(hash("sha256", chr(4) . $payload, true), 0, 8)));
    if (($data["hash"] ?? null) !== $expectedHash) {
        $fail("wrong hash for line {$exp["line"]}");
    }
    if (($data["route"] ?? null) !== "F" || isset($data["path"])) {
        $fail("advert is not flood without JSON path");
    }
    if ((int) $data["len"] !== 2 + $pathBytes + (int) $data["payload_len"]) {
        $fail("wrong len for line {$exp["line"]}");
    }
    $byNode[$pub]["hash"][$observer] = $data["hash"];
    $byNode[$pub]["path"][$observer] = bin2hex(substr($raw, 2, $pathBytes));
    $byNode[$pub]["snr"][$observer] = $data["SNR"];
    $byNode[$pub]["rssi"][$observer] = $data["RSSI"];
}
if (count($expected) !== 3 || count($byNode) !== 3) {
    $fail("expected 3 advert nodes, expected=" . count($expected) . " byNode=" . count($byNode));
}
foreach ($expected as $pub => $exp) {
    foreach ([$argv[2], $argv[3]] as $observer) {
        if (!isset($byNode[$pub]["hash"][$observer])) {
            $fail("node for line {$exp["line"]} is missing observer {$observer}");
        }
    }
    if ($byNode[$pub]["hash"][$argv[2]] !== $byNode[$pub]["hash"][$argv[3]]) {
        $fail("node for line {$exp["line"]} has different hashes");
    }
    if ($byNode[$pub]["path"][$argv[2]] === $byNode[$pub]["path"][$argv[3]]) {
        $fail("observers must use different paths for line {$exp["line"]}");
    }
}
$firstPub = array_key_first($expected);
if ($byNode[$firstPub]["snr"][$argv[2]] !== "5.5" || $byNode[$firstPub]["rssi"][$argv[2]] !== "-86") {
    $fail("observer one signal is not used");
}
if ($byNode[$firstPub]["snr"][$argv[3]] !== "4.2" || $byNode[$firstPub]["rssi"][$argv[3]] !== "-95") {
    $fail("observer two signal is not used");
}
' "$TMP/sub.log" "$PUBKEY1" "$PUBKEY2" "$SEED" "$TMP/dick.txt" || fail "first run assertions failed"

PROGRESS=$(php -r '$d=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); echo $d["next_line"];' "$TMP/dick.txt.progress")
[ "$PROGRESS" -eq 4 ] || fail "expected progress 4, got $PROGRESS"

run_with_subscriber 2 --config="$TMP/config.json" --count=1
[ "$(grep -c '^' "$TMP/sub.log")" -eq 2 ] || fail "expected 2 MQTT messages for the second point"
grep -q '/status ' "$TMP/sub.log" && fail "second run published status"
grep -q '/packets/' "$TMP/sub.log" && fail "second run published archive subtopics"
php -r '
require "src/advert.php";
$lines = array_values(array_filter(array_map("trim", file($argv[1])), static fn(string $line): bool => $line !== ""));
$names = [];
foreach ($lines as $line) {
    [$topic, $json] = preg_split("/ /", $line, 2);
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $raw = hex2bin($data["raw"]);
    $pathLen = ord($raw[1]);
    $pathBytes = (($pathLen >> 6) + 1) * ($pathLen & 63);
    $names[] = advertVerifyPayload(substr($raw, 2 + $pathBytes))["name"];
}
if (count($names) !== 2 || count(array_unique($names)) !== 1 || $names[0] !== advertName(4, 59.677400, 33.340563, $argv[2])) {
    fwrite(STDERR, "FAIL: second run did not publish line 4 from both observers\n");
    exit(1);
}
' "$TMP/sub.log" "$SEED" || fail "second run assertions failed"
PROGRESS=$(php -r '$d=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); echo $d["next_line"];' "$TMP/dick.txt.progress")
[ "$PROGRESS" -eq 5 ] || fail "expected progress 5, got $PROGRESS"

run_with_subscriber 2 --config="$TMP/config.json" --reset-progress --count=1
[ "$(grep -c '^' "$TMP/sub.log")" -eq 2 ] || fail "expected 2 MQTT messages after reset"
grep -q '/status ' "$TMP/sub.log" && fail "reset run published status"
grep -q '/packets/' "$TMP/sub.log" && fail "reset run published archive subtopics"
php -r '
require "src/advert.php";
$lines = array_values(array_filter(array_map("trim", file($argv[1])), static fn(string $line): bool => $line !== ""));
$expectedName = advertName(1, 59.708585, 33.270746, $argv[2]);
$names = [];
foreach ($lines as $line) {
    [$topic, $json] = preg_split("/ /", $line, 2);
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $raw = hex2bin($data["raw"]);
    $pathLen = ord($raw[1]);
    $pathBytes = (($pathLen >> 6) + 1) * ($pathLen & 63);
    $names[] = advertVerifyPayload(substr($raw, 2 + $pathBytes))["name"];
}
if (count($names) !== 2 || count(array_unique($names)) !== 1 || $names[0] !== $expectedName) {
    fwrite(STDERR, "FAIL: reset changed the node name\n");
    exit(1);
}
' "$TMP/sub.log" "$SEED" || fail "reset stability assertions failed"
PROGRESS=$(php -r '$d=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); echo $d["next_line"];' "$TMP/dick.txt.progress")
[ "$PROGRESS" -eq 2 ] || fail "expected progress 2 after reset, got $PROGRESS"

PROGRESS_BEFORE=$(cat "$TMP/dick.txt.progress")
set +e
php drawer.php --config="$TMP/config.json" --dry-run --count=2 >"$TMP/dry.out" 2>"$TMP/dry.err"
DRY_RC=$?
set -e
[ "$DRY_RC" -eq 0 ] || fail "dry-run exited $DRY_RC: $(cat "$TMP/dry.err")"
[ "$(grep -c '^' "$TMP/dry.out")" -eq 4 ] || fail "dry-run should print one rx packet per observer per point"
[ "$(cat "$TMP/dick.txt.progress")" = "$PROGRESS_BEFORE" ] || fail "dry-run changed progress"

php -r '
$config = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$config["advert"]["publish_status"] = true;
$config["advert"]["progress_file"] = $argv[3];
$config["advert"]["loop"] = false;
file_put_contents($argv[2], json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
' "$TMP/config.json" "$TMP/config-kld.json" "$TMP/kld.progress"
printf '{"next_line":5}\n' >"$TMP/kld.progress"

run_with_subscriber 6 --config="$TMP/config-kld.json" --count=1
[ "$(grep -c '^' "$TMP/sub.log")" -eq 6 ] || fail "expected 6 MQTT messages for foreign region: $(cat "$TMP/sub.log")"
[ "$(grep -c "^$PREFIX/LED/$PUBKEY1/status " "$TMP/sub.log")" -eq 1 ] || fail "missing home status for observer one"
[ "$(grep -c "^$PREFIX/LED/$PUBKEY2/status " "$TMP/sub.log")" -eq 1 ] || fail "missing home status for observer two"
[ "$(grep -c "^$PREFIX/KLD/$PUBKEY1/status " "$TMP/sub.log")" -eq 1 ] || fail "missing KLD status for observer one"
[ "$(grep -c "^$PREFIX/KLD/$PUBKEY2/status " "$TMP/sub.log")" -eq 1 ] || fail "missing KLD status for observer two"
[ "$(grep -c "^$PREFIX/KLD/$PUBKEY1/packets " "$TMP/sub.log")" -eq 1 ] || fail "missing KLD packet for observer one"
[ "$(grep -c "^$PREFIX/KLD/$PUBKEY2/packets " "$TMP/sub.log")" -eq 1 ] || fail "missing KLD packet for observer two"
grep -q '"direction":"rx"' "$TMP/sub.log" || fail "foreign region packet is not rx"
grep -q '"status":"online"' "$TMP/sub.log" || fail "foreign region status is not online"
PROGRESS=$(php -r '$d=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); echo $d["next_line"];' "$TMP/kld.progress")
[ "$PROGRESS" -eq 6 ] || fail "expected KLD progress 6, got $PROGRESS"

echo "advert-integration: PASS"
