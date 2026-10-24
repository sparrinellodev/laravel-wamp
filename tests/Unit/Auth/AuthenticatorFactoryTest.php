<?php

use Hermod\LaravelWamp\Auth\AnonymousAuthenticator;
use Hermod\LaravelWamp\Auth\AuthenticatorFactory;
use Hermod\LaravelWamp\Auth\TicketAuthenticator;
use Hermod\LaravelWamp\Auth\WampCraAuthenticator;
use Hermod\LaravelWamp\Contracts\TicketProviderContract;
use Hermod\LaravelWamp\Exceptions\AuthenticationException;

class FixedTicketProvider implements TicketProviderContract
{
    public function ticket(?string $authId, array $extra = []): string
    {
        return 'fixed-jwt';
    }
}

describe('AuthenticatorFactory', function () {

    beforeEach(function () {
        $this->factory = new AuthenticatorFactory;
    });

    // -------------------------------------------------------------------------
    // Anonymous
    // -------------------------------------------------------------------------

    it('creates an AnonymousAuthenticator for the anonymous method', function () {
        $auth = $this->factory->make(['method' => 'anonymous']);

        expect($auth)->toBeInstanceOf(AnonymousAuthenticator::class);
    });

    it('uses anonymous as the default method', function () {
        $auth = $this->factory->make([]);

        expect($auth)->toBeInstanceOf(AnonymousAuthenticator::class);
    });

    // -------------------------------------------------------------------------
    // Ticket
    // -------------------------------------------------------------------------

    it('creates a TicketAuthenticator from a valid config', function () {
        $auth = $this->factory->make([
            'method' => 'ticket',
            'authid' => 'user123',
            'ticket' => 'my-ticket',
        ]);

        expect($auth)->toBeInstanceOf(TicketAuthenticator::class)
            ->and($auth->authId())->toBe('user123');
    });

    it('throws if authid is missing for ticket auth', function () {
        expect(fn () => $this->factory->make([
            'method' => 'ticket',
            'ticket' => 'my-ticket',
            // authid missing
        ]))->toThrow(AuthenticationException::class, 'authid');
    });

    it('throws if ticket is missing for ticket auth', function () {
        expect(fn () => $this->factory->make([
            'method' => 'ticket',
            'authid' => 'user123',
            // ticket missing
        ]))->toThrow(AuthenticationException::class, 'ticket');
    });

    it('creates a TicketAuthenticator with a ticket provider built by the resolver', function () {
        $resolved = [];
        $factory = new AuthenticatorFactory(
            resolver: function (string $class) use (&$resolved) {
                $resolved[] = $class;

                return new $class;
            },
        );

        $auth = $factory->make([
            'method' => 'ticket',
            'authid' => 'user123',
            'ticket_provider' => FixedTicketProvider::class,
        ]);

        expect($auth->handleChallenge(''))->toBe('fixed-jwt')
            ->and($resolved)->toBe([FixedTicketProvider::class]);
    });

    it('instantiates the ticket provider directly when no resolver is given', function () {
        $auth = $this->factory->make([
            'method' => 'ticket',
            'authid' => 'user123',
            'ticket_provider' => FixedTicketProvider::class,
        ]);

        expect($auth->handleChallenge(''))->toBe('fixed-jwt');
    });

    it('throws if both ticket and ticket_provider are set', function () {
        expect(fn () => $this->factory->make([
            'method' => 'ticket',
            'authid' => 'user123',
            'ticket' => 'my-ticket',
            'ticket_provider' => FixedTicketProvider::class,
        ]))->toThrow(AuthenticationException::class, 'but not both');
    });

    it('treats an empty ticket as missing', function () {
        expect(fn () => $this->factory->make([
            'method' => 'ticket',
            'authid' => 'user123',
            'ticket' => '',
        ]))->toThrow(AuthenticationException::class, "either a 'ticket' or a 'ticket_provider'");
    });

    it('throws if the ticket provider class does not exist', function () {
        expect(fn () => $this->factory->make([
            'method' => 'ticket',
            'authid' => 'user123',
            'ticket_provider' => 'App\Wamp\MissingTicketProvider',
        ]))->toThrow(AuthenticationException::class, 'not found');
    });

    it('throws if the ticket provider does not implement TicketProviderContract', function () {
        expect(fn () => $this->factory->make([
            'method' => 'ticket',
            'authid' => 'user123',
            'ticket_provider' => stdClass::class,
        ]))->toThrow(AuthenticationException::class, 'must implement');
    });

    it('wraps failures while creating the ticket provider', function () {
        $factory = new AuthenticatorFactory(
            resolver: fn (string $class) => throw new RuntimeException('Unresolvable dependency'),
        );

        expect(fn () => $factory->make([
            'method' => 'ticket',
            'authid' => 'user123',
            'ticket_provider' => FixedTicketProvider::class,
        ]))->toThrow(function (AuthenticationException $e) {
            expect($e->getMessage())->toContain('could not be created')
                ->and($e->getPrevious())->toBeInstanceOf(RuntimeException::class);
        });
    });

    // -------------------------------------------------------------------------
    // WAMP-CRA
    // -------------------------------------------------------------------------

    it('creates a WampCraAuthenticator from a valid config', function () {
        $auth = $this->factory->make([
            'method' => 'wampcra',
            'authid' => 'user123',
            'secret' => 'my-secret',
        ]);

        expect($auth)->toBeInstanceOf(WampCraAuthenticator::class)
            ->and($auth->authId())->toBe('user123');
    });

    it('throws if authid is missing for wampcra', function () {
        expect(fn () => $this->factory->make([
            'method' => 'wampcra',
            'secret' => 'my-secret',
        ]))->toThrow(AuthenticationException::class, 'authid');
    });

    it('throws if secret is missing for wampcra', function () {
        expect(fn () => $this->factory->make([
            'method' => 'wampcra',
            'authid' => 'user123',
        ]))->toThrow(AuthenticationException::class, 'secret');
    });

    // -------------------------------------------------------------------------
    // Unknown method
    // -------------------------------------------------------------------------

    it('throws for an unsupported method', function () {
        expect(fn () => $this->factory->make(['method' => 'oauth2']))
            ->toThrow(AuthenticationException::class, 'Unsupported authentication method');
    });
});
