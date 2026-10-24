<?php

namespace Hermod\LaravelWamp\Auth;

use Hermod\LaravelWamp\Contracts\TicketProviderContract;
use SensitiveParameter;

/**
 * Ticket provider that always returns the same pre-shared ticket.
 *
 * Backs the static `ticket` configuration, such as an API key or a long-lived token.
 */
class StaticTicketProvider implements TicketProviderContract
{
    /**
     * Create a new StaticTicketProvider instance.
     *
     * @param  string  $ticket  The pre-shared ticket.
     */
    public function __construct(
        #[SensitiveParameter]
        private readonly string $ticket,
    ) {}

    /**
     * Return the pre-shared ticket, regardless of the authentication context.
     *
     * @param  string|null  $authId  Unused: the ticket is the same for every identity.
     * @param  array<string, mixed>  $extra  Unused: the challenge does not affect a static ticket.
     */
    public function ticket(?string $authId, array $extra = []): string
    {
        return $this->ticket;
    }
}
