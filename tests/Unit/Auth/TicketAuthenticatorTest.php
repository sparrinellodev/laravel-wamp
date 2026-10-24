<?php

use Hermod\LaravelWamp\Auth\AuthMethod;
use Hermod\LaravelWamp\Auth\TicketAuthenticator;

describe('TicketAuthenticator', function () {

    beforeEach(function () {
        $this->auth = new TicketAuthenticator(
            authId: 'user123',
            ticket: 'my-secret-ticket',
        );
    });

    it('returns the correct method', function () {
        expect($this->auth->method())->toBe(AuthMethod::Ticket);
    });

    it('returns the correct authId', function () {
        expect($this->auth->authId())->toBe('user123');
    });

    it('has no authExtra', function () {
        expect($this->auth->authExtra())->toBe([]);
    });

    it('requires a challenge', function () {
        expect($this->auth->requiresChallenge())->toBeTrue();
    });

    it('returns the ticket as the challenge response', function () {
        // For ticket auth the router challenge is ignored
        // and the ticket is sent as-is as the signature
        $result = $this->auth->handleChallenge('challenge-ignorata');

        expect($result)->toBe('my-secret-ticket');
    });

    it('always returns the ticket regardless of the challenge', function () {
        expect($this->auth->handleChallenge('challenge-1'))
            ->toBe('my-secret-ticket')
            ->and($this->auth->handleChallenge('challenge-2'))
            ->toBe('my-secret-ticket');
    });
});
