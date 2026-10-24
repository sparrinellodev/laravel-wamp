<?php

namespace Hermod\LaravelWamp\Auth;

/**
 * Defines the supported WAMP authentication methods.
 */
enum AuthMethod: string
{
    /**
     * Anonymous authentication.
     * No credentials are provided. Typically used for public endpoints.
     */
    case Anonymous = 'anonymous';

    /**
     * Ticket-based authentication.
     * The ticket is sent to the router as-is: a static secret (e.g., an API key) or a
     * dynamic token such as a short-lived JWT supplied by a TicketProviderContract.
     */
    case Ticket = 'ticket';

    /**
     * Challenge-Response Authentication (WAMP-CRA).
     * Provides secure authentication using HMAC signatures without transmitting the secret.
     */
    case WampCra = 'wampcra';
}