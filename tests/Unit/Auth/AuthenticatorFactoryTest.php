<?php

use Hermod\LaravelWamp\Auth\AnonymousAuthenticator;
use Hermod\LaravelWamp\Auth\AuthenticatorFactory;
use Hermod\LaravelWamp\Auth\TicketAuthenticator;
use Hermod\LaravelWamp\Auth\WampCraAuthenticator;
use Hermod\LaravelWamp\Exceptions\AuthenticationException;

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
