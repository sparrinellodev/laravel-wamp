<?php

namespace Hermod\LaravelWamp\Auth;

use Closure;
use Hermod\LaravelWamp\Contracts\AuthenticatorContract;
use Hermod\LaravelWamp\Contracts\TicketProviderContract;
use Hermod\LaravelWamp\Exceptions\AuthenticationException;
use Throwable;

/**
 * Factory class to resolve and instantiate the appropriate WAMP authenticator.
 */
class AuthenticatorFactory
{
    /**
     * Create a new AuthenticatorFactory instance.
     *
     * @param  (Closure(class-string): object)|null  $resolver  Builds ticket providers from their class name (the service container in Laravel); defaults to `new $class`.
     */
    public function __construct(
        private readonly ?Closure $resolver = null,
    ) {}

    /**
     * Create an authenticator instance based on the provided configuration.
     *
     * @param  array<string, mixed>  $config  The authentication configuration array.
     * @return \Hermod\LaravelWamp\Contracts\AuthenticatorContract
     *
     * @throws \Hermod\LaravelWamp\Exceptions\AuthenticationException If the method is unsupported or missing required parameters.
     */
    public function make(array $config): AuthenticatorContract
    {
        $method = AuthMethod::tryFrom($config['method'] ?? 'anonymous');

        if ($method === null) {
            throw new AuthenticationException(
                "Unsupported authentication method: '{$config['method']}'. " .
                "Accepted values: anonymous, ticket, wampcra."
            );
        }

        return match ($method) {
            AuthMethod::Anonymous => new AnonymousAuthenticator,

            AuthMethod::Ticket => new TicketAuthenticator(
                authId: $config['authid'] ?? throw new AuthenticationException(
                    "Ticket authentication requires an 'authid' to be present in the configuration."
                ),
                ticket: $this->resolveTicket($config),
            ),

            AuthMethod::WampCra => new WampCraAuthenticator(
                authId: $config['authid'] ?? throw new AuthenticationException(
                    "WAMP-CRA authentication requires an 'authid' to be present in the configuration."
                ),
                secret: $config['secret'] ?? throw new AuthenticationException(
                    "WAMP-CRA authentication requires a 'secret' to be present in the configuration."
                ),
            ),
        };
    }

    // -------------------------------------------------------------------------
    // Ticket Resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve the ticket configured for ticket authentication: a static `ticket` or a `ticket_provider` class.
     *
     * @param  array<string, mixed>  $config  The authentication configuration array.
     * @return string|TicketProviderContract The static ticket, or the provider asked for a ticket on every handshake.
     *
     * @throws AuthenticationException If neither or both options are set, or the provider cannot be created.
     */
    private function resolveTicket(array $config): string|TicketProviderContract
    {
        // An empty value counts as missing, e.g. WAMP_AUTH_TICKET= left blank in .env
        $ticket = $config['ticket'] ?? '';
        $provider = $config['ticket_provider'] ?? '';

        if (($ticket === '') === ($provider === '')) {
            throw new AuthenticationException(
                "Ticket authentication requires either a 'ticket' or a 'ticket_provider' in the configuration, but not both.",
            );
        }

        return $ticket !== '' ? $ticket : $this->makeTicketProvider($provider);
    }

    /**
     * Create the configured ticket provider, through the resolver when one is set.
     *
     * @param  mixed  $class  The configured `ticket_provider` value.
     *
     * @throws AuthenticationException If the class does not exist, cannot be created, or does not implement TicketProviderContract.
     */
    private function makeTicketProvider(mixed $class): TicketProviderContract
    {
        if (! is_string($class)) {
            throw new AuthenticationException(
                "The 'ticket_provider' option must be a class name, ".get_debug_type($class).' given.',
            );
        }

        if (! class_exists($class) && ! interface_exists($class)) {
            throw new AuthenticationException(
                "Ticket provider class '{$class}' not found.",
            );
        }

        try {
            $provider = $this->resolver !== null ? ($this->resolver)($class) : new $class;
        } catch (Throwable $e) {
            throw new AuthenticationException(
                "Ticket provider '{$class}' could not be created.",
                previous: $e,
            );
        }

        if (! $provider instanceof TicketProviderContract) {
            throw new AuthenticationException(
                "Ticket provider '{$class}' must implement ".TicketProviderContract::class.'.',
            );
        }

        return $provider;
    }
}
