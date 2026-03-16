# OSPP Station Simulator

**Current version: 0.2.0**

Simulates 1–N OSPP (Open Self-Service Point Protocol) stations connecting via MQTT to a CSMS server. Built for development, automated testing, load testing, and demos — no physical hardware required.

## Features

- **Multi-station simulation** — run 1 to 100+ virtual stations simultaneously
- **Full OSPP protocol** — BootNotification, Heartbeat, StartService, StopService, MeterValues, Reservations, Firmware, Diagnostics, Offline, Security
- **YAML scenario engine** — 41 built-in scenarios across 8 categories (core, sessions, reservations, device management, security, offline, fleet, chaos)
- **Probabilistic auto-responder** — configurable accept/reject rates, response delays, error injection per action
- **Real-time meter values** — cumulative, monotonic readings with per-service consumption profiles and jitter
- **REST API** (port 8086) — control stations, trigger faults, run scenarios programmatically
- **WebSocket push** (port 8085) — live event stream for dashboards and monitoring
- **CI/CD ready** — JUnit XML + JSON export, `--fail-fast`, `--exit-code` for pipelines

## Requirements

- PHP 8.3+
- Composer
- MQTT broker (EMQX recommended)
- `ospp/protocol` ^0.2.1

## Quick Start

```bash
composer install
cp .env.example .env

# Single station, auto-boot
php simulator simulate --stations=1 --auto-boot --mqtt-host=localhost

# Run a scenario headless
php simulator simulate --scenario=core/happy-boot --headless --junit-output=results.xml

# List all scenarios
php simulator scenarios:list

# Validate a scenario
php simulator scenarios:validate scenarios/core/happy-boot.yaml
```

## Scenarios

| Category | Count | Scenarios |
|----------|-------|-----------|
| `core/` | 7 | happy-boot, boot-rejected, heartbeat-cycle, status-notification, data-transfer, station-offline, reconnect-recovery |
| `sessions/` | 6 | full-session-lifecycle, start-service, stop-service, session-rejected, session-timeout, meter-values-streaming |
| `reservations/` | 3 | reserve-and-start, reserve-cancel, reserve-expire |
| `device-management/` | 9 | firmware-update-success, firmware-update-failure, diagnostics-upload, config-change, get-configuration, soft-reset, trigger-message, maintenance-mode, service-catalog-update |
| `security/` | 6 | certificate-install, trigger-cert-renewal, hmac-verification, mac-verification-failure, security-event, offline-pass |
| `offline/` | 3 | offline-pass-authorize, offline-transaction-reconcile, offline-fraud-scenario |
| `fleet/` | 3 | 10-station-boot, 50-station-mixed, 100-station-stress |
| `chaos/` | 4 | random-disconnect, slow-responses, malformed-messages, out-of-order-events |
| **Total** | **41** | |

## Testing

- **Unit tests**: 158 tests, 747 assertions
- **Live scenarios**: 26/26 OSPP messages validated against CSMS
- **Run all scenarios**: `bash run-all-scenarios.sh`
- Requires a running CSMS server stack (MQTT broker + CSMS application)

## Docker

```bash
docker compose up --build
```

## Configuration

Station behavior is defined in `config/stations/default.yaml`:

- **identity** — station model, vendor, firmware version
- **capabilities** — bay count, offline mode, BLE, meter values
- **services** — wash_basic, wash_premium, vacuum, air with pricing
- **behavior** — per-action accept rates, delays, error codes
- **meter_values** — consumption profiles per service type
- **offline** — ECDSA signing, pass constraints

## Known Limitations

### MQTT Protocol Version

The simulator uses `php-mqtt/client` which supports MQTT 3.1.1 only.
OSPP spec requires MQTT 5.0. The following MQTT 5.0 features are NOT available:

- **Will Delay Interval** — LWT is published immediately on disconnect detection, not after 10s grace period
- **Session Expiry Interval** — uses broker default
- **Message Expiry Interval** — not supported
- **Reason Codes** — basic CONNACK only
- **Shared Subscriptions** — server-side, does not affect simulator

This does NOT affect protocol conformance testing — all 26 OSPP actions are fully supported. The limitation is transport-level only.

No PHP MQTT 5.0 library with ReactPHP support currently exists. Migration to MQTT 5.0 requires either Swoole (simps/mqtt) or a different language runtime.

### LWT (Last Will and Testament)

LWT is configured at CONNECT time but triggers only on actual network failure (TCP connection loss without DISCONNECT packet). `kill -9` on Linux sends TCP FIN which the broker interprets as clean disconnect — LWT is NOT published.

To test LWT, use `php simulator send-event ConnectionLost` or simulate network failure with `iptables -A OUTPUT -d <broker_ip> -j DROP`.

## License

[MIT](LICENSE)
