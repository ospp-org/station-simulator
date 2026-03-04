#!/bin/sh

RESULTS=""
PASS=0
FAIL=0
TOTAL=0
CONTAINER="${CONTAINER:-station-simulator}"

run_scenario() {
    local name="$1"
    local timeout="${2:-30}"
    local station_id="$3"

    TOTAL=$((TOTAL + 1))
    echo ""
    echo "=== [$TOTAL] $name (station=$station_id, timeout=${timeout}s) ==="

    local output
    output=$(docker exec "$CONTAINER" sh -c "php /app/simulator simulate \
        --scenario='$name' \
        --station-id='$station_id' \
        --headless \
        --fail-fast \
        --exit-code \
        --mqtt-host=emqx \
        --mqtt-port=1883 2>&1")
    local exit_code=$?

    # Show last 25 lines
    echo "$output" | tail -25

    if [ $exit_code -eq 0 ]; then
        PASS=$((PASS + 1))
        RESULTS="${RESULTS}\nPASS  $name"
    else
        FAIL=$((FAIL + 1))
        RESULTS="${RESULTS}\nFAIL  $name  (exit=$exit_code)"
    fi
}

echo "=========================================="
echo " OSPP Scenario Suite — Full Run"
echo "=========================================="

# Pre-flight: warm up PHP-FPM workers so first requests don't hit cold start
echo "  [warmup] Priming PHP-FPM workers..."
docker exec csms-app sh -c "php /var/www/html/artisan about > /dev/null 2>&1" || true
docker exec "$CONTAINER" sh -c "curl -s -o /dev/null -w '%{http_code}' http://csms-nginx/api/v1/health 2>/dev/null" || true
sleep 2

# Cleanup stale CSMS state from previous runs
echo "  [cleanup] Resetting stale CSMS state..."
docker exec csms-postgres sh -c \
  "psql -U csms -d csms -c \"UPDATE sessions SET status='failed', stopped_at=NOW() WHERE status IN ('pending','active','stopping','authorized'); UPDATE reservations SET status='cancelled', cancelled_at=NOW() WHERE status IN ('pending','confirmed'); DELETE FROM firmware_updates WHERE status NOT IN ('installed','activated','failed'); DELETE FROM diagnostics_uploads WHERE status NOT IN ('uploaded','failed'); UPDATE bays SET status='available';\""

# Core
run_scenario "core/happy-boot"              30 "stn_00000001"
run_scenario "core/heartbeat-cycle"         30 "stn_00000002"
run_scenario "core/status-notification"     20 "stn_00000003"
run_scenario "core/station-offline"         20 "stn_00000004"
run_scenario "core/data-transfer"           30 "stn_00000005"

# Sessions
run_scenario "sessions/start-service"           30 "stn_00000006"
run_scenario "sessions/stop-service"            60 "stn_00000007"
run_scenario "sessions/meter-values-streaming"  90 "stn_00000008"
run_scenario "sessions/full-session-lifecycle"  60 "stn_00000009"

# Reservations
run_scenario "reservations/reserve-and-start"   45 "stn_0000000a"
run_scenario "reservations/reserve-cancel"      60 "stn_0000000b"

# Device Management
run_scenario "device-management/config-change"          30 "stn_0000000c"
run_scenario "device-management/get-configuration"      30 "stn_0000000d"
run_scenario "device-management/maintenance-mode"       30 "stn_0000000e"
run_scenario "device-management/trigger-message"        30 "stn_0000000f"
run_scenario "device-management/service-catalog-update" 30 "stn_00000010"
run_scenario "device-management/soft-reset"             60 "stn_00000011"
run_scenario "device-management/firmware-update-failure"  60 "stn_00000012"
run_scenario "device-management/firmware-update-success" 120 "stn_00000013"
run_scenario "device-management/diagnostics-upload"      60 "stn_00000014"

# Security
run_scenario "security/security-event"          20 "stn_00000015"
run_scenario "security/hmac-verification"       30 "stn_00000016"
run_scenario "security/certificate-install"     30 "stn_00000017"
run_scenario "security/trigger-cert-renewal"    30 "stn_00000018"
run_scenario "security/offline-pass"            30 "stn_00000019"

# Reconnect/Recovery
run_scenario "core/reconnect-recovery"          45 "stn_0000001a"

echo ""
echo "=========================================="
echo " RESULTS: $PASS PASS / $FAIL FAIL / $TOTAL TOTAL"
echo "=========================================="
printf "$RESULTS\n"
echo ""
