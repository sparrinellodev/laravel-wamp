<?php

use Hermod\LaravelWamp\Auth\StaticTicketProvider;

describe('StaticTicketProvider', function () {

    it('always returns the configured ticket', function () {
        $provider = new StaticTicketProvider('my-secret-ticket');

        expect($provider->ticket('user123'))->toBe('my-secret-ticket')
            ->and($provider->ticket(null, ['nonce' => 'abc']))->toBe('my-secret-ticket');
    });
});
