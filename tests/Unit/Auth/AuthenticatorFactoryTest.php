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

    it('crea un AnonymousAuthenticator per metodo anonymous', function () {
        $auth = $this->factory->make(['method' => 'anonymous']);

        expect($auth)->toBeInstanceOf(AnonymousAuthenticator::class);
    });

    it('usa anonymous come metodo di default', function () {
        $auth = $this->factory->make([]);

        expect($auth)->toBeInstanceOf(AnonymousAuthenticator::class);
    });

    // -------------------------------------------------------------------------
    // Ticket
    // -------------------------------------------------------------------------

    it('crea un TicketAuthenticator con config corretta', function () {
        $auth = $this->factory->make([
            'method' => 'ticket',
            'authid' => 'user123',
            'ticket' => 'my-ticket',
        ]);

        expect($auth)->toBeInstanceOf(TicketAuthenticator::class)
            ->and($auth->authId())->toBe('user123');
    });

    it('lancia eccezione se authid mancante per ticket auth', function () {
        expect(fn () => $this->factory->make([
            'method' => 'ticket',
            'ticket' => 'my-ticket',
            // authid mancante
        ]))->toThrow(AuthenticationException::class, 'authid');
    });

    it('lancia eccezione se ticket mancante per ticket auth', function () {
        expect(fn () => $this->factory->make([
            'method' => 'ticket',
            'authid' => 'user123',
            // ticket mancante
        ]))->toThrow(AuthenticationException::class, 'ticket');
    });

    // -------------------------------------------------------------------------
    // WAMP-CRA
    // -------------------------------------------------------------------------

    it('crea un WampCraAuthenticator con config corretta', function () {
        $auth = $this->factory->make([
            'method' => 'wampcra',
            'authid' => 'user123',
            'secret' => 'my-secret',
        ]);

        expect($auth)->toBeInstanceOf(WampCraAuthenticator::class)
            ->and($auth->authId())->toBe('user123');
    });

    it('lancia eccezione se authid mancante per wampcra', function () {
        expect(fn () => $this->factory->make([
            'method' => 'wampcra',
            'secret' => 'my-secret',
        ]))->toThrow(AuthenticationException::class, 'authid');
    });

    it('lancia eccezione se secret mancante per wampcra', function () {
        expect(fn () => $this->factory->make([
            'method' => 'wampcra',
            'authid' => 'user123',
        ]))->toThrow(AuthenticationException::class, 'secret');
    });

    // -------------------------------------------------------------------------
    // Metodo sconosciuto
    // -------------------------------------------------------------------------

    it('lancia eccezione per metodo non supportato', function () {
        expect(fn () => $this->factory->make(['method' => 'oauth2']))
            ->toThrow(AuthenticationException::class, 'Unsupported authentication method');
    });
});
