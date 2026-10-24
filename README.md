# Laravel-WAMP — WAMP Client for Laravel

[![Latest Version](https://img.shields.io/packagist/v/hermod/laravel-wamp.svg)](https://packagist.org/packages/hermod/laravel-wamp)
[![PHP Version](https://img.shields.io/packagist/php-v/hermod/laravel-wamp.svg)](https://packagist.org/packages/hermod/laravel-wamp)
[![License](https://img.shields.io/packagist/l/hermod/laravel-wamp.svg)](LICENSE.md)

Laravel-WAMP is a modern WAMP v2 client for Laravel 12+, built on AMPHP v3
and PHP Fibers. Born as an actively maintained alternative to Thruway,
it supports RPC (Caller/Callee) and PubSub with JSON serialization, MessagePack, and CBOR.

> **In Norse mythology, Hermod is the messenger of the gods —
> the one who carries messages between realms.**

---

## Requirements

- PHP 8.2+
- Laravel 12.x+
- A WAMP v2 router (e.g., [Crossbar.io](https://crossbar.io))

---

## Installation

```bash
composer require hermod/laravel-wamp
```

Publish the configuration:

```bash
php artisan vendor:publish --tag=wamp-config
```

---

## Configuration

Add the variables to your `.env`:

```env
# Connection
WAMP_URL=ws://localhost:8080/ws
WAMP_REALM=realm1
WAMP_SERIALIZER=json

# Authentication (anonymous by default)
WAMP_AUTH_METHOD=anonymous

# Automatic reconnect
WAMP_RECONNECT=true
WAMP_RECONNECT_MAX=5
WAMP_RECONNECT_DELAY=1
WAMP_RECONNECT_MAX_DELAY=30
WAMP_RECONNECT_MULTIPLIER=2

# Heartbeat
WAMP_HEARTBEAT=true
WAMP_HEARTBEAT_INTERVAL=30
```

### Ticket Authentication

```env
WAMP_AUTH_METHOD=ticket
WAMP_AUTH_ID=myuser
WAMP_AUTH_TICKET=my-secret-token
```

### Dynamic Ticket Authentication (e.g., JWT)

When the ticket is a short-lived token, point the connection to a class that supplies it,
instead of a static `ticket`:

```php
// config/wamp.php
'auth' => [
    'method'          => 'ticket',
    'authid'          => env('WAMP_AUTH_ID'),
    'ticket_provider' => App\Wamp\JwtTicketProvider::class,
],
```

```php
namespace App\Wamp;

use Hermod\LaravelWamp\Contracts\TicketProviderContract;
use Illuminate\Support\Facades\Http;

class JwtTicketProvider implements TicketProviderContract
{
    public function ticket(?string $authId, array $extra = []): string
    {
        // e.g. OAuth2 client credentials against your identity provider
        return Http::asForm()
            ->post(config('services.wamp.token_url'), [
                'grant_type'    => 'client_credentials',
                'client_id'     => config('services.wamp.client_id'),
                'client_secret' => config('services.wamp.client_secret'),
            ])
            ->throw()
            ->json('access_token');
    }
}
```

- `ticket` and `ticket_provider` are mutually exclusive.
- The provider is resolved from the service container, so constructor injection works.
- It is called on **every handshake**: the first connection and every automatic reconnection,
  so a reconnect after the token has expired gets a fresh one.
- Laravel-WAMP does not cache tickets. If obtaining one is expensive (e.g. you call
  `Wamp::connect()` on every HTTP request), cache it in the provider with a TTL shorter
  than the token's `exp`.
- The router checks the ticket only when the session opens: an established session is not
  closed when the token expires.
- If the provider throws or returns an empty string, the handshake fails with an
  `AuthenticationException` (the original exception is available via `getPrevious()`);
  during automatic reconnection the attempt is retried with backoff.
- The provider runs synchronously during the handshake, so a slow token endpoint delays
  the connection.
- Use a class name, not a closure: closures cannot be stored by `php artisan config:cache`.

### Authentication with WAMP-CRA

```env
WAMP_AUTH_METHOD=wampcra
WAMP_AUTH_ID=myuser
WAMP_AUTH_SECRET=my-secret
```

### Multiple connections in `config/wamp.php`

```php
'connections' => [

    'default' => [
        'transport'  => 'websocket',
        'url'        => env('WAMP_URL', 'ws://localhost:8080/ws'),
        'realm'      => 'realm1',
        'serializer' => 'json',
        'auth'       => ['method' => 'anonymous'],
        'reconnect'  => ['enabled' => true, 'max_attempts' => 5],
        'heartbeat'  => ['enabled' => true, 'interval' => 30],
    ],

    'local_tcp' => [
        'transport'  => 'rawsocket',
        'url'        => 'tcp://localhost:8081',
        'realm'      => 'realm1',
        'serializer' => 'msgpack',
        'auth'       => ['method' => 'anonymous'],
        'reconnect'  => ['enabled' => true, 'max_attempts' => 5],
        'heartbeat'  => ['enabled' => true, 'interval' => 30],
    ],

    'local_unix' => [
        'transport'  => 'rawsocket',
        'url'        => 'unix:///var/run/crossbar/router.sock',
        'realm'      => 'realm1',
        'serializer' => 'cbor',
        'auth'       => ['method' => 'anonymous'],
        'reconnect'  => ['enabled' => true, 'max_attempts' => 5],
        'heartbeat'  => ['enabled' => true, 'interval' => 30],
    ],

    'secure' => [
        'transport'  => 'websocket',
        'url'        => env('WAMP_SECURE_URL', 'wss://router.example.com/ws'),
        'realm'      => 'secure_realm',
        'serializer' => 'cbor',
        'auth'       => [
            'method' => 'wampcra',
            'authid' => env('WAMP_AUTH_ID'),
            'secret' => env('WAMP_AUTH_SECRET'),
        ],
        'reconnect'  => ['enabled' => true, 'max_attempts' => 10],
        'heartbeat'  => ['enabled' => true, 'interval' => 20],
    ],

],
```

---

## Usage Examples

### RPC — Procedure Registration (Callee)

#### Without Authentication (Anonymous)

In your `AppServiceProvider`:

```php
use Hermod\LaravelWamp\Laravel\Events\WampServeStarted;
use Illuminate\Support\Facades\Event;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Event::listen(WampServeStarted::class, function (WampServeStarted $event) {

            // Simple procedure — add two numbers
            $event->client->register('com.myapp.somma', function (array $args): int {
                return $args[0] + $args[1];
            });

            // Procedure with kwargs — personalized greeting
            $event->client->register('com.myapp.saluta', function (array $args, array $kwargs): string {
                $nome  = $kwargs['nome']  ?? 'Utente';
                $titolo = $kwargs['titolo'] ?? '';
                return "Ciao, {$titolo} {$nome}!";
            });

            // Procedure with access to the Laravel DB
            $event->client->register('com.myapp.utente.trova', function (array $args): array {
                $user = \App\Models\User::find($args[0]);
                return $user?->toArray() ?? [];
            });

            // Procedure with error handling
            $event->client->register('com.myapp.divisione', function (array $args): float {
                if ($args[1] === 0) {
                    throw new \InvalidArgumentException('Divisione per zero non consentita.');
                }
                return $args[0] / $args[1];
            });

        });
    }
}
```

Start the worker:

```bash
php artisan wamp:serve
```

#### With Ticket Authentication

```env
WAMP_AUTH_METHOD=ticket
WAMP_AUTH_ID=backend-service
WAMP_AUTH_TICKET=supersecrettoken123
```

The `AppServiceProvider` remains identical — authentication is transparent.

```bash
php artisan wamp:serve
# The client will automatically authenticate with the configured ticket
```

#### With WAMP-CRA authentication

```env
WAMP_AUTH_METHOD=wampcra
WAMP_AUTH_ID=backend-service
WAMP_AUTH_SECRET=my-hmac-secret
```

```bash
php artisan wamp:serve
# The client will automatically calculate the HMAC-SHA256 signature
```

#### With specific connection

```bash
php artisan wamp:serve --connection=secure
```

---

### RPC — Procedure Call (Caller)

#### Synchronous call

```php
use Hermod\LaravelWamp\Laravel\Facades\Wamp;

// In a controller, job, command, etc.
Wamp::connect();

// Call with positional arguments
$somma = Wamp::call('com.myapp.somma', [3, 5]);
// → 8

// Call with kwargs
$saluto = Wamp::call('com.myapp.saluta', [], ['nome' => 'Mario', 'titolo' => 'Dr.']);
// → "Ciao, Dr. Mario!"

// Call with error handling
try {
    $risultato = Wamp::call('com.myapp.divisione', [10, 0]);
} catch (\Hermod\LaravelWamp\RpcException $e) {
    Log::error("Errore RPC: {$e->getMessage()}", ['wamp_error' => $e->wampError]);
}

Wamp::disconnect();
```

#### Asynchronous call

```php
use Hermod\LaravelWamp\Laravel\Facades\Wamp;
use function Amp\Future\await;

Wamp::connect();

// Single asynchronous call
$future = Wamp::callAsync('com.myapp.somma', [10, 20]);
$result = $future->await();
// → 30

// Parallel calls — executed simultaneously
$futures = [
    'somma'   => Wamp::callAsync('com.myapp.somma',    [1, 2]),
    'saluto'  => Wamp::callAsync('com.myapp.saluta',   [], ['nome' => 'Mario']),
    'utente'  => Wamp::callAsync('com.myapp.utente.trova', [42]),
];

$risultati = await($futures);
// $risultati['somma']  → 3
// $risultati['saluto'] → "Ciao, Mario!"
// $risultati['utente'] → ['id' => 42, 'name' => '...']

Wamp::disconnect();
```

#### In a Laravel Route

```php
use Hermod\LaravelWamp\Laravel\Facades\Wamp;
use Illuminate\Support\Facades\Route;

Route::get('/calcola/{a}/{b}', function (int $a, int $b) {
    Wamp::connect();
    $result = Wamp::call('com.myapp.somma', [$a, $b]);
    Wamp::disconnect();
    return response()->json(['risultato' => $result]);
});

Route::get('/utente/{id}', function (int $id) {
    Wamp::connect();
    $utente = Wamp::call('com.myapp.utente.trova', [$id]);
    Wamp::disconnect();

    if (empty($utente)) {
        return response()->json(['error' => 'Utente non trovato'], 404);
    }

    return response()->json($utente);
});
```

#### From terminal (debug)

```bash
# Call with positional arguments
php artisan wamp:call com.myapp.somma 3 5

# Calling with kwargs in JSON
php artisan wamp:call com.myapp.saluta --kwargs='{"nome":"Mario","titolo":"Dr."}'

# Call on specific connection
php artisan wamp:call com.myapp.somma 10 20 --connection=secure

# Output atteso:
# ✓ Risultato (12.5ms):
# 30
```

---

### PubSub — Publisher

#### Fire and Forget Publication

```php
use Hermod\LaravelWamp\Laravel\Facades\Wamp;

Wamp::connect();

// Positional array → args
Wamp::publish('com.myapp.notifiche', ['messaggio importante']);

// Associative array → kwargs automatically
Wamp::publish('com.myapp.ordini', ['ordine_id' => 123, 'stato' => 'spedito']);

// Args and kwargs combined
Wamp::publish('com.myapp.eventi', [1, 2, 3], ['extra' => 'info']);

Wamp::disconnect();
```

#### Publication with acknowledgement

```php
use Hermod\LaravelWamp\Laravel\Facades\Wamp;

Wamp::connect();

// Waiting for PUBLISHED confirmation from the router
$publicationId = Wamp::publishWithAck(
    'com.myapp.notifiche',
    ['messaggio' => 'Ordine confermato!']
)->await();

Log::info("Messaggio pubblicato", ['publication_id' => $publicationId]);

Wamp::disconnect();
```

#### From a Laravel Job

```php
namespace App\Jobs;

use Hermod\LaravelWamp\Laravel\Facades\Wamp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotificaOrdineSpe­dito implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int    $ordineId,
        private readonly string $corriere,
    ) {}

    public function handle(): void
    {
        Wamp::connect();

        Wamp::publish('com.myapp.ordini.spediti', [], [
            'ordine_id' => $this->ordineId,
            'corriere'  => $this->corriere,
            'timestamp' => now()->toISOString(),
        ]);

        Wamp::disconnect();
    }
}
```

#### Da un Observer Eloquent

```php
namespace App\Observers;

use App\Models\Ordine;
use Hermod\LaravelWamp\Laravel\Facades\Wamp;

class OrdineObserver
{
    public function updated(Ordine $ordine): void
    {
        if ($ordine->isDirty('stato')) {
            Wamp::connect();

            Wamp::publish('com.myapp.ordini.aggiornati', [], [
                'ordine_id'  => $ordine->id,
                'stato_nuovo' => $ordine->stato,
                'stato_vecchio' => $ordine->getOriginal('stato'),
            ]);

            Wamp::disconnect();
        }
    }
}
```

---

### PubSub — Subscriber

#### Subscribing to a topic

In your `AppServiceProvider`:

```php
use Hermod\LaravelWamp\Laravel\Events\WampServeStarted;

Event::listen(WampServeStarted::class, function (WampServeStarted $event) {

    // Simple subscription
    $event->client->subscribe('com.myapp.notifiche', function (array $args, array $kwargs): void {
        Log::info('Notifica ricevuta', [
            'args'   => $args,
            'kwargs' => $kwargs,
        ]);
    });

    // Subscription with business logic
    $event->client->subscribe('com.myapp.ordini.spediti', function (array $args, array $kwargs): void {
        $ordineId = $kwargs['ordine_id'] ?? null;
        $corriere = $kwargs['corriere']  ?? 'sconosciuto';

        if ($ordineId) {
            \App\Models\Ordine::find($ordineId)?->update([
                'notifica_spedizione_inviata' => true,
            ]);

            Log::info("Ordine {$ordineId} spedito con {$corriere}");
        }
    });

    // Dynamic subscription and unsubscription
    $subscription = $event->client->subscribe('com.myapp.temporaneo', function (array $args): void {
        Log::debug('Evento temporaneo ricevuto', $args);
    });

    // You can unsubscribe later
    // $event->client->unsubscribeById($subscription);

});
```

Start the worker subscriber:

```bash
php artisan wamp:serve
```

#### Automatic Laravel Event

Every WAMP `EVENT` received automatically dispatches a Laravel event,
without having to register an explicit handler:

```php
use Hermod\LaravelWamp\Laravel\Events\WampEventReceived;

// In EventServiceProvider or AppServiceProvider
Event::listen(WampEventReceived::class, function (WampEventReceived $event): void {

    // Filter by topic
    match ($event->topic) {
        'com.myapp.ordini.spediti' => dispatch(new \App\Jobs\ProcessaSpedizione(
            ordineId: $event->kwargs['ordine_id'],
        )),
        'com.myapp.notifiche' => \App\Models\Notifica::create([
            'contenuto' => $event->args[0] ?? '',
            'ricevuta_at' => now(),
        ]),
        default => Log::debug("Evento WAMP non gestito: {$event->topic}"),
    };

});
```

---

### Combining RPC and PubSub in the same worker

```php
use Hermod\LaravelWamp\Laravel\Events\WampServeStarted;

Event::listen(WampServeStarted::class, function (WampServeStarted $event) {

    // ── RPC Procedures ────────────────────────────────────
    $event->client->register('com.myapp.utente.crea', function (array $args, array $kwargs): array {
        $user = \App\Models\User::create([
            'name'  => $kwargs['nome'],
            'email' => $kwargs['email'],
        ]);

        // Publish event after user creation
        $event->client->publish('com.myapp.utenti.creati', [], [
            'user_id' => $user->id,
            'email'   => $user->email,
        ]);

        return $user->toArray();
    });

    $event->client->register('com.myapp.statistiche', function (): array {
        return [
            'utenti_totali' => \App\Models\User::count(),
            'ordini_attivi' => \App\Models\Ordine::where('stato', 'attivo')->count(),
        ];
    });

    // ── PubSub Subscriptions ──────────────────────────────
    $event->client->subscribe('com.myapp.cache.invalida', function (array $args, array $kwargs): void {
        $chiave = $kwargs['chiave'] ?? '*';
        \Illuminate\Support\Facades\Cache::forget($chiave);
        Log::info("Cache invalidata per chiave: {$chiave}");
    });

    $event->client->subscribe('com.myapp.broadcast', function (array $args, array $kwargs): void {
        // Forward the WAMP event as a Laravel broadcast event
        \Illuminate\Support\Facades\Broadcast::on('general')
            ->event(new \App\Events\WampBroadcast($kwargs))
            ->sendNow();
    });

});
```

---

### Automatic Reconnect

Reconnect is configured in `config/wamp.php` and works transparently
during `listen()`. When the connection drops, Laravel-WAMP:

1. Detects the disconnection in the listening loop and closes the session
2. Rejects any in-flight call or `publishWithAck()` with a `WampClientException`
3. Waits for the initial delay (default: 1 second)
4. Attempts to reconnect
5. If it fails, it applies exponential backoff (1s → 2s → 4s → 8s → 16s → max 30s)
6. After a successful reconnect, it automatically re-registers all procedures and subscriptions,
   including those still awaiting the router's confirmation when the connection dropped

If every attempt fails, `listen()` throws a `WampClientException` (the underlying
`ReconnectException` is available via `getPrevious()`), so `php artisan wamp:serve` exits
with an error and your process manager (e.g. Supervisor) can restart it.

```php
// Custom configuration for critical environment
// config/wamp.php
'reconnect' => [
    'enabled'      => true,
    'max_attempts' => 10,      // maximum 10 attempts
    'base_delay'   => 0.5,     // starts from 0.5 seconds
    'max_delay'    => 60.0,    // 60-second cap
    'multiplier'   => 2.0,     // doubles with every failure
],
```

---

## Artisan Commands

```bash
# Start a WAMP worker (Callee + Subscriber)
php artisan wamp:serve
php artisan wamp:serve --connection=secure
php artisan wamp:serve --realm=myrealm
php artisan wamp:serve --serializer=cbor

# Make an RPC call from the terminal
php artisan wamp:call com.myapp.somma 3 5
php artisan wamp:call com.myapp.saluta --kwargs='{"nome":"Mario"}'
php artisan wamp:call com.myapp.somma 10 20 --connection=secure
```

---

## Serializers

| Driver    | Subprotocol      | Stato      | Note                              | .env                    |
| --------- | ---------------- | ---------- | --------------------------------- | ----------------------- |
| `json`    | `wamp.2.json`    | ✅ Stabile | Default, nessuna dipendenza extra | WAMP_SERIALIZER=json    |
| `cbor`    | `wamp.2.cbor`    | ✅ Stabile | Richiede `spomky-labs/cbor-php`   | WAMP_SERIALIZER=cbor    |
| `msgpack` | `wamp.2.msgpack` | ✅ Stabile | Richiede `rybakit/msgpack`        | WAMP_SERIALIZER=msgpack |

---

## Authentication

| Method      | Use case                           | Required configuration                  |
| ----------- | ---------------------------------- | --------------------------------------- |
| `anonymous` | Local development, closed networks | none                                    |
| `ticket`    | API key, static or dynamic JWT     | `authid`, `ticket` or `ticket_provider` |
| `wampcra`   | Secure authentication with HMAC    | `authid`, `secret`                      |

---

## Roadmap

| Version  | Content                                           |
| -------- | ------------------------------------------------- |
| **v0.1** | RPC Core — Caller, Callee, JSON, CBOR ✅          |
| **v0.2** | PubSub — Publisher, Subscriber ✅                 |
| **v0.3** | Auth — WAMP-CRA, Ticket, Reconnect ✅             |
| **v1.0** | MessagePack, RawSocket, Complete documentation ✅ |

---

## Testing

```bash
# All tests
./vendor/bin/pest

# With coverage
./vendor/bin/pest --coverage --min=80

# For specific suite
./vendor/bin/pest --filter=Auth
./vendor/bin/pest --filter=Reconnect
./vendor/bin/pest --filter=PubSub
./vendor/bin/pest --filter=Rpc
./vendor/bin/pest --filter=RawSocket
./vendor/bin/pest --filter=Msgpack
```

---

## Contribute

Read [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

---

## License

Laravel-WAMP is open source software released under the [MIT] License (LICENSE.md).

```

```
