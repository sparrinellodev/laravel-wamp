# Changelog

All relevant changes to Hermod are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/it/1.0.0/),
and the project adopts [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Dynamic ticket authentication:** `TicketAuthenticator` now also accepts a `TicketProviderContract`, which is asked for a ticket on every handshake (the first connection and every reconnection), so short-lived tokens such as JWTs can be used. Static tickets keep working unchanged through the new `StaticTicketProvider`.
- New `ticket_provider` option for ticket authentication in `config/wamp.php`: the class name of a `TicketProviderContract`, resolved from the service container. It is an alternative to `ticket` (setting both, or neither, is a configuration error), and a sample `jwt` connection shows how to use it.

### Changed

- An empty `ticket` (e.g. `WAMP_AUTH_TICKET=` left blank) is now reported as a configuration error when the client is built, instead of being sent to the router.

### Fixed

- The `default` connection in `config/wamp.php` now reads `WAMP_AUTH_ID`, `WAMP_AUTH_TICKET` and `WAMP_AUTH_SECRET`, so ticket and WAMP-CRA authentication can be enabled from `.env` alone, as the README describes. Applications that have already published `config/wamp.php` must add these keys to their `default` connection by hand.
- **Automatic reconnect:** reconnecting after a dropped connection no longer fails on every attempt. A transport failure now closes the session, and a failed handshake leaves it closed, so `hello()` can run again.
- Procedures and subscriptions — including those still awaiting the router's confirmation — are restored on the new session instead of failing with "already registered" / "already subscribed".
- In-flight RPC calls and `publishWithAck()` acknowledgements are rejected with `WampClientException` when the connection drops, instead of never completing.
- `listen()` throws `WampClientException` (with the `ReconnectException` as previous) once reconnection attempts are exhausted, and `isConnected()` returns `false` after a connection loss.
- A failure while restoring registrations or subscriptions now counts as a failed reconnection attempt instead of retrying forever.

## [2.0.0] - 2026-08-07

### Changed (Breaking Changes)

- **Configuration File Renamed:** Renamed configuration file from `config/hermod.php` to `config/wamp.php` for better naming consistency with the Laravel package ecosystem.
- **Publish Tag Updated:** The Artisan publish tag changed from `hermod-config` to `wamp-config`.
- **PHP Namespace Alignment:** Standardized namespaces across the package to `Hermod\LaravelWamp\*`.

### Added

- Migration guide (`UPGRADE.md`) for upgrading from v1.x to v2.0.0.

## [1.0.0] - 2026-04-26

### Added

- Full MessagePack Serializer via `rybakit/msgpack` (pure PHP, no C extensions):
- Serialization with `PackOptions::FORCE_STR` for WAMP compatibility
- Deserialization with `UnpackOptions::BIGINT_AS_STR` for large integers
- Subprotocol `wamp.2.msgpack`
- Full RawSocket Transport:
- `RawSocketHandshake` — 4-byte handshake handling according to WAMP spec
- `RawSocketFrame` — message framing with ping/pong support
- `RawSocketTransport` — transport over TCP (`tcp://`) and Unix socket (`unix://`)
- `RawSocketTransportFactory`
- Unified `TransportFactory` — automatically resolve WebSocket or RawSocket from the config
- `WampClientFactory` updated to use `TransportFactory`
- `WampServiceProvider` updated with `RawSocketTransportFactory` and `TransportFactory` registration
- New sample connections in `config/hermod.php`:
- `local_tcp` — RawSocket over TCP
- `local_unix` — RawSocket over Unix socket
- ​​Full unit tests for MessagePack, RawSocket, and TransportFactory

### Updated

- `composer.json` — added `rybakit/msgpack:^0.7` dependency
- `composer.json` — added `amphp/socket:^2.0` dependency
- `MsgpackSerializer` — full implementation (previously used external extension)

## [0.3.0] - 2026-04-24

### Added

- Full Auth Layer:
- `AnonymousAuthenticator` — anonymous authentication (default)
- `TicketAuthenticator` — static or dynamic token authentication
- `WampCraAuthenticator` — WAMP-CRA authentication with HMAC-SHA256 and salted PBKDF2 support
- `AuthenticatorFactory` — resolves the authenticator from the configuration
- `AuthMethod` enum — strong typing of authentication methods
- New WAMP messages:
- `CHALLENGE` (type 4) in `MessageType`
- `AUTHENTICATE` (type 5) in `MessageType`
- `MessageFactory::helloWithAuth()` — HELLO with authmethods and authid support
- `MessageFactory::authenticate()` — AUTHENTICATE message with signature
- Resilience:
- `ExponentialBackoffStrategy` — Configurable exponential backoff with a cap on the maximum delay
- `ReconnectManager` — Automatic reconnect management with procedure and subscription reset
- New contracts:
- `AuthenticatorContract`
- `ReconnectStrategyContract`
- New exceptions:
- `AuthenticationException` — Authentication errors with wampError and details
- `ReconnectException` — Exhausted reconnect attempts
- `WampSession` updated with auth sequence management: HELLO → [CHALLENGE → AUTHENTICATE] → WELCOME
- `WampClientFactory` updated with `AuthenticatorFactory` and `ReconnectManager`
- `WampClient` updated with automatic reconnect in `listen()`
- `WampServiceProvider` updated with registration AuthenticatorFactory
- Updated `config/hermod.php` with `auth`, `reconnect`, and `heartbeat` sections
- Complete unit testing for Auth and Resilience

### Fixed

- `WampSession::hello()` now uses `helloWithAuth()` for all methods
  ensuring compatibility with routers that require authmethods in the HELLO

## [0.2.0] - 2026-04-20

### Added

- Complete PubSub Layer:
- `Publisher` with `publish()` (fire and forget) and `publishWithAck()` (with confirmation) support
- `Subscriber` with registration handler and `subscribe()`/`unsubscribe()` management
- `Subscription` — object representing an active subscription
- `PendingSubscriptionRegistry` — management of subscriptions awaiting confirmation
- New contracts:
- `PublisherContract`
- `SubscriberContract`
- Laravel `WampEventReceived` event — automatically dispatched with each WAMP EVENT received
- New methods in `MessageFactory`: `publish()`, `subscribe()`, `unsubscribe()`
- `MessageDispatcher` updated to handle `PUBLISHED`, `SUBSCRIBED`, `UNSUBSCRIBED`, `EVENT`
- `WampClient` and `WampClientFactory` updated with The PubSub Layer
- `WampClientContract` now extends `PublisherContract` and `SubscriberContract`
- `Wamp` facade updated with new PubSub methods
- Comprehensive unit testing for all PubSub components

### Fixed

- `MessageFactory` — the `options`, `details`, and `kwargs` fields now correctly serialize
  as `{}` instead of `[]` when empty (critical fix for WAMP compatibility)
- `WebSocketTransport::buildHandshake()` — the subprotocol is now correctly passed
  as the `Sec-WebSocket-Protocol` HTTP header
- `WampSession::goodbye()` — correctly handles the case where
  the transport is already closed before the call
- `WampClient::listen()` — correctly distinguishes between clean router
  closure (GOODBYE) and connection loss
- `WampClient::call()` and `callAsync()` — internally start the message reception loop
  to resolve the Future even outside of an active AMPHP event loop

## [0.1.0] - 2026-04-18

### Added

- Core implementation of the WAMP v2 protocol
- Transport Layer via WebSocket (amphp/websocket-client)
- Session Layer with HELLO/WELCOME/ABORT/GOODBYE management
- Full RPC Layer:
- Caller with synchronous and asynchronous calls (Future)
- Callee with registration and INVOCATION management
- PendingCallRegistry for request/response correlation
- MessageDispatcher for message dispatching
- Serializers:
- JSON (default, ready to use)
- CBOR (via spomky-labs/cbor-php)
- MessagePack (ready-made structure, requires ext-msgpack)
- Laravel 12/13 integration:
- WampServiceProvider with auto-discovery
- `Wamp` facade
- Artisan `wamp:serve` command
- Artisan `wamp:call` command
- Event `WampServeStarted`
- Complete unit testing with Pest
- Static analysis with PHPStan (level 6)
