<?php

use Hermod\LaravelWamp\Auth\AuthMethod;
use Hermod\LaravelWamp\Auth\TicketAuthenticator;
use Hermod\LaravelWamp\Contracts\TicketProviderContract;
use Hermod\LaravelWamp\Exceptions\AuthenticationException;

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

    it('hides the ticket argument from stack traces', function () {
        $parameter = (new ReflectionMethod(TicketAuthenticator::class, '__construct'))->getParameters()[1];

        expect($parameter->getAttributes(SensitiveParameter::class))->toHaveCount(1);
    });

    // -------------------------------------------------------------------------
    // Dynamic tickets
    // -------------------------------------------------------------------------

    describe('with a ticket provider', function () {

        it('asks the provider for a ticket on every challenge', function () {
            $provider = new class implements TicketProviderContract
            {
                public int $calls = 0;

                public function ticket(?string $authId, array $extra = []): string
                {
                    return 'jwt-'.++$this->calls;
                }
            };

            $auth = new TicketAuthenticator('user123', $provider);

            expect($auth->handleChallenge(''))->toBe('jwt-1')
                ->and($auth->handleChallenge(''))->toBe('jwt-2')
                ->and($provider->calls)->toBe(2);
        });

        it('passes the authid and the challenge extra to the provider', function () {
            $provider = new class implements TicketProviderContract
            {
                /** @var array<int, mixed> */
                public array $received = [];

                public function ticket(?string $authId, array $extra = []): string
                {
                    $this->received = [$authId, $extra];

                    return 'jwt';
                }
            };

            (new TicketAuthenticator('user123', $provider))->handleChallenge('', ['nonce' => 'abc']);

            expect($provider->received)->toBe(['user123', ['nonce' => 'abc']]);
        });

        it('wraps provider failures without exposing their message', function () {
            $provider = new class implements TicketProviderContract
            {
                public function ticket(?string $authId, array $extra = []): string
                {
                    throw new RuntimeException('Token endpoint rejected client secret s3cr3t');
                }
            };

            expect(fn () => (new TicketAuthenticator('user123', $provider))->handleChallenge(''))
                ->toThrow(function (AuthenticationException $e) {
                    expect($e->getMessage())->toContain('could not supply a ticket')
                        ->not->toContain('s3cr3t')
                        ->and($e->getPrevious())->toBeInstanceOf(RuntimeException::class);
                });
        });

        it('rejects an empty ticket', function () {
            $provider = new class implements TicketProviderContract
            {
                public function ticket(?string $authId, array $extra = []): string
                {
                    return '';
                }
            };

            expect(fn () => (new TicketAuthenticator('user123', $provider))->handleChallenge(''))
                ->toThrow(AuthenticationException::class, 'empty ticket');
        });
    });
});
