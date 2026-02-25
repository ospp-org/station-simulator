# OSPP Station Simulator

Simulates 1–N OSPP (Open Self-Service Point Protocol) stations connecting via MQTT to a CSMS server. Built for development, automated testing, load testing, and demos — no physical hardware required.

## Features

- **Multi-station simulation** — run 1 to 100+ virtual stations simultaneously
- **Full OSPP protocol** — BootNotification, Heartbeat, StartService, StopService, MeterValues, Reservations, Firmware, Diagnostics, Offline, Security
- **YAML scenario engine** — 28 built-in scenarios across 7 categories (core, sessions, reservations, device management, offline, fleet, chaos)
- **Probabilistic auto-responder** — configurable accept/reject rates, response delays, error injection per action
- **Real-time meter values** — cumulative, monotonic readings with per-service consumption profiles and jitter
- **REST API** (port 8086) — control stations, trigger faults, run scenarios programmatically
- **WebSocket push** (port 8085) — live event stream for dashboards and monitoring
- **CI/CD ready** — JUnit XML + JSON export, `--fail-fast`, `--exit-code` for pipelines

## Requirements

- PHP 8.3+
- Composer
- MQTT broker (EMQX recommended)
- `ospp/protocol` ^0.1

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

| Category | Scenarios | Description |
|----------|-----------|-------------|
| `core/` | 4 | Boot, heartbeat, offline/reconnect |
| `sessions/` | 4 | Full lifecycle, timeout, rejection, meter streaming |
| `reservations/` | 3 | Reserve, cancel, expiry |
| `device-management/` | 6 | Firmware, diagnostics, config, reset, service catalog |
| `offline/` | 3 | Pass authorization, transaction reconciliation, fraud |
| `fleet/` | 3 | 10/50/100 station load tests |
| `chaos/` | 4 | Disconnects, slow responses, malformed messages, out-of-order |
| `security/` | 1 | MAC verification failure |

## Docker

```bash
docker-compose up --build
```

## Configuration

Station behavior is defined in `config/stations/default.yaml`:

- **identity** — station model, vendor, firmware version
- **capabilities** — bay count, offline mode, BLE, meter values
- **services** — wash_basic, wash_premium, vacuum, air with pricing
- **behavior** — per-action accept rates, delays, error codes
- **meter_values** — consumption profiles per service type
- **offline** — ECDSA signing, pass constraints

## License

[MIT](LICENSE)
