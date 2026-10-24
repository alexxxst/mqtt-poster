#!/usr/bin/env bash
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"

PORT=18883
TMP=$(mktemp -d)
BROKER_PID=
SUB_PID=
PREFIX="meshcore/TEST$(date +%s)$$"
PUBKEY=$(php -r 'echo strtoupper(bin2hex(random_bytes(32)));')

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
Eve hello 3
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
  "identity": {
    "public_key": "$PUBKEY",
    "node_name": "MeshCore-Test"
  },
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
    "battery_mv": 4100,
    "uptime_secs": 12345,
    "errors": 0,
    "queue_len": 1,
    "noise_floor": -110,
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
    "snr": 5.5,
    "snr_min": 3.0,
    "snr_max": 8.5,
    "snr_step": 0.6,
    "rssi": -86,
    "rssi_min": -104,
    "rssi_max": -74,
    "rssi_step": 3,
    "score": 1000,
    "duration": 120,
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

mosquitto_sub -h 127.0.0.1 -p "$PORT" -t "$PREFIX/#" -C 4 -W 20 -v >"$TMP/sub.log" 2>&1 &
SUB_PID=$!
sleep 0.3

php poster.php --config="$TMP/config.json" --count=3 >"$TMP/poster.out" 2>"$TMP/poster.err"
POSTER_RC=$?
wait "$SUB_PID" || true
SUB_PID=

[ "$POSTER_RC" -eq 0 ] || fail "poster exited $POSTER_RC: $(cat "$TMP/poster.err")"
[ "$(grep -c '^' "$TMP/sub.log")" -eq 4 ] || fail "expected 4 MQTT messages: $(cat "$TMP/sub.log")"

STATUS_TOPIC="$PREFIX/$PUBKEY/status"
PACKETS_TOPIC="$PREFIX/$PUBKEY/packets"
grep -q "^$STATUS_TOPIC " "$TMP/sub.log" || fail "missing status topic"
grep -q '"status":"online"' "$TMP/sub.log" || fail "missing online status"
[ "$(grep -c "^$PACKETS_TOPIC " "$TMP/sub.log")" -eq 3 ] || fail "expected 3 main packet messages"
[ "$(grep -c "^$PACKETS_TOPIC/" "$TMP/sub.log")" -eq 0 ] || fail "archive subtopics must not be published"
grep -q '"packet_type":"5"' "$TMP/sub.log" || fail "missing GRP_TXT packet"
grep -q "\"origin_id\":\"$PUBKEY\"" "$TMP/sub.log" || fail "missing origin_id"
grep -q '"queue_len":1' "$TMP/sub.log" || fail "status queue_len is not 1"
grep -q '"route":"F"' "$TMP/sub.log" || fail "packets are not flood-route"
grep -q '"raw":"154[23]' "$TMP/sub.log" || fail "flood raw is missing path bytes"
grep -q '"path":' "$TMP/sub.log" && fail "flood JSON must not contain a path field"

FIRST_PACKET=$(grep "^$PACKETS_TOPIC " "$TMP/sub.log" | head -1 | sed "s|^$PACKETS_TOPIC ||")
printf '%s' "$FIRST_PACKET" > "$TMP/first.json"
BODY=$(php -r '
require "src/meshcore.php";
$data = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$packet = meshcoreDecryptRaw(hex2bin($data["raw"]), MESHCORE_PUBLIC_CHANNEL_PSK);
echo $packet["body"];
' "$TMP/first.json")
[ "$BODY" = "Alice: hello 1" ] || fail "unexpected decrypted body: $BODY"

# The online status and the last packet must still be retained after exit.
RETAINED=$(mosquitto_sub -h 127.0.0.1 -p "$PORT" -t "$STATUS_TOPIC" -C 1 -W 2 -v 2>&1 || true)
echo "$RETAINED" | grep -q '"status":"online"' || fail "status is not retained: $RETAINED"

LAST_PACKET=$(mosquitto_sub -h 127.0.0.1 -p "$PORT" -t "$PACKETS_TOPIC" -C 1 -W 2 -v 2>&1 || true)
echo "$LAST_PACKET" | grep -q '"packet_type":"5"' || fail "last packet is not retained: $LAST_PACKET"

PROGRESS=$(php -r '$d=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); echo $d["next_line"];' "$TMP/messages.progress")
[ "$PROGRESS" -eq 4 ] || fail "expected progress 4, got $PROGRESS"

echo "meshcore-integration: PASS"
