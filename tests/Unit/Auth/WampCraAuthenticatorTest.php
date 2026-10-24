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

    it('restituisce il metodo corretto', function () {
        expect($this->auth->method())->toBe(AuthMethod::WampCra);
    });

    it('restituisce l\'authId corretto', function () {
        expect($this->auth->authId())->toBe('user123');
    });

    it('non ha authExtra', function () {
        expect($this->auth->authExtra())->toBe([]);
    });

    it('richiede challenge', function () {
        expect($this->auth->requiresChallenge())->toBeTrue();
    });

    it('calcola correttamente la firma HMAC-SHA256', function () {
        $challenge = 'test-challenge-string';
        $expected = base64_encode(hash_hmac('sha256', $challenge, 'my-secret', binary: true));

        expect($this->auth->handleChallenge($challenge))->toBe($expected);
    });

    it('produce firme diverse per challenge diverse', function () {
        $sig1 = $this->auth->handleChallenge('challenge-uno');
        $sig2 = $this->auth->handleChallenge('challenge-due');

        expect($sig1)->not->toBe($sig2);
    });

    it('produce firme diverse per secret diversi', function () {
        $auth1 = new WampCraAuthenticator('user', 'secret-uno');
        $auth2 = new WampCraAuthenticator('user', 'secret-due');

        $sig1 = $auth1->handleChallenge('stessa-challenge');
        $sig2 = $auth2->handleChallenge('stessa-challenge');

        expect($sig1)->not->toBe($sig2);
    });

    it('lancia eccezione per challenge vuota', function () {
        expect(fn () => $this->auth->handleChallenge(''))
            ->toThrow(AuthenticationException::class, 'Empty challenge');
    });

    describe('WAMP-CRA salted (con PBKDF2)', function () {

        it('deriva la chiave con PBKDF2 quando viene fornita la salt', function () {
            $challenge = 'test-challenge';
            $extra = [
                'challenge' => $challenge,
                'salt' => 'random-salt',
                'iterations' => 100,
                'keylen' => 32,
            ];

            $result = $this->auth->handleChallenge($challenge, $extra);

            // Verifica che il risultato sia una stringa base64 non vuota
            expect($result)
                ->toBeString()
                ->not->toBeEmpty();

            // Verifica che sia diverso dalla firma senza salt
            $unsalted = $this->auth->handleChallenge($challenge);
            expect($result)->not->toBe($unsalted);
        });

        it('produce lo stesso risultato con gli stessi parametri PBKDF2', function () {
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

        it('usa i valori di default se iterations e keylen non sono forniti', function () {
            $challenge = 'test-challenge';
            $extra = [
                'challenge' => $challenge,
                'salt' => 'some-salt',
                // iterations e keylen non forniti → useranno i default
            ];

            $result = $this->auth->handleChallenge($challenge, $extra);

            expect($result)->toBeString()->not->toBeEmpty();
        });
    });
});
