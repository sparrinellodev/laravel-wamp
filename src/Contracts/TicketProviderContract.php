<?php

namespace Hermod\LaravelWamp\Contracts;

/**
 * Supplies the ticket sent to the router during ticket-based authentication.
 *
 * Implement this contract to authenticate with a dynamic token, such as a short-lived
 * JWT obtained from an identity provider. The provider is asked for a ticket on every
 * handshake — the first connection and every automatic reconnection — so it can always
 * return a valid token. Caching, if needed, is up to the implementation.
 */
interface TicketProviderContract
{
    /**
     * Return the ticket to send in the AUTHENTICATE message.
     *
     * @param  string|null  $authId  The authentication ID announced in the HELLO message.
     * @param  array<string, mixed>  $extra  The extra details sent by the router in the CHALLENGE message.
     * @return string The ticket (e.g. a JWT). Must not be empty.
     */
    public function ticket(?string $authId, array $extra = []): string;
}
