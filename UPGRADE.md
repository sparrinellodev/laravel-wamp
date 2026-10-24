# Upgrade Guide

---

## From v2.0 to v2.1

v2.1.0 is backward compatible: no code changes are required. A few behaviors changed, though,
and you may want to review them.

### Default connection credentials

The `default` connection now reads `WAMP_AUTH_ID`, `WAMP_AUTH_TICKET` and `WAMP_AUTH_SECRET`,
so ticket and WAMP-CRA authentication can be enabled from `.env` alone. If you published
`config/wamp.php` before v2.1, add these keys to the `auth` block of your `default` connection:

```php
'auth' => [
    'method' => env('WAMP_AUTH_METHOD', 'anonymous'),
    'authid' => env('WAMP_AUTH_ID'),
    'ticket' => env('WAMP_AUTH_TICKET'),
    'secret' => env('WAMP_AUTH_SECRET'),
],
```

### Exhausted reconnection attempts

When every automatic reconnection attempt fails, `listen()` now throws a `WampClientException`,
with the original `ReconnectException` available via `getPrevious()`. If you catch
`ReconnectException` around `listen()`, catch `WampClientException` instead
(`php artisan wamp:serve` already does).

### In-flight requests on connection loss

Calls and `publishWithAck()` acknowledgements still waiting for the router when the connection
drops now fail with a `WampClientException`, instead of never completing.

### Empty tickets

An empty `ticket` (for example `WAMP_AUTH_TICKET=` left blank) is now rejected with an
`AuthenticationException` when the client is built, instead of being sent to the router.

### Optional: dynamic tickets

To authenticate with short-lived tokens such as JWTs, see
[Dynamic Ticket Authentication](README.md#dynamic-ticket-authentication-eg-jwt) in the README.

---

## From v1.x to v2.0.0

Laravel-WAMP v2.0.0 includes a breaking change regarding the naming convention of the configuration file to better adhere to Laravel best practices.

### Breaking Changes Summary

1. **Configuration File Rename:** `config/hermod.php` is now `config/wamp.php`.
2. **Publish Tag Rename:** `--tag=hermod-config` is now `--tag=wamp-config`.
3. **Helper/Config Access:** Accessing configuration via `config('hermod.*')` now uses `config('wamp.*')`.

### Migration Steps

#### Step 1: Rename or Re-publish Config File
In your Laravel application, either rename the existing configuration file:

```bash
mv config/hermod.php config/wamp.php
```

Or re-publish the new configuration file:

```bash
php artisan vendor:publish --tag=wamp-config
```

**Note:** If you choose to re-publish, remember to transfer your custom connection settings from config/hermod.php to config/wamp.php and then delete config/hermod.php.

#### Step 2: Update Code Calls (If Applicable)
If your application code explicitly reads configuration values via the config() helper:

```php
// Old (v1.x)
$host = config('hermod.connections.default.url');

// New (v2.0.0)
$host = config('wamp.connections.default.url');
```

#### Step 3: Clear Configuration Cache
Clear the configuration cache to ensure Laravel loads the new file:

```bash
php artisan config:clear
```
