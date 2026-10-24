# CLAUDE.md

`hermod/laravel-wamp` — a WAMP v2 client library for Laravel 12+, built on AMPHP v3 (Fibers). Composer package, not an application: there is no `artisan`, no routes, no database. Namespace root: `Hermod\LaravelWamp\` → `src/`.

## Commands

```bash
./vendor/bin/pest --testsuite=Unit            # run tests (see note below)
./vendor/bin/pest --testsuite=Unit --filter=Callee
./vendor/bin/phpstan analyse --memory-limit=1G   # level 6 + Larastan, src/ only
./vendor/bin/pint --test <file>               # style check (preset: laravel)
```

Plain `./vendor/bin/pest` currently runs **zero tests and exits 2**: `phpunit.xml` and `tests/Pest.php` reference `tests/Integration/`, which does not exist. Always pass `--testsuite=Unit` until that directory is created or the reference removed.

## Architecture

Layered; each layer depends only on the one below via `src/Contracts/*Contract`:

```
Transport (WebSocket | RawSocket tcp:// unix://)
  └─ Serializer (json | msgpack | cbor)  → negotiated as subprotocol wamp.2.<name>
      └─ WampSession  — HELLO → [CHALLENGE → AUTHENTICATE] → WELCOME, GOODBYE; SessionState enum
          ├─ Rpc:    Caller (+PendingCallRegistry), Callee (Registration)
          ├─ PubSub: Publisher, Subscriber (+PendingSubscriptionRegistry, Subscription)
          └─ MessageDispatcher — routes inbound WampMessage by MessageType
WampClient — façade over all of the above + ReconnectManager
```

- **Wiring** happens in two places: `Client/WampClientFactory::make(array $config)` builds the whole object graph per connection; `Laravel/WampServiceProvider` registers the factories as singletons and binds `WampClient` (alias `wamp.client`, used by the `Wamp` facade) from `config('wamp.default')`.
- **No background event loop.** The client is pull-based: `call()`, `callAsync()` and `publishWithAck()` loop on `$session->receive()` → `$dispatcher->dispatch()` until their `Future` completes. `listen()` is the long-running loop used by `wamp:serve`. Any new request/acknowledge flow must pump messages the same way, or its Future never resolves.
- **Errors**: `MessageDispatcher::dispatchError()` routes `ERROR` by the original request type (`$message->get(1)`). Router-initiated `GOODBYE` throws `WampProtocolException`, which `listen()` treats as a clean exit; `TransportException` triggers reconnect.
- **Reconnect** (`ReconnectManager` + `ExponentialBackoffStrategy`) re-runs `hello()` and then re-registers every procedure and re-subscribes every topic captured before the drop.
- **Laravel integration**: `wamp:serve` dispatches `WampServeStarted` so apps register handlers in a listener; every inbound `EVENT` also dispatches `WampEventReceived`. `wamp:call` is a CLI debugging aid.
- **Auth**: `AuthMethod` enum (`anonymous`, `ticket`, `wampcra`) → `AuthenticatorFactory` → `AuthenticatorContract`. WAMP-CRA supports salted PBKDF2 keys.

## WAMP wire-format rules (easy to break)

- Empty `options` / `details` / `kwargs` dicts must be `(object) []`, never `[]` — PHP empty arrays serialize as JSON `[]`, which routers reject. `MessageFactory` does this everywhere; keep it that way.
- Session/request IDs are up to 2^53. `CborSerializer` falls back to `UnsignedBigIntegerTag` / `NegativeBigIntegerTag` above 32 bits; `MsgpackSerializer` packs with `PackOptions::FORCE_STR` and unpacks with `UnpackOptions::BIGINT_AS_STR`. Any serializer change needs a 64-bit round-trip test.
- `stdClass` must be handled explicitly by every serializer (maps), not only associative arrays.

## Conventions

- **English everywhere**: PHPDoc, inline comments, exception messages, and Pest `describe`/`it` descriptions. Many existing tests still use Italian descriptions and assert Italian message fragments — migrate them to English when you touch them. This supersedes the "comments in Italian" line in `CONTRIBUTING.md`.
- PHP 8.2+. Constructor property promotion with `private readonly`; typed params/returns/properties always; enums for closed sets; named arguments when wiring factories.
- Files do **not** declare `strict_types` (despite `CONTRIBUTING.md`). Don't add it piecemeal; that is a codebase-wide decision.
- PHPDoc on every class and public method, with array shapes (`array<string, mixed>`) — PHPStan level 6 requires iterable value types. Long classes group methods under `// ----` banner comments; follow the existing layout.
- Exceptions live in `src/Exceptions/`, one per domain, extending `\RuntimeException`; protocol-level ones carry a `wampError` URI. Throw the domain exception, not a generic one.
- Tests: Pest `describe()` / `it()` syntax, Mockery for doubles (`afterEach(fn () => Mockery::close())`), mirroring `src/` under `tests/Unit/`. Tests needing the container use `uses(TestCase::class)` (Orchestra Testbench). Tests assert on exception message substrings, so changing a message means updating its test.
- Config file is `config/wamp.php` (key `wamp.*`, publish tag `wamp-config`) since v2.0.0 — never reintroduce `hermod.*`.
- Every user-facing change gets an entry in `CHANGELOG.md` (Keep a Changelog, SemVer). Commits follow Conventional Commits (`feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `chore:`).

## Known state (as of 2026-10-10 — remove items once fixed)

- `pint --test` flags ~65 files. Write new code Pint-clean, but don't reformat untouched files inside a feature/fix change; a formatting-only commit is planned separately.
- PHPStan reports 17 errors (mostly missing iterable types in the `Wamp` facade `@method` tags; `CborSerializer.php:279` calls a non-existent `CBORObject::normalize()`; an unused `$maxMessageSize` in `RawSocketTransport`; the `ignoreErrors` pattern in `phpstan.neon` no longer matches).
- `composer.json` `extra.laravel.aliases.Wamp` points to `Hermod\Laravel\Facades\Wamp`; the real class is `Hermod\LaravelWamp\Laravel\Facades\Wamp`.
- The `heartbeat` config section is not read anywhere in `src/`.
- `amphp/socket` is used directly by `RawSocketTransport` but only arrives transitively; `suggest.ext-msgpack` is obsolete (msgpack is pure PHP via `rybakit/msgpack`).
- `tests/Feature/ExampleTest.php` and `tests/Unit/ExampleTest.php` are scaffolding leftovers; the Feature one is not in any suite.
- No CI workflow exists in `.github/`.
