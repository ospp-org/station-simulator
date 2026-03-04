#!/bin/bash
# Run all scenarios and collect results
cd /d/Personal/Codebase/ospp/station-simulator

SCENARIOS=(
  "core/happy-boot:30"
  "core/heartbeat-cycle:60"
  "core/status-notification:30"
  "core/data-transfer:30"
  "core/station-offline:30"
  "sessions/start-service:30"
  "sessions/stop-service:60"
  "sessions/meter-values-streaming:60"
  "reservations/reserve-and-start:60"
  "reservations/reserve-cancel:60"
  "device-management/get-configuration:30"
  "device-management/config-change:30"
  "device-management/soft-reset:30"
  "device-management/maintenance-mode:30"
  "device-management/service-catalog-update:30"
  "device-management/trigger-message:30"
  "device-management/firmware-update-success:120"
  "device-management/diagnostics-upload:60"
  "security/certificate-install:30"
  "security/trigger-cert-renewal:30"
  "security/security-event:30"
)

RESULTS=()
PASS=0
FAIL=0

for entry in "${SCENARIOS[@]}"; do
  scenario="${entry%%:*}"
  timeout="${entry##*:}"
  echo "=== Running: $scenario (timeout: ${timeout}s) ==="

  OUTPUT=$(MSYS_NO_PATHCONV=1 docker compose run --rm --entrypoint php station-simulator \
    /app/simulator simulate \
    --scenario="$scenario" \
    --station-id=stn_00000001 \
    --headless --fail-fast --exit-code \
    --timeout="$timeout" 2>&1)

  EXIT_CODE=$?

  # Extract result line
  RESULT_LINE=$(echo "$OUTPUT" | grep -E "(PASSED|FAILED)" | tail -1 | sed 's/\x1b\[[0-9;]*m//g')

  if [ $EXIT_CODE -eq 0 ]; then
    RESULTS+=("PASS|$scenario|$RESULT_LINE")
    PASS=$((PASS + 1))
    echo "  PASS"
  else
    # Extract error
    ERROR_LINE=$(echo "$OUTPUT" | grep -E "FAIL:" | head -1 | sed 's/\x1b\[[0-9;]*m//g')
    RESULTS+=("FAIL|$scenario|$ERROR_LINE")
    FAIL=$((FAIL + 1))
    echo "  FAIL: $ERROR_LINE"
  fi
  echo ""
done

echo ""
echo "========================================="
echo "FINAL RESULTS: $PASS PASS / $FAIL FAIL"
echo "========================================="
printf "%-8s | %-45s | %s\n" "Status" "Scenario" "Details"
echo "---------|-----------------------------------------------|--------"
for result in "${RESULTS[@]}"; do
  IFS='|' read -r status scenario details <<< "$result"
  printf "%-8s | %-45s | %s\n" "$status" "$scenario" "$details"
done
