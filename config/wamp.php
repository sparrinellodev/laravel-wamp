<?php

use Hermod\LaravelWamp\Serializer\CborSerializer;
use Hermod\LaravelWamp\Serializer\JsonSerializer;
use Hermod\LaravelWamp\Serializer\MsgpackSerializer;

return [

    /*
    |--------------------------------------------------------------------------
    | Default WAMP Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the WAMP connections below you wish
    | to use as your default connection for all WAMP operations. Of
    | course, you may use multiple connections at once if needed.
    |
    */

    'default' => env('WAMP_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | WAMP Connections
    |--------------------------------------------------------------------------
    |
    | Here are each of the WAMP connections setup for your application.
    | You can configure multiple environments, routers, or authentication
    | mechanisms and switch between them dynamically.
    |
    */

    'connections' => [

        'default' => [
            // Transport protocol ('websocket' or 'rawsocket')
            'transport' => 'websocket',

            // The URI of the WAMP router
            'url' => env('WAMP_URL', 'ws://localhost:8080/ws'),

            // The routing realm to connect to
            'realm' => env('WAMP_REALM', 'realm1'),

            // The serialization format ('json', 'msgpack', 'cbor')
            'serializer' => env('WAMP_SERIALIZER', 'json'),

            /*
             * Authentication Configuration
             * 'anonymous' is the default and requires no additional parameters.
             * 'ticket' uses authid + ticket, 'wampcra' uses authid + secret;
             * each method ignores the keys it does not need.
             */
            'auth' => [
                'method' => env('WAMP_AUTH_METHOD', 'anonymous'),
                'authid' => env('WAMP_AUTH_ID'),
                'ticket' => env('WAMP_AUTH_TICKET'),
                'secret' => env('WAMP_AUTH_SECRET'),
            ],

            /*
             * Resilience / Auto-Reconnect
             * Handles connection drops by applying an exponential backoff strategy.
             */
            'reconnect' => [
                'enabled' => env('WAMP_RECONNECT', true),
                'max_attempts' => env('WAMP_RECONNECT_MAX', 5),
                'base_delay' => env('WAMP_RECONNECT_DELAY', 1.0),   // In seconds
                'max_delay' => env('WAMP_RECONNECT_MAX_DELAY', 30.0), // In seconds
                'multiplier' => env('WAMP_RECONNECT_MULTIPLIER', 2.0),
            ],

            /*
             * Heartbeat / Keep-Alive
             * Sends periodic pings to keep the connection active.
             */
            'heartbeat' => [
                'enabled' => env('WAMP_HEARTBEAT', true),
                'interval' => env('WAMP_HEARTBEAT_INTERVAL', 30), // In seconds
            ],
        ],

        // Example connection using Ticket authentication (e.g., API keys, static JWT)
        'ticket' => [
            'transport' => 'websocket',
            'url' => env('WAMP_URL', 'ws://localhost:8080/ws'),
            'realm' => env('WAMP_REALM', 'realm1'),
            'serializer' => env('WAMP_SERIALIZER', 'json'),
            'auth' => [
                'method' => 'ticket',
                'authid' => env('WAMP_AUTH_ID'),
                'ticket' => env('WAMP_AUTH_TICKET'),
            ],
            'reconnect' => [
                'enabled' => true,
                'max_attempts' => 5,
                'base_delay' => 1.0,
                'max_delay' => 30.0,
                'multiplier' => 2.0,
            ],
            'heartbeat' => [
                'enabled' => true,
                'interval' => 30,
            ],
        ],

        // Example connection using a dynamic ticket (e.g., a short-lived JWT).
        // 'ticket_provider' is a class of your application implementing
        // Hermod\LaravelWamp\Contracts\TicketProviderContract: it is resolved from
        // the service container and asked for a ticket on every handshake.
        'jwt' => [
            'transport' => 'websocket',
            'url' => env('WAMP_URL', 'ws://localhost:8080/ws'),
            'realm' => env('WAMP_REALM', 'realm1'),
            'serializer' => env('WAMP_SERIALIZER', 'json'),
            'auth' => [
                'method' => 'ticket',
                'authid' => env('WAMP_AUTH_ID'),
                'ticket_provider' => 'App\Wamp\JwtTicketProvider',
            ],
            'reconnect' => [
                'enabled' => true,
                'max_attempts' => 5,
                'base_delay' => 1.0,
                'max_delay' => 30.0,
                'multiplier' => 2.0,
            ],
            'heartbeat' => [
                'enabled' => true,
                'interval' => 30,
            ],
        ],

        // Example connection using WAMP-CRA (Challenge-Response Authentication)
        'cra' => [
            'transport' => 'websocket',
            'url' => env('WAMP_URL', 'ws://localhost:8080/ws'),
            'realm' => env('WAMP_REALM', 'realm1'),
            'serializer' => env('WAMP_SERIALIZER', 'json'),
            'auth' => [
                'method' => 'wampcra',
                'authid' => env('WAMP_AUTH_ID'),
                'secret' => env('WAMP_AUTH_SECRET'),
            ],
            'reconnect' => [
                'enabled' => true,
                'max_attempts' => 5,
                'base_delay' => 1.0,
                'max_delay' => 30.0,
                'multiplier' => 2.0,
            ],
            'heartbeat' => [
                'enabled' => true,
                'interval' => 30,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | WAMP Serializers
    |--------------------------------------------------------------------------
    |
    | Here you may map the serializer name to its corresponding implementation.
    | Make sure the required dependencies are installed if you use
    | MessagePack (rybakit/msgpack) or CBOR (spomky-labs/cbor-php).
    |
    */

    'serializers' => [
        'json' => JsonSerializer::class,
        'msgpack' => MsgpackSerializer::class,
        'cbor' => CborSerializer::class,
    ],

];