#!/usr/bin/env bash
# Start the D1 worker, run all PHPUnit tests, then stop the worker.
set -e

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

WORKER_DIR="$ROOT/tests/worker"
WORKER_LOG="${WORKER_LOG:-/tmp/l1-wrangler.log}"
READINESS_URL="http://127.0.0.1:8787"
READINESS_TIMEOUT=30

# Ensure worker dependencies are installed
if [[ ! -d "$WORKER_DIR/node_modules" ]]; then
  echo "Installing worker dependencies (tests/worker)…"
  (cd "$WORKER_DIR" && npm ci)
fi

# Ensure we don't talk to a stale worker process.
pkill -f "wrangler dev" 2>/dev/null || true
sleep 1

# Start worker in background
echo "Starting D1 worker…"
(cd "$WORKER_DIR" && npm run start > "$WORKER_LOG" 2>&1) &
WRANGLER_PID=$!

cleanup() {
  kill $WRANGLER_PID 2>/dev/null || true
  pkill -f "wrangler dev" 2>/dev/null || true
}
trap cleanup EXIT

# Wait for worker to be ready
for i in $(seq 1 "$READINESS_TIMEOUT"); do
  if curl -s -o /dev/null "$READINESS_URL" 2>/dev/null; then
    break
  fi
  if ! kill -0 $WRANGLER_PID 2>/dev/null; then
    echo "Worker failed to start. See $WORKER_LOG"
    exit 1
  fi
  sleep 1
done

if ! curl -s -o /dev/null "$READINESS_URL" 2>/dev/null; then
  echo "Worker did not become ready in ${READINESS_TIMEOUT}s. See $WORKER_LOG"
  exit 1
fi

# Run all tests (no group exclusion)
vendor/bin/phpunit
exit $?
