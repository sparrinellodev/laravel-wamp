<?php

use Hermod\LaravelWamp\Auth\AuthMethod;
use Hermod\LaravelWamp\Auth\WampCraAuthenticator;
use Hermod\LaravelWamp\Exceptions\AuthenticationException;

describe('WampCraAuthenticator', function () {

    beforeEach(function () {
        $this->auth = new WampCraAuthenticator(
            authId: 'user123',
            secret: 'my-secret',
        );
    });

    it('returns the correct method', function () {
        expect($this->auth->method())->toBe(AuthMethod::WampCra);
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

    it('computes the HMAC-SHA256 signature', function () {
        $challenge = 'test-challenge-string';
        $expected = base64_encode(hash_hmac('sha256', $challenge, 'my-secret', binary: true));

        expect($this->auth->handleChallenge($challenge))->toBe($expected);
    });

    it('produces different signatures for different challenges', function () {
        $sig1 = $this->auth->handleChallenge('challenge-uno');
        $sig2 = $this->auth->handleChallenge('challenge-due');

        expect($sig1)->not->toBe($sig2);
    });

    it('produces different signatures for different secrets', function () {
        $auth1 = new WampCraAuthenticator('user', 'secret-uno');
        $auth2 = new WampCraAuthenticator('user', 'secret-due');

        $sig1 = $auth1->handleChallenge('stessa-challenge');
        $sig2 = $auth2->handleChallenge('stessa-challenge');

        expect($sig1)->not->toBe($sig2);
    });

    it('throws for an empty challenge', function () {
        expect(fn () => $this->auth->handleChallenge(''))
            ->toThrow(AuthenticationException::class, 'Empty challenge');
    });

    describe('WAMP-CRA salted (PBKDF2)', function () {

        it('derives the key with PBKDF2 when a salt is provided', function () {
            $challenge = 'test-challenge';
            $extra = [
                'challenge' => $challenge,
                'salt' => 'random-salt',
                'iterations' => 100,
                'keylen' => 32,
            ];

            $result = $this->auth->handleChallenge($challenge, $extra);

            // The result must be a non-empty base64 string
            expect($result)
                ->toBeString()
                ->not->toBeEmpty();

            // It must differ from the unsalted signature
            $unsalted = $this->auth->handleChallenge($challenge);
            expect($result)->not->toBe($unsalted);
        });

        it('produces the same result for the same PBKDF2 parameters', function () {
            $challenge = 'test-challenge';
            $extra = [
                'challenge' => $challenge,
                'salt' => 'fixed-salt',
                'iterations' => 1000,
                'keylen' => 32,
            ];

            $result1 = $this->auth->handleChallenge($challenge, $extra);
            $result2 = $this->auth->handleChallenge($challenge, $extra);

            expect($result1)->toBe($result2);
        });

        it('uses default values when iterations and keylen are not provided', function () {
            $challenge = 'test-challenge';
            $extra = [
                'challenge' => $challenge,
                'salt' => 'some-salt',
                // iterations and keylen omitted → defaults are used
            ];

            $result = $this->auth->handleChallenge($challenge, $extra);

            expect($result)->toBeString()->not->toBeEmpty();
        });
    });
});
