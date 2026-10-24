<?php

namespace Hermod\LaravelWamp\Auth;

use Hermod\LaravelWamp\Contracts\AuthenticatorContract;
use Hermod\LaravelWamp\Contracts\TicketProviderContract;
use Hermod\LaravelWamp\Exceptions\AuthenticationException;
use SensitiveParameter;
use Throwable;

/**
 * Handles ticket-based authentication for the WAMP connection.
 *
 * The ticket is either a static pre-shared secret (an API key or a long-lived token)
 * or supplied by a TicketProviderContract, which is asked for a fresh ticket on every
 * handshake (e.g. a short-lived JWT). It is sent to the router as the signature of
 * the AUTHENTICATE message.
 */
class TicketAuthenticator implements AuthenticatorContract
{
    /** @var TicketProviderContract The source of the ticket sent on each handshake. */
    private readonly TicketProviderContract $tickets;

    /**
     * Create a new Ticket Authenticator instance.
     *
     * @param  string  $authId  The authentication ID (e.g., username or client ID).
     * @param  string|TicketProviderContract  $ticket  A static ticket, or a provider asked for a ticket on every handshake.
     */
    public function __construct(
        private readonly string $authId,
        #[SensitiveParameter]
        string|TicketProviderContract $ticket,
    ) {
        $this->tickets = is_string($ticket) ? new StaticTicketProvider($ticket) : $ticket;
    }

    /**
     * Get the WAMP authentication method type.
     *
     * @return \Hermod\LaravelWamp\Auth\AuthMethod
     */
    public function method(): AuthMethod
    {
        return AuthMethod::Ticket;
    }

    /**
     * Get the authentication ID (authid) for the connection.
     *
     * @return string|null
     */
    public function authId(): ?string
    {
        return $this->authId;
    }

    /**
     * Get extra authentication parameters to be sent during the HELLO message.
     *
     * @return array<string, mixed>
     */
    public function authExtra(): array
    {
        return [];
    }

    /**
     * Handle the authentication challenge issued by the WAMP router.
     *
     * The ticket is requested from the provider on every call, so a dynamic provider
     * can return a fresh token for each handshake. The ticket itself never appears in
     * exception messages.
     *
     * @param  string  $challenge  The challenge string provided by the router (usually empty for ticket auth).
     * @param  array<string, mixed>  $extra  Additional challenge details.
     * @return string|null The ticket used as the signature.
     *
     * @throws AuthenticationException If the provider fails or returns an empty ticket.
     */
    public function handleChallenge(string $challenge, array $extra = []): ?string
    {
        $provider = $this->tickets::class;

        try {
            $ticket = $this->tickets->ticket($this->authId, $extra);
        } catch (Throwable $e) {
            // The provider's own message may contain secrets: keep it only as the previous exception
            throw new AuthenticationException(
                "Ticket authentication failed: {$provider} could not supply a ticket.",
                previous: $e,
            );
        }

        if ($ticket === '') {
            throw new AuthenticationException(
                "Ticket authentication failed: {$provider} supplied an empty ticket.",
            );
        }

        return $ticket;
    }

    /**
     * Determine if this authentication method requires a challenge-response sequence.
     *
     * @return bool
     */
    public function requiresChallenge(): bool
    {
        return true;
    }
}
