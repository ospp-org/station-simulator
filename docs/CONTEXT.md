# Station Simulator — Context

## What is this?
CLI tool that simulates an OSPP car wash station
for testing CSMS servers.

## Protocol
- OSPP v0.1.0-draft.1
- Spec: D:\Personal\Codebase\ospp\spec
- 26 MQTT messages (12 station→server, 14 server→station)
- 5 state machines (Bay, Session, Reservation, Firmware, Diagnostics)

## SDK
- ospp/protocol v0.2.1 (Packagist)
- Includes: MessageBuilder, enums (PascalCase wire format),
  state machine transitions, HMAC signing, JSON schemas
- SchemaPath::directory() for schema access

## CSMS Server (test target)
- D:\Personal\Codebase\osp\csms-server
- EMQX 5.8 MQTT broker (TLS 1.3, port 8883)
- Topics: ospp/v1/stations/{stationId}/to-server
         ospp/v1/stations/{stationId}/to-station
- 2528 tests passing, all 26 handlers implemented

## Wire format
- MessageType: Request/Response/Event (PascalCase)
- Source: Station/Server (PascalCase)
- protocolVersion: "0.1.0"
- Envelope: {messageId, messageType, source,
  action, protocolVersion, timestamp, payload, mac?}
- HMAC-SHA256 on 19 critical messages (CriticalMessageRegistry)
- sessionKey derived per boot via HMAC-SHA256

## Key field names (post schema-fix)
- serverTime (not currentTime)
- heartbeatIntervalSec (not heartbeatInterval)
- liquidMl (not waterMl)
- consumableMl (not chemicalMl)
- ChangeConfiguration: {keys: [{key, value}]} (array-based)
- UpdateFirmware: signature field required

## What station simulator must do
1. Boot sequence: connect MQTT → BootNotification →
   receive sessionKey + config
2. Heartbeat: periodic per heartbeatIntervalSec
3. StatusNotification: bay status changes
4. Respond to commands: StartService, StopService,
   ReserveBay, CancelReservation, GetConfiguration,
   ChangeConfiguration, Reset, SetMaintenanceMode,
   UpdateServiceCatalog, UpdateFirmware, GetDiagnostics,
   CertificateInstall, TriggerCertificateRenewal,
   TriggerMessage, DataTransfer
5. Send events: MeterValues (during active session),
   SecurityEvent, FirmwareStatusNotification,
   DiagnosticsNotification
6. TransactionEvent: sync offline transactions
7. SignCertificate: send CSR for cert renewal
8. ConnectionLost: LWT message on disconnect

## Old code
There is old station simulator code in this folder.
Protocol has changed significantly — old code is
REFERENCE ONLY. Do not assume it works.

## Technology decision
- PHP with ospp/protocol SDK (v0.2.1)
- Laravel Zero (CLI framework)
- NOT Laravel (no HTTP needed)
- php-mqtt/client for MQTT

## Related repos
- Protocol: https://github.com/ospp-org/spec
- SDK: https://github.com/ospp-org/ospp-sdk-php
- CSMS: D:\Personal\Codebase\osp\csms-server
