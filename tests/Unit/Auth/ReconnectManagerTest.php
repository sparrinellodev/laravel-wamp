<?php

use Hermod\LaravelWamp\Exceptions\ReconnectException;
use Hermod\LaravelWamp\Reconnect\ExponentialBackoffStrategy;
use Hermod\LaravelWamp\Reconnect\ReconnectManager;

describe('ReconnectManager', function () {

    // -------------------------------------------------------------------------
    // Stato iniziale
    // -------------------------------------------------------------------------

    it('parte non in stato di reconnect', function () {
        $manager = new ReconnectManager(
            strategy: new ExponentialBackoffStrategy(3, 0.001, 1.0, 2.0),
            enabled: true,
        );

        expect($manager->isReconnecting())->toBeFalse();
    });

    it('riconosce se è abilitato o disabilitato', function () {
        $enabled = new ReconnectManager(
            strategy: new ExponentialBackoffStrategy(3, 0.001, 1.0, 2.0),
            enabled: true,
        );
        $disabled = new ReconnectManager(
            strategy: new ExponentialBackoffStrategy(3, 0.001, 1.0, 2.0),
            enabled: false,
        );

        expect($enabled->isEnabled())->toBeTrue()
            ->and($disabled->isEnabled())->toBeFalse();
    });

    // -------------------------------------------------------------------------
    // Reconnect riuscito
    // -------------------------------------------------------------------------

    it('chiama onSuccess dopo reconnect riuscito', function () {
        $manager = new ReconnectManager(
            strategy: new ExponentialBackoffStrategy(3, 0.001, 1.0, 2.0),
            enabled: true,
        );

        $successCalled = false;
        $connectCalls = 0;

        $manager->reconnect(
            connectFn: function () use (&$connectCalls) {
                $connectCalls++;
                // Riesce al primo tentativo
            },
            onSuccess: function () use (&$successCalled) {
                $successCalled = true;
            },
        );

        expect($successCalled)->toBeTrue()
            ->and($connectCalls)->toBe(1);
    });

    it('riprova dopo fallimento e riesce al secondo tentativo', function () {
        $manager = new ReconnectManager(
            strategy: new ExponentialBackoffStrategy(3, 0.001, 1.0, 2.0),
            enabled: true,
        );

        $attempts = 0;
        $successCalled = false;

        $manager->reconnect(
            connectFn: function () use (&$attempts) {
                $attempts++;
                if ($attempts < 2) {
                    throw new RuntimeException('Connessione fallita');
                }
                // Riesce al secondo tentativo
            },
            onSuccess: function () use (&$successCalled) {
                $successCalled = true;
            },
        );

        expect($successCalled)->toBeTrue()
            ->and($attempts)->toBe(2);
    });

    // -------------------------------------------------------------------------
    // Reconnect fallito
    // -------------------------------------------------------------------------

    it('lancia ReconnectException quando i tentativi si esauriscono', function () {
        $manager = new ReconnectManager(
            strategy: new ExponentialBackoffStrategy(3, 0.001, 1.0, 2.0),
            enabled: true,
        );

        $attempts = 0;

        expect(function () use ($manager, &$attempts) {
            $manager->reconnect(
                connectFn: function () use (&$attempts) {
                    $attempts++;
                    throw new RuntimeException('Connessione sempre fallita');
                },
                onSuccess: fn () => null,
            );
        })->toThrow(ReconnectException::class, 'Reconnect failed after');

        expect($attempts)->toBe(3);
    });

    // -------------------------------------------------------------------------
    // Reconnect disabilitato
    // -------------------------------------------------------------------------

    it('lancia ReconnectException immediatamente se disabilitato', function () {
        $manager = new ReconnectManager(
            strategy: new ExponentialBackoffStrategy(3, 0.001, 1.0, 2.0),
            enabled: false,
        );

        expect(fn () => $manager->reconnect(
            connectFn: fn () => null,
            onSuccess: fn () => null,
        ))->toThrow(ReconnectException::class, 'is disabled');
    });

    // -------------------------------------------------------------------------
    // Reset dopo reconnect
    // -------------------------------------------------------------------------

    it('resetta la strategy dopo reconnect riuscito', function () {
        $strategy = new ExponentialBackoffStrategy(5, 0.001, 1.0, 2.0);
        $manager = new ReconnectManager(strategy: $strategy, enabled: true);

        $attempts = 0;

        // Prima reconnect — fallisce 2 volte poi riesce
        $manager->reconnect(
            connectFn: function () use (&$attempts) {
                $attempts++;
                if ($attempts < 3) {
                    throw new RuntimeException('Fallito');
                }
            },
            onSuccess: fn () => null,
        );

        // Dopo il reset la strategy dovrebbe ripartire da 0
        expect($strategy->attempts())->toBe(0)
            ->and($strategy->nextDelay())->toBe(0.001);
    });
});
