<?php

use Hermod\LaravelWamp\Auth\AuthMethod;

describe('AuthMethod', function () {

    it('has the correct value for each method', function () {
        expect(AuthMethod::Anonymous->value)->toBe('anonymous')
            ->and(AuthMethod::Ticket->value)->toBe('ticket')
            ->and(AuthMethod::WampCra->value)->toBe('wampcra');
    });

    it('resolves from a valid string', function () {
        expect(AuthMethod::tryFrom('anonymous'))->toBe(AuthMethod::Anonymous)
            ->and(AuthMethod::tryFrom('ticket'))->toBe(AuthMethod::Ticket)
            ->and(AuthMethod::tryFrom('wampcra'))->toBe(AuthMethod::WampCra);
    });

    it('returns null for an unknown string', function () {
        expect(AuthMethod::tryFrom('oauth2'))->toBeNull()
            ->and(AuthMethod::tryFrom(''))->toBeNull();
    });
});
