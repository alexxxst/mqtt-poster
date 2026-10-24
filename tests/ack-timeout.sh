#!/usr/bin/env bash
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"

PORT=18886
TMP=$(mktemp -d)
BROKER_PID=

fail() { echo "FAIL: $*" >&2; exit 1; }

cleanup() {
  if [ -n "${BROKER_PID:-}" ]; then
    kill "$BROKER_PID" 2>/dev/null || true
    wait "$BROKER_PID" 2>/dev/null || true
  fi
  rm -rf "$TMP"
}
trap cleanup EXIT

command -v php >/dev/null 2>&1 || fail "php not found"

php tests/fake-broker.php "$PORT" >"$TMP/broker.log" 2>&1 &
BROKER_PID=$!

for _ in $(seq 1 50); do
  grep -q '^listening ' "$TMP/broker.log" && break
  sleep 0.1
done
grep -q '^listening ' "$TMP/broker.log" || {
  cat "$TMP/broker.log" >&2
  fail "fake broker did not start"
}

PUBKEY=$(php -r 'echo strtoupper(bin2hex(random_bytes(32)));')
cat > "$TMP/messages.txt" <<'EOF'
Alice hello
Bob second
EOF
cat > "$TMP/config.json" <<JSON
{
  "mqtt": {
    "host": "127.0.0.1",
    "port": $PORT,
    "topic": "meshcore/TEST",
    "qos": 1,
    "interval_ms": 10,
    "queue_max": 10
  },
  "identity": {
    "public_key": "$PUBKEY",
    "node_name": "MeshCore-Test"
  },
  "status": {
    "enabled": false
  },
  "packet": {
    "channel_secret": "8b3387e9c5cdea6ac9e5edbaa115cd72",
    "message_file": "$TMP/messages.txt",
    "progress_file": "$TMP/messages.progress",
    "loop": false,
    "retain": false,
    "direction": "rx"
  },
  "template_file": "$ROOT/chat.tpl"
}
JSON

start=$(date +%s)
set +e
php poster.php --config="$TMP/config.json" --count=2 >"$TMP/poster.out" 2>"$TMP/poster.err" &
POSTER_PID=$!
for _ in $(seq 1 40); do
  if ! kill -0 "$POSTER_PID" 2>/dev/null; then break; fi
  sleep 0.5
done
if kill -0 "$POSTER_PID" 2>/dev/null; then
  kill "$POSTER_PID" 2>/dev/null || true
  wait "$POSTER_PID" 2>/dev/null || true
  fail "poster hung instead of timing out"
fi
wait "$POSTER_PID"
rc=$?
set -e
end=$(date +%s)
elapsed=$((end - start))

[ "$rc" -eq 1 ] || fail "expected exit 1, got $rc: $(cat "$TMP/poster.err")"
grep -q 'QoS acknowledgement timeout' "$TMP/poster.err" \
  || fail "missing ack timeout error: $(cat "$TMP/poster.err")"
grep -q 'not delivered' "$TMP/poster.err" \
  || fail "missing not-delivered error: $(cat "$TMP/poster.err")"
grep -q 'queue overflow' "$TMP/poster.err" \
  && fail "failed publish was re-enqueued instead of retried: $(cat "$TMP/poster.err")"
[ "$elapsed" -ge 5 ] || fail "ack timeout returned too fast (${elapsed}s)"
[ "$elapsed" -le 20 ] || fail "ack timeout took too long (${elapsed}s)"

connections=$(grep -c '^connection ' "$TMP/broker.log" || true)
[ "$connections" -ge 1 ] || {
  cat "$TMP/broker.log" >&2
  fail "fake broker saw no connection"
}

echo "ack-timeout smoke: PASS (${elapsed}s, connections=$connections)"
