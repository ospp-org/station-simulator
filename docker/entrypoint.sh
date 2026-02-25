#!/bin/sh
set -e

# Build command from environment variables
CMD=${1:-simulate}

case "$CMD" in
  simulate)
    exec php /app/simulator simulate \
      --stations="${SIMULATOR_STATIONS:-1}" \
      ${SIMULATOR_AUTO_BOOT:+--auto-boot} \
      --mqtt-host="${MQTT_HOST:-localhost}" \
      --mqtt-port="${MQTT_PORT:-1883}" \
      ${SCENARIO:+--scenario="$SCENARIO"} \
      ${HEADLESS:+--headless} \
      ${FAIL_FAST:+--fail-fast} \
      ${JUNIT_OUTPUT:+--junit-output="$JUNIT_OUTPUT"} \
      ${JSON_OUTPUT:+--json-output="$JSON_OUTPUT"} \
      ${EXIT_CODE:+--exit-code} \
      --verbose
    ;;
  dashboard)
    exec php /app/simulator dashboard \
      --ws-port="${WS_PORT:-8085}" \
      --api-port="${API_PORT:-8086}"
    ;;
  scenarios:list)
    exec php /app/simulator scenarios:list ${2:+--tag="$2"}
    ;;
  scenarios:validate)
    exec php /app/simulator scenarios:validate "${2:-scenarios/}"
    ;;
  *)
    exec php /app/simulator "$@"
    ;;
esac
